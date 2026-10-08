# Pesquisa de avaliação no fim da ligação

> Spec. Decisões fechadas, não rascunho. Tudo que está aqui foi verificado na
> instalação de 07/10/2026 ou na fonte do FreeSWITCH 1.10.12 em
> `/usr/src/freeswitch-1.10.12`. Nenhuma API, coluna ou variável deste
> documento foi suposta.

## Objetivo

Quando o atendente desliga, em vez de a ligação cair, o cliente ouve uma
pergunta e digita uma nota de 1 a 5. A nota fica gravada no PABX, com o ramal
que atendeu, e aparece numa aba nova do portal do cliente — o acesso que ele já
usa no FusionPBX do tenant dele.

**O hub não é tocado.** Nem o `CallRegistrationService`, nem a tabela `calls`,
nem a decisão de 23/09/2026 de a ligação não abrir conversa. A integração com o
Chatwoot fica preparada (ver "Compatibilidade futura"), não construída.

## O primitivo, e por que ele resolve exatamente o pedido

O requisito é: *ao final da chamada, quando o atendente desliga, em vez de ir
para desligar, vai para a pesquisa, e depois sim desliga.* Isso é literalmente o
que `transfer_after_bridge` faz, e as exceções que preocupam já estão resolvidas
na fonte.

O plano de discagem da fila hoje (gerado por `api_fila.php`) termina assim:

```xml
<action application="set" data="hangup_after_bridge=true"/>
<action application="callcenter" data="9100@demo.pabx.simplificaja.com.br"/>
<action application="hangup" data=""/>
```

`mod_callcenter` faz o bridge com `switch_ivr_uuid_bridge`
(`mod_callcenter.c:1980`), que sai por `audio_bridge_on_exchange_media` em
`switch_ivr_bridge.c:969`. Lá:

```c
if (!switch_channel_test_flag(channel, CF_TRANSFER) && !switch_channel_test_flag(channel, CF_REDIRECT) &&
    !switch_channel_test_flag(channel, CF_XFER_ZOMBIE) && bd && !bd->clean_exit && state != CS_PARK &&
    state != CS_ROUTING && state == CS_EXCHANGE_MEDIA && !switch_channel_test_flag(channel, CF_INNER_BRIDGE)) {

    if (state < CS_HANGUP && switch_true(... PARK_AFTER_BRIDGE ...)) {
        switch_ivr_park_session(session);
    } else if (state < CS_HANGUP && (var = switch_channel_get_variable(channel, SWITCH_TRANSFER_AFTER_BRIDGE_VARIABLE))) {
        transfer_after_bridge(session, var);
```

Três consequências, todas do código e não de configuração nossa:

1. **Transferência não dispara a pesquisa.** Se o atendente transferir, o canal
   do cliente está com `CF_TRANSFER` (ou `CF_REDIRECT`, ou `CF_XFER_ZOMBIE`) e o
   bloco inteiro é pulado. O cliente segue para quem recebeu a transferência.
2. **A variável se apaga ao ser consumida.** Primeira linha de
   `transfer_after_bridge()` (`switch_ivr_bridge.c:958`):
   `switch_channel_set_variable(..., SWITCH_TRANSFER_AFTER_BRIDGE_VARIABLE, NULL)`.
   Dispara uma vez por ligação. Não existe laço possível.
3. **`hangup_after_bridge=true` continua valendo e não precisa mudar.** No
   caminho multi-thread (`switch_ivr_bridge.c:1929`) o ramo do
   `transfer_after_bridge` é um `else if` **antes** do ramo que honra o
   `hangup_after_bridge`. Quando a variável está setada, ela ganha; quando não
   está, o comportamento é bit a bit o de hoje.

### A sintaxe usa dois-pontos, não espaço

`transfer_after_bridge()` separa por `:` (`switch_ivr_bridge.c:961`):

```c
switch_separate_string(mydata, ':', argv, (sizeof(argv) / sizeof(argv[0])))
switch_ivr_session_transfer(session, argv[0], argv[1], argv[2]);
```

```
aplicação transfer                →  transfer 6000 XML demo.pabx...     (espaço)
variável transfer_after_bridge    →  6000:XML:demo.pabx...              (dois-pontos)
```

Com espaço, o log diz `No extension specified` e a ligação cai. Este é o erro
mais fácil de cometer neste spec.

### Quem atendeu

`mod_callcenter` grava no canal **do cliente** (`member_channel`):

| Variável | Onde na fonte | Conteúdo |
|---|---|---|
| `cc_agent` | `mod_callcenter.c:2031` | nome do agente, ex. `1001@demo.pabx...` |
| `cc_agent_uuid` | `mod_callcenter.c:1974` | uuid do agente |
| `cc_agent_bridged` | `mod_callcenter.c:2019` | `true` quando realmente conversaram |
| `cc_queue` | `mod_callcenter.c:3083` | nome da fila |

São variáveis de canal, e sobrevivem ao `switch_ivr_session_transfer` para a
URA de pesquisa. É daí que sai a nota por atendente, que é o que torna a tela
útil para o gestor.

## Escopo

**Entra:**

- URA de pesquisa como destino novo, por domínio, na faixa 6000–6999
- Campo opcional `pesquisa` na fila, que adiciona o `set` no plano
- Tabela `v_simplificaja_pesquisas`
- Script Lua que grava a nota
- Aba "Avaliações" no `simplificaja_portal`
- Rotas na `simplificaja_api` para criar, listar e remover a pesquisa
- `scripts/configurar-pesquisa.sql` e a linha correspondente no `instalar.sh`

**Não entra:**

- Qualquer mudança no repositório do hub
- A pesquisa em destino que não seja fila (ramal direto, anúncio, menu). A fila
  é onde o atendimento humano acontece e é o plano que nós geramos. Ramal direto
  usa o `local_extension` do FusionPBX, que não é nosso — fica para depois, se
  pedirem.
- Comentário gravado em áudio depois da nota
- Envio da nota para o Chatwoot

## Arquitetura

### Fluxo

```
cliente liga  →  entrada-75681  →  anúncio 8000  →  fila 9100
                                                        ↓
                                              atendente atende, conversam
                                                        ↓
                                            atendente desliga (fim do bridge)
                                                        ↓
                                   CF_TRANSFER ausente → transfer_after_bridge
                                                        ↓
                                              URA de pesquisa 6000
                                          "digite de 1 a 5"  (3 tentativas)
                                                        ↓
                                     lua simplificaja_pesquisa.lua  →  grava
                                                        ↓
                                                     hangup
```

Se o atendente transferir, o fluxo nunca chega na pesquisa. Se ninguém atender,
não houve bridge e também não há pesquisa — correto, não há o que avaliar. Se o
cliente desligar sem digitar, não existe linha gravada; a tela mostra isso como
taxa de resposta.

### Camadas e arquivos

| Arquivo | Ação | Responsabilidade |
|---|---|---|
| `app/simplificaja_api/resources/classes/api_pesquisa.php` | criar | gera o plano da URA, escolhe número livre, lista e remove |
| `app/simplificaja_api/resources/classes/api_fila.php` | alterar | aceita `pesquisa` e emite o `set transfer_after_bridge` |
| `app/simplificaja_api/index.php` | alterar | 3 rotas novas |
| `scripts/simplificaja_pesquisa.lua` | criar | grava a nota no banco |
| `scripts/configurar-pesquisa.sql` | criar | cria a tabela e o item de menu |
| `scripts/instalar.sh` | alterar | aplica o SQL novo e copia o Lua |
| `app/simplificaja_portal/resources/classes/portal_data.php` | alterar | consultas da aba |
| `app/simplificaja_portal/index.php` | alterar | a view `ratings` |

## Modelo de dados

```sql
create table if not exists v_simplificaja_pesquisas (
    pesquisa_uuid  uuid        primary key,
    domain_uuid    uuid        not null,
    call_uuid      uuid        not null,
    pesquisa       text        not null,
    nota           smallint    not null check (nota between 1 and 5),
    ramal          text,
    extension_uuid uuid,
    fila           text,
    telefone       text,
    criado_em      timestamptz not null default now(),
    unique (call_uuid, pesquisa)
);

create index if not exists idx_simplificaja_pesquisas_dominio
    on v_simplificaja_pesquisas (domain_uuid, criado_em desc);
```

**O prefixo `v_` não é estética.** A varredura de remoção de cliente em
`api_dominio.php:217` casa exatamente:

```sql
and t.table_name like 'v\_%'
and exists (select 1 from information_schema.columns c
            where ... and c.column_name = 'domain_uuid')
```

Com `v_simplificaja_pesquisas` e a coluna `domain_uuid`, apagar um cliente
limpa as avaliações dele sem nenhum código novo. Sem o prefixo, a varredura não
enxerga a tabela e as notas ficam órfãs — é o mesmo defeito que levou a
varredura de 8 para 85 tabelas. **Renomear esta tabela sem manter o `v_` e o
`domain_uuid` reintroduz aquele bug.**

`nota` é `smallint` com `check between 1 and 5` porque 1–5 é a escala que o
Chatwoot valida (`CsatSurveyResponse` tem `inclusion: { in: [1,2,3,4,5] }`).
Gravar 0–9 aqui tornaria a integração futura uma conversão com perda.

`pesquisa` guarda o número da pesquisa que gerou a nota, e a unicidade é
`(call_uuid, pesquisa)`, não `call_uuid` sozinho. **Uma ligação pode gerar mais
de uma nota**, porque uma fila pode pedir duas perguntas em sequência -- "nota
do atendente" e depois "nota da empresa". Com `call_uuid` único, a segunda nota
seria descartada em silêncio pelo `on conflict do nothing`.

A unicidade continua sendo a segunda rede contra reentrada: a mesma pesquisa não
grava duas vezes na mesma ligação.

## A tabela não nasce pelo FusionPBX

A declaração nativa seria `$apps[$x]['db'][...]` no `app_config.php`, que faria
a tabela aparecer em *Advanced → Upgrade → Schema*. **Não usamos isso**, por
duas razões: o `app_config.php` da nossa API hoje só declara metadados, e a
convenção deste repositório para esquema e configuração é script SQL
idempotente (`aplicar-configuracao.sql`, `configurar-portal.sql`, `marca.sql`).
Entregar a tabela ao schema tool do FusionPBX passaria a ele a gestão de uma
tabela que é nossa.

Consequência obrigatória: `scripts/instalar.sh` lista cada SQL por nome
(linhas 75 e 106). **O novo script tem de ser adicionado lá**, ou um servidor
novo — a migração para a VPS brasileira, por exemplo — sobe sem a tabela e a
pesquisa falha em silêncio. Isso é exatamente a classe de problema que o
`docs/migrar-servidor.md` existe para evitar.

## A URA de pesquisa

Classe `api_pesquisa.php`, no mesmo molde de `api_anuncio.php`.

```php
const APP_UUID       = '1e9f0b45-d844-40ab-b900-aec2f465d408';
const FAIXA_INICIO   = 6000;
const FAIXA_FIM      = 6999;
const ORDEM_NO_PLANO = '226';
const TENTATIVAS     = 3;
const ESPERA_RESPOSTA = 5000;
const ESPERA_ENTRE_DIGITOS = 3000;
const NOTA_MINIMA    = 1;
const NOTA_MAXIMA    = 5;
```

A faixa 6000–6999 foi escolhida por estar **totalmente livre** na instalação de
07/10/2026. Números em uso hoje, em todos os domínios: `0`, `1001`, `1002`,
`2001`, `2002`, `5900`, `8000` (anúncio), `9100` (fila), `75681` (DID). As
faixas já tomadas pelas nossas classes são 7000–7999 (horário, `api_horario.php`)
e 8000–8999 (anúncio, `api_anuncio.php`).

`ORDEM_NO_PLANO = '226'` fica depois do horário (225) e antes da fila (230). A
pesquisa só é alcançada por transferência explícita, igual anúncio e horário.

XML gerado:

```xml
<extension name="pesquisa" continue="" uuid="...">
  <condition field="destination_number" expression="^6000$">
    <action application="answer" data=""/>
    <action application="set" data="playback_terminators=none"/>
    <action application="playback" data="silence_stream://1500"/>
    <action application="play_and_get_digits" data="1 1 3 5000 # /var/lib/freeswitch/recordings/demo.pabx.simplificaja.com.br/pesquisa.wav /var/lib/freeswitch/recordings/demo.pabx.simplificaja.com.br/pesquisa.wav nota ^[1-5]$ 3000"/>
    <action application="lua" data="simplificaja_pesquisa.lua"/>
    <action application="hangup" data=""/>
  </condition>
</extension>
```

O `silence_stream://1500` é o mesmo `api_anuncio::ESPERA_ANTES_DO_AUDIO` que
resolveu a saudação cortada: sem ele o início do áudio se perde. Usar a
constante, não repetir o número.

O áudio da pergunta é uma gravação do domínio, enviada pela aba Áudios do painel
(`POST gravacoes`, já existente). O mesmo arquivo serve de áudio de erro na
repetição — não inventar um segundo arquivo obrigatório.

### A ordem dos argumentos, conferida na fonte

`mod_dptools.c:2841` separa os argumentos por **espaço**, e o mapeamento
(`mod_dptools.c:2847-2886`) é:

| Posição | Significado | Valor nosso |
|---|---|---|
| 0 | `min_digits` | 1 |
| 1 | `max_digits` | 1 |
| 2 | `max_tries` | `TENTATIVAS` (3) |
| 3 | `timeout` | `ESPERA_RESPOSTA` (5000) |
| 4 | `valid_terminators` | `#` |
| 5 | `prompt_audio_file` | o áudio da pergunta |
| 6 | `bad_input_audio_file` | o mesmo áudio |
| 7 | `var_name` | `nota` |
| 8 | `digits_regex` | `^[1-5]$`, montado de `NOTA_MINIMA` e `NOTA_MAXIMA` |
| 9 | `digit_timeout` | `ESPERA_ENTRE_DIGITOS` (3000) |

**Dependência invisível:** como a separação é por espaço, um nome de áudio com
espaço quebraria o plano de discagem. Isso hoje é impossível porque
`api_gravacao.php:89` sanea o nome com
`preg_replace('/[^A-Za-z0-9_-]/', '', ...)`. Quem relaxar aquele regex no futuro
quebra a pesquisa sem tocar em nenhum arquivo dela — é o tipo de acoplamento
que só aparece em produção.

## A fila aponta para a pesquisa

`api_fila.php` passa a aceitar um campo opcional `pesquisa` com o número da
URA. Quando presente, e **somente** quando presente, emite uma linha a mais:

```xml
<action application="set" data="hangup_after_bridge=true"/>
<action application="set" data="transfer_after_bridge=6000:XML:demo.pabx.simplificaja.com.br"/>
<action application="callcenter" data="9100@demo.pabx.simplificaja.com.br"/>
<action application="hangup" data=""/>
```

Fila sem o campo gera o XML idêntico ao de hoje, byte a byte. É assim que
clientes existentes ficam inalterados.

`editar_fila` já apaga e recria a fila (o `mod_callcenter` guarda estado em
memória que atualizar o banco não alcança), então o campo entra no caminho que
já existe, sem mecanismo novo.

### Convivência com o estouro

A fila já pode ter um destino de estouro (`$dados['estouro']`,
`api_fila.php:387`), que emite um `transfer` **depois** do `callcenter`. Os dois
não colidem, porque atendem caminhos diferentes:

- **Ninguém atendeu:** não houve bridge, então
  `audio_bridge_on_exchange_media` nunca roda e o `transfer_after_bridge` não
  dispara. O `callcenter` devolve o canal e a linha do estouro executa. É o
  comportamento de hoje, intacto.
- **Atenderam e o atendente desligou:** o `transfer_after_bridge` leva o canal
  para a pesquisa antes de a execução voltar ao plano, então a linha do estouro
  não é alcançada. Correto — estouro é para quem não foi atendido.

Fila com estouro **e** pesquisa é portanto uma combinação válida, e a validação
de aceite cobre as duas pernas.

## Mais de uma pesquisa, e em sequência

Decidido em 08/10/2026. Um cliente pode ter várias pesquisas, cada fila escolhe
a sua, e uma fila pode pedir duas perguntas em sequência:

```
fila 9100 (Atendimento)  →  6000 "nota do atendente"
                                 ↓  proxima
                            6001 "nota da empresa"
                                 ↓
                              desliga

fila 9200 (Suporte)      →  6002 "resolveu seu problema?"
fila 9300 (Financeiro)   →  sem pesquisa
```

### O encadeamento não pode usar `transfer_after_bridge`

A variável se apaga ao ser consumida (`switch_ivr_bridge.c:958`), então ela leva
o cliente para a **primeira** pesquisa e só. A sequência é a própria pesquisa
transferindo para a seguinte -- é o mesmo mecanismo pelo qual o anúncio já
encadeia `anúncio → anúncio → fila`.

Cada pesquisa ganha um campo opcional `proxima`. Sem ele, a pesquisa termina em
`hangup`, como hoje. Com ele, o `hangup` dá lugar a um `transfer`:

```xml
<action application="play_and_get_digits" data="... nota ^[1-5]$ 3000"/>
<action application="lua" data="simplificaja_pesquisa.lua"/>
<action application="transfer" data="6001 XML demo.pabx.simplificaja.com.br"/>
```

A ordem importa: o `lua` roda **antes** do `transfer`, então a nota da pergunta
atual já está gravada quando a próxima pergunta sobrescreve a variável `nota`.

`cc_agent`, `cc_queue` e `cc_agent_bridged` são variáveis de canal e sobrevivem
ao transfer, então toda nota da sequência sai com o mesmo atendente e a mesma
fila -- que é o que faz as duas perguntas serem comparáveis.

### Qual pergunta cada nota responde

O plano de cada pesquisa seta `pesquisa_atual` com o próprio número, antes de
coletar:

```xml
<action application="set" data="pesquisa_atual=6000"/>
```

O script Lua grava esse valor na coluna `pesquisa`. Sem isso as duas notas da
mesma ligação ficariam indistinguíveis, e a média por pergunta -- que é o ponto
de ter duas -- seria impossível.

### Laço é recusado na gravação, não tratado na ligação

`6000 → 6001 → 6000` giraria para sempre, com o cliente preso. Em vez de guarda
no plano de discagem, a API **recusa** o `proxima` que fecha ciclo: ao salvar,
caminha a corrente a partir do destino e devolve 409 se reencontrar a pesquisa
que está sendo editada.

É mais barato e mais honesto: o erro aparece para quem configurou, na hora, em
vez de aparecer para quem ligou.

### Remover respeita quem aponta

A recusa de remoção passa a cobrir os dois sentidos: fila que aponta para a
pesquisa (pelo `transfer_after_bridge`) **e** pesquisa que aponta para ela pelo
`proxima`. Remover sem isso deixaria uma sequência transferindo para um número
que não existe.

### O agradecimento é campo, não outra pergunta

Corrigido em 08/10/2026, depois de uma ligação real. O "obrigado" tinha sido
montado como uma segunda pesquisa, e pesquisa pergunta: tocou o áudio, esperou
nota de 1 a 5 que ninguém ia digitar, repetiu, e só desligou quando quem ligou
desistiu. Medido no log: o áudio tocou quatro vezes em 41 segundos.

Cada pesquisa ganha um campo opcional `agradecimento` -- um áudio tocado depois
de a nota ser gravada, antes do `transfer` ou do `hangup`:

```xml
<action application="lua" data="simplificaja_pesquisa.lua"/>
<action application="set" data="playback_terminators=none"/>
<action application="playback" data=".../obrigado.wav"/>
<action application="hangup" data=""/>
```

`playback_terminators=none` entra **só aqui**, depois da coleta. Antes dela
atrapalharia o dígito que quem ligou aperta durante a pergunta.

O agradecimento toca mesmo quando ninguém respondeu, porque a execução segue o
plano de todo jeito. É aceitável -- um "obrigado pelo contato" não ofende quem
não avaliou --, mas é comportamento escolhido, não acidente.

### Os tempos saem de medição, não de escolha

A pergunta gravada no teste tinha 20 segundos, e o `play_and_get_digits` repete
o prompt INTEIRO a cada tentativa. Com 5s de espera e 3 tentativas, quem ligou
ouvia a mesma frase três vezes, recomeçando 5 segundos depois de ela acabar --
o que soa como repetir na hora.

| Constante | Antes | Agora |
|---|---|---|
| `TENTATIVAS` | 3 | 2 |
| `ESPERA_RESPOSTA` | 5000 | 10000 |

Nenhuma constante compensa um áudio de 20 segundos: o jeito real de encurtar o
ciclo é gravar a pergunta curta.

## O script que grava

`scripts/simplificaja_pesquisa.lua`, instalado em
`/usr/share/freeswitch/scripts/` ao lado do `chatwoot_hangup.lua` — o caminho do
`require` depende disso.

O padrão de banco é o nativo do FusionPBX, o mesmo do `call_flow.lua:39-49`:

```lua
local Database = require "resources.functions.database"
local dbh = Database.new('system')
dbh:query("UPDATE v_call_flows SET ... WHERE ...", { ... })
```

Verificado em execução na instalação em 07/10/2026: um `luarun` que abriu o
banco por esse caminho e leu `v_domains` devolveu os 4 domínios. **Não usar
`luasocket`** — esta instalação não tem, e foi por isso que o
`chatwoot_hangup.lua` usa `mod_curl`.

O script lê do canal e grava uma linha:

| Coluna | Origem |
|---|---|
| `call_uuid` | `uuid` |
| `domain_uuid` | `domain_uuid` (o plano de entrada já seta) |
| `nota` | `nota`, a variável do `play_and_get_digits` |
| `ramal` | `cc_agent`, só a parte antes do `@` |
| `extension_uuid` | consulta em `v_extensions` pelo número do ramal |
| `fila` | `cc_queue` |
| `telefone` | `caller_id_number` |

`extension_uuid` não é redundante com `ramal`. O portal filtra o que o perfil
`user` pode ver com `extension_scope_sql('<coluna>', ...)`
(`portal_data.php:31`), e esse helper compara `extension_uuid` — é assim que
todas as consultas do portal já funcionam. Sem a coluna, a aba Avaliações
precisaria de um mecanismo de escopo próprio, paralelo ao que existe, e seria o
único lugar do portal com regra de visibilidade diferente. Fica nula quando o
ramal não resolve (atendente apagado depois da ligação), e nesse caso a nota
aparece só para quem tem visão de domínio.

Regras:

- `nota` vazia ou fora de 1–5 → não grava nada e sai. Cliente que desligou sem
  responder não vira linha.
- `cc_agent_bridged` diferente de `true` → não grava. Não houve conversa.
- Tudo em `pcall`, e qualquer falha vai para o log com prefixo
  `[pesquisa]`. Pelo mesmo motivo do gancho de desligamento: falha ao gravar uma
  nota não pode afetar a ligação.

## A aba no portal

`app/simplificaja_portal/index.php` hoje tem a lista branca em `index.php:21`:

```php
if (!in_array($view, ['dashboard', 'calls', 'extensions'], true)) {
```

Passa a incluir `'ratings'`, com rótulo "Avaliações", seguindo o padrão já
usado: chave em inglês, rótulo em português. O ícone acompanha os três atuais
(`fa-house`, `fa-phone-volume`, `fa-headset`) — `fa-star` encaixa.

A permissão é a mesma que o app já exige, `xml_cdr_view` (`index.php:9`), e
não se cria permissão nova.

`portal_data.php` ganha os métodos, no estilo dos que já existem
(`call_count`, `call_volume`, `call_history`), todos parametrizados e filtrados
por `$this->domain_uuid`:

- `rating_summary(DateTimeImmutable $since): array` — média, total de respostas
  e distribuição por nota
- `rating_response_rate(DateTimeImmutable $since): float` — respostas sobre
  ligações atendidas, reusando `call_count`
- `rating_by_extension(DateTimeImmutable $since): array` — média e total por
  ramal, que é a tela que o gestor quer
- `rating_history(int $days, string $search): array` — a lista, com telefone,
  data, nota e ramal

Escopo de ramal: as notas são do domínio, e o portal já distingue quem vê o
domínio inteiro de quem vê só os próprios ramais (`has_domain_view`,
`extension_scope_sql`). A aba segue a mesma regra que a de Ligações: perfil
`user` sem visão de domínio vê as notas dos ramais dele.

O app é somente leitura e não executa ação sobre chamada ou ramal. A aba nova
mantém isso.

## Multi-tenant

Cada camada carrega o isolamento que já existe, e nenhuma depende de convenção:

| Camada | O que isola |
|---|---|
| Plano de discagem | `domain_uuid` na linha de `v_dialplans`; ligar no `demo` não alcança outro domínio |
| Tabela | `domain_uuid` não nulo, e a consulta do portal sempre filtra por ele |
| Portal | `$_SESSION['domain_uuid']`, verificado em `index.php:14` antes de qualquer consulta |
| API | o `domain_uuid` sai da chave enviada, não do host (`client.rb:116-120`) |
| Remoção | a varredura de `api_dominio.php` alcança a tabela pelo prefixo `v_` |

## Compatibilidade futura com o Chatwoot

Nada é construído agora. O que se decide agora, porque depois sai caro:

**Gravar `call_uuid`.** O hub guarda `provider_call_id = call_uuid` em toda
ligação (`call_registration_service.rb:85`), e o gancho envia
`variable_uuid`/`Unique-ID` do canal do cliente
(`chatwoot_hangup.lua:60`). O canal do cliente mantém o mesmo uuid através do
`switch_ivr_session_transfer`, então o uuid que a pesquisa grava é o mesmo que o
hub recebe.

Com isso, o dia do Chatwoot é: uma rota `GET pesquisas` na API e um join por uma
chave. Sem migração, sem backfill, sem ambiguidade. Sem o uuid, seria casar nota
com ligação por telefone e horário, o que quebra na primeira pessoa que ligar
duas vezes no mesmo dia.

Ordem dos eventos, para registro: a pesquisa grava **antes** do gancho de
desligamento disparar. Não há corrida, porque o join é feito na leitura.

## O que não muda

Dito explicitamente, porque é o ponto da pergunta que originou este spec:

- Fila sem o campo `pesquisa` gera XML idêntico ao atual
- `hangup_after_bridge=true` permanece
- Nenhum arquivo do repositório do hub é alterado
- O `chatwoot_hangup.lua` não é alterado
- Os planos globais (54 entradas, que valem para todos os clientes) não são
  alterados. A pesquisa é **por domínio**, mesmo custando um passo a mais ao
  criar cliente — um erro num plano global atingiria todos de uma vez.
- Nenhuma permissão nova no FusionPBX

## Riscos

| Risco | Tratamento |
|---|---|
| Sintaxe com espaço em vez de `:` | a validação de aceite confere o XML gerado linha a linha antes de ligar |
| Pesquisa disparar em transferência | impedido por `CF_TRANSFER` na fonte; a validação inclui uma transferência deliberada |
| Nota sem atendimento real | `cc_agent_bridged != true` não grava |
| Tabela órfã ao apagar cliente | prefixo `v_` + `domain_uuid`; a validação apaga um cliente e confere |
| Servidor novo sem a tabela | o SQL entra no `instalar.sh`; a validação inclui instalação limpa |
| Falha ao gravar derrubar a ligação | `pcall` no Lua, como no gancho |

## Validação de aceite

Tudo no `demo`, que é um domínio isolado. Em ordem:

1. Criar a pesquisa pela API; conferir que o número saiu da faixa 6000–6999
2. Ler o XML gerado no banco e conferir o `transfer_after_bridge=NNNN:XML:dominio`
   — com dois-pontos
3. Ligar, atender, desligar **pelo atendente**: a pesquisa toca; digitar 4;
   conferir a linha no banco com `nota=4`, `ramal`, `fila`, `call_uuid`
4. Ligar, atender e **transferir para outro ramal**: a pesquisa **não** toca, e
   nenhuma linha é gravada
5. Ligar e desligar antes de atender: nenhuma pesquisa, nenhuma linha
6. Ligar, atender, desligar e **desligar sem digitar**: nenhuma linha
7. Digitar `9` três vezes: nenhuma linha, ligação encerra sem erro no log
8. Abrir o portal como usuário `user` do `demo`: a aba Avaliações mostra a nota
   do passo 3, com o ramal
9. Conferir que uma fila **sem** o campo `pesquisa` gera XML idêntico ao de hoje
   (diff contra o XML salvo antes da mudança)
10. Apagar um cliente de teste e conferir zero linhas órfãs em
    `v_simplificaja_pesquisas`
11. Rodar `instalar.sh` num servidor limpo e conferir que a tabela existe

O passo 4 é o que valida o requisito que originou este spec, e o 9 é o que
valida que nada que já funciona mudou.

## Decisões fechadas

| Decisão | Valor | Por quê |
|---|---|---|
| Escala | 1 a 5 | é o que o `CsatSurveyResponse` do Chatwoot valida |
| Faixa | 6000–6999 | livre na instalação; 7000+ é horário, 8000+ é anúncio |
| Ordem no plano | 226 | depois do horário (225), antes da fila (230) |
| Tentativas | 3 | mesmo áudio na repetição |
| Onde a nota mora | PABX | decisão do Isaac em 07/10/2026; Chatwoot "ainda não" |
| Onde a pesquisa se liga | na fila | é onde o atendimento humano acontece e é plano nosso |
| Tabela | `v_simplificaja_pesquisas` | o `v_` faz a remoção de cliente limpar sozinha |
| Acesso a banco no Lua | `Database.new('system')` | nativo do FusionPBX, verificado em execução |
