<?php

/**
 * Criação e remoção de ramal.
 *
 * Escreve pelas classes do FusionPBX, nunca por SQL: o diretório servido ao
 * FreeSWITCH é gerado pelo código deles, e linha inserida por fora não existe
 * para o switch. Ver docs/armadilhas.md.
 */
class api_ramal {

	/** Chamadas simultâneas por ramal. Teto contra fraude tarifária: um ramal
	 *  comprometido consegue abrir dezenas de chamadas em segundos. */
	const LIMITE_SIMULTANEAS = 2;

	private static function db() {
		return database::new(['db' => $GLOBALS['db'] ?? null]);
	}

	public static function criar(string $domain_uuid, array $dados): array {
		$numero = trim((string) ($dados['extension'] ?? ''));
		if ($numero === '' || !ctype_digit($numero)) {
			responde(['erro' => 'extension é obrigatório e deve ser numérico'], 422);
		}

		$db = self::db();

		$dominio = $db->select(
			"select domain_name from v_domains where domain_uuid = :u",
			['u' => $domain_uuid], 'column'
		);
		if (empty($dominio)) {
			responde(['erro' => 'domínio da chave não existe'], 500);
		}

		$existe = (int) $db->select(
			"select count(*) as n from v_extensions where domain_uuid = :u and extension = :e",
			['u' => $domain_uuid, 'e' => $numero], 'column'
		);
		if ($existe > 0) {
			responde(['erro' => "ramal $numero já existe neste domínio"], 409);
		}

		// Credencial SIP fica exposta na internet: senha longa e aleatória.
		// A primeira varredura ao PABX chegou 13 minutos depois da instalação.
		$senha = self::senha();

		$permissoes = ['extension_add', 'extension_edit'];
		$p = permissions::new();
		foreach ($permissoes as $permissao) {
			$p->add($permissao, 'temp');
		}

		$array['extensions'][0] = [
			'extension_uuid'             => uuid(),
			'domain_uuid'                => $domain_uuid,
			'extension'                  => $numero,
			'password'                   => $senha,
			'accountcode'                => $numero,
			'user_context'               => $dominio,
			'effective_caller_id_name'   => $dados['nome'] ?? $numero,
			'effective_caller_id_number' => $numero,
			'call_timeout'               => 30,
			'limit_max'                  => self::LIMITE_SIMULTANEAS,
			'directory_visible'          => 'true',
			'enabled'                    => 'true',
			'description'                => $dados['descricao'] ?? '',
		];

		$db->save($array);

		foreach ($permissoes as $permissao) {
			$p->delete($permissao, 'temp');
		}

		// O diretório é servido a partir do cache; sem limpar, o ramal novo não
		// registra até o cache expirar. A classe cache é de instância, não
		// estática -- chamar estaticamente é erro fatal.
		$cache = new cache();
		$cache->delete('directory:' . $numero . '@' . $dominio);

		return [
			'extension'  => $numero,
			'senha'      => $senha,
			'dominio'    => $dominio,
			'simultaneas'=> self::LIMITE_SIMULTANEAS,
		];
	}

	public static function remover(string $domain_uuid, string $numero): array {
		$db = self::db();

		$linha = $db->select(
			"select extension_uuid from v_extensions where domain_uuid = :u and extension = :e",
			['u' => $domain_uuid, 'e' => $numero], 'row'
		);
		if (empty($linha)) {
			responde(['erro' => "ramal $numero não existe neste domínio"], 404);
		}

		$dominio = $db->select("select domain_name from v_domains where domain_uuid = :u",
			['u' => $domain_uuid], 'column');

		$p = permissions::new();
		$p->add('extension_delete', 'temp');
		$db->execute("delete from v_extensions where extension_uuid = :x",
			['x' => $linha['extension_uuid']]);
		$p->delete('extension_delete', 'temp');

		$cache = new cache();
		$cache->delete('directory:' . $numero . '@' . $dominio);

		return ['extension' => $numero, 'removido' => true];
	}

	/** Senha aleatória sem caracteres que atrapalham em configuração de softphone. */
	private static function senha(): string {
		$alfabeto = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$senha = '';
		for ($i = 0; $i < 20; $i++) {
			$senha .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
		}
		return $senha;
	}
}
