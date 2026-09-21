#!/bin/bash
# Refaz o wss.pem depois que o certbot renova, e recarrega o TLS do FreeSWITCH.
#
# Por que existe: o nginx lê `fullchain.pem` e `privkey.pem` separados, mas o
# FreeSWITCH quer os dois CONCATENADOS num arquivo só. Renovar e recarregar só
# o nginx faz o painel abrir normalmente e o softphone continuar falhando --
# com um erro de TLS genérico que não diz o que está errado.
#
# Instalar como deploy-hook do certbot:
#   /etc/letsencrypt/renewal-hooks/deploy/renovar-wss.sh
# O certbot roda tudo que está nessa pasta após cada renovação bem-sucedida.
set -euo pipefail

DOMINIO=pabx.simplificaja.com.br
VIVO=/etc/letsencrypt/live/$DOMINIO
DESTINO=/etc/freeswitch/tls/wss.pem

# O certbot exporta RENEWED_LINEAGE; fora dele, assume o domínio do PABX.
ORIGEM=${RENEWED_LINEAGE:-$VIVO}

if [ ! -s "$ORIGEM/fullchain.pem" ] || [ ! -s "$ORIGEM/privkey.pem" ]; then
  echo "[wss] $ORIGEM sem fullchain/privkey; nada a fazer" >&2
  exit 0
fi

# Escreve num temporário e move: se algo falhar no meio, o wss.pem antigo
# continua íntegro em vez de virar um arquivo pela metade.
TEMP=$(mktemp)
trap 'rm -f "$TEMP"' EXIT
cat "$ORIGEM/fullchain.pem" "$ORIGEM/privkey.pem" > "$TEMP"

# Confere que o par bate antes de publicar: certificado e chave trocados
# derrubam o WSS e o sintoma não aponta para aqui. Compara a chave pública dos
# dois lados, que funciona igual para RSA e ECDSA -- o Let's Encrypt emite
# ECDSA por padrão, e a checagem por `-modulus` só serve para RSA.
do_certificado=$(openssl x509 -in "$TEMP" -noout -pubkey | openssl md5)
da_chave=$(openssl pkey -in "$TEMP" -pubout 2>/dev/null | openssl md5)
if [ -z "$da_chave" ] || [ "$do_certificado" != "$da_chave" ]; then
  echo "[wss] certificado e chave não conferem; wss.pem preservado" >&2
  exit 1
fi

install -o www-data -g www-data -m 640 "$TEMP" "$DESTINO"
echo "[wss] $DESTINO atualizado"

# O sofia carrega o certificado ao subir o perfil, então reloadxml não basta.
# Reiniciar o perfil derruba os registros por instantes; é o preço, e acontece
# a cada 60 dias.
if command -v fs_cli >/dev/null 2>&1; then
  fs_cli -x "sofia profile internal restart" >/dev/null 2>&1 || true
  echo "[wss] perfil internal reiniciado"
fi

systemctl reload nginx 2>/dev/null || true
