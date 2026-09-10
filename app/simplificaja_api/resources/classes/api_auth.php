<?php

/**
 * Autenticação da API por chave de domínio.
 *
 * O escopo de todo endpoint SAI DAQUI. Nenhum endpoint aceita domain_uuid vindo
 * do corpo da requisição -- seria o caminho para um cliente alcançar o outro.
 */
class api_auth {

	const CATEGORIA = 'simplificaja';
	const SUBCATEGORIA = 'api_key';

	/**
	 * Devolve o domain_uuid da chave enviada. Encerra em 401 se não houver
	 * chave válida.
	 */
	public static function domain_uuid(): string {
		$enviada = $_SERVER['HTTP_X_API_KEY'] ?? '';
		if ($enviada === '') {
			self::recusa();
		}

		$database = database::new(['db' => $GLOBALS['db'] ?? null]);
		$linhas = $database->select(
			"select domain_uuid, domain_setting_value "
			."from v_domain_settings "
			."where domain_setting_category = :categoria "
			."and domain_setting_subcategory = :subcategoria "
			."and domain_setting_enabled = true ",
			['categoria' => self::CATEGORIA, 'subcategoria' => self::SUBCATEGORIA],
			'all'
		);

		foreach ($linhas ?? [] as $linha) {
			// hash_equals: comparação de string comum vaza tempo e permite
			// descobrir a chave byte a byte.
			if (hash_equals((string) $linha['domain_setting_value'], $enviada)) {
				return $linha['domain_uuid'];
			}
		}

		self::recusa();
	}

	private static function recusa(): void {
		http_response_code(401);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(['erro' => 'chave inválida ou ausente'], JSON_UNESCAPED_UNICODE);
		exit;
	}
}
