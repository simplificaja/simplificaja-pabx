<?php

/**
 * Leituras do tenant. Tudo escopado pelo domain_uuid que veio da chave.
 *
 * O estado ao vivo (quem está registrado agora) não está no banco -- vem do
 * FreeSWITCH pelo event socket, que é como o próprio FusionPBX consulta.
 */
class api_leitura {

	private static function db() {
		return database::new(['db' => $GLOBALS['db'] ?? null]);
	}

	private static function nome_do_dominio(string $domain_uuid): string {
		$nome = self::db()->select(
			"select domain_name from v_domains where domain_uuid = :u",
			['u' => $domain_uuid], 'column'
		);
		if (empty($nome)) {
			// A chave aponta para um domínio que não existe: erro de
			// implantação, não estado normal. Estoura alto.
			responde(['erro' => 'domínio da chave não existe'], 500);
		}
		return $nome;
	}

	/**
	 * Pergunta ao FreeSWITCH e devolve a saída, ou string vazia se ele não
	 * estiver acessível.
	 */
	private static function switch_api(string $comando): string {
		$socket = event_socket::create();
		if (!$socket || !$socket->is_connected()) {
			return '';
		}
		return (string) event_socket::api($comando);
	}

	public static function dominio(string $domain_uuid): array {
		$db = self::db();
		$d = $db->select(
			"select domain_name, domain_enabled from v_domains where domain_uuid = :u",
			['u' => $domain_uuid], 'row'
		);
		if (empty($d)) {
			responde(['erro' => 'domínio da chave não existe'], 500);
		}

		return [
			'dominio'  => $d['domain_name'],
			'ativo'    => in_array($d['domain_enabled'], ['t', 'true', true, 1], true),
			'ramais'   => (int) $db->select("select count(*) as n from v_extensions where domain_uuid = :u",
				['u' => $domain_uuid], 'column'),
			'destinos' => (int) $db->select("select count(*) as n from v_destinations where domain_uuid = :u",
				['u' => $domain_uuid], 'column'),
			'troncos'  => (int) $db->select("select count(*) as n from v_gateways where domain_uuid = :u",
				['u' => $domain_uuid], 'column'),
		];
	}

	public static function ramais(string $domain_uuid): array {
		$db = self::db();
		$linhas = $db->select(
			"select extension, description, enabled from v_extensions "
			."where domain_uuid = :u order by extension",
			['u' => $domain_uuid], 'all'
		) ?? [];

		$registrados = self::registrados(self::nome_do_dominio($domain_uuid));
		foreach ($linhas as &$linha) {
			$linha['registrado'] = in_array($linha['extension'], $registrados, true);
		}
		return $linhas;
	}

	/**
	 * Quem está registrado agora, naquele domínio.
	 *
	 * Casa por `usuario@dominio`, NUNCA só pelo número: o comando devolve os
	 * registros de todos os domínios do servidor, e número de ramal se repete
	 * entre clientes. Casar só pelo número mostraria o 1001 do cliente A como
	 * registrado porque o 1001 do cliente B está.
	 */
	private static function registrados(string $dominio): array {
		$saida = self::switch_api('sofia status profile internal reg');
		if ($saida === '') {
			return [];
		}
		preg_match_all('/User:\s+(\S+?)@' . preg_quote($dominio, '/') . '\b/', $saida, $m);
		return array_values(array_unique($m[1] ?? []));
	}

	/** Destinos do tenant, com o aviso de XML faltando -- que é o que separa
	 *  destino vivo de destino que aparece na tela e nunca é usado. */
	public static function destinos(string $domain_uuid): array {
		return self::db()->select(
			"select d.destination_number, d.destination_enabled, d.destination_description, "
			."p.dialplan_name, coalesce(length(p.dialplan_xml), 0) as xml_bytes "
			."from v_destinations d "
			."left join v_dialplans p on p.dialplan_uuid = d.dialplan_uuid "
			."where d.domain_uuid = :u order by d.destination_number",
			['u' => $domain_uuid], 'all'
		) ?? [];
	}

	public static function troncos(string $domain_uuid): array {
		$db = self::db();
		$linhas = $db->select(
			"select gateway_uuid, gateway, proxy, username, enabled from v_gateways "
			."where domain_uuid = :u order by gateway",
			['u' => $domain_uuid], 'all'
		) ?? [];

		// O `sofia status` identifica o gateway pelo UUID, não pelo nome:
		//   external::fbbe29c7-...	gateway	sip:75681@177.11.50.217	REGED
		$saida = self::switch_api('sofia status');
		foreach ($linhas as &$linha) {
			$linha['registrado'] = $saida !== '' && (bool) preg_match(
				'/::' . preg_quote($linha['gateway_uuid'], '/') . '\s.*\bREGED\b/',
				$saida
			);
		}
		return $linhas;
	}
}
