<?php

/**
 * Criação e remoção de tronco (gateway).
 *
 * Cada número do cliente é uma conta SIP própria, logo um gateway próprio.
 *
 * Os valores fixados aqui não são preferência: cada um corresponde a uma falha
 * observada na validação de 09-10/09/2026. Ver docs/armadilhas.md.
 */
class api_tronco {

	private static function db() {
		return database::new(['db' => $GLOBALS['db'] ?? null]);
	}

	public static function criar(string $domain_uuid, array $dados): array {
		foreach (['nome', 'usuario', 'senha', 'host'] as $campo) {
			if (empty($dados[$campo])) {
				responde(['erro' => "$campo é obrigatório"], 422);
			}
		}

		$db = self::db();

		$existe = (int) $db->select(
			"select count(*) as n from v_gateways where domain_uuid = :u and gateway = :g",
			['u' => $domain_uuid, 'g' => $dados['nome']], 'column'
		);
		if ($existe > 0) {
			responde(['erro' => "tronco {$dados['nome']} já existe neste domínio"], 409);
		}

		$gateway_uuid = uuid();

		$permissoes = ['gateway_add', 'gateway_edit'];
		$p = permissions::new();
		foreach ($permissoes as $permissao) {
			$p->add($permissao, 'temp');
		}

		$array['gateways'][0] = [
			'gateway_uuid'       => $gateway_uuid,
			'domain_uuid'        => $domain_uuid,
			'gateway'            => $dados['nome'],
			'username'           => $dados['usuario'],
			'password'           => $dados['senha'],
			'proxy'              => $dados['host'],
			// O realm pode diferir do host: a operadora da validação desafiava
			// com realm "nextbilling" enquanto o host era um IP. Errar isto
			// produz 401 eterno.
			'realm'              => $dados['realm'] ?? $dados['host'],
			'register'           => 'true',
			'register_transport' => $dados['transporte'] ?? 'udp',
			// Perfil externo: é o que tem auth-calls = false e aceita a chamada
			// que a operadora entrega sem exigir que ela se autentique.
			'profile'            => 'external',
			'context'            => 'public',
			// Sem isto o Contact sai como `gw+<uuid>@...`, que não bate com a
			// conta SIP e algumas plataformas descartam em silêncio.
			'extension'          => $dados['usuario'],
			'extension_in_contact' => 'true',
			// Operadora brasileira fala G.711. Oferecer Opus primeiro faz SBC
			// antigo descartar o INVITE sem responder.
			'codec_prefs'        => 'PCMA,PCMU',
			'expire_seconds'     => 3600,
			'retry_seconds'      => 30,
			'enabled'            => 'true',
			'description'        => $dados['descricao'] ?? '',
		];

		$db->save($array);

		foreach ($permissoes as $permissao) {
			$p->delete($permissao, 'temp');
		}

		$liberado = self::liberar_ip($db, $dados['host']);

		self::recarregar();

		return [
			'gateway_uuid' => $gateway_uuid,
			'nome'         => $dados['nome'],
			'host'         => $dados['host'],
			'ip_liberado'  => $liberado,
		];
	}

	public static function remover(string $domain_uuid, string $nome): array {
		$db = self::db();

		$linha = $db->select(
			"select gateway_uuid from v_gateways where domain_uuid = :u and gateway = :g",
			['u' => $domain_uuid, 'g' => $nome], 'row'
		);
		if (empty($linha)) {
			responde(['erro' => "tronco $nome não existe neste domínio"], 404);
		}

		$p = permissions::new();
		$p->add('gateway_delete', 'temp');
		$db->execute("delete from v_gateways where gateway_uuid = :g",
			['g' => $linha['gateway_uuid']]);
		$p->delete('gateway_delete', 'temp');

		$socket = event_socket::create();
		if ($socket && $socket->is_connected()) {
			event_socket::api('sofia profile external killgw ' . $linha['gateway_uuid']);
		}
		self::recarregar();

		return ['nome' => $nome, 'removido' => true];
	}

	/**
	 * Faz o FreeSWITCH enxergar a mudança.
	 *
	 * A configuração do sofia -- com os gateways -- é servida do cache. Sem
	 * apagar essa chave, o tronco novo existe no banco, aparece na tela e é
	 * INVISÍVEL para o FreeSWITCH: não aparece nem como falhando no
	 * `sofia status`. Mesma classe de falha do dialplan_xml.
	 *
	 * `rescan` e nunca `restart`: restart derruba o tronco de todos os tenants.
	 */
	private static function recarregar(): void {
		$cache = new cache();
		$cache->delete(gethostname() . ':configuration:sofia.conf');

		$socket = event_socket::create();
		if ($socket && $socket->is_connected()) {
			event_socket::api('reloadxml');
			event_socket::api('reloadacl');
			event_socket::api('sofia profile external rescan');
		}
	}

	/**
	 * Libera o IP da operadora na lista `providers`.
	 *
	 * Sem isto o Event Guard bane quem autentica contra IP puro -- que é a cara
	 * de qualquer tronco -- e as respostas dela somem no firewall. O FreeSWITCH
	 * acusa timeout enquanto a captura de pacote mostra a resposta chegando.
	 *
	 * A lista é do SERVIDOR, não do tenant: liberar o IP de um cliente libera
	 * para todos. É inerente ao FreeSWITCH, cujas ACLs não têm domínio.
	 */
	private static function liberar_ip($db, string $host): bool {
		if (!filter_var($host, FILTER_VALIDATE_IP)) {
			// host por nome: não dá para pôr em ACL, e o Event Guard só bane IP
			return false;
		}

		$ja = (int) $db->select(
			"select count(*) as n from v_access_control_nodes where node_cidr = :c",
			['c' => $host . '/32'], 'column'
		);
		if ($ja > 0) {
			return true;
		}

		$acl = $db->select(
			"select access_control_uuid from v_access_controls where access_control_name = 'providers'",
			[], 'column'
		);
		if (empty($acl)) {
			responde(['erro' => "lista de acesso 'providers' não existe no FusionPBX"], 500);
		}

		$p = permissions::new();
		$p->add('access_control_node_add', 'temp');
		$array['access_control_nodes'][0] = [
			'access_control_node_uuid' => uuid(),
			'access_control_uuid'      => $acl,
			'node_type'                => 'allow',
			'node_cidr'                => $host . '/32',
			'node_description'         => 'tronco criado pela API do SimplificaJá',
		];
		$db->save($array);
		$p->delete('access_control_node_add', 'temp');

		return true;
	}
}
