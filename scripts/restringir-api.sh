#!/bin/bash
#
# Restringe a API do SimplificaJá ao IP que a consome.
#
#   IP_PERMITIDO=173.212.252.121 ./restringir-api.sh
#
# A chave por domínio já protege, mas uma camada só é pouco para algo que cria
# ramais e troncos -- e ramal é credencial SIP exposta na internet.

set -euo pipefail
: "${IP_PERMITIDO:?defina IP_PERMITIDO}"

CONF=/etc/nginx/sites-enabled/fusionpbx
cp "$CONF" "$CONF.bak.$(date +%s)"

if grep -q 'simplificaja_api' "$CONF"; then
  echo "já restrito; para trocar o IP, edite $CONF"
  exit 0
fi

python3 - "$CONF" "$IP_PERMITIDO" <<'PY'
import sys
conf, ip = sys.argv[1], sys.argv[2]
s = open(conf).read()
bloco = f'''
	# API do SimplificaJá: só quem consome fala com ela.
	location /app/simplificaja_api/ {{
		allow {ip};
		deny  all;
		try_files $uri $uri/ =404;
		location ~ \\.php$ {{
			allow {ip};
			deny  all;
			fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
			fastcgi_index index.php;
			include fastcgi_params;
			fastcgi_param SCRIPT_FILENAME /var/www/fusionpbx$fastcgi_script_name;
		}}
	}}
'''
marca = '''        #redirect websockets to port 8080
        location /websockets/ {'''
i = s.find(marca)
assert i != -1, "não achei o ponto de inserção no nginx"
open(conf, 'w').write(s[:i] + bloco.lstrip('\n') + '\n' + s[i:])
print("bloco inserido")
PY

nginx -t && systemctl reload nginx
echo "pronto: só $IP_PERMITIDO alcança a API"
