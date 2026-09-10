#!/bin/bash
#
# Gera os áudios da URA em português no formato que o FreeSWITCH toca.
#
#   DOMINIO=pabx.simplificaja.com.br ./gerar-audios.sh
#
# Voz sintetizada: serve para validar o fluxo. Para cliente real, grave com voz
# humana e substitua os arquivos -- a URA só toca o WAV, trocar não mexe em nada.

set -euo pipefail
: "${DOMINIO:?defina DOMINIO}"

DEST="/var/lib/freeswitch/recordings/$DOMINIO"
mkdir -p "$DEST"

command -v espeak-ng >/dev/null || {
  DEBIAN_FRONTEND=noninteractive apt-get install -y espeak-ng
}
command -v sox >/dev/null || {
  DEBIAN_FRONTEND=noninteractive apt-get install -y sox
}

gera() {
  local arquivo="$1"; shift
  espeak-ng -v pt-br -s 150 -w /tmp/raw.wav "$*"
  # 8 kHz mono 16 bits: o que o FreeSWITCH toca sem reamostrar
  sox /tmp/raw.wav -r 8000 -c 1 -b 16 "$DEST/$arquivo"
  rm -f /tmp/raw.wav
}

gera boas-vindas.wav      "Olá! Você ligou para a Simplifica Já."
gera menu.wav             "Para falar com o atendimento, digite 1. Para o setor comercial, digite 2. Para ouvir novamente, digite 9."
gera entrando-na-fila.wav "Aguarde um momento. Você será atendido pelo próximo atendente disponível."
gera opcao-invalida.wav   "Opção inválida."

# A URA do FusionPBX toca uma saudação só, então anúncio e menu vão juntos.
sox -n -r 8000 -c 1 -b 16 /tmp/sil.wav trim 0.0 0.5
sox "$DEST/boas-vindas.wav" /tmp/sil.wav "$DEST/menu.wav" "$DEST/ura-completa.wav"
rm -f /tmp/sil.wav

chown -R www-data:www-data "$DEST"
ls -la "$DEST"
