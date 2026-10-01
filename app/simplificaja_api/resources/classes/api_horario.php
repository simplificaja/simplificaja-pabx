<?php

/**
 * Horário de atendimento: um fluxo por janela.
 *
 * O cliente não quer "um fluxo com remendos de horário": quer que a ligação de
 * terça às 10h siga um caminho e a de domingo às 3h siga outro. Então o horário
 * é um DESTINO como anúncio, menu, fila ou ramal -- aparece em todo "vai para",
 * e o caminho da ligação bifurca sozinho.
 *
 * ── O que faz a ordem valer ──────────────────────────────────────────────
 *
 * `break="on-true"` em cada condição. Confirmado em mod_dialplan_xml.c:584:
 *
 *   if (((anti_action == SWITCH_FALSE && do_break_i == BREAK_ON_TRUE) || ...
 *
 * `anti_action == SWITCH_FALSE` significa que a condição CASOU. Então com
 * `on-true`: casou, executa as ações e PARA; não casou, segue para a próxima.
 * É exatamente "a primeira regra que casa vence".
 *
 * O padrão é `break="on-false"`, que dá E-lógico entre condições -- serve para
 * a condição do número e NÃO para as alternativas. Trocar isso não dá erro:
 * passa a atender no horário errado, calado.
 *
 * ── Por que XML cru e não linhas de detalhe ──────────────────────────────
 *
 * O app de condição de horário do FusionPBX guarda tudo em `v_dialplan_details`
 * e o XML é montado pelo gerador deles. Duas coisas aqui dependem de atributo
 * exato: o `break="on-true"` acima, e `wday` com `time-of-day` no MESMO
 * `<condition>` -- em condições separadas a semântica vira ordenada e a regra
 * casa quando não devia. Escrever o XML é garantir os dois. Mesmo caminho que
 * `api_anuncio` já usa, e o APP_UUID é nosso para a tela deles não reivindicar
 * e sobrescrever na primeira gravação.
 *
 * ── Fuso ─────────────────────────────────────────────────────────────────
 *
 * `wday` e `time-of-day` obedecem à variável de canal `timezone` (macro
 * check_tz(), mod_dialplan_xml.c:86-96). A faixa de datas NÃO obedece: o `ts`
 * vai cru para `switch_fulldate_cmp` (switch_xml.c:3160-3210), então ela usa o
 * fuso do sistema -- que é por isso que o servidor está em America/Sao_Paulo.
 * O `set timezone` aqui é defesa para o dia em que o servidor mudar.
 */
class api_horario {
	/** Nosso, não o do app de condição de horário do FusionPBX: ver o cabeçalho. */
	const APP_UUID = 'c3d8b215-6e74-4a19-9f05-2b7e41c6a83d';

	/** Faixa livre: ramais em 1001+, anúncios e URAs automáticos em 8000-8999,
	 *  filas e menus escolhidos por quem monta. */
	const FAIXA_INICIO = 7000;
	const FAIXA_FIM = 7999;

	const FUSO = 'America/Sao_Paulo';

	/** Depois do anúncio (220) e antes da rota de entrada (230): horário é
	 *  destino interno, alcançado por transferência. */
	const ORDEM_NO_PLANO = '225';

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

	/** As quatro tabelas que dividem o plano de numeração, mais os planos de
	 *  discagem -- onde moram anúncio e horário. O FreeSWITCH atende pelo
	 *  primeiro plano que casar, e colisão aparece como "a ligação foi para o
	 *  lugar errado", não como erro. */
	private static function ocupado(string $domain_uuid, string $numero): bool {
		$consultas = [
			"select ivr_menu_uuid as u from v_ivr_menus where domain_uuid = :u and ivr_menu_extension = :n",
			"select extension_uuid as u from v_extensions where domain_uuid = :u and extension = :n",
			"select call_center_queue_uuid as u from v_call_center_queues where domain_uuid = :u and queue_extension = :n",
			"select ring_group_uuid as u from v_ring_groups where domain_uuid = :u and ring_group_extension = :n",
			"select dialplan_uuid as u from v_dialplans where domain_uuid = :u and dialplan_number = :n",
		];
		foreach ($consultas as $sql) {
			if (!empty(self::db()->select($sql, ['u' => $domain_uuid, 'n' => $numero], 'column'))) {
				return true;
			}
		}
		return false;
	}

	private static function proximo_livre(string $domain_uuid): string {
		for ($n = self::FAIXA_INICIO; $n <= self::FAIXA_FIM; $n++) {
			if (!self::ocupado($domain_uuid, (string) $n)) {
				return (string) $n;
			}
		}
		responde(['erro' => 'não há número livre na faixa '
			. self::FAIXA_INICIO . '-' . self::FAIXA_FIM], 409);
	}

	// ── validação ───────────────────────────────────────────────────────────

	private static function hora_valida(string $v): bool {
		return (bool) preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $v);
	}

	private static function data_valida(string $v): bool {
		return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)
			&& checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4));
	}

	/**
	 * A última regra tem de ser o "resto".
	 *
	 * Sem ela, a ligação que não casa com nenhuma faixa sai do plano e morre
	 * sem tocar em ninguém -- a falha silenciosa que o horário existe para
	 * evitar. Exigir aqui é mais barato que descobrir num domingo.
	 */
	private static function validar(array $regras): array {
		if (empty($regras)) {
			responde(['erro' => 'o horário precisa de pelo menos uma regra'], 422);
		}
		$ultimo = count($regras) - 1;
		if (($regras[$ultimo]['tipo'] ?? '') !== 'resto') {
			responde(['erro' => 'a última regra tem de ser o "resto": sem ela a ligação fora '
				. 'de todas as faixas morre sem tocar em ninguém'], 422);
		}

		foreach ($regras as $i => $regra) {
			$onde = 'regra ' . ($i + 1);
			if (empty($regra['destino'])) {
				responde(['erro' => "$onde sem destino"], 422);
			}
			switch ($regra['tipo'] ?? '') {
				case 'semana':
					$dias = array_map('intval', (array) ($regra['dias'] ?? []));
					if (empty($dias)) {
						responde(['erro' => "$onde: escolha pelo menos um dia da semana"], 422);
					}
					foreach ($dias as $dia) {
						if ($dia < 1 || $dia > 7) {
							responde(['erro' => "$onde: dia da semana vai de 1 (domingo) a 7 (sábado)"], 422);
						}
					}
					if (!self::hora_valida((string) ($regra['das'] ?? ''))
						|| !self::hora_valida((string) ($regra['ate'] ?? ''))) {
						responde(['erro' => "$onde: hora no formato HH:MM"], 422);
					}
					// Faixa que vira a meia-noite precisa de duas regras: o
					// `time-of-day` do FreeSWITCH não aceita início maior que
					// fim, e aceitar aqui geraria uma condição que nunca casa.
					if ((string) $regra['das'] >= (string) $regra['ate']) {
						responde(['erro' => "$onde: a hora de início tem de ser menor que a de fim. "
							. 'Faixa que atravessa a meia-noite precisa de duas regras'], 422);
					}
					break;
				case 'datas':
					if (!self::data_valida((string) ($regra['inicio'] ?? ''))
						|| !self::data_valida((string) ($regra['fim'] ?? ''))) {
						responde(['erro' => "$onde: data no formato AAAA-MM-DD"], 422);
					}
					if ((string) $regra['inicio'] > (string) $regra['fim']) {
						responde(['erro' => "$onde: a data de início é depois da de fim"], 422);
					}
					break;
				case 'resto':
					if ($i !== $ultimo) {
						responde(['erro' => 'só a última regra pode ser o "resto"'], 422);
					}
					break;
				default:
					responde(['erro' => "$onde: tipo deve ser semana, datas ou resto"], 422);
			}
		}
		return $regras;
	}

	// ── montagem do XML ─────────────────────────────────────────────────────

	/**
	 * `wday` do FreeSWITCH é 1=domingo a 7=sábado.
	 *
	 * Dias consecutivos viram faixa (`2-6`), o resto vira lista (`2,4,6`). O
	 * FreeSWITCH aceita as duas; a faixa é o que a tela nativa deles mostra, e
	 * é o que se lê mais rápido no XML quando alguém for depurar.
	 */
	private static function dias_em_texto(array $dias): string {
		$dias = array_values(array_unique($dias));
		sort($dias);
		$consecutivos = count($dias) > 1;
		for ($i = 1; $i < count($dias); $i++) {
			if ($dias[$i] !== $dias[$i - 1] + 1) {
				$consecutivos = false;
				break;
			}
		}
		return $consecutivos
			? $dias[0] . '-' . $dias[count($dias) - 1]
			: implode(',', $dias);
	}

	/**
	 * Os atributos da condição de uma regra, já como texto do XML.
	 *
	 * Tudo num `<condition>` só: `wday` e `time-of-day` separados em duas
	 * condições deixariam de ser "sexta ENTRE 8 e 18" e passariam a ser
	 * "sexta, OU qualquer dia entre 8 e 18".
	 */
	private static function atributos_da_regra(array $regra, string $numero): string {
		switch ($regra['tipo']) {
			case 'semana':
				return 'wday="' . xml::sanitize(self::dias_em_texto(array_map('intval', $regra['dias']))) . '"'
					. ' time-of-day="' . xml::sanitize($regra['das'] . ':00-' . $regra['ate'] . ':00') . '"';
			case 'datas':
				// `INICIO~FIM`, e várias faixas numa condição separadas por
				// vírgula (switch_fulldate_cmp, switch_utils.c:3640-3651).
				return 'date-time="' . xml::sanitize($regra['inicio'] . ' 00:00:00~'
					. $regra['fim'] . ' 23:59:59') . '"';
			default:
				// O "resto": repete a condição do número, que já sabemos
				// verdadeira porque a primeira condição do plano só deixa
				// passar este número. Mais seguro do que depender da semântica
				// de `<condition>` sem atributo nenhum.
				return 'field="destination_number" expression="^' . xml::sanitize($numero) . '$"';
		}
	}

	private static function dialplan(string $domain_uuid, string $uuid, string $dominio,
		string $numero, string $nome, array $regras, string $descricao): array {

		$xml  = '<extension name="' . xml::sanitize($nome) . '" continue="false" uuid="' . xml::sanitize($uuid) . '">' . "\n";
		// Primeira condição SEM atributo `break`, ou seja `on-false`: número
		// diferente para aqui, e o plano não faz nada. Isto é o portão.
		//
		// Com `break="never"` o portão deixa de existir: número que não casa
		// segue para as condições de tempo, uma delas casa, e TODA ligação do
		// contexto é desviada. Aconteceu na primeira versão disto, e só apareceu
		// porque o XML foi conferido atributo por atributo -- não dá erro em
		// lugar nenhum, a ligação só vai para o lugar errado.
		//
		// `inline="true"` no `set` é obrigatório: sem ele a ação é enfileirada
		// para depois da montagem do plano, e as condições de tempo abaixo já
		// teriam sido avaliadas com o fuso do servidor. O `check_tz()` relê a
		// variável de canal a cada condição (mod_dialplan_xml.c:86-96), então
		// definir aqui, inline, chega em tempo.
		$xml .= '	<condition field="destination_number" expression="^' . xml::sanitize($numero) . '$">' . "\n";
		$xml .= '		<action application="set" data="timezone=' . self::FUSO . '" inline="true"/>' . "\n";
		$xml .= '	</condition>' . "\n";

		foreach ($regras as $regra) {
			$xml .= '	<condition ' . self::atributos_da_regra($regra, $numero) . ' break="on-true">' . "\n";
			$xml .= '		<action application="transfer" data="' . xml::sanitize($regra['destino'])
				. ' XML ' . xml::sanitize($dominio) . '"/>' . "\n";
			$xml .= '	</condition>' . "\n";
		}
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

	// ── API ─────────────────────────────────────────────────────────────────

	public static function criar(string $domain_uuid, array $dados): array {
		$nome = trim((string) ($dados['nome'] ?? ''));
		if ($nome === '') {
			responde(['erro' => 'nome é obrigatório'], 422);
		}
		$regras = self::validar((array) ($dados['regras'] ?? []));

		$dominio = self::nome_do_dominio($domain_uuid);
		$numero = self::proximo_livre($domain_uuid);

		$permissoes = ['dialplan_add', 'dialplan_edit'];
		$p = permissions::new();
		foreach ($permissoes as $permissao) {
			$p->add($permissao, 'temp');
		}

		$db = self::db();
		$array = [];
		$array['dialplans'][0] = self::dialplan($domain_uuid, uuid(), $dominio, $numero, $nome,
			$regras, (string) ($dados['descricao'] ?? ''));
		$db->save($array);
		self::exigir_gravado($db, "horário $nome");
		unset($array);

		foreach ($permissoes as $permissao) {
			$p->delete($permissao, 'temp');
		}

		self::recarregar($dominio);
		return ['horario' => $nome, 'numero' => $numero, 'regras' => count($regras)];
	}

	/**
	 * As regras lidas do XML, não de tabela paralela.
	 *
	 * Tabela paralela sai de sincronia com o plano de discagem, e aí a tela
	 * mostra uma coisa e a ligação faz outra -- o pior defeito possível numa
	 * tela de diagnóstico. Mesmo princípio do `destino` do anúncio.
	 */
	public static function listar(string $domain_uuid): array {
		$linhas = self::db()->select(
			"select dialplan_uuid, dialplan_name, dialplan_number, dialplan_description, "
			."dialplan_enabled, coalesce(length(dialplan_xml), 0) as xml_bytes, dialplan_xml "
			."from v_dialplans where domain_uuid = :u and app_uuid = :a "
			."order by dialplan_number",
			['u' => $domain_uuid, 'a' => self::APP_UUID], 'all'
		) ?? [];

		foreach ($linhas as &$linha) {
			$linha['regras'] = self::extrair_regras((string) ($linha['dialplan_xml'] ?? ''));
			// O XML inteiro não serve para a tela e é o maior campo da resposta.
			unset($linha['dialplan_xml']);
		}
		return $linhas;
	}

	/**
	 * Devolve as regras no mesmo formato que `criar` recebe.
	 *
	 * Só as condições com `break="on-true"` são regras: a primeira condição do
	 * plano é a do número com o `set timezone`, e ela tem `break="never"`.
	 */
	private static function extrair_regras(string $xml): array {
		if ($xml === '') {
			return [];
		}
		$achou = preg_match_all(
			'/<condition\s+([^>]*?)break="on-true"[^>]*>(.*?)<\/condition>/s',
			$xml, $blocos, PREG_SET_ORDER
		);
		if (!$achou) {
			return [];
		}

		$regras = [];
		foreach ($blocos as $bloco) {
			$atributos = $bloco[1];
			$destino = preg_match('/application="transfer" data="([^" ]+)/', $bloco[2], $m)
				? $m[1] : null;

			if (preg_match('/date-time="([^"~]+)~([^"]+)"/', $atributos, $m)) {
				$regras[] = ['tipo' => 'datas', 'destino' => $destino,
					'inicio' => substr($m[1], 0, 10), 'fim' => substr($m[2], 0, 10)];
				continue;
			}
			if (preg_match('/wday="([^"]+)"/', $atributos, $dias)
				&& preg_match('/time-of-day="([^"-]+)-([^"]+)"/', $atributos, $horas)) {
				$regras[] = ['tipo' => 'semana', 'destino' => $destino,
					'dias' => self::dias_de_texto($dias[1]),
					'das' => substr($horas[1], 0, 5), 'ate' => substr($horas[2], 0, 5)];
				continue;
			}
			// Sobrou a condição do próprio número: é o "resto".
			$regras[] = ['tipo' => 'resto', 'destino' => $destino];
		}
		return $regras;
	}

	/** O inverso de `dias_em_texto`: aceita faixa (`2-6`) e lista (`2,4,6`). */
	private static function dias_de_texto(string $texto): array {
		if (strpos($texto, '-') !== false) {
			[$de, $ate] = array_map('intval', explode('-', $texto, 2));
			return range($de, $ate);
		}
		return array_map('intval', explode(',', $texto));
	}

	/**
	 * Edita no lugar, sem recriar.
	 *
	 * Da para atualizar o mesmo plano porque o XML e gerado inteiro a cada vez:
	 * nao ha estado em memoria como o do mod_callcenter -- que e por que
	 * `api_fila::editar` tem de apagar e recriar -- nem vinculo em outra tabela.
	 * Mesmo caminho do anuncio.
	 *
	 * O numero nao muda, e isso e o ponto: ele e endereco. Quem aponta para o
	 * horario guarda esse endereco, e trocar quebraria a entrada sem avisar.
	 * Atualizar no lugar faz disso uma garantia em vez de um cuidado.
	 */
	public static function editar(string $domain_uuid, array $dados): array {
		$numero = trim((string) ($dados['numero'] ?? ''));
		if ($numero === '') {
			responde(['erro' => 'numero é obrigatório'], 422);
		}
		$nome = trim((string) ($dados['nome'] ?? ''));
		if ($nome === '') {
			responde(['erro' => 'nome é obrigatório'], 422);
		}
		$regras = self::validar((array) ($dados['regras'] ?? []));

		$db = self::db();
		$uuid = $db->select(
			"select dialplan_uuid from v_dialplans "
			."where domain_uuid = :u and app_uuid = :a and dialplan_number = :n",
			['u' => $domain_uuid, 'a' => self::APP_UUID, 'n' => $numero], 'column'
		);
		if (empty($uuid)) {
			responde(['erro' => "não existe horário no número $numero"], 404);
		}

		$dominio = self::nome_do_dominio($domain_uuid);
		$permissoes = ['dialplan_add', 'dialplan_edit'];
		$p = permissions::new();
		foreach ($permissoes as $permissao) {
			$p->add($permissao, 'temp');
		}

		$array = [];
		$array['dialplans'][0] = self::dialplan($domain_uuid, (string) $uuid, $dominio, $numero,
			$nome, $regras, (string) ($dados['descricao'] ?? ''));
		$db->save($array);
		self::exigir_gravado($db, "o horário $numero");
		unset($array);

		foreach ($permissoes as $permissao) {
			$p->delete($permissao, 'temp');
		}

		self::recarregar($dominio);
		return ['horario' => $nome, 'numero' => $numero, 'regras' => count($regras)];
	}

	/**
	 * Remove sem checar quem aponta, de propósito.
	 *
	 * Nenhuma classe desta API bloqueia remoção por estar em uso, e inventar a
	 * exceção aqui seria incoerente. A prevenção mora na ficha, que e' onde esta
	 * a pessoa: o botão pergunta nomeando a consequência, como já faz o da fila,
	 * e o caminho da ligação mostra em vermelho quem aponta para o que não
	 * existe mais.
	 */
	public static function remover(string $domain_uuid, string $numero): array {
		$db = self::db();
		$linha = $db->select(
			"select dialplan_uuid, dialplan_name from v_dialplans "
			."where domain_uuid = :u and dialplan_number = :n and app_uuid = :a",
			['u' => $domain_uuid, 'n' => $numero, 'a' => self::APP_UUID], 'row'
		);
		if (empty($linha)) {
			responde(['erro' => "não existe horário no número $numero"], 404);
		}

		$dominio = self::nome_do_dominio($domain_uuid);
		// Mesmo caminho do anúncio: `execute` direto, porque `save()` não apaga.
		// Os detalhes vão primeiro -- o horário grava XML e não detalhes, mas
		// quem editar pela tela do FusionPBX passa a ter detalhes, e deixar
		// linha órfã apontando para plano que não existe suja a tela deles.
		$p = permissions::new();
		$p->add('dialplan_delete', 'temp');
		$db->execute("delete from v_dialplan_details where dialplan_uuid = :d",
			['d' => $linha['dialplan_uuid']]);
		$db->execute("delete from v_dialplans where dialplan_uuid = :d",
			['d' => $linha['dialplan_uuid']]);
		$p->delete('dialplan_delete', 'temp');

		self::recarregar($dominio);
		return ['removido' => $linha['dialplan_name'], 'numero' => $numero];
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
