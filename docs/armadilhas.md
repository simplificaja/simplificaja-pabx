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

   O mesmo vale para **troncos**: a configuração do sofia, com os gateways, é
   servida do cache. Tronco criado que não aparece nem como falhando no
   `sofia status`? Falta apagar a chave `<hostname>:configuration:sofia.conf`.
   Mesma classe do item 1 -- existe no banco, aparece na tela, invisível para o
   FreeSWITCH.

   Em código, a classe `cache` é de **instância**:
   `$cache = new cache(); $cache->delete(...)`. Chamar `cache::delete()`
   estaticamente é erro fatal -- e o dado já foi gravado quando o fatal
   acontece, então a escrita passa e só a resposta morre.

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

## `DELETE /dominio` deixa o IP da operadora para trás

Remover o domínio apaga tronco, destino e ramais, mas **não** remove a entrada
que o `POST /troncos` criou na lista de acesso `providers`. Cada cliente que sai
deixa um IP liberado no Event Guard para sempre — decaimento lento, sem sintoma
visível, que só aparece quando alguém audita a ACL.

Descoberto em 20/09/2026 testando a criação e remoção de um cliente inteiro: o
IP de teste continuou na ACL depois de o domínio ter sumido.

Conferir com:

```sql
select n.node_cidr, n.node_description from v_access_control_nodes n
join v_access_controls a on a.access_control_uuid = n.access_control_uuid
where a.access_control_name = 'providers';
```

## Remover o domínio não tira o tronco da memória do FreeSWITCH

`DELETE /dominio` apaga o gateway do Postgres, mas o sofia mantém o que já
carregou. O tronco do cliente removido **continua tentando registrar na
operadora**, em `FAIL_WAIT` com retentativa, indefinidamente — ruído em
direção à operadora por um cliente que não existe mais, e ninguém percebe
porque nada na tela mostra.

Descoberto em 21/09/2026: um gateway de teste aparecia em `sofia status` sem
existir em `v_gateways`.

Conferir e limpar:

```bash
fs_cli -x "sofia status" | grep -i gateway     # o que está carregado
fs_cli -x "sofia profile external killgw <uuid>"
```

Compare sempre com o banco — o que está em memória e não está em
`v_gateways` é órfão:

```sql
select g.gateway, coalesce(d.domain_name,'SEM DOMINIO') from v_gateways g
left join v_domains d on d.domain_uuid = g.domain_uuid;
```

## Gravação sem `recording_base64` não toca no painel

O app de gravações do FusionPBX grava o arquivo em disco **e** guarda o
conteúdo em `recording_base64` (`recordings/recording_edit.php:269`). O base64
é o que a tela usa para tocar o áudio; o arquivo é o que a chamada toca.
Gravação criada só com o arquivo funciona na ligação e **o botão de tocar no
painel não faz nada** — sem erro, sem aviso.

Em 21/09/2026 as cinco gravações do domínio `pabx.` estavam assim, criadas à
mão antes da API existir.

Conferir:

```sql
select recording_filename, octet_length(recording_base64) from v_recordings;
```

`null` ali é gravação meio criada. E o base64 tem que ser do arquivo **já
convertido** para 8 kHz mono: se vier do original, o painel toca um áudio e a
ligação toca outro.

## O diretório das gravações não é o que o nome sugere

Existem dois caminhos parecidos no servidor:

```
/var/lib/freeswitch/recordings          <- o que vale
/var/lib/freeswitch/storage/recordings  <- existe e não é usado
```

O FusionPBX resolve por `$settings->get('switch','recordings')`, mas nesta
instalação esse valor está **vazio no banco** e quem responde de verdade é o
FreeSWITCH, por `global_getvar recordings_dir`.

Gravar no caminho errado produz o pior tipo de falha: o arquivo existe, tem o
formato certo, tem o dono certo, e simplesmente não toca -- porque nem o
FreeSWITCH nem o painel olham ali. Conferir o formato e o dono não pega isso.

Perguntar, nunca cravar:

```bash
fs_cli -x "global_getvar recordings_dir"
```
