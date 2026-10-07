# Pesquisa de avaliação — plano de implementação

> **Para quem executa:** cada passo é uma ação só. Marque o checkbox ao
> concluir. Não pule as verificações: este repositório **não tem suíte de
> testes**, então a verificação é a consulta ao banco, a leitura do XML gerado e
> a ligação real. É a única rede que existe.

**Objetivo:** ao final da ligação, quando o atendente desliga, o cliente ouve
uma pergunta, digita de 1 a 5, e a nota aparece na aba Avaliações do portal do
tenant dele no FusionPBX.

**Arquitetura:** tudo no PABX. A fila passa a setar `transfer_after_bridge`
apontando para uma URA nova na faixa 6000–6999; a URA coleta o dígito com
`play_and_get_digits` e chama um script Lua que grava uma linha em
`v_simplificaja_pesquisas`; o `simplificaja_portal` ganha uma quarta aba que lê
essa tabela. O repositório do hub não é tocado.

**Tecnologias:** PHP 8.3 (classes do FusionPBX: `database`, `permissions`,
`cache`, `event_socket`, `xml::sanitize`), Lua do FreeSWITCH
(`resources.functions.database`), PostgreSQL 18, plano de discagem XML do
FreeSWITCH 1.10.12.

**Spec:** `docs/superpowers/specs/2026-10-07-pesquisa-de-avaliacao-design.md` —
leia antes de começar. Este plano argumenta a partir dela.

## Restrições globais

Valem para todas as tarefas, sem repetição em cada uma:

- **A faixa é 6000–6999.** Não é 7000 (horário) nem 8000 (anúncio).
- **A sintaxe de `transfer_after_bridge` usa dois-pontos:**
  `NNNN:XML:dominio`. Com espaço, o log diz `No extension specified` e a
  ligação cai.
- **A tabela se chama `v_simplificaja_pesquisas`** e tem coluna `domain_uuid`.
  O prefixo `v_` é o que faz a remoção de cliente limpá-la sozinha
  (`api_dominio.php:217`). Renomear sem manter os dois reintroduz o bug dos
  órfãos.
- **A nota é 1 a 5**, porque é a escala que o `CsatSurveyResponse` do Chatwoot
  valida. Não usar 0–9.
- **Nenhum arquivo do repositório do hub** (`/home/isaac/SimplificaJa/code/chatwoot`)
  é alterado por este plano.
- **Nenhum plano de discagem global** é alterado. Tudo é por `domain_uuid`.
- **Toda escrita no banco pelo Lua vai dentro de `pcall`.** Falha ao gravar uma
  nota não pode afetar a ligação.
- **Deploy é `scp`**, não há CI neste repositório. O servidor é
  `root@109.123.250.200` com `~/.ssh/id_ed25519`.
- **Testar só no `demo.pabx.simplificaja.com.br`.** Não tocar em `velte` nem em
  `testejoao`.
- Commits em português, no estilo do repositório (`feat(pesquisa): ...`).

---

### Tarefa 1: A tabela

**Arquivos:**
- Criar: `scripts/configurar-pesquisa.sql`
- Alterar: `scripts/instalar.sh`

**Interfaces:**
- Produz: a tabela `v_simplificaja_pesquisas`, consumida pelas tarefas 3 e 5.

- [ ] **Passo 1: Escrever o script SQL**

Criar `scripts/configurar-pesquisa.sql`:

```sql
-- Pesquisa de avaliação: a nota que o cliente digita no fim da ligação.
--
-- O prefixo `v_` e a coluna `domain_uuid` não são estética: a varredura de
-- remoção de cliente em api_dominio.php casa exatamente `v\_%` com uma coluna
-- `domain_uuid`, e é por isso que apagar um cliente limpa as notas dele sem
-- código novo. Renomear sem manter os dois deixa as notas órfãs no banco.
--
-- Idempotente: pode rodar de novo sem efeito.

create table if not exists v_simplificaja_pesquisas (
    pesquisa_uuid  uuid        primary key,
    domain_uuid    uuid        not null,
    call_uuid      uuid        not null unique,
    nota           smallint    not null check (nota between 1 and 5),
    ramal          text,
    extension_uuid uuid,
    fila           text,
    telefone       text,
    criado_em      timestamptz not null default now()
);

create index if not exists idx_simplificaja_pesquisas_dominio
    on v_simplificaja_pesquisas (domain_uuid, criado_em desc);

-- O dono dos arquivos do FusionPBX é o www-data, e é ele quem a API usa.
grant select, insert, update, delete on v_simplificaja_pesquisas to fusionpbx;

-- Verificação: deve devolver uma linha com as 9 colunas.
select count(*) as colunas from information_schema.columns
 where table_name = 'v_simplificaja_pesquisas';
```

- [ ] **Passo 2: Conferir o nome do usuário do banco antes de rodar**

O `grant` acima supõe que o papel é `fusionpbx`. Confirmar:

```bash
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "su - postgres -c \"psql -At -d fusionpbx -c 'select tableowner from pg_tables where tablename = \\\"v_domains\\\"'\""
```

Se o dono for outro, corrigir o `grant` no script. **Não adivinhar.**

- [ ] **Passo 3: Aplicar no servidor**

```bash
scp -i ~/.ssh/id_ed25519 scripts/configurar-pesquisa.sql root@109.123.250.200:/tmp/
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "chmod 644 /tmp/configurar-pesquisa.sql && su - postgres -c 'psql -v ON_ERROR_STOP=1 -d fusionpbx -f /tmp/configurar-pesquisa.sql' && rm -f /tmp/configurar-pesquisa.sql"
```

Esperado: `colunas | 9`.

- [ ] **Passo 4: Rodar de novo e confirmar que é idempotente**

Repetir o comando do passo 3. Esperado: o mesmo `colunas | 9`, sem erro.

- [ ] **Passo 5: Registrar no instalador**

Em `scripts/instalar.sh`, depois do bloco que aplica o
`aplicar-configuracao.sql` (linhas 104–107), acrescentar:

```bash
cp "$REPO/scripts/configurar-pesquisa.sql" /tmp/configurar-pesquisa.sql
chmod 644 /tmp/configurar-pesquisa.sql
su - postgres -c "psql -v ON_ERROR_STOP=1 -d fusionpbx -f /tmp/configurar-pesquisa.sql"
rm -f /tmp/configurar-pesquisa.sql
ok "tabela da pesquisa de avaliação"
```

Sem isto, um servidor novo — a migração para a VPS brasileira, por exemplo —
sobe sem a tabela e a pesquisa falha em silêncio.

- [ ] **Passo 6: Conferir que o instalador cita o script**

```bash
grep -n 'configurar-pesquisa.sql' scripts/instalar.sh
```

Esperado: duas linhas (o `cp` e o `psql`).

- [ ] **Passo 7: Commitar**

```bash
git add scripts/configurar-pesquisa.sql scripts/instalar.sh
git commit -m "feat(pesquisa): tabela da nota, com o v_ que a remocao de cliente alcanca"
```

---

### Tarefa 2: A URA de pesquisa

**Arquivos:**
- Criar: `app/simplificaja_api/resources/classes/api_pesquisa.php`
- Alterar: `app/simplificaja_api/index.php`

**Interfaces:**
- Consome: a tabela da tarefa 1 não é usada aqui. Esta tarefa só gera plano de
  discagem.
- Produz: `api_pesquisa::criar/listar/remover`, e o número da URA que a tarefa 4
  coloca no `transfer_after_bridge`.

- [ ] **Passo 1: Escrever a classe**

Criar `app/simplificaja_api/resources/classes/api_pesquisa.php`. É o molde do
`api_anuncio.php`, com `play_and_get_digits` e `lua` no lugar do `playback`
simples:

```php
<?php

/**
 * Pesquisa de avaliação: pergunta a nota e grava.
 *
 * É alcançada só por `transfer_after_bridge`, que o FreeSWITCH dispara ao fim
 * do bridge -- quando o atendente desliga. Transferência entre ramais não
 * chega aqui: o canal carrega CF_TRANSFER e o bloco pós-bridge é pulado
 * (switch_ivr_bridge.c:969).
 *
 * Plano de discagem direto, não URA do FusionPBX: a URA deles tem menu, opções
 * e destino por tecla, que não é o que uma nota de 1 a 5 precisa.
 */
class api_pesquisa {

	/** Separa as pesquisas dos outros planos nas listagens. */
	const APP_UUID = '1e9f0b45-d844-40ab-b900-aec2f465d408';

	/** Faixa própria: 7xxx é horário, 8xxx é anúncio, 9xxx é fila e menu. */
	const FAIXA_INICIO = 6000;
	const FAIXA_FIM = 6999;

	/** Depois do horário (225), antes das rotas de entrada (230). */
	const ORDEM_NO_PLANO = '226';

	const TENTATIVAS = 3;
	const ESPERA_RESPOSTA = 5000;
	const ESPERA_ENTRE_DIGITOS = 3000;
	const NOTA_MINIMA = 1;
	const NOTA_MAXIMA = 5;

	/** O script roda em /usr/share/freeswitch/scripts; o require depende disso. */
	const SCRIPT = 'simplificaja_pesquisa.lua';

	private static function db() {
		return database::new(['db' => $GLOBALS['db'] ?? null]);
	}

	private static function nome_do_dominio(string $domain_uuid): string {
		$nome = self::db()->select(
			"select domain_name from v_domains where domain_uuid = :u",
			['u' => $domain_uuid], 'column'
		);
		if (empty($nome)) {
			responde(['erro' => 'domínio não encontrado'], 404);
		}
		return (string) $nome;
	}

	private static function caminho_do_audio(string $dominio, string $arquivo): string {
		return '/var/lib/freeswitch/recordings/' . $dominio . '/' . basename($arquivo);
	}

	public static function listar(string $domain_uuid): array {
		$linhas = self::db()->select(
			"select dialplan_uuid, dialplan_name, dialplan_number, dialplan_description, "
			."dialplan_enabled, dialplan_xml "
			."from v_dialplans where domain_uuid = :u and app_uuid = :a "
			."order by dialplan_number",
			['u' => $domain_uuid, 'a' => self::APP_UUID], 'all'
		) ?? [];

		// O áudio mora no XML, para não haver tabela paralela saindo de
		// sincronia com o plano de discagem. Mesmo motivo do anúncio.
		foreach ($linhas as &$linha) {
			$linha['audio'] = self::audio_do_xml($linha['dialplan_xml'] ?? null);
			unset($linha['dialplan_xml']);
		}
		return $linhas;
	}

	/**
	 * O áudio é o 6º argumento do `play_and_get_digits`, separados por espaço.
	 * Pegar por posição e não por regex de caminho: o caminho muda de domínio
	 * para domínio.
	 */
	private static function audio_do_xml(?string $xml): ?string {
		if ($xml === null) {
			return null;
		}
		if (!preg_match('/application="play_and_get_digits" data="([^"]*)"/', $xml, $m)) {
			return null;
		}
		$partes = explode(' ', $m[1]);
		return isset($partes[5]) ? basename($partes[5]) : null;
	}

	public static function criar(string $domain_uuid, array $dados): array {
		foreach (['nome', 'audio'] as $campo) {
			if (empty($dados[$campo])) {
				responde(['erro' => "$campo é obrigatório"], 422);
			}
		}

		$numero = trim((string) ($dados['numero'] ?? ''));
		if ($numero === '') {
			$numero = self::proximo_livre($domain_uuid);
			if ($numero === null) {
				responde(['erro' => 'não há número interno livre na faixa '
					. self::FAIXA_INICIO . '-' . self::FAIXA_FIM], 409);
			}
		} elseif (!ctype_digit($numero)) {
			responde(['erro' => 'número da pesquisa deve ser numérico'], 422);
		} elseif (self::ocupado($domain_uuid, $numero)) {
			responde(['erro' => "o número $numero já está em uso"], 409);
		}

		$dominio = self::nome_do_dominio($domain_uuid);
		$audio = self::caminho_do_audio($dominio, (string) $dados['audio']);
		if (!file_exists($audio)) {
			responde(['erro' => 'áudio não existe neste domínio'], 404);
		}

		$uuid = uuid();
		$p = permissions::new();
		foreach (['dialplan_add', 'dialplan_detail_add'] as $permissao) {
			$p->add($permissao, 'temp');
		}

		$db = self::db();
		$array['dialplans'][0] = self::dialplan($domain_uuid, $uuid, $dominio, $numero,
			(string) $dados['nome'], $audio, (string) ($dados['descricao'] ?? ''));
		$db->save($array);
		self::exigir_gravado($db, "a pesquisa $numero");
		foreach (['dialplan_add', 'dialplan_detail_add'] as $permissao) {
			$p->delete($permissao, 'temp');
		}

		self::recarregar($dominio);

		return ['pesquisa' => $dados['nome'], 'numero' => $numero, 'audio' => basename($audio)];
	}

	public static function remover(string $domain_uuid, string $numero): array {
		$db = self::db();
		$uuid = $db->select(
			"select dialplan_uuid from v_dialplans "
			."where domain_uuid = :u and app_uuid = :a and dialplan_number = :n",
			['u' => $domain_uuid, 'a' => self::APP_UUID, 'n' => $numero], 'column'
		);
		if (empty($uuid)) {
			responde(['erro' => "não existe pesquisa no número $numero"], 404);
		}

		// Fila que ainda aponta para esta pesquisa passaria a transferir para
		// um número que não existe, e aí o cliente ouve "número inválido" em
		// vez de desligar. Recusar é mais honesto que deixar quebrado.
		$apontam = $db->select(
			"select dialplan_number from v_dialplans "
			."where domain_uuid = :u and dialplan_xml like :p",
			['u' => $domain_uuid, 'p' => '%transfer_after_bridge=' . $numero . ':%'], 'all'
		) ?? [];
		if (!empty($apontam)) {
			$numeros = implode(', ', array_column($apontam, 'dialplan_number'));
			responde(['erro' => "a pesquisa $numero ainda é usada pela fila $numeros; "
				."tire a pesquisa da fila antes de remover"], 409);
		}

		$p = permissions::new();
		$p->add('dialplan_delete', 'temp');
		$db->execute("delete from v_dialplan_details where dialplan_uuid = :d", ['d' => $uuid]);
		$db->execute("delete from v_dialplans where dialplan_uuid = :d", ['d' => $uuid]);
		$p->delete('dialplan_delete', 'temp');

		self::recarregar(self::nome_do_dominio($domain_uuid));

		return ['pesquisa' => $numero, 'removido' => true];
	}

	/**
	 * `database::save()` não lança exceção: campo que ele não reconhece faz a
	 * linha não entrar e a chamada volta como se tivesse dado certo.
	 */
	private static function exigir_gravado(database $db, string $oque): void {
		$codigo = (int) ($db->message['code'] ?? 0);
		if ($codigo !== 200) {
			responde(['erro' => "o PABX não gravou $oque",
				'detalhe' => $db->message['message'] ?? null], 500);
		}
	}

	private static function dialplan(string $domain_uuid, string $uuid, string $dominio,
		string $numero, string $nome, string $audio, string $descricao): array {

		// Os argumentos do play_and_get_digits são separados por ESPAÇO
		// (mod_dptools.c:2841), nesta ordem: min, max, tentativas, espera,
		// terminadores, áudio, áudio-de-erro, variável, regex, espera-entre.
		// Nome de áudio com espaço quebraria isto; hoje é impossível porque
		// api_gravacao.php:89 sanea o nome com [^A-Za-z0-9_-].
		$coleta = implode(' ', [
			'1', '1', (string) self::TENTATIVAS, (string) self::ESPERA_RESPOSTA, '#',
			$audio, $audio, 'nota',
			'^[' . self::NOTA_MINIMA . '-' . self::NOTA_MAXIMA . ']$',
			(string) self::ESPERA_ENTRE_DIGITOS,
		]);

		$xml  = '<extension name="' . xml::sanitize($nome) . '" continue="" uuid="' . xml::sanitize($uuid) . '">' . "\n";
		$xml .= '	<condition field="destination_number" expression="^' . xml::sanitize($numero) . '$">' . "\n";
		$xml .= '		<action application="answer" data=""/>' . "\n";
		// Mesma constante do anúncio: sem o silêncio a operadora descarta o
		// começo da frase, porque o caminho de áudio até o celular ainda não
		// está pronto quando o nosso 200 OK sai.
		$xml .= '		<action application="playback" data="silence_stream://'
			. api_anuncio::ESPERA_ANTES_DO_AUDIO . '"/>' . "\n";
		$xml .= '		<action application="play_and_get_digits" data="' . xml::sanitize($coleta) . '"/>' . "\n";
		$xml .= '		<action application="lua" data="' . self::SCRIPT . '"/>' . "\n";
		$xml .= '		<action application="hangup" data=""/>' . "\n";
		$xml .= '	</condition>' . "\n";
		$xml .= '</extension>' . "\n";

		return [
			'dialplan_uuid'        => $uuid,
			'domain_uuid'          => $domain_uuid,
			'dialplan_name'        => $nome,
			'dialplan_number'      => $numero,
			'dialplan_context'     => $dominio,
			'dialplan_continue'    => 'false',
			'dialplan_xml'         => $xml,
			'dialplan_order'       => self::ORDEM_NO_PLANO,
			'dialplan_enabled'     => 'true',
			'dialplan_description' => $descricao,
			'app_uuid'             => self::APP_UUID,
		];
	}

	/** As quatro tabelas que dividem o plano de numeração, mais os planos. */
	private static function ocupado(string $domain_uuid, string $numero): bool {
		$consultas = [
			"select ivr_menu_uuid as u from v_ivr_menus where domain_uuid = :u and ivr_menu_extension = :n",
			"select extension_uuid as u from v_extensions where domain_uuid = :u and extension = :n",
			"select call_center_queue_uuid as u from v_call_center_queues where domain_uuid = :u and queue_extension = :n",
			"select ring_group_uuid as u from v_ring_groups where domain_uuid = :u and ring_group_extension = :n",
			"select dialplan_uuid as u from v_dialplans where domain_uuid = :u and dialplan_number = :n",
		];
		foreach ($consultas as $sql) {
			$achou = self::db()->select($sql, ['u' => $domain_uuid, 'n' => $numero], 'column');
			if (!empty($achou)) {
				return true;
			}
		}
		return false;
	}

	private static function proximo_livre(string $domain_uuid): ?string {
		for ($n = self::FAIXA_INICIO; $n <= self::FAIXA_FIM; $n++) {
			if (!self::ocupado($domain_uuid, (string) $n)) {
				return (string) $n;
			}
		}
		return null;
	}

	/**
	 * Plano gravado não basta: o FreeSWITCH serve o dialplan do cache, e o
	 * cache tem três variantes de chave -- só apagar a chave nua deixa a
	 * antiga viva. Mesma armadilha da fila e do anúncio.
	 */
	private static function recarregar(string $dominio): void {
		$cache = new cache();
		foreach (['dialplan:' . $dominio, $dominio . ':dialplan', 'dialplan'] as $chave) {
			$cache->delete($chave);
		}
		$socket = event_socket::create();
		if ($socket && $socket->is_connected()) {
			event_socket::api('reloadxml');
		}
	}
}
```

- [ ] **Passo 2: Registrar as rotas**

Em `app/simplificaja_api/index.php`, junto das rotas de anúncio (a partir da
linha 132), acrescentar três casos seguindo o mesmo padrão dos vizinhos:

```php
		case 'GET pesquisas':
			responde(['pesquisas' => api_pesquisa::listar($domain_uuid)]);

		case 'POST pesquisas':
			responde(api_pesquisa::criar($domain_uuid, $corpo));

		case 'DELETE pesquisas':
			responde(api_pesquisa::remover($domain_uuid, (string) ($corpo['numero'] ?? '')));
```

Conferir, ao escrever, como os vizinhos nomeiam `$corpo` e `$domain_uuid` — usar
exatamente os mesmos nomes, não supor.

- [ ] **Passo 3: Subir os dois arquivos**

```bash
scp -i ~/.ssh/id_ed25519 \
  app/simplificaja_api/resources/classes/api_pesquisa.php \
  root@109.123.250.200:/var/www/fusionpbx/app/simplificaja_api/resources/classes/
scp -i ~/.ssh/id_ed25519 app/simplificaja_api/index.php \
  root@109.123.250.200:/var/www/fusionpbx/app/simplificaja_api/
```

- [ ] **Passo 4: Conferir que o PHP não tem erro de sintaxe**

```bash
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "php -l /var/www/fusionpbx/app/simplificaja_api/resources/classes/api_pesquisa.php && php -l /var/www/fusionpbx/app/simplificaja_api/index.php"
```

Esperado: `No syntax errors detected` nas duas.

- [ ] **Passo 5: Gravar o áudio da pergunta no demo**

Pela aba Áudios do super admin do hub, no cliente do `demo`, enviar um áudio
com o nome `pesquisa`. Conferir que chegou:

```bash
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "ls -la /var/lib/freeswitch/recordings/demo.pabx.simplificaja.com.br/"
```

Esperado: `pesquisa.wav` na lista.

- [ ] **Passo 6: Criar a pesquisa pela API e conferir o número**

Usar a chave do `demo`. Esperado na resposta: `"numero"` entre 6000 e 6999.

- [ ] **Passo 7: Ler o XML gerado e conferir linha por linha**

```bash
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "su - postgres -c \"psql -At -d fusionpbx -c \\\"select dialplan_xml from v_dialplans where app_uuid = '1e9f0b45-d844-40ab-b900-aec2f465d408'\\\"\""
```

Conferir, nesta ordem: `answer`, `playback silence_stream://1500`,
`play_and_get_digits` com **dez** argumentos separados por espaço na ordem
documentada, `lua simplificaja_pesquisa.lua`, `hangup`. E que o `dialplan_order`
é `226`.

- [ ] **Passo 8: Commitar**

```bash
git add app/simplificaja_api/resources/classes/api_pesquisa.php app/simplificaja_api/index.php
git commit -m "feat(pesquisa): URA que pergunta a nota, na faixa 6000"
```

---

### Tarefa 3: O script que grava a nota

**Arquivos:**
- Criar: `scripts/simplificaja_pesquisa.lua`
- Alterar: `scripts/instalar.sh`

**Interfaces:**
- Consome: a tabela da tarefa 1; a variável `nota` que o
  `play_and_get_digits` da tarefa 2 deixa no canal; e as variáveis que o
  `mod_callcenter` grava no canal do cliente (`cc_agent`, `cc_queue`,
  `cc_agent_bridged`).
- Produz: uma linha em `v_simplificaja_pesquisas`, consumida pela tarefa 5.

- [ ] **Passo 1: Escrever o script**

Criar `scripts/simplificaja_pesquisa.lua`:

```lua
-- Grava a nota que o cliente digitou no fim da ligação.
--
-- Chamado pelo plano da pesquisa, depois do play_and_get_digits e antes do
-- hangup. Roda no canal do CLIENTE, que é onde o mod_callcenter deixou
-- `cc_agent`, `cc_queue` e `cc_agent_bridged` -- e essas variáveis sobrevivem
-- ao transfer_after_bridge porque são variáveis de canal.
--
-- Banco pelo helper nativo do FusionPBX, igual o call_flow.lua:39-49. NÃO usar
-- luasocket: esta instalação não tem, e foi por isso que o chatwoot_hangup.lua
-- usa mod_curl.
--
-- Tudo em pcall: falhar ao gravar uma nota não pode afetar a ligação.

local ok, err = pcall(function()
	if session == nil then
		return
	end

	local nota = session:getVariable("nota")
	if nota == nil or not nota:match("^[1-5]$") then
		-- Cliente desligou sem responder, ou errou as três tentativas. Não é
		-- erro: é ausência de resposta, e ausência não vira linha.
		return
	end

	-- Sem bridge não houve conversa, e não há o que avaliar. O
	-- transfer_after_bridge já garante isso, mas a checagem é barata e
	-- protege contra alguém apontar a pesquisa por outro caminho.
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

	-- `cc_agent` vem como `1001@dominio`; a tela mostra o ramal.
	local agente = session:getVariable("cc_agent") or ""
	local ramal = agente:match("^([^@]+)") or nil

	require "resources.functions.config"
	local Database = require "resources.functions.database"
	local dbh = Database.new('system')
	if not dbh then
		freeswitch.consoleLog("err", "[pesquisa] sem conexao com o banco\n")
		return
	end

	-- O escopo por usuário do portal compara extension_uuid
	-- (portal_data.php:31), então a nota guarda o uuid e não só o número.
	local extension_uuid = nil
	if ramal ~= nil then
		dbh:query(
			"select extension_uuid from v_extensions "
			.. "where domain_uuid = :d and extension = :e",
			{ d = domain_uuid, e = ramal },
			function(row) extension_uuid = row.extension_uuid end)
	end

	-- `on conflict do nothing`: o transfer_after_bridge se apaga ao ser
	-- consumido e dispara uma vez só, mas o indice unico é a segunda rede.
	dbh:query(
		"insert into v_simplificaja_pesquisas "
		.. "(pesquisa_uuid, domain_uuid, call_uuid, nota, ramal, extension_uuid, fila, telefone) "
		.. "values (gen_random_uuid(), :d, :c, :n, :r, :x, :f, :t) "
		.. "on conflict (call_uuid) do nothing",
		{
			d = domain_uuid,
			c = call_uuid,
			n = nota,
			r = ramal,
			x = extension_uuid,
			f = session:getVariable("cc_queue"),
			t = session:getVariable("caller_id_number"),
		})

	dbh:release()
	freeswitch.consoleLog("notice",
		"[pesquisa] nota " .. nota .. " do ramal " .. tostring(ramal) .. " gravada\n")
end)

if not ok then
	freeswitch.consoleLog("err", "[pesquisa] falhou: " .. tostring(err) .. "\n")
end
```

- [ ] **Passo 2: Conferir que `gen_random_uuid()` existe neste PostgreSQL**

É nativo a partir do PostgreSQL 13 e o servidor roda 18, mas confirmar em vez
de supor:

```bash
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "su - postgres -c \"psql -At -d fusionpbx -c 'select gen_random_uuid()'\""
```

Esperado: um uuid. Se der erro, trocar por `uuid_generate_v4()` e habilitar a
extensão — ou gerar o uuid no Lua.

- [ ] **Passo 3: Subir o script**

```bash
scp -i ~/.ssh/id_ed25519 scripts/simplificaja_pesquisa.lua \
  root@109.123.250.200:/usr/share/freeswitch/scripts/
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "chmod 644 /usr/share/freeswitch/scripts/simplificaja_pesquisa.lua && ls -la /usr/share/freeswitch/scripts/simplificaja_pesquisa.lua /usr/share/freeswitch/scripts/chatwoot_hangup.lua"
```

`chmod 644` e nada mais: é o que o `instalar.sh` faz com o
`chatwoot_hangup.lua` (linha 87), e o `ls` lado a lado confirma que os dois
ficaram iguais.

- [ ] **Passo 4: Registrar no instalador**

Em `scripts/instalar.sh`, logo depois do bloco do gancho (que termina na linha
91), acrescentar:

```bash
# O script da pesquisa não tem placeholder para substituir, então é cópia
# direta -- diferente do gancho, que leva URL e segredo por sed.
PESQUISA=/usr/share/freeswitch/scripts/simplificaja_pesquisa.lua
cp "$REPO/scripts/simplificaja_pesquisa.lua" "$PESQUISA"
chmod 644 "$PESQUISA"
ok "instalado em $PESQUISA"
```

- [ ] **Passo 5: Commitar**

```bash
git add scripts/simplificaja_pesquisa.lua scripts/instalar.sh
git commit -m "feat(pesquisa): script que grava a nota, com pcall e sem luasocket"
```

---

### Tarefa 4: A fila aponta para a pesquisa

**Arquivos:**
- Alterar: `app/simplificaja_api/resources/classes/api_fila.php`

**Interfaces:**
- Consome: o número da URA da tarefa 2.
- Produz: o `set transfer_after_bridge` no plano da fila, que é o que faz o
  FreeSWITCH levar o cliente para a pesquisa.

**Esta é a única tarefa que toca o caminho de ligação que já funciona.** O
passo 6 existe para provar que fila sem pesquisa continua idêntica.

- [ ] **Passo 1: Guardar o XML de hoje, para comparar depois**

```bash
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "su - postgres -c \"psql -At -d fusionpbx -c \\\"select dialplan_xml from v_dialplans where dialplan_name = 'Atendimento' and domain_uuid = (select domain_uuid from v_domains where domain_name = 'demo.pabx.simplificaja.com.br')\\\"\"" \
  > /tmp/fila-antes.xml
cat /tmp/fila-antes.xml
```

- [ ] **Passo 2: Adicionar o `set` no gerador do plano**

Em `api_fila.php`, no método `dialplan()`, **imediatamente depois** da linha do
`hangup_after_bridge=true` (hoje linha 368):

```php
		// Pesquisa de avaliação: ao fim do bridge -- quando o atendente
		// desliga -- o FreeSWITCH leva o cliente para cá em vez de derrubar.
		// Transferência entre ramais não dispara: o canal carrega CF_TRANSFER
		// e o bloco pós-bridge é pulado (switch_ivr_bridge.c:969).
		//
		// A SINTAXE USA DOIS-PONTOS, não espaço como a aplicação `transfer`:
		// switch_ivr_bridge.c:961 separa com ':'. Com espaço o log diz
		// "No extension specified" e a ligação cai.
		//
		// `hangup_after_bridge=true` continua acima e não muda: no código os
		// dois são `else if`, e o do transfer_after_bridge vem antes
		// (switch_ivr_bridge.c:1929). Fila sem pesquisa gera o XML de sempre.
		$pesquisa = trim((string) ($dados['pesquisa'] ?? ''));
		if ($pesquisa !== '') {
			$xml .= '		<action application="set" data="transfer_after_bridge='
				. xml::sanitize($pesquisa) . ':XML:' . $dominio . '"/>' . "\n";
		}
```

- [ ] **Passo 3: Subir e conferir a sintaxe**

```bash
scp -i ~/.ssh/id_ed25519 app/simplificaja_api/resources/classes/api_fila.php \
  root@109.123.250.200:/var/www/fusionpbx/app/simplificaja_api/resources/classes/
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "php -l /var/www/fusionpbx/app/simplificaja_api/resources/classes/api_fila.php"
```

- [ ] **Passo 4: Salvar a fila do demo COM a pesquisa**

Pelo `POST fila-editar`, mandando os mesmos dados de hoje mais
`pesquisa: "<numero da tarefa 2>"`.

- [ ] **Passo 5: Conferir o XML gerado — com dois-pontos**

```bash
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "su - postgres -c \"psql -At -d fusionpbx -c \\\"select dialplan_xml from v_dialplans where dialplan_name = 'Atendimento' and domain_uuid = (select domain_uuid from v_domains where domain_name = 'demo.pabx.simplificaja.com.br')\\\"\"" \
  | grep transfer_after_bridge
```

Esperado exatamente: `transfer_after_bridge=6000:XML:demo.pabx.simplificaja.com.br`
(com o número real). **Se houver espaço em vez de dois-pontos, parar e
corrigir** — não ligar antes de acertar isto.

- [ ] **Passo 6: Provar que fila SEM pesquisa não mudou**

Salvar a fila de novo, agora **sem** o campo `pesquisa`, e comparar com o
guardado no passo 1:

```bash
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "su - postgres -c \"psql -At -d fusionpbx -c \\\"select dialplan_xml from v_dialplans where dialplan_name = 'Atendimento' and domain_uuid = (select domain_uuid from v_domains where domain_name = 'demo.pabx.simplificaja.com.br')\\\"\"" \
  > /tmp/fila-depois.xml
diff /tmp/fila-antes.xml /tmp/fila-depois.xml && echo "IDENTICO: nada que funciona mudou"
```

Esperado: sem diferença. Se houver, **parar** — a mudança não é aditiva como
deveria.

- [ ] **Passo 7: Religar a pesquisa na fila**

Repetir o passo 4, para a validação da tarefa 6 ter o que testar.

- [ ] **Passo 8: Commitar**

```bash
git add app/simplificaja_api/resources/classes/api_fila.php
git commit -m "feat(pesquisa): fila leva o cliente para a pesquisa quando o atendente desliga"
```

---

### Tarefa 5: A aba no portal

**Arquivos:**
- Alterar: `app/simplificaja_portal/resources/classes/portal_data.php`
- Alterar: `app/simplificaja_portal/index.php`
- Alterar: `scripts/configurar-portal.sql`

**Interfaces:**
- Consome: as linhas que a tarefa 3 grava.

- [ ] **Passo 1: Adicionar as consultas**

Em `portal_data.php`, no estilo de `call_count` e `call_history` — sempre
filtrando por `$this->domain_uuid` e sempre passando por
`extension_scope_sql`:

```php
	public function rating_summary(DateTimeImmutable $since): array {
		$sql = "select count(*) as respostas, round(avg(p.nota), 2) as media ";
		$sql .= "from v_simplificaja_pesquisas p ";
		$sql .= "where p.domain_uuid = :domain_uuid and p.criado_em >= :since ";
		$parameters = ['domain_uuid' => $this->domain_uuid, 'since' => $since->format('Y-m-d H:i:sP')];
		$sql .= $this->extension_scope_sql('p.extension_uuid', $parameters, $this->cdr_domain_view);
		$row = $this->database->select($sql, $parameters, 'row');
		return is_array($row) ? $row : ['respostas' => 0, 'media' => null];
	}

	public function rating_distribution(DateTimeImmutable $since): array {
		$sql = "select p.nota, count(*) as total from v_simplificaja_pesquisas p ";
		$sql .= "where p.domain_uuid = :domain_uuid and p.criado_em >= :since ";
		$parameters = ['domain_uuid' => $this->domain_uuid, 'since' => $since->format('Y-m-d H:i:sP')];
		$sql .= $this->extension_scope_sql('p.extension_uuid', $parameters, $this->cdr_domain_view);
		$sql .= "group by p.nota order by p.nota desc";
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	public function rating_by_extension(DateTimeImmutable $since): array {
		$sql = "select coalesce(p.ramal, 'sem ramal') as ramal, count(*) as respostas, ";
		$sql .= "round(avg(p.nota), 2) as media from v_simplificaja_pesquisas p ";
		$sql .= "where p.domain_uuid = :domain_uuid and p.criado_em >= :since ";
		$parameters = ['domain_uuid' => $this->domain_uuid, 'since' => $since->format('Y-m-d H:i:sP')];
		$sql .= $this->extension_scope_sql('p.extension_uuid', $parameters, $this->cdr_domain_view);
		$sql .= "group by coalesce(p.ramal, 'sem ramal') order by media asc";
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	public function rating_history(int $days, string $search): array {
		$since = new DateTimeImmutable('-'.max(1, min($days, 90)).' days');
		$sql = "select p.criado_em, p.nota, p.ramal, p.fila, p.telefone ";
		$sql .= "from v_simplificaja_pesquisas p ";
		$sql .= "where p.domain_uuid = :domain_uuid and p.criado_em >= :since ";
		$parameters = ['domain_uuid' => $this->domain_uuid, 'since' => $since->format('Y-m-d H:i:sP')];
		$sql .= $this->extension_scope_sql('p.extension_uuid', $parameters, $this->cdr_domain_view);
		if ($search !== '') {
			$sql .= "and (p.telefone ilike :search or p.ramal ilike :search) ";
			$parameters['search'] = '%'.$search.'%';
		}
		$sql .= "order by p.criado_em desc limit 200";
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}
```

A taxa de resposta sai no `index.php` dividindo `rating_summary()['respostas']`
por `call_count($since)` — não precisa de consulta nova.

- [ ] **Passo 2: Liberar a view no index**

Em `app/simplificaja_portal/index.php:21`, incluir `'ratings'` na lista branca:

```php
if (!in_array($view, ['dashboard', 'calls', 'extensions', 'ratings'], true)) {
```

- [ ] **Passo 3: Adicionar o botão no menu lateral**

Em `index.php`, depois da linha do botão de Ramais (hoje linha 153),
acrescentar — é a mesma linha com `ratings` e `fa-star`:

```php
		<a class="sj-nav-button <?= $view === 'ratings' ? 'active' : '' ?>" data-view="ratings" href="?view=ratings" title="Avaliações" <?= $view === 'ratings' ? 'data-secao-ativa="true" aria-current="page"' : '' ?>><i class="fas fa-star"></i><span class="sj-nav-label">Avaliações</span></a>
```

O `data-secao-ativa` não é decoração: a navegação por JavaScript do portal lê
esse atributo (`index.php:314` e `:390`) para saber qual botão marcar depois de
trocar de aba. Sem ele, a aba abre e nenhum botão fica aceso.

- [ ] **Passo 4: Carregar os dados da aba**

No bloco de preparação (junto do `if ($view === 'dashboard')` da linha 58),
acrescentar:

```php
elseif ($view === 'ratings') {
	$since = new DateTimeImmutable('-30 days');
	$rating_summary = $portal->rating_summary($since);
	$rating_distribution = $portal->rating_distribution($since);
	$rating_by_extension = $portal->rating_by_extension($since);
	$rating_history = $portal->rating_history(30, (string) ($_GET['search'] ?? ''));
	// Taxa de resposta: quantos avaliaram, de quantos foram atendidos. Sem
	// consulta nova -- `call_count` já existe e já respeita o escopo.
	$answered = $portal->call_count($since) - $portal->call_count($since, true);
	$rating_rate = $answered > 0
		? round(((int) $rating_summary['respostas'] / $answered) * 100)
		: null;
}
```

- [ ] **Passo 5: Renderizar a aba**

No bloco de views, depois do `elseif ($view === 'extensions')`. Usa só classes
que o portal já tem (`sj-card`, `sj-stats`, `sj-stat`, `sj-ext-bar`,
`sj-ext-note`, `sj-badge`) e os helpers já definidos no arquivo (`$e` para
escapar, `$display_time` para a data no fuso certo):

```php
<?php elseif ($view === 'ratings'): ?>
	<div class="sj-action"><span class="sj-title">Avaliações</span></div>
	<div class="sj-toolbar">
		<span class="sj-muted">Nota que o cliente digitou no fim da ligação · últimos 30 dias</span>
		<span class="sj-muted"><?= (int) $rating_summary['respostas'] ?> respostas</span>
	</div>
	<div class="sj-grid">
		<section class="sj-card">
			<div class="sj-card-head"><span>Nota média</span><i class="fas fa-star"></i></div>
			<div class="sj-stats">
				<div class="sj-stat green"><strong><?= $rating_summary['media'] === null ? '—' : $e(number_format((float) $rating_summary['media'], 2, ',', '')) ?></strong><span>de 5</span></div>
				<div class="sj-stat purple"><strong><?= $rating_rate === null ? '—' : $rating_rate.'%' ?></strong><span>responderam</span></div>
			</div>
			<div class="sj-ext-note">Ligação atendida em que o cliente não digitou nada não entra na média.</div>
		</section>
		<section class="sj-card">
			<div class="sj-card-head"><span>Distribuição</span><i class="fas fa-chart-simple"></i></div>
			<?php if (empty($rating_distribution)): ?>
				<div class="sj-ext-note">Nenhuma avaliação no período.</div>
			<?php else: ?>
				<?php $maior = max(array_column($rating_distribution, 'total')); ?>
				<?php foreach ($rating_distribution as $faixa): ?>
					<div class="sj-ext-note"><?= (int) $faixa['nota'] ?> — <?= (int) $faixa['total'] ?></div>
					<div class="sj-ext-bar"><span style="width:<?= $maior > 0 ? round(((int) $faixa['total'] / $maior) * 100) : 0 ?>%"></span></div>
				<?php endforeach; ?>
			<?php endif; ?>
		</section>
		<section class="sj-card wide">
			<div class="sj-card-head"><span>Por atendente</span><span style="font-weight:400;color:#737a80">menor nota primeiro</span></div>
			<?php if (empty($rating_by_extension)): ?>
				<div class="sj-ext-note">Nenhuma avaliação no período.</div>
			<?php endif; ?>
			<?php foreach ($rating_by_extension as $linha): ?>
				<div class="sj-missed">
					<strong>Ramal <?= $e((string) $linha['ramal']) ?></strong>
					<span><?= (int) $linha['respostas'] ?> respostas</span>
					<span class="sj-badge"><?= $e(number_format((float) $linha['media'], 2, ',', '')) ?></span>
				</div>
			<?php endforeach; ?>
		</section>
	</div>
	<section class="sj-chart-card">
		<div class="sj-card-head"><span>Avaliações recebidas</span><span class="sj-muted">últimos 30 dias</span></div>
		<?php if (empty($rating_history)): ?>
			<div class="sj-ext-note">Nenhuma avaliação ainda. A pesquisa só toca quando o atendente encerra a ligação.</div>
		<?php endif; ?>
		<?php foreach ($rating_history as $avaliacao): ?>
			<div class="sj-missed">
				<strong><?= $e((string) ($avaliacao['telefone'] ?: 'Número indisponível')) ?></strong>
				<span>Ramal <?= $e((string) ($avaliacao['ramal'] ?: '—')) ?><?= $avaliacao['fila'] ? ' · '.$e((string) $avaliacao['fila']) : '' ?></span>
				<time><?= $e($display_time($avaliacao['criado_em'])) ?></time>
				<span class="sj-badge"><?= (int) $avaliacao['nota'] ?></span>
			</div>
		<?php endforeach; ?>
	</section>
<?php endif; ?>
```

Conferir, ao colar, onde termina o `endif` da cadeia de views que já existe —
este bloco entra **antes** dele, não depois, ou a aba nunca renderiza.

Conferir também que `$display_time` aceita um `timestamptz` do Postgres. Ele é
usado hoje com `start_stamp` do `v_xml_cdr`, que é do mesmo tipo
(`index.php:95`), mas vale olhar a implementação antes de confiar.

- [ ] **Passo 6: Adicionar o item de menu no SQL**

Em `scripts/configurar-portal.sql`. A idempotência ali **não** vem de
`where not exists`: vem de apagar e reinserir UUIDs reservados. Então o quarto
item entra nos cinco lugares onde os três já aparecem, com os UUIDs da mesma
família (`...641014`, `...641004`, `...641024`, `...641037`, `...641038`):

Nos três `delete ... where menu_item_uuid in (...)`, acrescentar à lista:

```sql
  'd24e33c8-0b88-4aaf-9c3c-5d996b641014'
```

No `insert into v_menu_items`, acrescentar a quarta linha (mesmo
`menu_item_parent_uuid` de Ligações e Ramais, ordem 3):

```sql
  ('d24e33c8-0b88-4aaf-9c3c-5d996b641014', 'b4750c3f-2a86-b00d-b7d0-345c14eca286', '2fbe35e3-c82e-411f-b357-48e4c17d3add', 'd24e33c8-0b88-4aaf-9c3c-5d996b641004', 'Avaliações', '/app/simplificaja_portal/index.php?view=ratings', 'fa-solid fa-star', 'internal', 3, now());
```

No `insert into v_menu_item_groups`:

```sql
  ('d24e33c8-0b88-4aaf-9c3c-5d996b641024', 'b4750c3f-2a86-b00d-b7d0-345c14eca286', 'd24e33c8-0b88-4aaf-9c3c-5d996b641014', 'user', 'e5ab28b7-1aa7-4e65-ad5e-6adbd9222e76', now());
```

No `insert into v_menu_languages`, as duas línguas:

```sql
  ('d24e33c8-0b88-4aaf-9c3c-5d996b641037', 'b4750c3f-2a86-b00d-b7d0-345c14eca286', 'd24e33c8-0b88-4aaf-9c3c-5d996b641014', 'en-us', 'Ratings', now()),
  ('d24e33c8-0b88-4aaf-9c3c-5d996b641038', 'b4750c3f-2a86-b00d-b7d0-345c14eca286', 'd24e33c8-0b88-4aaf-9c3c-5d996b641014', 'pt-br', 'Avaliações', now());
```

Atenção ao mover a vírgula e o `;`: as listas de `values` terminam com `;` na
última linha, e acrescentar no fim exige trocar o `;` da linha anterior por
vírgula. Erro aqui faz o `psql` abortar — o que é bom, porque o script roda com
`ON_ERROR_STOP=1`.

- [ ] **Passo 7: Subir e aplicar**

```bash
scp -i ~/.ssh/id_ed25519 app/simplificaja_portal/index.php \
  root@109.123.250.200:/var/www/fusionpbx/app/simplificaja_portal/
scp -i ~/.ssh/id_ed25519 app/simplificaja_portal/resources/classes/portal_data.php \
  root@109.123.250.200:/var/www/fusionpbx/app/simplificaja_portal/resources/classes/
scp -i ~/.ssh/id_ed25519 scripts/configurar-portal.sql root@109.123.250.200:/tmp/
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "php -l /var/www/fusionpbx/app/simplificaja_portal/index.php && php -l /var/www/fusionpbx/app/simplificaja_portal/resources/classes/portal_data.php && chmod 644 /tmp/configurar-portal.sql && su - postgres -c 'psql -v ON_ERROR_STOP=1 -d fusionpbx -f /tmp/configurar-portal.sql' && rm -f /tmp/configurar-portal.sql"
```

- [ ] **Passo 8: Conferir que o menu tem quatro itens**

```bash
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "su - postgres -c \"psql -At -d fusionpbx -c \\\"select menu_item_title from v_menu_items where menu_item_link like '%simplificaja_portal%'\\\"\""
```

Esperado: `Visão geral`, `Ligações`, `Ramais`, `Avaliações`.

- [ ] **Passo 9: Commitar**

```bash
git add app/simplificaja_portal scripts/configurar-portal.sql
git commit -m "feat(pesquisa): aba Avaliacoes no portal do cliente, com nota por ramal"
```

---

### Tarefa 6: Validação de aceite, com ligação real

**Arquivos:** só `docs/roteiro-de-validacao-pabx.md`, no último passo.

Esta tarefa fecha a "Validação de aceite" da spec. Três dos onze itens de lá já
foram cobertos nas tarefas anteriores, por estarem no momento certo do trabalho:
a faixa do número (T2 passo 6), o XML com dois-pontos (T4 passo 5) e a prova de
que fila sem pesquisa não mudou (T4 passo 6). **O passo 3 abaixo é o que carrega
o peso** — é o requisito que originou o spec — e nenhum passo pode ser
dispensado.

Um item da spec **não é verificável agora**: "rodar `instalar.sh` num servidor
limpo". Não existe servidor limpo hoje. O que esta tarefa garante é que o
script está citado no instalador (T1 passo 6 e T3 passo 4); a prova real
acontece na migração para a VPS brasileira, e é por isso que o
`docs/migrar-servidor.md` existe. Anotar isso lá como item de conferência em vez
de marcar como feito aqui.

- [ ] **Passo 1: Deixar o log aberto numa janela**

```bash
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "tail -f /var/log/freeswitch/freeswitch.log | grep -a --line-buffered -E 'pesquisa|transfer_after_bridge|play_and_get_digits'"
```

- [ ] **Passo 2: Ligar, atender, e o ATENDENTE desligar**

Digitar `4`. Conferir no banco:

```bash
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "su - postgres -c \"psql -d fusionpbx -c 'select nota, ramal, fila, telefone, criado_em from v_simplificaja_pesquisas order by criado_em desc limit 3'\""
```

Esperado: uma linha com `nota = 4`, o ramal que atendeu e a fila preenchidos.

- [ ] **Passo 3: Ligar, atender e TRANSFERIR para outro ramal**

Este é o passo que valida o requisito que originou tudo. Esperado: **a pesquisa
não toca** e **nenhuma linha nova** aparece no banco. Se a pesquisa tocar no
meio do atendimento, parar e reabrir a spec.

- [ ] **Passo 4: Ligar e desligar antes de atender**

Esperado: nenhuma pesquisa, nenhuma linha. (E, se a fila tiver estouro, o
estouro acontece como antes.)

- [ ] **Passo 5: Ligar, atender, atendente desliga, e desligar sem digitar**

Esperado: nenhuma linha. No log, nada em vermelho.

- [ ] **Passo 6: Digitar `9` três vezes**

Esperado: nenhuma linha, a ligação encerra, e nenhum `[ERR]` no log.

- [ ] **Passo 7: Abrir o portal como usuário `user` do demo**

A aba Avaliações mostra a nota 4 do passo 2, com o ramal. Conferir que a média
e a taxa de resposta fazem sentido com o número de ligações do período.

- [ ] **Passo 8: Conferir o isolamento entre clientes**

```bash
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "su - postgres -c \"psql -At -d fusionpbx -c 'select count(distinct domain_uuid) from v_simplificaja_pesquisas'\""
```

Esperado: `1` — só o demo tem notas. Nenhuma outra fila recebeu o `set`.

- [ ] **Passo 9: Apagar um cliente de teste e conferir zero órfãos**

Criar um cliente descartável, gerar uma nota nele, apagar pelo super admin do
hub, e então:

```bash
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "su - postgres -c \"psql -At -d fusionpbx -c 'select count(*) from v_simplificaja_pesquisas p where not exists (select 1 from v_domains d where d.domain_uuid = p.domain_uuid)'\""
```

Esperado: `0`. Isto prova que o prefixo `v_` fez a varredura alcançar a tabela.

- [ ] **Passo 10: Conferir que o FreeSWITCH não reiniciou durante os testes**

```bash
ssh -i ~/.ssh/id_ed25519 root@109.123.250.200 \
  "systemctl show freeswitch -p NRestarts"
```

Comparar com o valor de antes dos testes. Tem de ser o mesmo — igual à
verificação que fechou o crash da fila.

- [ ] **Passo 11: Dar baixa na documentação**

Acrescentar a pesquisa ao `docs/roteiro-de-validacao-pabx.md`, como uma fase
nova, para ela entrar no roteiro do cliente do zero.

```bash
git add docs/roteiro-de-validacao-pabx.md
git commit -m "docs: pesquisa de avaliacao no roteiro de validacao"
```

---

## O que este plano deliberadamente não faz

- Pesquisa em destino que não seja fila (ramal direto, anúncio, menu). Ramal
  direto passa pelo `local_extension` do FusionPBX, que não é nosso.
- Comentário em áudio depois da nota.
- Qualquer envio da nota para o Chatwoot. A coluna `call_uuid` existe para
  tornar isso um `join` por uma chave no dia que você pedir — mas o dia não é
  este.
- Tela da pesquisa no super admin do hub. A pesquisa é criada pela API; se você
  quiser configurá-la pela ficha do cliente, é trabalho à parte, no outro
  repositório.
