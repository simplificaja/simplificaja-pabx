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
		self::guardar_chave($db, $domain_uuid, $chave);

		$socket = event_socket::create();
		if ($socket && $socket->is_connected()) {
			event_socket::api('reloadxml');
		}

		return [
			'domain_uuid' => $domain_uuid,
			'dominio'     => $nome,
			'api_key'     => $chave,
		];
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

	private static function guardar_chave($db, string $domain_uuid, string $chave): void {
		$p = permissions::new();
		$p->add('domain_setting_add', 'temp');
		$array['domain_settings'][0] = [
			'domain_setting_uuid'        => uuid(),
			'domain_uuid'                => $domain_uuid,
			'domain_setting_category'    => 'simplificaja',
			'domain_setting_subcategory' => 'api_key',
			'domain_setting_name'        => 'text',
			'domain_setting_value'       => $chave,
			'domain_setting_enabled'     => 'true',
			'domain_setting_description' => 'Chave da API consumida pelo painel do SimplificaJá',
		];
		$db->save($array);
		$p->delete('domain_setting_add', 'temp');
	}
}
