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
		$grupos = self::grupos_por_ramal($domain_uuid);
		foreach ($linhas as &$linha) {
			$linha['registrado'] = in_array($linha['extension'], $registrados, true);
			$linha['grupos'] = $grupos[$linha['extension']] ?? [];
		}
		return $linhas;
	}

	/**
	 * De quais grupos cada ramal participa. Participar de mais de um é normal
	 * -- nada na tabela impede -- e é justamente o caso que a tela precisa
	 * mostrar: sem isso, "a ligação tocou aqui" não diz de onde veio.
	 */
	private static function grupos_por_ramal(string $domain_uuid): array {
		$linhas = self::db()->select(
			"select d.destination_number, g.ring_group_name "
			."from v_ring_group_destinations d "
			."join v_ring_groups g on g.ring_group_uuid = d.ring_group_uuid "
			."where d.domain_uuid = :u order by g.ring_group_name",
			['u' => $domain_uuid], 'all'
		) ?? [];

		$mapa = [];
		foreach ($linhas as $linha) {
			$mapa[$linha['destination_number']][] = $linha['ring_group_name'];
		}
		return $mapa;
	}

	/**
	 * Grupos com os membros dentro, na ordem em que tocam.
	 *
	 * `destination_delay` é atraso na estratégia simultânea e ORDEM na
	 * sequencial -- a própria tela do FusionPBX troca o rótulo do campo. Ordenar
	 * por ele serve aos dois casos.
	 */
	public static function grupos(string $domain_uuid): array {
		$db = self::db();
		$grupos = $db->select(
			"select ring_group_uuid, ring_group_name, ring_group_extension, "
			."ring_group_strategy, ring_group_call_timeout, ring_group_cid_name_prefix, "
			."ring_group_timeout_data, ring_group_enabled, "
			."coalesce(length(p.dialplan_xml), 0) as xml_bytes "
			."from v_ring_groups g left join v_dialplans p on p.dialplan_uuid = g.dialplan_uuid "
			."where g.domain_uuid = :u order by ring_group_extension",
			['u' => $domain_uuid], 'all'
		) ?? [];
		if (empty($grupos)) {
			return [];
		}

		$membros = $db->select(
			"select ring_group_uuid, destination_number, destination_delay "
			."from v_ring_group_destinations where domain_uuid = :u "
			."order by destination_delay, destination_number",
			['u' => $domain_uuid], 'all'
		) ?? [];

		$nomes = self::nomes_dos_ramais($domain_uuid);
		$registrados = self::registrados(self::nome_do_dominio($domain_uuid));

		foreach ($grupos as &$grupo) {
			$grupo['ramais'] = [];
			foreach ($membros as $membro) {
				if ($membro['ring_group_uuid'] !== $grupo['ring_group_uuid']) {
					continue;
				}
				$numero = $membro['destination_number'];
				$grupo['ramais'][] = [
					'extension'   => $numero,
					'description' => $nomes[$numero] ?? null,
					'registrado'  => in_array($numero, $registrados, true),
				];
			}
		}
		return $grupos;
	}

	private static function nomes_dos_ramais(string $domain_uuid): array {
		$linhas = self::db()->select(
			"select extension, description from v_extensions where domain_uuid = :u",
			['u' => $domain_uuid], 'all'
		) ?? [];
		return array_column($linhas, 'description', 'extension');
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

	/**
	 * URAs com as opções dentro. A contagem sozinha não serve: "2 opções" não
	 * diz para onde a tecla 1 leva, e conferir isso é a razão de a tela existir.
	 */
	public static function uras(string $domain_uuid): array {
		$db = self::db();
		$uras = $db->select(
			"select i.ivr_menu_uuid, i.ivr_menu_name, i.ivr_menu_extension, "
			."i.ivr_menu_greet_long, i.ivr_menu_greet_short, i.ivr_menu_exit_data, "
			."i.ivr_menu_enabled, coalesce(length(p.dialplan_xml),0) as xml_bytes "
			."from v_ivr_menus i left join v_dialplans p on p.dialplan_uuid = i.dialplan_uuid "
			."where i.domain_uuid = :u order by i.ivr_menu_extension",
			['u' => $domain_uuid], 'all'
		) ?? [];
		if (empty($uras)) {
			return [];
		}

		$opcoes = $db->select(
			"select ivr_menu_uuid, ivr_menu_option_digits, ivr_menu_option_param "
			."from v_ivr_menu_options where domain_uuid = :u order by ivr_menu_option_order",
			['u' => $domain_uuid], 'all'
		) ?? [];

		foreach ($uras as &$ura) {
			$ura['opcoes'] = [];
			foreach ($opcoes as $opcao) {
				if ($opcao['ivr_menu_uuid'] === $ura['ivr_menu_uuid']) {
					$ura['opcoes'][] = [
						'digito'  => $opcao['ivr_menu_option_digits'],
						'destino' => self::numero_do_destino($opcao['ivr_menu_option_param']),
					];
				}
			}
			$ura['saida'] = self::numero_do_destino($ura['ivr_menu_exit_data']);
		}
		return $uras;
	}

	/**
	 * O número dentro do que a opção da URA executa. São duas formas, e a
	 * tela precisa das duas:
	 *
	 *   transfer 1001 XML cliente.pabx...                 -> 1001
	 *   bridge {leg_timeout=25,...}user/1001@cliente...   -> 1001
	 *
	 * Ramal usa `bridge` para a URA poder retomar quando ninguém atende;
	 * grupo e outra URA usam `transfer`, que são rotas do dialplan.
	 */
	private static function numero_do_destino(?string $param): ?string {
		$texto = trim((string) $param);
		if ($texto === '') {
			return null;
		}
		if (preg_match('~user/([^@]+)@~', $texto, $m)) {
			return $m[1];
		}
		$partes = preg_split('/\s+/', $texto);
		$numero = $partes[0] === 'transfer' ? ($partes[1] ?? '') : ($partes[0] ?? '');
		return $numero === '' ? null : $numero;
	}

	/**
	 * O conteúdo de uma gravação, para o painel tocar. Fica fora da listagem
	 * de propósito: são dezenas de KB por arquivo, e carregar tudo a cada
	 * abertura de tela para tocar no máximo um é desperdício garantido.
	 */
	public static function gravacao(string $domain_uuid, string $nome): array {
		$arquivo = preg_replace('/[^A-Za-z0-9_.-]/', '', $nome);
		$linha = self::db()->select(
			"select recording_filename, recording_base64 from v_recordings "
			."where domain_uuid = :u and recording_filename = :f",
			['u' => $domain_uuid, 'f' => $arquivo], 'row'
		);
		if (empty($linha)) {
			responde(['erro' => "não existe gravação chamada $arquivo"], 404);
		}
		if (empty($linha['recording_base64'])) {
			responde(['erro' => "a gravação $arquivo não tem prévia guardada"], 422);
		}
		return ['gravacao' => $arquivo, 'audio' => $linha['recording_base64']];
	}

	public static function gravacoes(string $domain_uuid): array {
		return self::db()->select(
			"select recording_filename, recording_name, recording_description, "
			."octet_length(recording_base64) as base64_bytes "
			."from v_recordings where domain_uuid = :u order by recording_filename",
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
