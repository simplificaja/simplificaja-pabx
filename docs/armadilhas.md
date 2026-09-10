# Armadilhas do FusionPBX

Se algo "está configurado e não funciona", confira estas antes de investigar
qualquer outra coisa. Todas custaram horas na validação de 09-10/09/2026.

1. **O FreeSWITCH lê a coluna `dialplan_xml`, não as linhas da rota.** Ela só é
   preenchida ao salvar pela tela. Rota criada por SQL existe no banco, aparece
   na interface e é invisível para o FreeSWITCH. Limpar cache não resolve, e é
   por isso que a API do FusionPBX **tem que ser PHP rodando dentro dele**,
   usando as classes dele — escrever no Postgres direto reproduz exatamente esse
   defeito.

2. **Rota de entrada exige linha em `v_destinations`.** O handler Lua monta o
   contexto público a partir de `v_destinations`, não de `v_dialplans`. Criar
   pelo app Destinations, nunca como plano de discagem cru.

3. **O Event Guard bane quem autentica contra IP puro em vez de domínio** — que
   é a cara de qualquer tronco. Ele descarta as respostas no firewall, o
   FreeSWITCH acusa timeout, e a captura de pacote mostra a resposta chegando.
   Liberar o IP na lista de controle de acesso `providers`.

4. **O perfil `external` precisa de `auth-calls = false`.** Com `true`, o PBX
   responde `407 Proxy Authentication Required` a toda chamada que a operadora
   entrega. A segurança vem do `apply-inbound-acl`, não da autenticação.

5. **A operadora pode entregar pela conta SIP, não pelo DID.** A da validação
   entregava para `75681`, não para o número. Com o Magnus no meio isso deixa de
   variar por cliente.

6. **Cache:** `rm -rf /var/cache/fusionpbx/*` depois de qualquer mudança de
   plano de discagem, senão o XML antigo continua valendo.

7. **O servidor está no fuso europeu.** Os logs saem 5 horas à frente de
   Brasília — ajustar antes de produção, senão investigar incidente vira conta
   de cabeça.

---

---

## O método que resolveu

Quando **o log e a captura de pacote discordam**, a diferença está entre a placa
de rede e a aplicação — quase sempre firewall. O `tcpdump` captura antes do
netfilter, então um pacote que aparece na captura e não chega no FreeSWITCH está
sendo descartado no meio.

Foi assim que o Event Guard apareceu: o FreeSWITCH acusava `408 Request Timeout`
enquanto a captura mostrava a operadora respondendo `401` em 200ms.
