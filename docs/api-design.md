# App `simplificaja_api` — desenho

App do FusionPBX que expõe ao painel do SimplificaJá as operações de telefonia
que hoje só existem nas telas dele. Desenhado em 10/09/2026, contra o FusionPBX
`5.5` no commit `087fc2b98`.

---

## O problema que ele resolve

Hoje, criar um cliente com telefone exige abrir o painel do FusionPBX e fazer à
mão: domínio, ramais, fila, URA, destinos e gateway por número. É lento, e pior,
é onde nascem os erros silenciosos descritos em `armadilhas.md`.

O app existe para que o painel do SimplificaJá faça isso — e para que a tela de
verificação consiga conferir o que existe do outro lado.

---

## A regra que dita tudo

**Nunca escrever no Postgres do FusionPBX por fora.** Não é preferência de
estilo. O FreeSWITCH lê a coluna `dialplan_xml`, e ela é preenchida pelo código
do FusionPBX ao salvar. Linha inserida por SQL existe no banco, aparece na tela
e é invisível para o FreeSWITCH — foi assim que três rotas nasceram mortas na
validação e levaram horas para serem diagnosticadas.

Por isso o app roda **dentro** do FusionPBX e usa as classes dele.

### O padrão de escrita

Descoberto lendo `app/dialplans/resources/classes/dialplan.php:425-446`, que é
como o próprio FusionPBX popula o XML na criação de um domínio:

```php
// 1. permissão temporária: o app escreve sem sessão de usuário
$p = permissions::new();
$p->add('dialplan_add', 'temp');
$p->add('dialplan_detail_add', 'temp');

// 2. grava pelo método deles, não por SQL
$database = database::new(['db' => $db]);
$database->save($array);

// 3. devolve a permissão
$p->delete('dialplan_add', 'temp');
$p->delete('dialplan_detail_add', 'temp');

// 4. gera o XML a partir das linhas gravadas
$dialplan = new dialplan();
$dialplan->source      = 'details';
$dialplan->destination = 'database';
$dialplan->context     = $domain_name;
$dialplan->is_empty    = 'dialplan_xml';
$dialplan->xml();

// 5. o cache serve XML velho até ser limpo
cache::delete('dialplan:' . $context);
```

Toda escrita do app segue esses cinco passos. **Se algum endpoint pular o passo
4, ele cria configuração morta** — é a falha característica desta integração, e
a que a suíte de verificação precisa cobrir primeiro.

### Onde as classes não bastam

O `dialplan_xml` de **destinos** é gerado dentro de `destination_edit.php`, um
arquivo de página, não numa classe. Não dá para chamar de fora.

Duas saídas, e a escolha importa:

- **Reproduzir a lógica no app** — funciona hoje, e diverge silenciosamente na
  primeira atualização do upstream. É como se cria o próximo bug de madrugada.
- **Montar as linhas e chamar `dialplan->xml()`** com `source=details`, que é
  código de classe e portanto estável.

Adotar a segunda. Onde o formato exigido pelo destino não sair disso, o endpoint
**falha explicitamente** em vez de gravar pela metade — configuração pela metade
é justamente o que não se pode enxergar.

---

## Autenticação

O FusionPBX não traz app de API; a autenticação é nossa.

- **Uma chave por domínio**, guardada como `default_setting` do domínio. Chave
  única faria um Chatwoot comprometido alcançar os ramais de todos os clientes.
- Enviada em `X-Api-Key`, comparada com `hash_equals` (comparação de string
  vaza tempo).
- **Toda operação é escopada pelo `domain_uuid` da chave**, nunca por um
  `domain_uuid` recebido no corpo. É o único ponto onde um vazamento entre
  clientes poderia entrar, então não existe caminho em que o chamador escolha o
  domínio.
- Restringir por IP no nginx: só o SimplificaJá fala com este app.

---

## Endpoints

Superfície mínima: construir para o painel que existe, não para o FusionPBX
inteiro. Quando um cliente pedir algo que não está aqui, decide-se se vira
botão.

### Leitura — o que a verificação consome

| Método | Caminho | Devolve |
|---|---|---|
| `GET` | `/dominio` | existe, ativo, contagens |
| `GET` | `/ramais` | ramais, com quem está registrado agora |
| `GET` | `/destinos` | número, fluxo, **e se o XML está gerado** |
| `GET` | `/troncos` | gateways e situação de registro |
| `GET` | `/saude` | as checagens de `armadilhas.md`, avaliadas |

O `/saude` é o que dá valor à tela de verificação: ele responde "esta rota tem
`dialplan_xml`?", "este destino tem linha em `v_destinations`?", "o
`auth-calls` do perfil externo está `false`?" — as perguntas cuja resposta
errada não aparece em lugar nenhum.

### Escrita

| Método | Caminho | Faz |
|---|---|---|
| `POST` | `/dominio` | cria o domínio do cliente com os padrões |
| `POST` | `/ramais` | cria ramal, devolve a senha SIP |
| `DELETE` | `/ramais/{numero}` | remove |
| `POST` | `/destinos` | número → fluxo, pelo caminho do app Destinations |
| `POST` | `/troncos` | gateway com usuário, senha e host do número |
| `POST` | `/grupos` | grupo de toque ou fila |

### Áudio e fluxos

| Método | Caminho | Faz |
|---|---|---|
| `POST` | `/gravacoes` | sobe um WAV ou MP3, converte e publica |
| `GET` | `/gravacoes` | lista as disponíveis no domínio |
| `DELETE` | `/gravacoes/{nome}` | remove |
| `POST` | `/uras` | cria a URA com saudação e opções |
| `PUT` | `/uras/{ramal}` | muda saudação ou opções |
| `POST` | `/fluxos` | encadeia número → URA → fila/grupo |

**Estavam fora do escopo na primeira versão desta spec, por engano.** A exclusão
veio da spec do telefone, onde o argumento era sobre o *cliente* se autoatender
— e ali continua valendo. Mas este app serve o **painel de administração**, cujo
propósito é justamente eliminar a configuração manual de cliente novo. Deixar
URA e gravações de fora derrota o propósito.

O gerenciamento de áudio aqui é mínimo de propósito: **subir, converter, listar,
apagar**. Sem gravar pelo navegador, sem edição. O `sox` já está no servidor e
faz a conversão para 8 kHz mono 16 bits, que é o formato que o FreeSWITCH toca
sem reamostrar.

Detalhe que não é óbvio: o app de gravações do FusionPBX grava o arquivo em
disco **e** guarda o conteúdo em `recording_base64`
(`recordings/recording_edit.php:269`). O base64 é o que a tela usa para tocar o
áudio. Salvar só o arquivo produz uma gravação que funciona na chamada e não
toca no painel — mais uma falha silenciosa a evitar.

---

## Etapas

De parte em parte, na ordem em que cada uma elimina trabalho manual:

**Etapa 1 — o cliente atende.** `/dominio`, `/ramais`, `/troncos`, `/destinos`
apontando direto para um grupo, e `/saude`. Ao fim dela, um cliente novo com
telefone simples é criado pelo nosso painel, sem abrir o FusionPBX.

A verificação entra já na primeira etapa apesar de não criar nada: é ela que
transforma "criei e parece certo" em "criei e funciona", e é barata por ser só
leitura.

**Etapa 2 — o atendimento é estruturado.** `/gravacoes`, `/uras` e `/fluxos`.
Sai o telefone que toca em todo mundo, entra a URA com opções caindo em filas.

**Etapa 3 — o que sobrar.** Rotação de chave, remoção de cliente, o que a
operação mostrar que falta.

---

## Erros

Um PABX mal configurado falha em silêncio; a API não pode.

- **Estado impossível estoura**: domínio inexistente, chave sem domínio, classe
  que não gerou XML. Nada de seguir adiante com aviso.
- **Erro devolve o que fazer**, não só o que houve: `"destino criado mas o XML
  não foi gerado — rode /saude"` vale mais que `"erro interno"`.
- **Escrita é transacional onde o `database::save` permite.** Onde não permitir,
  o endpoint verifica o resultado e desfaz — melhor não criar do que criar pela
  metade.

---

## Como se testa

Contra o PABX real, que já tem tronco funcionando. Cada endpoint de escrita se
verifica pelo efeito no FreeSWITCH, não pelo retorno HTTP:

- criou ramal → aparece em `sofia status profile internal reg` ao registrar
- criou destino → `grep` no XML gerado em `/var/cache/fusionpbx/` mostra a
  extensão
- criou tronco → `sofia status gateway` mostra `REGED`

Testar por retorno HTTP reproduziria exatamente o erro da validação: tudo
respondendo certo, nada funcionando.

---

## Pendências

- Definir o formato dos padrões aplicados em `POST /dominio` (a "receita" de
  `2026-09-10-provisionamento-multi-tenant-design.md` no repositório do
  Chatwoot).
- Confirmar se `destinations` consegue ser criado por `dialplan->xml()` ou se
  precisa de outra saída.
- Rotação da chave por domínio.
