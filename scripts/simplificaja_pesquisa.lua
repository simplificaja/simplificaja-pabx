-- Grava a nota que o cliente digitou no fim da ligação.
--
-- Chamado pelo plano da pesquisa, depois do `play_and_get_digits` e antes do
-- `hangup`. Roda no canal do CLIENTE, que é onde o mod_callcenter deixou
-- `cc_agent`, `cc_queue` e `cc_agent_bridged` -- e essas variáveis sobrevivem
-- ao `transfer_after_bridge` porque são variáveis de canal, e o
-- `switch_ivr_session_transfer` mantém a sessão.
--
-- Banco pelo helper nativo do FusionPBX, igual o `call_flow.lua`. NÃO usar
-- luasocket: esta instalação não tem, e foi por isso que o `chatwoot_hangup.lua`
-- usa mod_curl.
--
-- Tudo em `pcall`: falhar ao gravar uma nota não pode afetar a ligação.

local ok, err = pcall(function()
	if session == nil then
		freeswitch.consoleLog("warning", "[pesquisa] chamado sem sessao; nada a fazer\n")
		return
	end

	local nota = session:getVariable("nota")
	if nota == nil or not tostring(nota):match("^[1-5]$") then
		-- Cliente desligou sem responder, ou errou as três tentativas. Não é
		-- erro: é ausência de resposta, e ausência não vira linha.
		return
	end

	-- Sem bridge não houve conversa, e não há o que avaliar. O
	-- `transfer_after_bridge` já garante isso (só dispara ao fim de um bridge),
	-- mas a checagem é barata e protege se alguém apontar a pesquisa por outro
	-- caminho qualquer no futuro.
	if session:getVariable("cc_agent_bridged") ~= "true" then
		freeswitch.consoleLog("notice",
			"[pesquisa] nota " .. nota .. " descartada: nao houve bridge\n")
		return
	end

	local call_uuid = session:getVariable("uuid")
	local domain_uuid = session:getVariable("domain_uuid")
	if call_uuid == nil or call_uuid == "" or domain_uuid == nil or domain_uuid == "" then
		freeswitch.consoleLog("err",
			"[pesquisa] sem uuid ou domain_uuid; nota " .. nota .. " perdida\n")
		return
	end

	require "resources.functions.config"
	local Database = require "resources.functions.database"

	-- `nil` NÃO é parâmetro válido para este helper: cai em "undefined
	-- parameter" e a query inteira falha (database.lua:37-55).
	--
	-- O sentinela é a STRING "NULL", que o `apply_params` testa antes de
	-- qualquer outra coisa (database.lua:44) e troca pelo literal SQL. Não é o
	-- `Database.NULL`: aquela tabela existe no módulo mas não é exportada --
	-- `type(Database.NULL)` devolve `nil` nesta versão, e usá-la faz a query
	-- morrer em silêncio. Conferido em execução.
	--
	-- Funciona para qualquer tipo de coluna, porque vira um `NULL` nu no SQL em
	-- vez de uma string vazia que o Postgres teria de converter.
	local function ou_nulo(valor)
		if valor == nil or valor == "" then
			return "NULL"
		end
		return valor
	end

	local dbh = Database.new('system')
	if not dbh then
		freeswitch.consoleLog("err", "[pesquisa] sem conexao com o banco\n")
		return
	end

	-- `cc_agent` vem como `1001@dominio`; a tela mostra só o ramal.
	local agente = session:getVariable("cc_agent") or ""
	local ramal = agente:match("^([^@]+)")

	-- O escopo por usuário do portal compara `extension_uuid`, como toda
	-- consulta de lá. Guardar o uuid e não só o número é o que faz a aba nova
	-- seguir a mesma regra de visibilidade das outras três.
	local extension_uuid = nil
	if ramal ~= nil then
		dbh:query(
			"select extension_uuid from v_extensions "
			.. "where domain_uuid = :dominio and extension = :ramal",
			{ dominio = domain_uuid, ramal = ramal },
			function(row) extension_uuid = row.extension_uuid end)
	end

	-- `on conflict do nothing`: o `transfer_after_bridge` se apaga ao ser
	-- consumido e dispara uma vez só, mas o índice único é a segunda rede.
	dbh:query(
		"insert into v_simplificaja_pesquisas "
		.. "(pesquisa_uuid, domain_uuid, call_uuid, nota, ramal, extension_uuid, fila, telefone) "
		.. "values (gen_random_uuid(), :dominio, :chamada, :nota, :ramal, :ramal_uuid, :fila, :telefone) "
		.. "on conflict (call_uuid) do nothing",
		{
			dominio    = domain_uuid,
			chamada    = call_uuid,
			nota       = tonumber(nota),
			ramal      = ou_nulo(ramal),
			ramal_uuid = ou_nulo(extension_uuid),
			fila       = ou_nulo(session:getVariable("cc_queue")),
			telefone   = ou_nulo(session:getVariable("caller_id_number")),
		})

	-- Conferir lendo de volta, em vez de confiar no retorno do `query`.
	--
	-- O helper não lança exceção quando a query falha: ele registra no log dele
	-- e devolve. Sem esta leitura o script anunciava "gravada" para uma nota que
	-- nunca entrou -- e foi exatamente o que aconteceu no primeiro teste, com o
	-- parâmetro nulo. Falha de gravação tem de aparecer como falha.
	--
	-- Serve também para a duplicata: com `on conflict do nothing` nada é
	-- inserido na segunda vez, mas a linha existe, e isso é sucesso.
	local gravou = false
	dbh:query(
		"select 1 as achou from v_simplificaja_pesquisas where call_uuid = :chamada",
		{ chamada = call_uuid },
		function() gravou = true end)

	dbh:release()

	if gravou then
		freeswitch.consoleLog("notice",
			"[pesquisa] nota " .. nota .. " do ramal " .. tostring(ramal) .. " gravada\n")
	else
		freeswitch.consoleLog("err",
			"[pesquisa] nota " .. nota .. " NAO foi gravada para a chamada "
			.. call_uuid .. "; procure o motivo no log do [database]\n")
	end
end)

if not ok then
	freeswitch.consoleLog("err", "[pesquisa] falhou: " .. tostring(err) .. "\n")
end
