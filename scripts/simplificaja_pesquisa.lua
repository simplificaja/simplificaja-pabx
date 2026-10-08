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

	-- Qual pergunta esta nota responde. Uma fila pode pedir duas em sequência,
	-- e a segunda sobrescreve a variável `nota` -- sem isto as duas notas da
	-- mesma ligação ficariam indistinguíveis. O plano de cada pesquisa seta
	-- este valor com o próprio número antes de coletar.
	local qual = session:getVariable("pesquisa_atual")
	if qual == nil or qual == "" then
		freeswitch.consoleLog("err",
			"[pesquisa] sem pesquisa_atual; nota " .. nota .. " perdida. "
			.. "O plano de discagem desta pesquisa precisa ser salvo de novo.\n")
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

	-- Nesta instalacao o `cc_agent` vem como o UUID do agente, nao como
	-- `1001@dominio`. Medido numa ligacao real de 08/10/2026: a nota saiu com
	-- "ramal 07a8d7f9-8b03-4083-8887-4f023b5d61f4", e a tela por atendente
	-- mostraria UUID -- inutil para quem precisa agir sobre uma nota baixa.
	--
	-- O numero do ramal esta no `agent_name`. Tratar as duas formas, porque
	-- `cc_agent` e documentado como nome do agente e o nome pode ser qualquer
	-- coisa dependendo de como o agente foi criado.
	if ramal ~= nil and ramal:match("^%x%x%x%x%x%x%x%x%-%x%x%x%x%-") then
		local pelo_uuid = nil
		dbh:query(
			"select agent_name from v_call_center_agents "
			.. "where call_center_agent_uuid = :agente",
			{ agente = ramal },
			function(row) pelo_uuid = row.agent_name end)
		if pelo_uuid ~= nil and pelo_uuid ~= "" then
			ramal = pelo_uuid
		end
	end

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

	-- `on conflict do nothing`: a unicidade é por (chamada, pergunta), não por
	-- chamada -- uma ligação pode responder duas perguntas. O índice é a
	-- segunda rede contra a mesma pergunta gravar duas vezes.
	dbh:query(
		"insert into v_simplificaja_pesquisas "
		.. "(pesquisa_uuid, domain_uuid, call_uuid, pesquisa, nota, ramal, extension_uuid, fila, telefone) "
		.. "values (gen_random_uuid(), :dominio, :chamada, :qual, :nota, :ramal, :ramal_uuid, :fila, :telefone) "
		.. "on conflict (call_uuid, pesquisa) do nothing",
		{
			dominio    = domain_uuid,
			chamada    = call_uuid,
			qual       = qual,
			nota       = tonumber(nota),
			ramal      = ou_nulo(ramal),
			ramal_uuid = ou_nulo(extension_uuid),
			-- So o numero: `cc_queue` vem como `9100@dominio`, e o dominio numa
			-- tela de cliente nao acrescenta nada. O nome bonito da fila o
			-- portal resolve no banco, como ja faz com o nome da pergunta.
			fila       = ou_nulo((session:getVariable("cc_queue") or ""):match("^([^@]+)")),
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
		"select 1 as achou from v_simplificaja_pesquisas "
		.. "where call_uuid = :chamada and pesquisa = :qual",
		{ chamada = call_uuid, qual = qual },
		function() gravou = true end)

	dbh:release()

	if gravou then
		freeswitch.consoleLog("notice",
			"[pesquisa] pergunta " .. qual .. ": nota " .. nota
			.. " do ramal " .. tostring(ramal) .. " gravada\n")
	else
		freeswitch.consoleLog("err",
			"[pesquisa] nota " .. nota .. " NAO foi gravada para a chamada "
			.. call_uuid .. "; procure o motivo no log do [database]\n")
	end
end)

if not ok then
	freeswitch.consoleLog("err", "[pesquisa] falhou: " .. tostring(err) .. "\n")
end
