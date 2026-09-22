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
        """Devolve (segredo, e_nosso). `e_nosso` distingue tenant alheio de
        tenant nosso sem segredo -- so o segundo e defeito."""
        agora = time.time()
        guardado = self._cache.get(dominio)
        if not guardado or agora - guardado[1] >= self.VALIDADE:
            guardado = (self._consultar(dominio), agora)
            self._cache[dominio] = guardado

        chaves = guardado[0]
        return chaves.get("webhook_secret"), bool(chaves.get("api_key"))

    def _consultar(self, dominio):
        # Traz as duas chaves de uma vez para distinguir dois estados que sao
        # muito diferentes e pareciam iguais: tenant que nunca foi nosso (o
        # FusionPBX e multi-tenant, e pode haver cliente so de PABX no mesmo
        # servidor) e tenant nosso que perdeu o segredo, que e defeito.
        sql = (
            "select s.domain_setting_subcategory, s.domain_setting_value "
            "from v_domain_settings s "
            "join v_domains d on d.domain_uuid = s.domain_uuid "
            "where d.domain_name = %s "
            "and s.domain_setting_category = 'simplificaja' "
            "and s.domain_setting_subcategory in ('webhook_secret', 'api_key') "
            "and s.domain_setting_enabled = true"
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
            achados = {}
            for linha in saida.stdout.strip().split("\n"):
                if "|" in linha:
                    chave, _, valor = linha.partition("|")
                    achados[chave.strip()] = valor.strip()
            return achados
        except (subprocess.SubprocessError, OSError) as erro:
            log.error("nao consegui ler as chaves de %s: %s", dominio, erro)
            return {}


def _literal(texto):
    """Aspas simples ao redor, dobrando as internas."""
    return "'" + texto.replace("'", "''") + "'"


class EventSocket:
    def __init__(self):
        self.sock = None
        self.buffer = b""

    def conectar(self):
        self.sock = socket.create_connection((FS_HOST, FS_PORT), timeout=10)
        # O timeout do `create_connection` nao vale so para o connect: ele fica
        # no socket e passa a valer para todo `recv` seguinte. Um socket de
        # eventos fica ocioso por natureza -- 30s sem chamada nenhuma e o recv
        # estourava, o loop tratava como queda e reconectava. Eram 114
        # reconexoes por hora, com ~1s sem ouvinte a cada uma: chamada que
        # desliga nessa janela nunca vira conversa, e nada no log acusa.
        self.sock.settimeout(None)
        # Quem detecta queda de verdade -- peer morto sem FIN -- e o keepalive
        # do TCP, nao um timeout de leitura. Socket fechado limpo continua
        # aparecendo como `recv` devolvendo vazio, que o codigo ja trata.
        self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_KEEPALIVE, 1)
        for opcao, valor in (("TCP_KEEPIDLE", 60), ("TCP_KEEPINTVL", 10), ("TCP_KEEPCNT", 3)):
            if hasattr(socket, opcao):
                self.sock.setsockopt(socket.IPPROTO_TCP, getattr(socket, opcao), valor)
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
    # Na saida quem liga e' a empresa, entao `from` tem que ser o numero que
    # ela apresenta -- o mesmo que a rota de saida poe no visor de quem recebe.
    # O caller id cru aqui e' o ramal (`1001`), que nao identifica caixa de
    # entrada nenhuma: a chamada seria descartada por falta de inbox.
    saindo = campo("Call-Direction", "variable_call_direction") == "outbound"
    origem = (["variable_effective_caller_id_number", "Caller-Caller-ID-Number"]
              if saindo else ["Caller-Caller-ID-Number", "variable_caller_id_number"])

    return {
        "domain": campo("variable_domain_name"),
        "direction": campo("Call-Direction", "variable_call_direction"),
        "from": campo(*origem),
        "to": campo("variable_caller_destination", "Caller-Destination-Number"),
        "extension": campo("variable_dialed_extension"),
        "call_uuid": campo("variable_call_uuid", "Unique-ID"),
        "duration": campo("variable_billsec") or "0",
        "hangup_cause": campo("Hangup-Cause", "variable_hangup_cause"),
        "started_at": campo("variable_start_stamp"),
    }


def registrar(sessao, segredos, evento):
    # Uma ligação gera várias pernas -- a da operadora, a da URA, a do ramal --
    # e só a dona pode virar conversa.
    #
    # NÃO dá para comparar `Unique-ID` com `variable_call_uuid`: a perna que o
    # `bridge` cria para `user/<ramal>` ganha `call_uuid` PRÓPRIO, então ela
    # passa por essa comparação como se fosse dona. Medido numa ligação real:
    # a mesma chamada entregou dois webhooks, `0861e750` (a de verdade) e
    # `65709868` (a do ramal).
    #
    # O que separa de fato é `originating_leg_uuid`: quem foi originado por
    # outra perna o carrega, quem nasceu de um INVITE não. Vale nos dois
    # sentidos -- na entrada a dona é a da operadora, na saída é a do ramal, e
    # em ambos a filha é a que tem o campo.
    if evento.get("variable_originating_leg_uuid"):
        return
    if evento.get("Call-Direction") not in ("inbound", "outbound"):
        return

    corpo = montar(evento)
    if not corpo["domain"] or not corpo["call_uuid"]:
        return

    segredo, nosso = segredos.de(corpo["domain"])
    if not segredo:
        # Tenant que nao e nosso nao e erro: o FusionPBX e multi-tenant e pode
        # hospedar cliente so de PABX. Gritar aqui enche o log de linha que
        # ninguem vai ler -- e ai a linha que importa passa batida.
        if nosso:
            log.error("dominio %s tem api_key e nao tem webhook_secret; "
                      "chamada %s nao registrada", corpo["domain"], corpo["call_uuid"])
        else:
            log.debug("dominio %s nao e do SimplificaJa; chamada %s ignorada",
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
