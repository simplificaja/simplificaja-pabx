# Mover o PABX para outro servidor

Runbook reaproveitável. Escrito na migração Europa → Brasil de outubro/2026, mas
nada aqui é específico dela — a seção da AWS no fim existe porque a próxima
provavelmente é lá, e a AWS tem uma armadilha que as outras não têm.

**O que decide o prazo não é o servidor: é a operadora.** Ela libera por IP. Peça
a liberação do IP novo **antes** de tudo e pergunte se ela consegue manter os
dois liberados por alguns dias. Sem essa janela a migração vira corte, e corte de
telefone em horário comercial é o que não se faz.

---

## Fase 0 · Escolher o servidor

- [ ] **Região perto da operadora.** Foi o motivo desta migração: o PABX na Europa
      dava 208 ms de ida e volta até a operadora brasileira, então o áudio
      atravessava o Atlântico duas vezes — inclusive numa ligação de um ramal
      para outro na mesma sala. Boca a ouvido ficava em ~250 ms, e a recomendação
      da ITU para conversa confortável é abaixo de 150 ms.
- [ ] **IP público fixo.** A operadora libera por IP; IP que muda derruba as
      entrantes sem avisar.
- [ ] **IP público na interface de rede, ou `external_rtp_ip` configurado à mão.**
      Ver a seção da AWS: é aqui que se perde um dia.
- [ ] **UDP liberado em faixa larga** (16384-32768). Provedor que só deixa abrir
      portas uma a uma não serve.
- [ ] **O provedor não bloqueia nem molda SIP.** Alguns tratam 5060 como abuso.
- [ ] Confira a latência **antes** de contratar, de uma máquina na mesma região:
      `ping <ip da operadora>` tem de ficar abaixo de 30 ms.

Medir depois de montar:

```bash
ping -c 10 177.11.50.217      # operadora
```

---

## Fase 1 · Preparar, sem mexer em nada ainda

- [ ] Pedir à operadora a liberação do IP novo, com os dois ativos na transição
- [ ] Baixar o TTL do DNS para 300 s, **uns dois dias antes** — senão a virada
      leva horas
- [ ] Snapshot do servidor atual, se o provedor oferecer
- [ ] Anotar a versão exata do FreeSWITCH que está rodando:
      `fs_cli -x version`
- [ ] Avisar os clientes, se já houver algum pagando

### A decisão da versão

Este servidor compila o FreeSWITCH do **fork do FusionPBX**
(`github.com/fusionpbx/freeswitch`), e é de lá que vem a regressão do commit
`d871c84ab8`: fila sem `announce_sound` mata o processo quando o atendente
atende. A gente neutralizou com gatilho no Postgres, mas o defeito continua no
binário.

- [ ] Decidir: repetir o mesmo build (previsível, carrega o bug) ou usar outro
      (sem o bug, mas é mudança não testada junto da migração)

Recomendação: **repita o mesmo build nesta migração.** Trocar servidor e trocar
binário na mesma janela é duas variáveis para depurar ao mesmo tempo. Troque o
build depois, com o servidor novo já estável.

---

## Fase 2 · O que viaja no dump do Postgres

```bash
# no servidor antigo
su - postgres -c 'pg_dump -Fc fusionpbx' > /root/fusionpbx-$(date +%F).dump
```

Vem junto, e não precisa de cuidado especial:

- domínios, ramais, filas e vínculos de atendente
- **planos de discagem com a coluna `dialplan_xml`** — é o que o FreeSWITCH lê de
  verdade, e é por isso que o dump basta
- rotas de entrada (`v_destinations`) e troncos (`v_gateways`), com senha
- **listas de controle de acesso**, incluindo a `providers` com o IP da operadora
- **configurações de domínio, incluindo a chave da nossa API** — é ela que o hub
  usa, então se a chave sobreviver o hub nem percebe a migração
- **o gatilho `simplificaja_fila_exige_anuncio`** — confira depois da restauração,
  porque é ele que impede a queda do FreeSWITCH

---

## Fase 3 · O que NÃO viaja — a lista que quebra migração

Esta é a parte que faz um PABX restaurado não atender ligação.

### 3.1 Gravações em disco

~3 MB hoje, e **não** estão no dump (o base64 no banco serve ao player do painel,
não à ligação).

```bash
rsync -av /var/lib/freeswitch/recordings/ novo:/var/lib/freeswitch/recordings/
chown -R www-data:www-data /var/lib/freeswitch/recordings
```

Sem isso: anúncio e música de espera viram silêncio, e a ligação "funciona" sem
áudio — defeito que não acusa erro em lugar nenhum.

### 3.2 A nossa API

31 arquivos, que **não** vêm do FusionPBX. Saem do repo `pabx`:

```bash
scp -r app/simplificaja_api root@novo:/var/www/fusionpbx/app/
ssh root@novo 'chown -R www-data:www-data /var/www/fusionpbx/app/simplificaja_api'
```

### 3.3 A liberação da API no nginx

A API só aceita o IP do hub. São **dois** blocos em
`/etc/nginx/sites-enabled/fusionpbx`:

```nginx
location /app/simplificaja_api/ {
    allow 173.212.252.121;    # o hub
    deny  all;
    ...
    location ~ \.php$ {
        allow 173.212.252.121;
        deny  all;
```

Sem isso a API fica **aberta na internet** — e ela cria ramal e lê senha SIP.
Confira com `curl` de fora: tem de dar 403.

### 3.4 O gancho que registra a ligação no hub

`/usr/share/freeswitch/scripts/chatwoot_hangup.lua`, mais o registro dele no
plano de discagem (`scripts/registrar-gancho.sh` do repo).

Sem isso o telefone funciona e **nenhuma ligação aparece no painel**.

### 3.5 Certificado do WSS

`/etc/freeswitch/tls/*.pem`, porta 7443. O softphone de navegador não conecta sem
ele. Regerar com `scripts/renovar-wss.sh`.

### 3.6 Fuso — em TRÊS camadas

Trocar uma não troca as outras, e cada uma quebra uma coisa diferente:

| Camada | Onde | O que quebra se errar |
|---|---|---|
| sistema | `timedatectl set-timezone America/Sao_Paulo` | faixa de datas do horário (`date-time`) e os logs |
| banco | `timezone` em `postgresql.conf`, depois `systemctl reload postgresql` | horários de ligação no portal saem errados |
| canal | `set timezone=` no plano de discagem | `wday` e `time-of-day` do horário |

A do canal vem no dump (está no XML gerado). As duas primeiras são manuais.

### 3.7 Firewall

```
UDP 16384:32768   RTP -- sem isso a ligação completa e não tem áudio
UDP/TCP 5060      SIP
UDP/TCP 5080      SIP externo
TCP 7443          WSS do softphone de navegador
TCP 80, 443       portal
TCP 22            SSH
```

### 3.8 Configuração do FusionPBX que difere do padrão

```bash
su - postgres -c 'psql -d fusionpbx -f scripts/aplicar-configuracao.sql'
```

Idempotente. Inclui o `auth-calls = false` no perfil externo — sem ele o PABX
responde `407` a **toda** chamada que a operadora entrega.

### 3.9 Módulos

Este servidor tem ~15 módulos listados em `modules.conf.xml` cujos `.so` não
existem, porque o instalador escolheu não compilá-los. É barulho no boot e não
quebra nada, mas vale decidir: `mod_say_pt` (falar números em português) e
`mod_spandsp` (DTMF *inband* e fax) são os dois que algum dia vão fazer falta.

---

## Fase 4 · Virar o DNS

- [ ] `pabx.simplificaja.com.br` → IP novo
- [ ] `*.pabx.simplificaja.com.br` → IP novo (cada cliente é um subdomínio)

**Nunca atrás do proxy do Cloudflare.** Nuvem cinza, DNS apenas. O proxy deles é
HTTP; SIP e RTP não passam, e o sintoma é "o telefone não registra" sem nenhum
erro útil.

**Do lado do hub não há nada a fazer:** ele acha o PABX por nome
(`FUSIONPBX_HOST=pabx.simplificaja.com.br`) e o WSS de cada cliente é
`wss://<cliente>.pabx.simplificaja.com.br:7443`. Se a chave da API veio no dump,
o hub nem percebe a troca.

---

## Fase 5 · Conferência de aceite

Em ordem, e **não** pule para a ligação antes do resto passar:

```bash
# 1. o fuso nas três camadas
timedatectl | grep 'Time zone'
su - postgres -c "psql -At -c 'SHOW timezone;'"

# 2. o IP que o FreeSWITCH anuncia -- tem de ser o PÚBLICO novo
fs_cli -x 'global_getvar external_rtp_ip'
fs_cli -x 'global_getvar external_sip_ip'

# 3. o tronco registrou
fs_cli -x 'sofia status gateway' | grep -E 'REGED|NOREG|FAIL'

# 4. a API responde ao hub, e recusa o resto
curl -s -o /dev/null -w '%{http_code}\n' https://pabx.simplificaja.com.br/app/simplificaja_api/index.php?r=dominio   # de fora: 403
# do hub, com a chave: 200

# 5. o gatilho do anúncio sobreviveu
su - postgres -c "psql -d fusionpbx -At -c \"SELECT tgname FROM pg_trigger WHERE tgname='simplificaja_fila_exige_anuncio';\""

# 6. latência até a operadora -- o motivo da migração
ping -c 10 177.11.50.217

# 7. nenhuma queda
systemctl show freeswitch -p NRestarts
dmesg -T | grep -c 'freeswitch.*segfault'
```

- [ ] O ramal registra num softphone
- [ ] Ligação entra: chama, toca o anúncio **do começo**, cai na fila, o softphone
      toca, atende, **áudio nos dois sentidos**, desliga e encerra nos dois lados
- [ ] Ligação de ramal para ramal
- [ ] A ficha do cliente no super admin abre e lista tudo
- [ ] A ligação aparece no painel (o gancho)

Para a verificação completa, seguir
[`docs/roteiro-de-validacao-pabx.md`](../../chatwoot/docs/roteiro-de-validacao-pabx.md)
do repo do hub, pulando a fase de criar o cliente.

---

## Fase 6 · Desligar o antigo

- [ ] Deixe o servidor antigo **ligado e intocado** por alguns dias. É o rollback.
- [ ] Antes de destruir: confira que não há registro chegando nele
      (`fs_cli -x 'sofia status profile internal reg'`)
- [ ] Peça à operadora para remover o IP antigo da liberação
- [ ] Guarde o último dump fora dos dois servidores

### Rollback

Virar o DNS de volta. É por isso que o TTL fica baixo e o servidor antigo fica de
pé: enquanto a operadora mantém os dois IPs liberados, voltar custa cinco
minutos.

---

## Específico da AWS, para quando for

**A armadilha, e ela é exatamente a que nos custou uma madrugada:** na AWS o IP
público **não fica na interface de rede** da instância. A interface tem só o IP
privado, e o público é NAT do provedor.

O FreeSWITCH detecta `external_rtp_ip` sozinho olhando a interface. Na Contabo
isso funciona porque o público está lá. Na AWS ele vai detectar o **privado**, e
anunciar no SDP um endereço que não existe para o mundo. O sintoma é o pior que
existe: a ligação completa, o atendente atende, e **não tem áudio** — sem erro em
log nenhum.

Então, na AWS, antes de qualquer teste:

```xml
<!-- /etc/freeswitch/vars.xml -->
<X-PRE-PROCESS cmd="set" data="external_rtp_ip=<IP elástico>"/>
<X-PRE-PROCESS cmd="set" data="external_sip_ip=<IP elástico>"/>
```

E conferir com `fs_cli -x 'global_getvar external_rtp_ip'`.

Mais:

- [ ] **IP elástico**, não o público efêmero: a operadora libera por IP, e IP que
      muda no reboot derruba as entrantes
- [ ] Região **sa-east-1** (São Paulo), pelo motivo da Fase 0
- [ ] *Security group* com **UDP 16384-32768** aberto. Na AWS cada faixa é uma
      regra, e esquecer essa dá o mesmo defeito de "sem áudio"
- [ ] Desligar *source/destination check* não é necessário, mas confira se há NAT
      extra no caminho
- [ ] Cobrança de tráfego de saída: RTP é ~90 KB/s por ligação em cada sentido.
      Vinte ligações simultâneas por oito horas por dia saem bem mais caro que a
      Contabo — fazer a conta antes

---

## Resumo em uma lista

Se precisar de um cartão de bolso:

1. Operadora libera o IP novo (prazo dela, peça primeiro)
2. TTL do DNS para 300 s, dois dias antes
3. Servidor novo na região certa, com IP fixo e UDP largo
4. FusionPBX instalado na **mesma versão**
5. `pg_restore` do dump
6. `rsync` das gravações
7. `scp` da nossa API + liberação no nginx
8. Gancho do hub + certificado do WSS
9. Fuso nas três camadas
10. `aplicar-configuracao.sql`
11. DNS (nuvem cinza)
12. Conferência de aceite, só então a ligação de teste
13. Antigo de pé por alguns dias
