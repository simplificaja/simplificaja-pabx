#!/usr/bin/env python3
"""Ouve o Event Socket do FreeSWITCH e registra a chamada no SimplificaJá.

Por que um serviço e não um gancho no plano de discagem:

O gancho anterior morava dentro de `app/hangup/index.lua`, um arquivo do
FusionPBX. O `POST /dominio` chama `domains::upgrade()`, que restaura os
scripts a partir da fonte -- então criar um cliente novo apagava a invocação e
derrubava o registro de chamada de todos os outros, em silêncio. Aconteceu em
21/09/2026 e só apareceu porque uma ligação de teste não chegou ao painel.

Mover a invocação para um plano de discagem no banco também não resolve: o
`local_extension` do FusionPBX roda num passo posterior e sobrescreve o
`api_hangup_hook` independente da ordem, e o `execute_on_hangup` não dispara no
teardown. Ouvir o Event Socket não disputa nada com eles.

Qual perna vira registro: com URA, anúncio ou fila, uma ligação gera várias
pernas. A que veio da operadora tem `Call-Direction` preenchido (inbound ou
outbound); as que o plano cria vêm vazias. Só a primeira vira chamada, senão
uma ligação viraria cinco conversas.
"""
import json
import logging
import os
import socket
import subprocess
import time
import urllib.parse

import requests

FS_HOST = os.environ.get("FS_HOST", "127.0.0.1")
FS_PORT = int(os.environ.get("FS_PORT", "8021"))
FS_SENHA = os.environ.get("FS_SENHA", "ClueCon")
DESTINO = os.environ.get(
    "SIMPLIFICAJA_WEBHOOK", "https://app.simplificaja.com.br/webhooks/fusionpbx"
)
CONFIG_FUSIONPBX = "/etc/fusionpbx/config.conf"

log = logging.getLogger("registrador")


def credenciais_do_banco():
    """Lê do config do FusionPBX para não manter uma segunda cópia da senha."""
    valores = {}
    with open(CONFIG_FUSIONPBX, encoding="utf-8") as arquivo:
        for linha in arquivo:
            if "=" not in linha or not linha.startswith("database.0."):
                continue
            chave, _, valor = linha.partition("=")
            valores[chave.strip().replace("database.0.", "")] = valor.strip()
    return valores


class Segredos:
    """Segredo do webhook por domínio, em cache.

    Cada cliente tem o seu: um PABX invadido alcança os clientes dele e não a
    instalação inteira do SimplificaJá.
    """

    VALIDADE = 300

    def __init__(self):
        self._cache = {}
        self._banco = credenciais_do_banco()

    def de(self, dominio):
        agora = time.time()
        guardado = self._cache.get(dominio)
        if guardado and agora - guardado[1] < self.VALIDADE:
            return guardado[0]

        segredo = self._consultar(dominio)
        self._cache[dominio] = (segredo, agora)
        return segredo

    def _consultar(self, dominio):
        sql = (
            "select s.domain_setting_value from v_domain_settings s "
            "join v_domains d on d.domain_uuid = s.domain_uuid "
            "where d.domain_name = %s "
            "and s.domain_setting_category = 'simplificaja' "
            "and s.domain_setting_subcategory = 'webhook_secret' "
            "and s.domain_setting_enabled = true limit 1"
        ) % _literal(dominio)
        ambiente = dict(os.environ, PGPASSWORD=self._banco.get("password", ""))
        try:
            saida = subprocess.run(
                ["psql", "-h", self._banco.get("host", "127.0.0.1"),
                 "-p", self._banco.get("port", "5432"),
                 "-U", self._banco.get("username", "fusionpbx"),
                 "-d", self._banco.get("name", "fusionpbx"),
                 "-tAc", sql],
                capture_output=True, text=True, timeout=10, env=ambiente, check=True,
            )
            return saida.stdout.strip() or None
        except (subprocess.SubprocessError, OSError) as erro:
            log.error("nao consegui ler o segredo de %s: %s", dominio, erro)
            return None


def _literal(texto):
    """Aspas simples ao redor, dobrando as internas."""
    return "'" + texto.replace("'", "''") + "'"


class EventSocket:
    def __init__(self):
        self.sock = None
        self.buffer = b""

    def conectar(self):
        self.sock = socket.create_connection((FS_HOST, FS_PORT), timeout=30)
        self.buffer = b""
        self._ler_bloco()  # auth/request
        self._enviar(f"auth {FS_SENHA}")
        resposta = self._ler_bloco()
        if "+OK" not in resposta.get("Reply-Text", ""):
            raise ConnectionError(f"autenticacao recusada: {resposta.get('Reply-Text')}")
        self._enviar("event plain CHANNEL_HANGUP_COMPLETE")
        self._ler_bloco()
        log.info("ouvindo CHANNEL_HANGUP_COMPLETE em %s:%s", FS_HOST, FS_PORT)

    def _enviar(self, comando):
        self.sock.sendall(f"{comando}\n\n".encode())

    def _ler_linha(self):
        while b"\n" not in self.buffer:
            pedaco = self.sock.recv(8192)
            if not pedaco:
                raise ConnectionError("socket fechou")
            self.buffer += pedaco
        linha, _, self.buffer = self.buffer.partition(b"\n")
        return linha.decode("utf-8", "replace")

    def _ler_bytes(self, quantos):
        while len(self.buffer) < quantos:
            pedaco = self.sock.recv(8192)
            if not pedaco:
                raise ConnectionError("socket fechou")
            self.buffer += pedaco
        corpo, self.buffer = self.buffer[:quantos], self.buffer[quantos:]
        return corpo.decode("utf-8", "replace")

    def _ler_bloco(self):
        cabecalhos = {}
        while True:
            linha = self._ler_linha()
            if linha == "":
                break
            chave, _, valor = linha.partition(":")
            cabecalhos[chave.strip()] = valor.strip()
        tamanho = int(cabecalhos.get("Content-Length", 0))
        if tamanho:
            cabecalhos["_corpo"] = self._ler_bytes(tamanho)
        return cabecalhos

    def proximo_evento(self):
        """Devolve o evento como dicionário, já desembrulhado do corpo."""
        bloco = self._ler_bloco()
        corpo = bloco.get("_corpo")
        if not corpo:
            return None
        evento = {}
        for linha in corpo.split("\n"):
            if ":" not in linha:
                continue
            chave, _, valor = linha.partition(":")
            evento[chave.strip()] = urllib.parse.unquote_plus(valor.strip())
        return evento


def montar(evento):
    """Traduz o evento para o corpo que o SimplificaJá espera."""
    def campo(*nomes):
        for nome in nomes:
            valor = evento.get(nome)
            if valor:
                return valor
        return ""

    # O número que a operadora discou, não o ramal que atendeu: depois da URA
    # ou do bridge, `Caller-Destination-Number` já vale outra coisa e a caixa
    # de entrada nunca casa.
    return {
        "domain": campo("variable_domain_name"),
        "direction": campo("Call-Direction", "variable_call_direction"),
        "from": campo("Caller-Caller-ID-Number", "variable_caller_id_number"),
        "to": campo("variable_caller_destination", "Caller-Destination-Number"),
        "extension": campo("variable_dialed_extension"),
        "call_uuid": campo("variable_call_uuid", "Unique-ID"),
        "duration": campo("variable_billsec") or "0",
        "hangup_cause": campo("Hangup-Cause", "variable_hangup_cause"),
        "started_at": campo("variable_start_stamp"),
    }


def registrar(sessao, segredos, evento):
    # Uma ligação gera várias pernas -- a da operadora, a do anúncio, a da URA,
    # a do ramal. A dona da chamada é a única cujo `Unique-ID` é o próprio
    # `call_uuid`; as filhas carregam o dela. Sem isto, uma ligação que passa
    # por URA viraria uma conversa por perna.
    if evento.get("Unique-ID") != evento.get("variable_call_uuid"):
        return
    if evento.get("Call-Direction") not in ("inbound", "outbound"):
        return

    corpo = montar(evento)
    if not corpo["domain"] or not corpo["call_uuid"]:
        return

    segredo = segredos.de(corpo["domain"])
    if not segredo:
        log.error("dominio %s sem webhook_secret; chamada %s nao registrada",
                  corpo["domain"], corpo["call_uuid"])
        return

    try:
        resposta = sessao.post(
            DESTINO, json=corpo, timeout=8,
            headers={"X-Webhook-Secret": segredo},
        )
        if resposta.status_code >= 400:
            log.error("webhook devolveu %s para %s: %s",
                      resposta.status_code, corpo["call_uuid"], resposta.text[:120])
        else:
            log.info("registrada %s %s -> %s (%ss)", corpo["direction"],
                     corpo["from"], corpo["to"], corpo["duration"])
    except requests.RequestException as erro:
        log.error("nao consegui entregar %s: %s", corpo["call_uuid"], erro)


def main():
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")
    segredos = Segredos()
    sessao = requests.Session()
    espera = 1

    while True:
        socket_fs = EventSocket()
        try:
            socket_fs.conectar()
            espera = 1
            while True:
                evento = socket_fs.proximo_evento()
                if evento:
                    registrar(sessao, segredos, evento)
        except (ConnectionError, OSError, socket.timeout) as erro:
            # O FreeSWITCH reinicia; o serviço tem que voltar sozinho, senão o
            # registro de chamada morre em silêncio até alguém perceber.
            log.warning("conexao caiu (%s); tentando de novo em %ss", erro, espera)
            time.sleep(espera)
            espera = min(espera * 2, 30)
        finally:
            try:
                socket_fs.sock.close()
            except (AttributeError, OSError):
                pass


if __name__ == "__main__":
    main()
