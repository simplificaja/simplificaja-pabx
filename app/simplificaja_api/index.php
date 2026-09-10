<?php

/**
 * API do SimplificaJá dentro do FusionPBX.
 *
 * Um arquivo despachando por caminho, sem framework: o FusionPBX não tem um, e
 * trazer dependência para cá é dívida sem ganho.
 */

	require dirname(__DIR__, 2) . "/resources/require.php";
	require __DIR__ . "/resources/classes/api_auth.php";
	require __DIR__ . "/resources/classes/api_leitura.php";

	header('Content-Type: application/json; charset=utf-8');

	/**
	 * Responde em JSON e encerra.
	 */
	function responde($dados, int $codigo = 200): void {
		http_response_code($codigo);
		echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	$domain_uuid = api_auth::domain_uuid();
	$metodo = $_SERVER['REQUEST_METHOD'];

	// A rota vem por parâmetro, não por caminho. O nginx do FusionPBX casa
	// `location ~ \.php$` -- com o `$` ancorando no fim -- então /index.php/ping
	// devolve 404 sem passar pelo PHP. Daria para acrescentar um bloco no nginx,
	// mas isso viraria edição obrigatória de nginx em toda instalação nova, e é
	// onde se derruba o painel inteiro do PABX. PATH_INFO continua aceito para
	// quem tiver o nginx preparado.
	$rota = trim($_SERVER['PATH_INFO'] ?? '', '/');
	if ($rota === '') {
		$rota = trim($_GET['r'] ?? '', '/');
	}

	switch ("$metodo $rota") {

		case 'GET ping':
			responde(['ok' => true, 'domain_uuid' => $domain_uuid]);

		case 'GET dominio':
			responde(api_leitura::dominio($domain_uuid));

		case 'GET ramais':
			responde(api_leitura::ramais($domain_uuid));

		case 'GET troncos':
			responde(api_leitura::troncos($domain_uuid));

		default:
			responde(['erro' => "rota desconhecida: $metodo $rota"], 404);
	}
