# PABX do SimplificaJá

Tudo que difere de um FusionPBX recém-instalado. Existe para que trocar de VPS
ou reinstalar o servidor não custe a mesma investigação de novo.

**Não é um fork do FusionPBX.** É o conjunto de modificações, configurações e
scripts que fazem o FusionPBX servir o SimplificaJá.

## O que tem aqui

| Pasta | O quê |
|---|---|
| `scripts/chatwoot_hangup.lua` | gancho de desligamento que manda a chamada para o Chatwoot |
| `scripts/gerar-audios.sh` | gera os áudios da URA em português |
| `scripts/aplicar-configuracao.sql` | as configurações que diferem do padrão |
| `docs/armadilhas.md` | o que parece certo na tela e não funciona |
| `api/` | a API PHP que o painel do SimplificaJá consome (a construir) |

## Servidor atual

`109.123.250.200` — Ubuntu 24, FusionPBX com FreeSWITCH 1.10 compilado do
código. Domínio `pabx.simplificaja.com.br`.

## Reinstalar do zero

1. **Instalar o FusionPBX** pelo instalador oficial. Em Ubuntu 24 o FreeSWITCH é
   compilado, e o instalador tem um defeito conhecido: ele instala
   `libpcre3-dev`, mas o FreeSWITCH 1.10 exige `libpcre2-dev`. O `configure`
   aborta, nada compila, e mesmo assim a saída diz "Installation has completed".

   ```
   apt install libpcre2-dev
   ```

   O `mod_spandsp` também falha ao compilar (a spandsp do git mudou a API na
   v18). Desabilitar o módulo — perde-se fax T.38, que não usamos.

2. **Carregar o `mod_curl`** e deixá-lo no autoload. O gancho de desligamento
   depende dele, porque esta instalação não tem luasocket:

   ```
   fs_cli -x "load mod_curl"
   # e acrescentar <load module="mod_curl"/> em
   # /etc/freeswitch/autoload_configs/modules.conf.xml
   ```

3. **Aplicar as configurações:**

   ```
   su - postgres -c 'psql -d fusionpbx -f aplicar-configuracao.sql'
   ```

4. **Instalar o gancho:**

   ```
   cp scripts/chatwoot_hangup.lua /usr/share/freeswitch/scripts/
   # substituir CHATWOOT_URL e CHATWOOT_SECRET pelos valores reais
   ```

   Registrar no plano de discagem **global** (contexto `global`, depois do
   `call-direction`, com `continue` ligado):

   ```
   set  api_hangup_hook=lua chatwoot_hangup.lua
   ```

5. **Gerar os áudios:**

   ```
   ./scripts/gerar-audios.sh
   ```

6. **Limpar o cache** — sem isto nada do que foi mudado tem efeito:

   ```
   rm -rf /var/cache/fusionpbx/* && fs_cli -x reloadxml
   ```

## O que ainda precisa ser feito à mão

Pelas telas do FusionPBX, porque criar no banco **não funciona** (ver
`docs/armadilhas.md`):

- Domínio do cliente
- Ramais
- Fila, URA e grupos
- Destinos dos números (Dialplan → Destinations, nunca Inbound Routes cru)
- Gateway de cada número

É exatamente esta lista que a API em `api/` existe para eliminar.

## Estado conhecido, para não assustar

- O gateway está com `register_transport = tcp`. Ficou assim durante o
  diagnóstico do `407` e funciona; UDP é o mais comum e vale revisitar sem
  pressa, mas mexer nisso quebra o que está validado.
- O servidor está no fuso europeu. Os logs saem 5h à frente de Brasília.
- Ramais `1001` e `1002` são de teste.
