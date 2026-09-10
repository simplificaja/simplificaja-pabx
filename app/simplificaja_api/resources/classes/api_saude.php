<?php

/**
 * Verificação do tenant.
 *
 * Cada checagem corresponde a uma falha real, das que existem no banco, aparecem
 * na tela e não funcionam. Ver docs/armadilhas.md -- todas custaram horas.
 *
 * O detalhe de cada falha diz o SINTOMA, não o defeito técnico: quem lê o painel
 * precisa saber o que o cliente está sentindo.
 */
class api_saude {

	private static function db() {
		return database::new(['db' => $GLOBALS['db'] ?? null]);
	}

	private static function switch_api(string $comando): string {
		$socket = event_socket::create();
		if (!$socket || !$socket->is_connected()) {
			return '';
		}
		return (string) event_socket::api($comando);
	}

	private static function item(string $checagem, bool $ok, ?string $detalhe = null): array {
		return ['checagem' => $checagem, 'ok' => $ok, 'detalhe' => $ok ? null : $detalhe];
	}

	public static function verificar(string $domain_uuid): array {
		$db = self::db();
		$r = [];

		// Só rotas ATIVAS entram nas checagens 1 e 2: rota desabilitada não é
		// servida, então não é problema -- e sinalizar o que não afeta ninguém
		// ensina a ignorar a verificação.
		//
		// 1. Rota sem XML gerado. O FreeSWITCH lê a coluna dialplan_xml, não as
		//    linhas da rota -- e ela só é preenchida pelo código do FusionPBX.
		$n = (int) $db->select(
			"select count(*) as n from v_dialplans "
			."where domain_uuid = :u and dialplan_enabled = true "
			."and coalesce(length(dialplan_xml), 0) = 0",
			['u' => $domain_uuid], 'column'
		);
		$r[] = self::item('rotas com XML gerado', $n === 0,
			"$n rota(s) sem XML: a ligação chega e não vai a lugar nenhum");

		// 2. Rota de entrada sem linha em v_destinations. O contexto público é
		//    montado a partir de v_destinations; sem ela a rota nunca é servida.
		$n = (int) $db->select(
			"select count(*) as n from v_dialplans p "
			."where p.domain_uuid = :u and p.dialplan_context = 'public' "
			."and p.dialplan_enabled = true "
			."and not exists (select 1 from v_destinations d where d.dialplan_uuid = p.dialplan_uuid)",
			['u' => $domain_uuid], 'column'
		);
		$r[] = self::item('destinos vinculados', $n === 0,
			"$n rota(s) de entrada sem registro em v_destinations: aparece na tela e nunca é usada");

		// 3. auth-calls do perfil externo. Com true o PBX responde 407 a toda
		//    chamada que a operadora entrega -- nenhuma ligação de entrada completa.
		//    É configuração do servidor, não do tenant.
		$v = $db->select(
			"select s.sip_profile_setting_value from v_sip_profiles p "
			."join v_sip_profile_settings s on s.sip_profile_uuid = p.sip_profile_uuid "
			."where p.sip_profile_name = 'external' and s.sip_profile_setting_name = 'auth-calls'",
			[], 'column'
		);
		$r[] = self::item('perfil externo aceita chamada do tronco', $v === 'false',
			'auth-calls está em "' . $v . '": o PBX responde 407 às chamadas da operadora');

		// 4. IP de cada tronco liberado. Sem isso o Event Guard bane a operadora,
		//    as respostas somem no firewall e tudo estoura em timeout.
		$gateways = $db->select(
			"select gateway_uuid, gateway, proxy from v_gateways "
			."where domain_uuid = :u and proxy is not null and proxy <> ''",
			['u' => $domain_uuid], 'all'
		) ?? [];

		foreach ($gateways as $g) {
			$liberado = (int) $db->select(
				"select count(*) as n from v_access_control_nodes n "
				."join v_access_controls a on a.access_control_uuid = n.access_control_uuid "
				."where a.access_control_name = 'providers' and n.node_type = 'allow' "
				."and n.node_cidr like :p",
				['p' => $g['proxy'] . '%'], 'column'
			);
			$r[] = self::item("IP {$g['proxy']} liberado no Event Guard", $liberado > 0,
				'fora da lista providers: o Event Guard vai banir a operadora e tudo dá timeout');
		}

		// 5. Troncos registrados. O sofia status identifica por UUID, não por nome.
		$saida = self::switch_api('sofia status');
		foreach ($gateways as $g) {
			$ok = $saida !== '' && (bool) preg_match(
				'/::' . preg_quote($g['gateway_uuid'], '/') . '\s.*\bREGED\b/', $saida
			);
			$r[] = self::item("tronco {$g['gateway']} registrado", $ok,
				'sem registro: não entra nem sai ligação por este número');
		}

		// 6. Ramais com senha fraca ou vazia. Credencial SIP fica exposta na
		//    internet, e fraude tarifária é o risco real desta operação.
		$n = (int) $db->select(
			"select count(*) as n from v_extensions "
			."where domain_uuid = :u and (password is null or length(password) < 12)",
			['u' => $domain_uuid], 'column'
		);
		$r[] = self::item('ramais com senha forte', $n === 0,
			"$n ramal(is) com senha curta ou vazia: alvo de varredura");

		return [
			'tudo_certo' => !in_array(false, array_column($r, 'ok'), true),
			'checagens'  => $r,
		];
	}
}
