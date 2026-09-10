#!/bin/bash
#
# Instala um FusionPBX já personalizado para o SimplificaJá, numa VM limpa.
#
#   Ubuntu 24, como root, VM recém-criada.
#
#   DOMINIO=pabx.simplificaja.com.br \
#   CHATWOOT_URL=https://app.simplificaja.com.br/webhooks/fusionpbx \
#   CHATWOOT_SECRET=xxxxx \
#   ./instalar.sh
#
# Roda em etapas e pode ser repetido: cada etapa verifica antes de agir.
# O que ele NÃO faz está no fim do arquivo.

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
: "${DOMINIO:?defina DOMINIO}"
: "${CHATWOOT_URL:?defina CHATWOOT_URL}"
: "${CHATWOOT_SECRET:?defina CHATWOOT_SECRET}"

etapa() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
ok()    { printf '   ok: %s\n' "$*"; }
pulo()  { printf '   já feito: %s\n' "$*"; }

# ---------------------------------------------------------------- 1. sistema
etapa "1. Dependência que o instalador oficial erra"
# O instalador do FusionPBX instala libpcre3-dev, mas o FreeSWITCH 1.10 exige
# libpcre2-dev. Sem isto o configure aborta, nada compila -- e mesmo assim a
# saída dele diz "Installation has completed".
if dpkg -s libpcre2-dev >/dev/null 2>&1; then
  pulo "libpcre2-dev"
else
  DEBIAN_FRONTEND=noninteractive apt-get install -y libpcre2-dev
  ok "libpcre2-dev"
fi

etapa "2. FusionPBX"
if [ -d /var/www/fusionpbx ]; then
  pulo "/var/www/fusionpbx existe"
else
  echo "   Rode o instalador oficial primeiro:"
  echo "     wget -O - https://raw.githubusercontent.com/fusionpbx/fusionpbx-install.sh/master/debian/pre-install.sh | sh"
  echo "     cd /usr/src/fusionpbx-install.sh/debian && ./install.sh"
  echo "   Depois rode este script de novo."
  exit 1
fi

# ------------------------------------------------------------------ 3. curl
etapa "3. mod_curl"
# O gancho de desligamento usa mod_curl porque esta instalação não tem
# luasocket. Ele vem compilado mas não vem carregado.
CONF=/etc/freeswitch/autoload_configs/modules.conf.xml
if grep -q 'mod_curl' "$CONF"; then
  pulo "mod_curl no autoload"
else
  cp "$CONF" "$CONF.bak.$(date +%s)"
  sed -i 's|<load module="mod_lua"/>|<load module="mod_lua"/>\n\t\t<load module="mod_curl"/>|' "$CONF"
  ok "mod_curl acrescentado ao autoload"
fi
fs_cli -x "load mod_curl" >/dev/null 2>&1 || true

# ------------------------------------------------------------- 4. nosso app
etapa "4. App do SimplificaJá"
DESTINO=/var/www/fusionpbx/app/simplificaja_api
mkdir -p "$DESTINO"
cp -r "$REPO/app/simplificaja_api/." "$DESTINO/"
chown -R www-data:www-data "$DESTINO"
ok "copiado para $DESTINO"
# O app aparece no menu depois que o FusionPBX relê os apps:
echo "   Depois: Advanced → Upgrade → App Defaults, ou"
echo "     php /var/www/fusionpbx/core/upgrade/upgrade.php"

# ------------------------------------------------------------- 5. gancho lua
etapa "5. Gancho de desligamento"
GANCHO=/usr/share/freeswitch/scripts/chatwoot_hangup.lua
sed -e "s|CHATWOOT_URL|$CHATWOOT_URL|" \
    -e "s|CHATWOOT_SECRET|$CHATWOOT_SECRET|" \
    "$REPO/scripts/chatwoot_hangup.lua" > "$GANCHO"
chmod 644 "$GANCHO"
ok "instalado em $GANCHO"
echo "   Falta registrar no plano de discagem GLOBAL (contexto 'global',"
echo "   depois do call-direction, com continue ligado):"
echo "     set  api_hangup_hook=lua chatwoot_hangup.lua"

# -------------------------------------------------------------- 6. os audios
etapa "6. Áudios da URA"
if [ -f "/var/lib/freeswitch/recordings/$DOMINIO/ura-completa.wav" ]; then
  pulo "áudios já gerados"
else
  DOMINIO="$DOMINIO" bash "$REPO/scripts/gerar-audios.sh"
  ok "áudios gerados"
fi

# ------------------------------------------------------- 7. as configuracoes
etapa "7. Configurações que diferem do padrão"
cp "$REPO/scripts/aplicar-configuracao.sql" /tmp/aplicar-configuracao.sql
chmod 644 /tmp/aplicar-configuracao.sql
su - postgres -c "psql -d fusionpbx -f /tmp/aplicar-configuracao.sql"
rm -f /tmp/aplicar-configuracao.sql
ok "aplicadas"

# --------------------------------------------------------------- 8. o cache
etapa "8. Cache"
# Sem isto nada do que foi mudado tem efeito: o FusionPBX continua servindo o
# XML antigo de /var/cache/fusionpbx.
rm -rf /var/cache/fusionpbx/*
fs_cli -x "reloadxml" >/dev/null 2>&1 || true
fs_cli -x "reloadacl" >/dev/null 2>&1 || true
ok "limpo e recarregado"

cat <<'FIM'

== Falta fazer à mão ==

  1. Registrar o gancho no plano de discagem global (comando acima).
  2. Certificado para o domínio, e curinga *.pabx... se for multi-cliente.
  3. Criar o domínio do cliente, ramais, fila, URA, destinos e gateway.

O item 3 é o que o app da API existe para eliminar. Enquanto ele não estiver
pronto, é pelas telas do FusionPBX -- criar no banco não funciona, ver
docs/armadilhas.md.

== O que este script deliberadamente NÃO faz ==

  - Não traz dados: domínios, ramais e clientes não vêm junto. Isto instala o
    PABX personalizado, não uma cópia do servidor antigo.
  - Não mexe em firewall além da lista de IPs de tronco.
  - Não guarda segredo nenhum no repositório: tudo vem por variável.

FIM
