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
		// DUAS configuracoes, nao uma: o tronco mexe no `sofia.conf` (o gateway) e
		// na `acl.conf` (o IP liberado no Event Guard). A versao anterior limpava
		// so a do sofia, e o `reloadacl` abaixo relia a lista VELHA do cache --
		// o IP entrava no banco e nao entrava na lista viva do FreeSWITCH.
		//
		// O sintoma era o pior possivel: o tronco registrava, a saida funcionava,
		// e a ligacao de ENTRADA voltava CALL_REJECTED em CS_NEW, antes de
		// qualquer roteamento, sem nada no CDR. Aconteceu com a GTGI.
		//
		// E sao TRES chaves por configuracao, nao uma: o FusionPBX grava tambem
		// prefixada e sufixada pelo nome do host (`remove_config_from_cache()` em
		// resources/switch.php). Limpar so uma deixa as outras duas vivas.
		foreach (['configuration:sofia.conf', 'configuration:acl.conf'] as $nome) {
			self::limpar_config($nome);
		}

		$socket = event_socket::create();
		if ($socket && $socket->is_connected()) {
			event_socket::api('reloadxml');
			event_socket::api('reloadacl');
			event_socket::api('sofia profile external rescan');
		}
	}

	/** As tres variantes de chave que o FusionPBX usa para a mesma configuracao. */
	private static function limpar_config(string $nome): void {
		$cache = new cache();
		$cache->delete($nome);
		$cache->delete(gethostname() . ':' . $nome);
		$cache->delete($nome . ':' . gethostname());
	}

	/**
	 * O IP de um host que pode vir como nome.
	 *
	 * A ACL do FreeSWITCH só entende IP, e a versão anterior disto devolvia
	 * `false` quando o host era nome -- em silêncio. O tronco era criado,
	 * registrava normalmente, e a ligação de ENTRADA era banida pelo Event
	 * Guard sem nada dizer por quê: o pior tipo de defeito que existe aqui.
	 * Aconteceu com a GTGI, cujo host foi cadastrado como
	 * `gt.pabx.simplificaja.com.br`.
	 *
	 * Resolver é a correção certa, e não um remendo: quem opera digita o que a
	 * operadora manda, e operadora manda nome tanto quanto IP.
	 *
	 * Limite honesto, e está na mensagem para quem lê a ficha: se a operadora
	 * trocar o IP por trás do nome, a liberação envelhece e a entrada volta a
	 * ser banida. Nome que resolve para VÁRIOS IPs também só tem o primeiro
	 * liberado -- por isso devolve a lista inteira e todos são liberados.
	 */
	/** Todos os IPs do host, porque operadora costuma ter mais de um servidor. */
	private static function ips_do_host(string $host): array {
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			return [$host];
		}
		return @gethostbynamel($host) ?: [];
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
		$ips = self::ips_do_host($host);
		if (empty($ips)) {
			return false;
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

		$novos = [];
		foreach ($ips as $ip) {
			$ja = (int) $db->select(
				"select count(*) as n from v_access_control_nodes where node_cidr = :c",
				['c' => $ip . '/32'], 'column'
			);
			if ($ja > 0) {
				continue;
			}
			// A descrição carrega o host ORIGINAL: daqui a um ano, olhando a
			// lista, `200.201.197.137/32` sozinho não diz de quem é.
			$novos[] = [
				'access_control_node_uuid' => uuid(),
				'access_control_uuid'      => $acl,
				'node_type'                => 'allow',
				'node_cidr'                => $ip . '/32',
				'node_description'         => 'operadora ' . $host . ' - tronco pela API do SimplificaJá',
			];
		}

		if (!empty($novos)) {
			$array = ['access_control_nodes' => $novos];
			$db->save($array);
		}
		$p->delete('access_control_node_add', 'temp');

		return true;
	}
}
