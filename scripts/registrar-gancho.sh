#!/bin/bash
# Pendura o gancho do SimplificaJá no fim do app `hangup` do FusionPBX.
#
# Aqui, e não num dialplan próprio, porque o FusionPBX já ocupa o
# `api_hangup_hook` global (`local_extension` o define). Um segundo `set`
# sobrescreveria o dele e derrubaria aviso de chamada perdida e correio de voz.
# O app `hangup` roda com `env:` disponível, que é o que o nosso script usa.
#
# Idempotente: rodar de novo não duplica.
set -euo pipefail

ALVO=/usr/share/freeswitch/scripts/app/hangup/index.lua
MARCA="-- SimplificaJá: registro de chamada"

if grep -qF -- "$MARCA" "$ALVO"; then
  echo "[gancho] já registrado em $ALVO"
  exit 0
fi

cat >> "$ALVO" <<'LUA'

-- SimplificaJá: registro de chamada
-- Em pcall porque falha nossa não pode derrubar o tratamento de hangup do
-- FusionPBX -- perdida, correio de voz e CDR passam por aqui.
local gancho_simplificaja = "/usr/share/freeswitch/scripts/chatwoot_hangup.lua"
local f = io.open(gancho_simplificaja)
if f then
	f:close()
	local ok, err = pcall(dofile, gancho_simplificaja)
	if not ok then
		freeswitch.consoleLog("err", "[chatwoot] gancho falhou: " .. tostring(err) .. "\n")
	end
end
LUA

echo "[gancho] registrado em $ALVO"
