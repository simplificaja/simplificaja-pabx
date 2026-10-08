<?php

/** Read-only, tenant-scoped data for the SimplificaJá customer portal. */
class simplificaja_portal_data {
	private database $database;
	private string $domain_uuid;
	private string $domain_name;
	private array $extension_uuids = [];
	private bool $cdr_domain_view;
	private bool $extension_domain_view;

	public function __construct(database $database) {
		$this->database = $database;
		$this->domain_uuid = (string) ($_SESSION['domain_uuid'] ?? '');
		$this->domain_name = (string) ($_SESSION['domain_name'] ?? '');
		$this->cdr_domain_view = permission_exists('xml_cdr_domain');
		$this->extension_domain_view = permission_exists('registration_domain')
			|| permission_exists('call_active_domain');

		foreach (($_SESSION['user']['extension'] ?? []) as $extension) {
			if (!empty($extension['extension_uuid']) && is_uuid($extension['extension_uuid'])) {
				$this->extension_uuids[] = $extension['extension_uuid'];
			}
		}
	}

	public function has_domain_view(): bool {
		return $this->cdr_domain_view;
	}

	public function extension_scope_sql(string $column, array &$parameters, bool $domain_scope): string {
		if ($domain_scope) {
			return '';
		}
		if (empty($this->extension_uuids)) {
			return "and false ";
		}

		$placeholders = [];
		foreach ($this->extension_uuids as $index => $uuid) {
			$key = 'portal_extension_'.$index;
			$placeholders[] = ':'.$key;
			$parameters[$key] = $uuid;
		}
		return 'and '.$column.' in ('.implode(', ', $placeholders).') ';
	}

	public function extensions(): array {
		$sql = "select extension_uuid, extension, number_alias, directory_first_name, directory_last_name, description ";
		$sql .= "from v_extensions where domain_uuid = :domain_uuid and enabled = true ";
		$parameters = ['domain_uuid' => $this->domain_uuid];
		$sql .= $this->extension_scope_sql('extension_uuid', $parameters, $this->extension_domain_view);
		$sql .= "order by extension asc ";
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	public function registrations(): ?array {
		$active = [];
		try {
			$registrations = new registrations([
				'database' => $this->database,
				'domain_uuid' => $this->domain_uuid,
				'domain_name' => $this->domain_name,
			]);
			$rows = $registrations->get('all');
			if (!is_array($rows)) {
				return null;
			}
			foreach ($rows as $row) {
				$user = (string) ($row['user'] ?? '');
				[$number, $user_domain] = array_pad(explode('@', $user, 2), 2, '');
				if (($row['sip-auth-realm'] ?? '') !== $this->domain_name && $user_domain !== $this->domain_name) {
					continue;
				}
				if ($number !== '') {
					$active[$number] = true;
				}
			}
		} catch (Throwable $exception) {
			// A switch connection problem should not take down the portal.
			return null;
		}
		return $active;
	}

	public function active_calls(array $extensions): array {
		$numbers = [];
		foreach ($extensions as $extension) {
			$numbers[(string) $extension['extension']] = true;
		}
		if (empty($numbers)) {
			return [];
		}

		$active = [];
		try {
			$event_socket = event_socket::create();
			if (!$event_socket->is_connected()) {
				$event_socket->connect();
			}
			if (!$event_socket->is_connected()) {
				return [];
			}

			$response = trim((string) $event_socket->request('api show channels as json'));
			$response = preg_replace('/^\+OK\s*/', '', $response);
			$data = json_decode($response, true);
			$channels = $data['rows'] ?? [];
			foreach ($channels as $channel) {
				$context = (string) ($channel['context'] ?? '');
				$channel_domain = (string) ($channel['variable_domain_uuid'] ?? '');
				$belongs_to_domain = ($channel_domain !== '' && $channel_domain === $this->domain_uuid)
					|| ($this->domain_name !== '' && $context === $this->domain_name);
				if (!$belongs_to_domain) {
					continue;
				}

				$possible_numbers = [
					(string) ($channel['dest'] ?? ''),
					(string) ($channel['destination_number'] ?? ''),
					(string) ($channel['variable_user_name'] ?? ''),
					(string) ($channel['variable_destination_number'] ?? ''),
				];
				foreach ($possible_numbers as $number) {
					if ($number !== '' && isset($numbers[$number])) {
						$active[$number] = [
							'caller' => (string) ($channel['cid_num'] ?? ''),
							'created' => (string) ($channel['created'] ?? ''),
						];
					}
				}
			}
		} catch (Throwable $exception) {
			return [];
		}
		return $active;
	}

	public function call_count(DateTimeImmutable $since, bool $missed_only = false): int {
		$sql = "select count(*) from v_xml_cdr c ";
		$sql .= "where c.domain_uuid = :domain_uuid and c.leg = 'a' and c.start_stamp >= :since ";
		$parameters = ['domain_uuid' => $this->domain_uuid, 'since' => $since->format('Y-m-d H:i:sP')];
		$sql .= $this->extension_scope_sql('c.extension_uuid', $parameters, $this->cdr_domain_view);
		if ($missed_only) {
			$sql .= "and c.missed_call = true ";
		}
		return (int) $this->database->select($sql, $parameters, 'column');
	}

	public function missed_calls(DateTimeImmutable $since, int $limit = 5): array {
		$sql = "select c.caller_id_number, c.caller_id_name, c.caller_destination, c.destination_number, ";
		$sql .= "c.direction, c.start_stamp, c.extension_uuid ";
		$sql .= "from v_xml_cdr c where c.domain_uuid = :domain_uuid and c.leg = 'a' ";
		$sql .= "and c.start_stamp >= :since and c.missed_call = true ";
		$parameters = ['domain_uuid' => $this->domain_uuid, 'since' => $since->format('Y-m-d H:i:sP')];
		$sql .= $this->extension_scope_sql('c.extension_uuid', $parameters, $this->cdr_domain_view);
		$sql .= "order by c.start_stamp desc limit ".max(1, min($limit, 20));
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	public function call_volume(DateTimeImmutable $since): array {
		$sql = "select floor(extract(epoch from c.start_stamp) / 3600) * 3600 as hour_epoch, count(*) as total ";
		$sql .= "from v_xml_cdr c where c.domain_uuid = :domain_uuid and c.leg = 'a' and c.start_stamp >= :since ";
		$parameters = ['domain_uuid' => $this->domain_uuid, 'since' => $since->format('Y-m-d H:i:sP')];
		$sql .= $this->extension_scope_sql('c.extension_uuid', $parameters, $this->cdr_domain_view);
		$sql .= "group by floor(extract(epoch from c.start_stamp) / 3600) order by floor(extract(epoch from c.start_stamp) / 3600) asc";
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	public function call_history(int $days, string $status, string $direction, string $search): array {
		$sql = "select c.caller_id_name, c.caller_id_number, c.caller_destination, c.destination_number, ";
		$sql .= "c.direction, c.start_stamp, c.duration, c.missed_call, c.call_disposition, e.extension ";
		$sql .= "from v_xml_cdr c left join v_extensions e on e.extension_uuid = c.extension_uuid ";
		$sql .= "where c.domain_uuid = :domain_uuid and c.leg = 'a' and c.start_stamp >= :since ";
		$parameters = [
			'domain_uuid' => $this->domain_uuid,
			'since' => (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->modify('-'.$days.' days')->format('Y-m-d H:i:sP'),
		];
		$sql .= $this->extension_scope_sql('c.extension_uuid', $parameters, $this->cdr_domain_view);
		if ($status === 'missed') {
			$sql .= "and c.missed_call = true ";
		} elseif ($status === 'answered') {
			$sql .= "and c.missed_call = false ";
		}
		if ($direction === 'inbound' || $direction === 'outbound') {
			$sql .= "and c.direction = :direction ";
			$parameters['direction'] = $direction;
		}
		if ($search !== '') {
			$sql .= "and (c.caller_id_number ilike :search or c.caller_id_name ilike :search or c.destination_number ilike :search or e.extension ilike :search) ";
			$parameters['search'] = '%'.$search.'%';
		}
		$sql .= "order by c.start_stamp desc limit 200";
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	public function call_routes(): array {
		$sql = "select destination_number, destination_app, destination_data ";
		$sql .= "from v_destinations where domain_uuid = :domain_uuid and destination_enabled = true ";
		$sql .= "order by destination_order asc, destination_number asc limit 6";
		$rows = $this->database->select($sql, ['domain_uuid' => $this->domain_uuid], 'all');
		if (!is_array($rows)) {
			return [];
		}

		$targets = [];
		foreach (['v_ivr_menus' => ['ivr_menu_extension', 'ivr_menu_name', 'URA'],
			'v_ring_groups' => ['ring_group_extension', 'ring_group_name', 'Grupo']
		] as $table => [$extension_column, $name_column, $label]) {
			$target_rows = $this->database->select(
				"select ".$extension_column." as extension, ".$name_column." as name from ".$table." where domain_uuid = :domain_uuid and ".($table === 'v_ivr_menus' ? 'ivr_menu_enabled' : 'ring_group_enabled')." = true",
				['domain_uuid' => $this->domain_uuid], 'all');
			foreach (($target_rows ?? []) as $target) {
				$targets[(string) $target['extension']] = $label.': '.(string) $target['name'];
			}
		}
		$extension_rows = $this->database->select(
			"select extension, coalesce(nullif(directory_first_name || ' ' || directory_last_name, ' '), extension) as name from v_extensions where domain_uuid = :domain_uuid and enabled = true",
			['domain_uuid' => $this->domain_uuid], 'all');
		foreach (($extension_rows ?? []) as $target) {
			$targets[(string) $target['extension']] = 'Ramal '.$target['extension'];
		}

		foreach ($rows as &$row) {
			$data = trim((string) ($row['destination_data'] ?? ''));
			$first = preg_split('/\s+/', $data)[0] ?? '';
			$row['target_label'] = $targets[$first] ?? 'Destino configurado';
		}
		unset($row);
		return $rows;
	}

	/**
	 * As notas que o cliente digitou no fim da ligação.
	 *
	 * O escopo segue o mesmo de todas as consultas daqui: `extension_scope_sql`
	 * compara `extension_uuid`, e é por isso que a tabela guarda o uuid do ramal
	 * e não só o número. Perfil `user` sem visão de domínio vê as notas dos
	 * ramais dele; quem tem `xml_cdr_domain` vê o domínio inteiro.
	 */
	public function rating_summary(DateTimeImmutable $since): array {
		$sql = "select count(*) as respostas, round(avg(p.nota), 2) as media ";
		$sql .= "from v_simplificaja_pesquisas p ";
		$sql .= "where p.domain_uuid = :domain_uuid and p.criado_em >= :since ";
		$parameters = ['domain_uuid' => $this->domain_uuid, 'since' => $since->format('Y-m-d H:i:sP')];
		$sql .= $this->extension_scope_sql('p.extension_uuid', $parameters, $this->cdr_domain_view);
		$rows = $this->database->select($sql, $parameters, 'all');
		if (!is_array($rows) || empty($rows[0])) {
			return ['respostas' => 0, 'media' => null];
		}
		return $rows[0];
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

	/** Menor média primeiro: é a lista que o gestor abre para agir. */
	public function rating_by_extension(DateTimeImmutable $since): array {
		$sql = "select coalesce(p.ramal, 'sem ramal') as ramal, count(*) as respostas, ";
		$sql .= "round(avg(p.nota), 2) as media from v_simplificaja_pesquisas p ";
		$sql .= "where p.domain_uuid = :domain_uuid and p.criado_em >= :since ";
		$parameters = ['domain_uuid' => $this->domain_uuid, 'since' => $since->format('Y-m-d H:i:sP')];
		$sql .= $this->extension_scope_sql('p.extension_uuid', $parameters, $this->cdr_domain_view);
		$sql .= "group by coalesce(p.ramal, 'sem ramal') order by media asc, respostas desc";
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	/**
	 * Média por pergunta. Com mais de uma pesquisa na mesma fila -- "nota do
	 * atendente" e depois "nota da empresa" -- a média geral mistura coisas
	 * diferentes e não diz nada. Esta é a leitura que importa.
	 *
	 * O nome da pergunta mora no plano de discagem, não na tabela de notas, para
	 * não haver dois lugares com o mesmo nome saindo de sincronia.
	 */
	public function rating_by_question(DateTimeImmutable $since): array {
		$sql = "select p.pesquisa, coalesce(d.dialplan_name, p.pesquisa) as pergunta, ";
		$sql .= "count(*) as respostas, round(avg(p.nota), 2) as media ";
		$sql .= "from v_simplificaja_pesquisas p ";
		$sql .= "left join v_dialplans d on d.domain_uuid = p.domain_uuid ";
		$sql .= "and d.dialplan_number = p.pesquisa and d.app_uuid = :app_uuid ";
		$sql .= "where p.domain_uuid = :domain_uuid and p.criado_em >= :since ";
		$parameters = [
			'domain_uuid' => $this->domain_uuid,
			'since' => $since->format('Y-m-d H:i:sP'),
			'app_uuid' => '1e9f0b45-d844-40ab-b900-aec2f465d408',
		];
		$sql .= $this->extension_scope_sql('p.extension_uuid', $parameters, $this->cdr_domain_view);
		$sql .= "group by p.pesquisa, d.dialplan_name order by p.pesquisa";
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	public function rating_history(int $days, string $search): array {
		$since = new DateTimeImmutable('-'.max(1, min($days, 90)).' days');
		$sql = "select p.criado_em, p.nota, p.ramal, p.telefone, ";
		$sql .= "coalesce(d.dialplan_name, p.pesquisa) as pergunta, ";
		// O nome da fila mora na tabela dela, nao na nota: assim renomear a fila
		// nao deixa o historico com dois nomes para a mesma coisa.
		$sql .= "coalesce(q.queue_name, p.fila) as fila ";
		$sql .= "from v_simplificaja_pesquisas p ";
		$sql .= "left join v_dialplans d on d.domain_uuid = p.domain_uuid ";
		$sql .= "and d.dialplan_number = p.pesquisa and d.app_uuid = :app_uuid ";
		$sql .= "left join v_call_center_queues q on q.domain_uuid = p.domain_uuid ";
		$sql .= "and q.queue_extension = p.fila ";
		$sql .= "where p.domain_uuid = :domain_uuid and p.criado_em >= :since ";
		$parameters = [
			'domain_uuid' => $this->domain_uuid,
			'since' => $since->format('Y-m-d H:i:sP'),
			'app_uuid' => '1e9f0b45-d844-40ab-b900-aec2f465d408',
		];
		$sql .= $this->extension_scope_sql('p.extension_uuid', $parameters, $this->cdr_domain_view);
		if ($search !== '') {
			$sql .= "and (p.telefone ilike :search or p.ramal ilike :search) ";
			$parameters['search'] = '%'.$search.'%';
		}
		$sql .= "order by p.criado_em desc limit 200";
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	public function domain_name(): string {
		return $this->domain_name;
	}
}
