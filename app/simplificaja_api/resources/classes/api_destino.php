<?php

/**
 * Criação e remoção de destino de entrada: número → fluxo.
 *
 * Segue o padrão provado em docs/exemplos/criar-destino.php. Dois pontos que
 * separam destino vivo de destino morto, e que já custaram uma madrugada:
 *
 *  1. Sem linha em `v_destinations`, o contexto público não serve a rota. Ela
 *     existe no banco, aparece na tela e nunca é usada.
 *  2. Sem `dialplan->xml()`, a coluna `dialplan_xml` fica vazia -- e é ELA que o
 *     FreeSWITCH lê, não as linhas da rota.
 */
class api_destino {

	/** app_uuid do app Inbound Routes; sem ele a rota não é reconhecida como destino. */
	const APP_INBOUND = 'c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4';

	private static function db() {
		return database::new(['db' => $GLOBALS['db'] ?? null]);
	}

	public static function criar(string $domain_uuid, array $dados): array {
		$numero  = trim((string) ($dados['numero'] ?? ''));
		$destino = trim((string) ($dados['destino'] ?? ''));
		if ($numero === '' || $destino === '') {
			responde(['erro' => 'numero e destino são obrigatórios'], 422);
		}

		$db = self::db();
		$dominio = $db->select("select domain_name from v_domains where domain_uuid = :u",
			['u' => $domain_uuid], 'column');
		if (empty($dominio)) {
			responde(['erro' => 'domínio da chave não existe'], 500);
		}

		$existe = (int) $db->select(
			"select count(*) as n from v_destinations where domain_uuid = :u and destination_number = :n",
			['u' => $domain_uuid, 'n' => $numero], 'column'
		);
		if ($existe > 0) {
			responde(['erro' => "já existe destino para o número $numero"], 409);
		}

		$dialplan_uuid = uuid();

		$permissoes = ['dialplan_add', 'dialplan_edit', 'dialplan_detail_add',
		               'dialplan_detail_edit', 'destination_add', 'destination_edit'];
		$p = permissions::new();
		foreach ($permissoes as $permissao) {
			$p->add($permissao, 'temp');
		}

		$array['dialplans'][0] = [
			'dialplan_uuid'        => $dialplan_uuid,
			'domain_uuid'          => $domain_uuid,
			'app_uuid'             => self::APP_INBOUND,
			'dialplan_name'        => 'entrada-' . $numero,
			'dialplan_number'      => $numero,
			'dialplan_context'     => 'public',
			'dialplan_continue'    => 'false',
			'dialplan_order'       => '100',
			'dialplan_enabled'     => 'true',
			'dialplan_description' => $dados['descricao'] ?? '',
		];

		$d = 0;
		$array['dialplans'][0]['dialplan_details'][$d++] = [
			'dialplan_detail_uuid'  => uuid(),
			'dialplan_uuid'         => $dialplan_uuid,
			'domain_uuid'           => $domain_uuid,
			'dialplan_detail_tag'   => 'condition',
			'dialplan_detail_type'  => 'destination_number',
			'dialplan_detail_data'  => '^' . preg_quote($numero, '/') . '$',
			'dialplan_detail_order' => '005',
			'dialplan_detail_group' => '0',
		];
		$array['dialplans'][0]['dialplan_details'][$d++] = [
			'dialplan_detail_uuid'  => uuid(),
			'dialplan_uuid'         => $dialplan_uuid,
			'domain_uuid'           => $domain_uuid,
			'dialplan_detail_tag'   => 'action',
			'dialplan_detail_type'  => 'export',
			'dialplan_detail_data'  => 'call_direction=inbound',
			'dialplan_detail_order' => '010',
			'dialplan_detail_group' => '0',
			'dialplan_detail_inline'=> 'true',
		];
		$array['dialplans'][0]['dialplan_details'][$d++] = [
			'dialplan_detail_uuid'  => uuid(),
			'dialplan_uuid'         => $dialplan_uuid,
			'domain_uuid'           => $domain_uuid,
			'dialplan_detail_tag'   => 'action',
			'dialplan_detail_type'  => 'set',
			'dialplan_detail_data'  => 'domain_name=' . $dominio,
			'dialplan_detail_order' => '015',
			'dialplan_detail_group' => '0',
			'dialplan_detail_inline'=> 'true',
		];
		$array['dialplans'][0]['dialplan_details'][$d++] = [
			'dialplan_detail_uuid'  => uuid(),
			'dialplan_uuid'         => $dialplan_uuid,
			'domain_uuid'           => $domain_uuid,
			'dialplan_detail_tag'   => 'action',
			'dialplan_detail_type'  => 'transfer',
			'dialplan_detail_data'  => $destino . ' XML ' . $dominio,
			'dialplan_detail_order' => '020',
			'dialplan_detail_group' => '0',
		];

		// A linha sem a qual o contexto público nunca serve esta rota.
		$array['destinations'][0] = [
			'destination_uuid'        => uuid(),
			'domain_uuid'             => $domain_uuid,
			'dialplan_uuid'           => $dialplan_uuid,
			'destination_type'        => 'inbound',
			'destination_number'      => $numero,
			'destination_enabled'     => 'true',
			'destination_description' => $dados['descricao'] ?? '',
		];

		$db->save($array);
		unset($array);

		foreach ($permissoes as $permissao) {
			$p->delete($permissao, 'temp');
		}

		self::gerar_xml();

		// Conferir ANTES de dizer que deu certo: sem esta verificação a API
		// criaria rotas mortas exatamente como se criou na validação.
		$tam = (int) $db->select(
			"select coalesce(length(dialplan_xml), 0) as n from v_dialplans where dialplan_uuid = :u",
			['u' => $dialplan_uuid], 'column'
		);
		if ($tam === 0) {
			responde(['erro' => 'destino criado mas o XML não foi gerado; rode /saude'], 500);
		}

		return [
			'numero'    => $numero,
			'destino'   => $destino,
			'xml_bytes' => $tam,
		];
	}

	public static function remover(string $domain_uuid, string $numero): array {
		$db = self::db();

		$linha = $db->select(
			"select destination_uuid, dialplan_uuid from v_destinations "
			."where domain_uuid = :u and destination_number = :n",
			['u' => $domain_uuid, 'n' => $numero], 'row'
		);
		if (empty($linha)) {
			responde(['erro' => "não existe destino para o número $numero"], 404);
		}

		$p = permissions::new();
		$p->add('destination_delete', 'temp');
		$p->add('dialplan_delete', 'temp');

		$db->execute("delete from v_destinations where destination_uuid = :d",
			['d' => $linha['destination_uuid']]);
		$db->execute("delete from v_dialplan_details where dialplan_uuid = :p",
			['p' => $linha['dialplan_uuid']]);
		$db->execute("delete from v_dialplans where dialplan_uuid = :p",
			['p' => $linha['dialplan_uuid']]);

		$p->delete('destination_delete', 'temp');
		$p->delete('dialplan_delete', 'temp');

		self::limpar_cache($numero);

		return ['numero' => $numero, 'removido' => true];
	}

	/**
	 * Gera o dialplan_xml a partir das linhas gravadas, pelo código do próprio
	 * FusionPBX -- provado em docs/exemplos/criar-destino.php.
	 */
	private static function gerar_xml(): void {
		$dialplan = new dialplan();
		$dialplan->source      = 'details';
		$dialplan->destination = 'database';
		$dialplan->context     = 'public';
		$dialplan->is_empty    = 'dialplan_xml';
		$dialplan->xml();

		self::limpar_cache();
	}

	/**
	 * O contexto público é cacheado POR NÚMERO discado: a chave é
	 * `dialplan:public:<numero>`. Limpar só `dialplan:public` não adianta.
	 */
	private static function limpar_cache(?string $numero = null): void {
		$cache = new cache();
		$cache->delete('dialplan:public');
		if ($numero !== null) {
			$cache->delete('dialplan:public:' . $numero);
		}

		$socket = event_socket::create();
		if ($socket && $socket->is_connected()) {
			event_socket::api('reloadxml');
		}
	}
}
