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
	require __DIR__ . "/resources/classes/api_saude.php";
	require __DIR__ . "/resources/classes/api_ramal.php";
	require __DIR__ . "/resources/classes/api_tronco.php";
	require __DIR__ . "/resources/classes/api_destino.php";
	require __DIR__ . "/resources/classes/api_dominio.php";
	require __DIR__ . "/resources/classes/api_ura.php";
	require __DIR__ . "/resources/classes/api_gravacao.php";
	require __DIR__ . "/resources/classes/api_grupo.php";
	require __DIR__ . "/resources/classes/api_saida.php";

	header('Content-Type: application/json; charset=utf-8');

	/**
	 * Responde em JSON e encerra.
	 */
	function responde($dados, int $codigo = 200): void {
		http_response_code($codigo);
		echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	$metodo = $_SERVER['REQUEST_METHOD'];
	$rota_pedida = trim($_SERVER['PATH_INFO'] ?? '', '/');
	if ($rota_pedida === '') { $rota_pedida = trim($_GET['r'] ?? '', '/'); }

	// As rotas de domínio criam ou apagam o tenant, então não têm domínio de
	// onde tirar escopo: usam a chave de instância. Todo o resto é escopado
	// pela chave do domínio.
	if ($rota_pedida === 'dominio' && in_array($metodo, ['POST', 'DELETE'], true)) {
		api_auth::exigir_admin();
		$domain_uuid = null;
	}
	else {
		$domain_uuid = api_auth::domain_uuid();
	}

	/** Corpo JSON da requisição, ou lista vazia. */
	function corpo(): array {
		$bruto = file_get_contents('php://input');
		return json_decode($bruto, true) ?? [];
	}

	// A rota vem por parâmetro, não por caminho. O nginx do FusionPBX casa
	// `location ~ \.php$` -- com o `$` ancorando no fim -- então /index.php/ping
	// devolve 404 sem passar pelo PHP. Daria para acrescentar um bloco no nginx,
	// mas isso viraria edição obrigatória de nginx em toda instalação nova, e é
	// onde se derruba o painel inteiro do PABX. PATH_INFO continua aceito para
	// quem tiver o nginx preparado.
	// Todo DELETE le do corpo, como POST. Tres liam da query e o de dominio
	// do corpo: a inconsistencia custa uma hora de quem for escrever o
	// proximo cliente, e o sintoma e 422 dizendo que falta um campo que
	// esta sendo mandado.
	$rota = $rota_pedida;

	switch ("$metodo $rota") {

		case 'GET ping':
			responde(['ok' => true, 'domain_uuid' => $domain_uuid]);

		case 'GET dominio':
			responde(api_leitura::dominio($domain_uuid));

		case 'GET ramais':
			responde(api_leitura::ramais($domain_uuid));

		case 'GET troncos':
			responde(api_leitura::troncos($domain_uuid));

		case 'GET saude':
			responde(api_saude::verificar($domain_uuid));

		case 'POST ramais':
			responde(api_ramal::criar($domain_uuid, corpo()), 201);

		case 'DELETE ramais':
			$numero = trim((string) (corpo()['extension'] ?? ''));
			if ($numero === '') { responde(['erro' => 'extension é obrigatório'], 422); }
			responde(api_ramal::remover($domain_uuid, $numero));

		case 'POST troncos':
			responde(api_tronco::criar($domain_uuid, corpo()), 201);

		case 'DELETE troncos':
			$nome = trim((string) (corpo()['nome'] ?? ''));
			if ($nome === '') { responde(['erro' => 'nome é obrigatório'], 422); }
			responde(api_tronco::remover($domain_uuid, $nome));

		case 'POST dominio':
			responde(api_dominio::criar(corpo()), 201);

		case 'DELETE dominio':
			responde(api_dominio::remover(corpo()));

		case 'GET uras':
			responde(api_leitura::uras($domain_uuid));

		case 'POST uras':
			responde(api_ura::criar($domain_uuid, corpo()));

		case 'DELETE uras':
			$ramal = trim((string) (corpo()['ramal'] ?? ''));
			if ($ramal === '') { responde(['erro' => 'ramal é obrigatório'], 422); }
			responde(api_ura::remover($domain_uuid, $ramal));

		case 'GET gravacoes':
			responde(api_leitura::gravacoes($domain_uuid));

		case 'POST gravacoes':
			responde(api_gravacao::criar($domain_uuid, corpo()));

		case 'DELETE gravacoes':
			$nome = trim((string) (corpo()['nome'] ?? ''));
			if ($nome === '') { responde(['erro' => 'nome é obrigatório'], 422); }
			responde(api_gravacao::remover($domain_uuid, $nome));

		case 'GET saida':
			responde(api_saida::ler($domain_uuid));

		case 'POST saida':
			responde(api_saida::criar($domain_uuid, corpo()), 201);

		case 'DELETE saida':
			responde(api_saida::remover($domain_uuid));

		case 'GET grupos':
			responde(api_leitura::grupos($domain_uuid));

		case 'POST grupos':
			responde(api_grupo::criar($domain_uuid, corpo()), 201);

		case 'DELETE grupos':
			$ramal = trim((string) (corpo()['ramal'] ?? ''));
			if ($ramal === '') { responde(['erro' => 'ramal é obrigatório'], 422); }
			responde(api_grupo::remover($domain_uuid, $ramal));

		// O conteudo de UMA gravacao. O nome vem na query porque GET nao tem
		// corpo; e o unico lugar da API que le de `$_GET`.
		case 'GET gravacao':
			$nome = trim((string) ($_GET['nome'] ?? ''));
			if ($nome === '') { responde(['erro' => 'nome é obrigatório'], 422); }
			responde(api_leitura::gravacao($domain_uuid, $nome));

		case 'GET destinos':
			responde(api_leitura::destinos($domain_uuid));

		case 'POST destinos':
			responde(api_destino::criar($domain_uuid, corpo()), 201);

		case 'DELETE destinos':
			$numero = trim((string) (corpo()['numero'] ?? ''));
			if ($numero === '') { responde(['erro' => 'numero é obrigatório'], 422); }
			responde(api_destino::remover($domain_uuid, $numero));

		default:
			responde(['erro' => "rota desconhecida: $metodo $rota"], 404);
	}
