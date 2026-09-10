#!/bin/bash
set -e
DEST=/var/lib/freeswitch/recordings/pabx.simplificaja.com.br
mkdir -p "$DEST"

gera() {
  local arquivo="$1"; shift
  local texto="$*"
  espeak-ng -v pt-br -s 150 -w /tmp/raw.wav "$texto"
  # FreeSWITCH toca melhor em 8k mono PCM 16 bits
  sox /tmp/raw.wav -r 8000 -c 1 -b 16 "$DEST/$arquivo"
  rm -f /tmp/raw.wav
}

gera boas-vindas.wav "Olá! Você ligou para a Simplifica Já."
gera menu.wav "Para falar com o atendimento, digite 1. Para o setor comercial, digite 2. Para ouvir novamente, digite 9."
gera entrando-na-fila.wav "Aguarde um momento. Você será atendido pelo próximo atendente disponível."
gera opcao-invalida.wav "Opção inválida."

chown -R www-data:www-data "$DEST"
ls -la "$DEST"
