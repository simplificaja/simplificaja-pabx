# PABX do SimplificaJá

Tudo que difere de um FusionPBX recém-instalado. Existe para que trocar de VPS
ou reinstalar o servidor não custe a mesma investigação de novo.

**Não é um fork do FusionPBX.** É o conjunto de modificações, configurações e
scripts que fazem o FusionPBX servir o SimplificaJá.

## O que tem aqui

| Pasta | O quê |
|---|---|
| `scripts/chatwoot_hangup.lua` | gancho de desligamento que manda a chamada para o Chatwoot |
| `scripts/instalar.sh` | instala um FusionPBX já personalizado numa VM limpa |
| `scripts/gerar-audios.sh` | gera os áudios da URA em português |
| `scripts/aplicar-configuracao.sql` | as configurações que diferem do padrão |
| `docs/armadilhas.md` | o que parece certo na tela e não funciona |
| `docs/api-design.md` | desenho do app da API: padrão de escrita, endpoints, autenticação |
| `docs/plano-etapa-1.md` | plano de implementação da primeira etapa da API |
| `docs/exemplos/` | código que prova padrões antes de virarem plano |
| `app/simplificaja_api/` | app do FusionPBX com a API que o painel consome (a construir) |
| `patches/` | alterações no upstream, se um dia forem inevitáveis |

## Servidor atual

`109.123.250.200` — Ubuntu 24, FusionPBX com FreeSWITCH 1.10 compilado do
código. Domínio `pabx.simplificaja.com.br`.

**Versão do FusionPBX:** branch `5.5`, upstream
`github.com/fusionpbx/fusionpbx`, validado no commit `087fc2b98`.

**Não forkamos o FusionPBX.** O que é nosso vive em `app/simplificaja_api/` e
instala por cópia; a árvore do upstream fica intocada, e atualizar é `git pull`.
Forkar transformaria cada correção de segurança deles em merge nosso, para
sempre, sem ganho nenhum.

## Instalar numa VM nova

```bash
# 1. instalador oficial do FusionPBX (Ubuntu 24)
wget -O - https://raw.githubusercontent.com/fusionpbx/fusionpbx-install.sh/master/debian/pre-install.sh | sh
cd /usr/src/fusionpbx-install.sh/debian && ./install.sh

# 2. nossa personalização
git clone <este repositorio> /opt/pabx && cd /opt/pabx
DOMINIO=pabx.simplificaja.com.br \
CHATWOOT_URL=https://app.simplificaja.com.br/webhooks/fusionpbx \
CHATWOOT_SECRET=o-segredo-real \
./scripts/instalar.sh
```

O `instalar.sh` faz o resto: corrige a dependência que o instalador oficial
erra, carrega o `mod_curl`, instala nosso app, o gancho de desligamento e os
áudios, aplica as configurações e limpa o cache. Pode rodar de novo — cada etapa
verifica antes de agir.

**Nenhum segredo mora no repositório**: tudo entra por variável de ambiente.

**Ele não traz dados.** Domínios, ramais e clientes não vêm junto — isto instala
o PABX personalizado, não uma cópia do servidor antigo. Recriar clientes é
provisionamento, que é o papel do app da API.

## O que ainda precisa ser feito à mão

Pelas telas do FusionPBX, porque criar no banco **não funciona** (ver
`docs/armadilhas.md`):

- Domínio do cliente
- Ramais
- Fila, URA e grupos
- Destinos dos números (Dialplan → Destinations, nunca Inbound Routes cru)
- Gateway de cada número

É exatamente esta lista que o app em `app/simplificaja_api/` existe para
eliminar.

## Estado conhecido, para não assustar

- O gateway está com `register_transport = tcp`. Ficou assim durante o
  diagnóstico do `407` e funciona; UDP é o mais comum e vale revisitar sem
  pressa, mas mexer nisso quebra o que está validado.
- O servidor está no fuso europeu. Os logs saem 5h à frente de Brasília.
- Ramais `1001` e `1002` são de teste.
