<?php
/**
 * Grupo de toque: um número interno que chama vários ramais.
 *
 * Mesmo padrão de escrita da URA -- e pela mesma razão. O `ring_group_edit.php`
 * monta o `dialplan_xml` à mão (linhas 473-482) com `app_uuid` fixo e ordem
 * 101. O XML não tem a lógica: ele só chama `app.lua ring_groups`, que lê as
 * linhas do banco na hora da ligação. Um cache só, o do dialplan -- não existe
 * equivalente ao `ivr.conf` aqui.
 */
class api_grupo {

	/** O app de grupo de toque do FusionPBX. */
	const APP_UUID = '1d61fb65-1eec-bc73-a6ee-a6203b4fe6f2';

	/**
	 * As duas estratégias que a tela oferece, e o que elas viram no
	 * FreeSWITCH. O `app.lua` troca o separador do bridge: `,` toca tudo
	 * junto, `|` toca um de cada vez (linhas 960-975).
	 */
	const ESTRATEGIAS = ['todos' => 'simultaneous', 'ordem' => 'sequence'];

	private static function db() {
		return database::new(['db' => $GLOBALS['db'] ?? null]);
	}

	private static function nome_do_dominio(string $domain_uuid): string {
		$nome = self::db()->select(
			"select domain_name from v_domains where domain_uuid = :u",
			['u' => $domain_uuid], 'column'
		);
		if (empty($nome)) {
			responde(['erro' => 'domínio da chave não existe'], 500);
		}
		return $nome;
	}

	/**
	 * O número interno é único no plano de numeração do cliente: ramal, URA e
	 * grupo disputam o mesmo espaço. Duas rotas com o mesmo número dão a
	 * ligação para a que o FreeSWITCH encontrar primeiro, sem erro nenhum.
	 */
	private static function exigir_numero_livre(string $domain_uuid, string $ramal): void {
		$db = self::db();
		$ocupado = [
			'de um atendente' => "select extension_uuid from v_extensions where domain_uuid = :u and extension = :e",
			'de uma URA'      => "select ivr_menu_uuid from v_ivr_menus where domain_uuid = :u and ivr_menu_extension = :e",
			'de outro grupo'  => "select ring_group_uuid from v_ring_groups where domain_uuid = :u and ring_group_extension = :e",
		];
		foreach ($ocupado as $quem => $sql) {
			if (!empty($db->select($sql, ['u' => $domain_uuid, 'e' => $ramal], 'column'))) {
				responde(['erro' => "o número $ramal já é $quem"], 409);
			}
		}
	}

	/**
	 * Membro tem que ser ramal que existe. Um grupo apontando para número
	 * inexistente toca no vazio: a ligação entra, ninguém atende, e nada no
	 * painel diz por quê.
	 */
	private static function membros(string $domain_uuid, array $dados): array {
		$pedidos = array_values(array_unique(array_filter(
			array_map('trim', (array) ($dados['ramais'] ?? []))
		)));
		if (empty($pedidos)) {
			responde(['erro' => 'o grupo precisa de pelo menos um ramal'], 422);
		}

		$existentes = self::db()->select(
			"select extension from v_extensions where domain_uuid = :u",
			['u' => $domain_uuid], 'all'
		) ?? [];
		$existentes = array_column($existentes, 'extension');

		$fantasmas = array_diff($pedidos, $existentes);
		if (!empty($fantasmas)) {
			responde(['erro' => 'ramal não existe: ' . implode(', ', $fantasmas)], 422);
		}
		return $pedidos;
	}

	public static function criar(string $domain_uuid, array $dados): array {
		foreach (['nome', 'ramal'] as $campo) {
			if (empty($dados[$campo])) {
				responde(['erro' => "$campo é obrigatório"], 422);
			}
		}
		$ramal = trim((string) $dados['ramal']);
		if (!ctype_digit($ramal)) {
			responde(['erro' => 'número do grupo deve ser numérico'], 422);
		}

		$chave = (string) ($dados['estrategia'] ?? 'todos');
		if (!isset(self::ESTRATEGIAS[$chave])) {
			responde(['erro' => 'estrategia deve ser "todos" ou "ordem"'], 422);
		}

		$dominio = self::nome_do_dominio($domain_uuid);
		self::exigir_numero_livre($domain_uuid, $ramal);
		$ramais = self::membros($domain_uuid, $dados);

		$espera = (int) ($dados['espera'] ?? 25);
		$ring_group_uuid = uuid();
		$dialplan_uuid = uuid();

		$p = permissions::new();
		$permissoes = ['ring_group_add', 'ring_group_destination_add', 'dialplan_add'];
		foreach ($permissoes as $permissao) {
			$p->add($permissao, 'temp');
		}

		$array['ring_groups'][0] = [
			'ring_group_uuid'        => $ring_group_uuid,
			'domain_uuid'            => $domain_uuid,
			'dialplan_uuid'          => $dialplan_uuid,
			'ring_group_name'        => $dados['nome'],
			'ring_group_extension'   => $ramal,
			'ring_group_context'     => $dominio,
			'ring_group_strategy'    => self::ESTRATEGIAS[$chave],
			'ring_group_call_timeout' => (string) $espera,
			// O prefixo e a resposta para quem esta em mais de um grupo: sem
			// ele as duas ligacoes tocam identicas e a pessoa atende sem saber
			// se e Comercial ou Suporte. Viaja no caller ID name, entao o
			// softphone mostra e o nosso registrador le.
			'ring_group_cid_name_prefix' => self::prefixo($dados),
			'ring_group_timeout_app'  => empty($dados['saida']) ? '' : 'transfer',
			'ring_group_timeout_data' => empty($dados['saida'])
				? '' : $dados['saida'] . ' XML ' . $dominio,
			'ring_group_ringback'     => '${us-ring}',
			'ring_group_enabled'      => 'true',
			'ring_group_description'  => $dados['descricao'] ?? '',
		];

		foreach ($ramais as $i => $numero) {
			$array['ring_groups'][0]['ring_group_destinations'][$i] = [
				'ring_group_destination_uuid' => uuid(),
				'ring_group_uuid'    => $ring_group_uuid,
				'domain_uuid'        => $domain_uuid,
				'destination_number' => $numero,
				// Na sequencia esta coluna e a ORDEM, nao atraso -- a propria
				// tela deles troca o rotulo do campo (ring_group_edit.php:891)
				// e o `order by destination_delay` no app.lua e o que manda.
				'destination_delay'   => (string) ($chave === 'ordem' ? $i : 0),
				'destination_timeout' => (string) $espera,
				'destination_prompt'  => '0',
				'destination_enabled' => 'true',
			];
		}

		$array['dialplans'][0] = self::dialplan($domain_uuid, $dialplan_uuid,
			$ring_group_uuid, $dados, $dominio, $ramal);

		self::db()->save($array);
		foreach ($permissoes as $permissao) {
			$p->delete($permissao, 'temp');
		}

		self::publicar($dominio);

		return ['grupo' => $dados['nome'], 'ramal' => $ramal, 'ramais' => count($ramais)];
	}

	/**
	 * Só o nome, sem separador: o `app.lua` já monta
	 * `prefixo .. "#" .. caller_id_name` (ring_groups/index.lua:429), então o
	 * softphone mostra "Comercial#Maria Prado". Acrescentar pontuação aqui
	 * produz "Comercial:#Maria Prado" -- e espaço no fim nem chega ao banco,
	 * porque `database::save()` passa trim() em todo valor.
	 */
	private static function prefixo(array $dados): string {
		return trim((string) ($dados['prefixo'] ?? $dados['nome']));
	}

	/** O XML à mão, como eles fazem. Ele não decide nada: quem monta a lista
	 *  de quem toca é o `app.lua`, lendo o banco na hora da ligação. */
	private static function dialplan(string $domain_uuid, string $dialplan_uuid,
		string $ring_group_uuid, array $dados, string $dominio, string $ramal): array {

		$xml  = '<extension name="' . xml::sanitize($dados['nome']) . '" continue="" uuid="' . xml::sanitize($dialplan_uuid) . '">' . "\n";
		$xml .= '	<condition field="destination_number" expression="^' . xml::sanitize($ramal) . '$">' . "\n";
		$xml .= '		<action application="ring_ready" data=""/>' . "\n";
		$xml .= '		<action application="set" data="ring_group_uuid=' . xml::sanitize($ring_group_uuid) . '"/>' . "\n";
		$xml .= '		<action application="set" data="record_stereo=true"/>' . "\n";
		$xml .= '		<action application="lua" data="app.lua ring_groups"/>' . "\n";
		$xml .= '	</condition>' . "\n";
		$xml .= '</extension>' . "\n";

		return [
			'dialplan_uuid'        => $dialplan_uuid,
			'domain_uuid'          => $domain_uuid,
			'dialplan_name'        => $dados['nome'],
			'dialplan_number'      => $ramal,
			'dialplan_context'     => $dominio,
			'dialplan_continue'    => 'false',
			'dialplan_xml'         => $xml,
			'dialplan_order'       => '101',
			'dialplan_enabled'     => 'true',
			'dialplan_description' => $dados['descricao'] ?? '',
			'app_uuid'             => self::APP_UUID,
		];
	}

	private static function publicar(string $dominio): void {
		$cache = new cache();
		$cache->delete('dialplan:' . $dominio);

		$socket = event_socket::create();
		if ($socket && $socket->is_connected()) {
			event_socket::api('reloadxml');
		}
	}

	public static function remover(string $domain_uuid, string $ramal): array {
		$db = self::db();
		$linha = $db->select(
			"select ring_group_uuid, dialplan_uuid from v_ring_groups "
			."where domain_uuid = :u and ring_group_extension = :e",
			['u' => $domain_uuid, 'e' => $ramal], 'row'
		);
		if (empty($linha)) {
			responde(['erro' => "não existe grupo no número $ramal"], 404);
		}

		$p = permissions::new();
		$permissoes = ['ring_group_delete', 'ring_group_destination_delete', 'dialplan_delete'];
		foreach ($permissoes as $permissao) {
			$p->add($permissao, 'temp');
		}
		$db->execute("delete from v_ring_group_destinations where ring_group_uuid = :g",
			['g' => $linha['ring_group_uuid']]);
		$db->execute("delete from v_ring_groups where ring_group_uuid = :g",
			['g' => $linha['ring_group_uuid']]);
		if (!empty($linha['dialplan_uuid'])) {
			$db->execute("delete from v_dialplans where dialplan_uuid = :d",
				['d' => $linha['dialplan_uuid']]);
		}
		foreach ($permissoes as $permissao) {
			$p->delete($permissao, 'temp');
		}

		self::publicar(self::nome_do_dominio($domain_uuid));
		return ['grupo' => $ramal, 'removido' => true];
	}
}
