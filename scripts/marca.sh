#!/bin/bash
# Aplica a marca do SimplificaJá no painel do FusionPBX.
#
# Rodar no servidor do PABX, como root. Idempotente.
set -euo pipefail

AQUI="$(cd "$(dirname "$0")" && pwd)"
DESTINO=/var/www/fusionpbx/themes/simplificaja/images

# Fora de themes/default de proposito: aquele e o tema deles e some numa
# atualizacao do FusionPBX.
mkdir -p "$DESTINO"
cp "$AQUI"/marca/*.svg "$AQUI"/marca/favicon.ico "$DESTINO"/
chown -R www-data:www-data /var/www/fusionpbx/themes/simplificaja

su - postgres -c "psql -d fusionpbx -f $AQUI/marca.sql"
echo "marca aplicada"
