<?php

/**
 * Criação do domínio de um cliente.
 *
 * Este é o único endpoint que NÃO usa chave de domínio -- ele cria o domínio,
 * então não há domínio ainda. Usa a chave de instância, que vale por todos os
 * clientes e por isso é a joia da coroa: fora do repositório, restrita por IP,
 * rotacionada a qualquer suspeita.
 */
class api_dominio {

	private static function db() {
		return database::new(['db' => $GLOBALS['db'] ?? null]);
	}

	public static function criar(array $dados): array {
		$nome = trim((string) ($dados['dominio'] ?? ''));
		if ($nome === '' || !preg_match('/^[a-z0-9.-]+$/i', $nome)) {
			responde(['erro' => 'dominio é obrigatório e deve ser um nome de host válido'], 422);
		}

		$db = self::db();

		$ja = (int) $db->select("select count(*) as n from v_domains where domain_name = :d",
			['d' => $nome], 'column');
		if ($ja > 0) {
			responde(['erro' => "domínio $nome já existe"], 409);
		}

		$domain_uuid = uuid();

		$p = permissions::new();
		$p->add('domain_add', 'temp');
		$array['domains'][0] = [
			'domain_uuid'        => $domain_uuid,
			'domain_name'        => $nome,
			'domain_enabled'     => 'true',
			'domain_description' => $dados['descricao'] ?? '',
		];
		$db->save($array);
		unset($array);
		$p->delete('domain_add', 'temp');

		// Roda os app_defaults.php dos 33 apps, que é o que faz o domínio nascer
		// utilizável. É o mesmo caminho que o FusionPBX usa; não reimplementar.
		//
		// Percorre TODOS os domínios, não só o novo -- é como o método deles
		// funciona, e os app_defaults são idempotentes.
		self::aplicar_padroes();

		// Chave própria do domínio: daqui em diante o painel fala com este
		// tenant por ela, e o escopo de tudo sai dela.
		$chave = bin2hex(random_bytes(24));
		self::guardar_ajuste($db, $domain_uuid, 'api_key', $chave,
			'Chave da API consumida pelo painel do SimplificaJá');

		// Segredo do gancho de desligamento. Por domínio, e não da instalação,
		// para que um PABX invadido alcance os clientes dele e não todos.
		$segredo = bin2hex(random_bytes(24));
		self::guardar_ajuste($db, $domain_uuid, 'webhook_secret', $segredo,
			'Segredo que o gancho de desligamento manda ao SimplificaJá');

		$socket = event_socket::create();
		if ($socket && $socket->is_connected()) {
			event_socket::api('reloadxml');
		}

		return [
			'domain_uuid' => $domain_uuid,
			'dominio'     => $nome,
			'api_key'     => $chave,
			'webhook_secret' => $segredo,
		];
	}

	/**
	 * Gera as chaves para um domínio que já existe.
	 *
	 * O FusionPBX é multi-tenant e o domínio pode ter nascido pela tela dele --
	 * cliente que comprou só PABX, por exemplo. Sem isto, esse cliente nunca
	 * vira cliente do painel: `criar` recusa domínio existente com 409, e a
	 * saída seria escrever as duas chaves no banco à mão.
	 *
	 * Idempotente: chamar de novo devolve as chaves que já existem em vez de
	 * rotacionar. Rotacionar aqui derrubaria a conexão que o painel já usa.
	 */
	public static function adotar(array $dados): array {
		$nome = trim((string) ($dados['dominio'] ?? ''));
		if ($nome === '') {
			responde(['erro' => 'dominio é obrigatório'], 422);
		}

		$db = self::db();
		$domain_uuid = $db->select(
			"select domain_uuid from v_domains where domain_name = :d",
			['d' => $nome], 'column'
		);
		if (empty($domain_uuid)) {
			responde(['erro' => "domínio $nome não existe; use POST /dominio para criar"], 404);
		}

		$chave = self::ajuste_existente($db, $domain_uuid, 'api_key');
		if ($chave === null) {
			$chave = bin2hex(random_bytes(24));
			self::guardar_ajuste($db, $domain_uuid, 'api_key', $chave,
				'Chave da API consumida pelo painel do SimplificaJá');
		}

		$segredo = self::ajuste_existente($db, $domain_uuid, 'webhook_secret');
		if ($segredo === null) {
			$segredo = bin2hex(random_bytes(24));
			self::guardar_ajuste($db, $domain_uuid, 'webhook_secret', $segredo,
				'Segredo que o gancho de desligamento manda ao SimplificaJá');
		}

		return [
			'domain_uuid'    => $domain_uuid,
			'dominio'        => $nome,
			'api_key'        => $chave,
			'webhook_secret' => $segredo,
			'adotado'        => true,
		];
	}

	private static function ajuste_existente($db, string $domain_uuid, string $chave): ?string {
		$valor = $db->select(
			"select domain_setting_value from v_domain_settings "
			."where domain_uuid = :u and domain_setting_category = 'simplificaja' "
			."and domain_setting_subcategory = :s and domain_setting_enabled = true limit 1",
			['u' => $domain_uuid, 's' => $chave], 'column'
		);
		return empty($valor) ? null : (string) $valor;
	}

	public static function remover(array $dados): array {
		$nome = trim((string) ($dados['dominio'] ?? ''));
		if ($nome === '') {
			responde(['erro' => 'dominio é obrigatório'], 422);
		}

		$db = self::db();
		$linha = $db->select("select domain_uuid from v_domains where domain_name = :d",
			['d' => $nome], 'row');
		if (empty($linha)) {
			responde(['erro' => "domínio $nome não existe"], 404);
		}

		$domain_uuid = $linha['domain_uuid'];

		// Antes de apagar as linhas: o que existe FORA do banco precisa sair
		// aqui, senão vira órfão que ninguém mais consegue associar ao dono.
		self::limpar_fora_do_banco($db, $domain_uuid);

		// Apagar o domínio deixa ramais, troncos e rotas órfãos no banco, que é
		// pior do que não apagar: ficam invisíveis e continuam ocupando número.
		$p = permissions::new();
		foreach (['domain_delete', 'extension_delete', 'gateway_delete',
		          'destination_delete', 'dialplan_delete'] as $permissao) {
			$p->add($permissao, 'temp');
		}

		$db->execute("delete from v_destinations where domain_uuid = :u", ['u' => $domain_uuid]);
		$db->execute("delete from v_dialplan_details where domain_uuid = :u", ['u' => $domain_uuid]);
		$db->execute("delete from v_dialplans where domain_uuid = :u", ['u' => $domain_uuid]);
		$db->execute("delete from v_gateways where domain_uuid = :u", ['u' => $domain_uuid]);
		$db->execute("delete from v_extensions where domain_uuid = :u", ['u' => $domain_uuid]);
		$db->execute("delete from v_domain_settings where domain_uuid = :u", ['u' => $domain_uuid]);
		$db->execute("delete from v_domains where domain_uuid = :u", ['u' => $domain_uuid]);

		foreach (['domain_delete', 'extension_delete', 'gateway_delete',
		          'destination_delete', 'dialplan_delete'] as $permissao) {
			$p->delete($permissao, 'temp');
		}

		$cache = new cache();
		$cache->flush();

		$socket = event_socket::create();
		if ($socket && $socket->is_connected()) {
			event_socket::api('reloadxml');
		}

		return ['dominio' => $nome, 'removido' => true];
	}

	private static function aplicar_padroes(): void {
		$dominios = new domains();
		$dominios->upgrade();
	}

	/**
	 * Duas coisas sobrevivem ao `delete` e viram lixo silencioso:
	 *
	 * - O gateway que o FreeSWITCH já carregou. Sai do Postgres e continua
	 *   tentando registrar na operadora indefinidamente, por um cliente que
	 *   não existe mais.
	 * - O IP da operadora na lista de acesso `providers`, que o `POST
	 *   /troncos` acrescenta. Cada cliente que sai deixa um IP liberado no
	 *   Event Guard para sempre.
	 *
	 * Detectar depois é pior que limpar agora: nenhuma tela mostra isso.
	 */
	private static function limpar_fora_do_banco($db, string $domain_uuid): void {
		$gateways = $db->select(
			"select gateway_uuid, proxy from v_gateways where domain_uuid = :u",
			['u' => $domain_uuid], 'all'
		) ?? [];
		if (empty($gateways)) {
			return;
		}

		$socket = event_socket::create();
		$ligado = $socket && $socket->is_connected();

		foreach ($gateways as $gateway) {
			if ($ligado) {
				event_socket::api('sofia profile external killgw ' . $gateway['gateway_uuid']);
			}
			// O IP só sai se mais nenhum tronco o usar: dois clientes podem
			// vir da mesma operadora, e derrubar o IP levaria o outro junto.
			$em_uso = (int) $db->select(
				"select count(*) as n from v_gateways "
				."where proxy = :p and domain_uuid <> :u",
				['p' => $gateway['proxy'], 'u' => $domain_uuid], 'column'
			);
			if ($em_uso === 0) {
				$db->execute(
					"delete from v_access_control_nodes where node_cidr = :c "
					."and access_control_uuid in (select access_control_uuid from v_access_controls "
					."where access_control_name = 'providers')",
					['c' => $gateway['proxy'] . '/32']
				);
			}
		}

		if ($ligado) {
			event_socket::api('reloadacl');
		}
	}

	private static function guardar_ajuste($db, string $domain_uuid, string $subcategoria,
		string $valor, string $descricao): void {
		$p = permissions::new();
		$p->add('domain_setting_add', 'temp');
		$array['domain_settings'][0] = [
			'domain_setting_uuid'        => uuid(),
			'domain_uuid'                => $domain_uuid,
			'domain_setting_category'    => 'simplificaja',
			'domain_setting_subcategory' => $subcategoria,
			'domain_setting_name'        => 'text',
			'domain_setting_value'       => $valor,
			'domain_setting_enabled'     => 'true',
			'domain_setting_description' => $descricao,
		];
		$db->save($array);
		$p->delete('domain_setting_add', 'temp');
	}
}
