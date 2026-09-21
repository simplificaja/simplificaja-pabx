-- Gancho de desligamento: manda o resumo da chamada para o Chatwoot.
-- Usa mod_curl porque esta instalacao nao tem luasocket.
-- Registrar no plano de discagem GLOBAL: dominio novo herda o gancho. Sem isso
-- as chamadas daquele cliente acontecem e nao sao registradas -- falha silenciosa.

local url = "CHATWOOT_URL"

local function var(...)
  for _, nome in ipairs({...}) do
    local v = env:getHeader(nome)
    if v ~= nil and v ~= "" then return v end
  end
  return ""
end

local function escapa(s)
  -- Sem aspas, barras ou espacos: o corpo vai como UM argumento do comando
  -- curl, entao espaco no meio viraria argumento novo e truncaria o JSON.
  return tostring(s):gsub('\\', ''):gsub('"', ''):gsub('%s+', 'T')
end

local campos = {
  domain       = var("variable_domain_name"),
  direction    = var("variable_call_direction"),
  from         = var("variable_caller_id_number", "Caller-Caller-ID-Number"),
  -- O DID discado. sip_to_user vem reescrito em chamada originada, entao o
  -- numero de destino canonico vem primeiro.
  to           = var("Caller-Destination-Number", "variable_sip_req_user", "variable_destination_number"),
  extension    = var("variable_dialed_extension"),
  call_uuid    = var("variable_uuid", "Unique-ID"),
  duration     = var("variable_billsec"),
  hangup_cause = var("variable_hangup_cause"),
  started_at   = var("variable_start_stamp"),
}
if campos.duration == "" then campos.duration = "0" end

-- O segredo e por dominio. Le do v_domain_settings do dominio da propria
-- chamada, e nao de um valor fixo no script: assim um PABX invadido alcanca
-- os clientes dele, e nao a instalacao inteira do SimplificaJa.
require "resources.functions.settings"
local domain_uuid = var("variable_domain_uuid")
local ajustes = domain_uuid ~= "" and settings(domain_uuid) or nil
local segredo = ajustes and ajustes["simplificaja"]
	and ajustes["simplificaja"]["webhook_secret"]
	and ajustes["simplificaja"]["webhook_secret"]["text"]

-- Sem segredo o Chatwoot recusaria com 401 e a chamada sumiria do historico
-- sem deixar rastro. Melhor gritar no console do FreeSWITCH.
if segredo == nil or segredo == "" then
	freeswitch.consoleLog("err",
		"[chatwoot] dominio " .. domain_uuid .. " sem simplificaja/webhook_secret; chamada nao registrada\n")
	return
end

local partes = {}
for k, v in pairs(campos) do
  table.insert(partes, string.format('"%s":"%s"', k, escapa(v)))
end
local corpo = "{" .. table.concat(partes, ",") .. "}"

local comando = string.format(
  '%s content-type application/json append_headers X-Webhook-Secret:%s timeout 5 post %s',
  url, segredo, corpo
)
local resposta = freeswitch.API():executeString("curl " .. comando)
freeswitch.consoleLog("info", "[chatwoot] " .. corpo .. " -> " .. tostring(resposta) .. "\n")
