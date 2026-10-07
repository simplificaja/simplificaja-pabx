<?php

/**
 * Pesquisa de avaliação: pergunta a nota no fim da ligação e grava.
 *
 * É alcançada só por `transfer_after_bridge`, que o FreeSWITCH dispara ao fim
 * do bridge -- quando o atendente desliga. Transferência entre ramais não chega
 * aqui: o canal carrega `CF_TRANSFER` e o bloco pós-bridge é pulado
 * (`switch_ivr_bridge.c:969`). Ninguém atendeu também não chega: sem bridge, o
 * bloco nunca roda.
 *
 * Plano de discagem direto, não URA do FusionPBX: a URA deles tem menu, opção
 * e destino por tecla, que não é o que uma nota de 1 a 5 precisa -- e o mesmo
 * motivo pelo qual o anúncio deixou de ser URA.
 */
class api_pesquisa {

	/** Separa as pesquisas dos outros planos de discagem nas listagens. */
	const APP_UUID = '1e9f0b45-d844-40ab-b900-aec2f465d408';

	/** Faixa própria: 1xxx ramal, 2xxx grupo, 7xxx horário, 8xxx anúncio, 9xxx fila. */
	const FAIXA_INICIO = 6000;
	const FAIXA_FIM = 6999;

	/** Depois do horário (225), antes das rotas de entrada (230). */
	const ORDEM_NO_PLANO = '226';

	const TENTATIVAS = 3;
	const ESPERA_RESPOSTA = 5000;
	const ESPERA_ENTRE_DIGITOS = 3000;

	/** A escala do CsatSurveyResponse do Chatwoot, para a nota viajar sem conversão. */
	const NOTA_MINIMA = 1;
	const NOTA_MAXIMA = 5;

	/** Mora em /usr/share/freeswitch/scripts; o `require` de lá depende disso. */
	const SCRIPT = 'simplificaja_pesquisa.lua';

	private static function db() {
		return database::new(['db' => $GLOBALS['db'] ?? null]);
	}

	private static function nome_do_dominio(string $domain_uuid): string {
		$nome = self::db()->select(
			"select domain_name from v_domains where domain_uuid = :u",
			['u' => $domain_uuid], 'column'
		);
		if (empty($nome)) {
			responde(['erro' => 'domínio não encontrado'], 404);
		}
		return (string) $nome;
	}

	private static function caminho_do_audio(string $dominio, string $arquivo): string {
		return '/var/lib/freeswitch/recordings/' . $dominio . '/' . basename($arquivo);
	}

	public static function listar(string $domain_uuid): array {
		$linhas = self::db()->select(
			"select dialplan_uuid, dialplan_name, dialplan_number, dialplan_description, "
			."dialplan_enabled, dialplan_xml "
			."from v_dialplans where domain_uuid = :u and app_uuid = :a "
			."order by dialplan_number",
			['u' => $domain_uuid, 'a' => self::APP_UUID], 'all'
		) ?? [];

		// O áudio mora no XML, para não haver tabela paralela saindo de sincronia
		// com o plano de discagem. Mesmo motivo do anúncio.
		foreach ($linhas as &$linha) {
			$linha['audio'] = self::audio_do_xml($linha['dialplan_xml'] ?? null);
			$linha['usada_por'] = self::quem_usa($domain_uuid, (string) $linha['dialplan_number']);
			unset($linha['dialplan_xml']);
		}
		return $linhas;
	}

	/**
	 * O áudio é o 6º argumento do `play_and_get_digits`, que são separados por
	 * espaço. Pegar por posição, não por regex de caminho: o caminho muda de
	 * domínio para domínio.
	 */
	private static function audio_do_xml(?string $xml): ?string {
		if ($xml === null) {
			return null;
		}
		if (!preg_match('/application="play_and_get_digits" data="([^"]*)"/', $xml, $m)) {
			return null;
		}
		$partes = explode(' ', $m[1]);
		return isset($partes[5]) ? basename($partes[5]) : null;
	}

	/** Filas cujo plano transfere para esta pesquisa ao fim do bridge. */
	private static function quem_usa(string $domain_uuid, string $numero): array {
		$linhas = self::db()->select(
			"select dialplan_number from v_dialplans "
			."where domain_uuid = :u and dialplan_xml like :p "
			."order by dialplan_number",
			['u' => $domain_uuid, 'p' => '%transfer_after_bridge=' . $numero . ':%'], 'all'
		) ?? [];
		return array_column($linhas, 'dialplan_number');
	}

	public static function criar(string $domain_uuid, array $dados): array {
		foreach (['nome', 'audio'] as $campo) {
			if (empty($dados[$campo])) {
				responde(['erro' => "$campo é obrigatório"], 422);
			}
		}

		$numero = trim((string) ($dados['numero'] ?? ''));
		if ($numero === '') {
			$numero = self::proximo_livre($domain_uuid);
			if ($numero === null) {
				responde(['erro' => 'não há número interno livre na faixa '
					. self::FAIXA_INICIO . '-' . self::FAIXA_FIM], 409);
			}
		} elseif (!ctype_digit($numero)) {
			responde(['erro' => 'número da pesquisa deve ser numérico'], 422);
		} elseif (self::ocupado($domain_uuid, $numero)) {
			responde(['erro' => "o número $numero já está em uso"], 409);
		}

		$dominio = self::nome_do_dominio($domain_uuid);
		$audio = self::caminho_do_audio($dominio, (string) $dados['audio']);
		if (!file_exists($audio)) {
			responde(['erro' => 'áudio não existe neste domínio'], 404);
		}

		$uuid = uuid();
		$p = permissions::new();
		foreach (['dialplan_add', 'dialplan_detail_add'] as $permissao) {
			$p->add($permissao, 'temp');
		}

		$db = self::db();
		$array['dialplans'][0] = self::dialplan($domain_uuid, $uuid, $dominio, $numero,
			(string) $dados['nome'], $audio, (string) ($dados['descricao'] ?? ''));
		$db->save($array);
		self::exigir_gravado($db, "a pesquisa $numero");
		foreach (['dialplan_add', 'dialplan_detail_add'] as $permissao) {
			$p->delete($permissao, 'temp');
		}

		self::recarregar($dominio);

		return ['pesquisa' => $dados['nome'], 'numero' => $numero, 'audio' => basename($audio)];
	}

	/**
	 * Edita no lugar, sem trocar o número: ele é endereço, e a fila que aponta
	 * para a pesquisa guarda esse endereço no `transfer_after_bridge`. Trocar
	 * deixaria a fila transferindo para um número que não existe.
	 */
	public static function editar(string $domain_uuid, array $dados): array {
		$numero = trim((string) ($dados['numero'] ?? ''));
		if ($numero === '') {
			responde(['erro' => 'numero é obrigatório'], 422);
		}
		foreach (['nome', 'audio'] as $campo) {
			if (empty($dados[$campo])) {
				responde(['erro' => "$campo é obrigatório"], 422);
			}
		}

		$db = self::db();
		$uuid = $db->select(
			"select dialplan_uuid from v_dialplans "
			."where domain_uuid = :u and app_uuid = :a and dialplan_number = :n",
			['u' => $domain_uuid, 'a' => self::APP_UUID, 'n' => $numero], 'column'
		);
		if (empty($uuid)) {
			responde(['erro' => "não existe pesquisa no número $numero"], 404);
		}

		$dominio = self::nome_do_dominio($domain_uuid);
		$audio = self::caminho_do_audio($dominio, (string) $dados['audio']);
		if (!file_exists($audio)) {
			responde(['erro' => 'áudio não existe neste domínio'], 404);
		}

		$p = permissions::new();
		foreach (['dialplan_add', 'dialplan_edit', 'dialplan_detail_add'] as $permissao) {
			$p->add($permissao, 'temp');
		}
		$array['dialplans'][0] = self::dialplan($domain_uuid, $uuid, $dominio, $numero,
			(string) $dados['nome'], $audio, (string) ($dados['descricao'] ?? ''));
		$db->save($array);
		self::exigir_gravado($db, "a pesquisa $numero");
		foreach (['dialplan_add', 'dialplan_edit', 'dialplan_detail_add'] as $permissao) {
			$p->delete($permissao, 'temp');
		}

		self::recarregar($dominio);

		return ['pesquisa' => $dados['nome'], 'numero' => $numero, 'audio' => basename($audio)];
	}

	public static function remover(string $domain_uuid, string $numero): array {
		$db = self::db();
		$uuid = $db->select(
			"select dialplan_uuid from v_dialplans "
			."where domain_uuid = :u and app_uuid = :a and dialplan_number = :n",
			['u' => $domain_uuid, 'a' => self::APP_UUID, 'n' => $numero], 'column'
		);
		if (empty($uuid)) {
			responde(['erro' => "não existe pesquisa no número $numero"], 404);
		}

		// Fila que ainda aponta para esta pesquisa passaria a transferir para um
		// número inexistente, e aí quem ligou ouve erro em vez de desligar.
		// Recusar é mais honesto que deixar quebrado em silêncio.
		$usada = self::quem_usa($domain_uuid, $numero);
		if (!empty($usada)) {
			responde(['erro' => "a pesquisa $numero ainda é usada pela fila "
				. implode(', ', $usada) . '; tire a pesquisa da fila antes de remover'], 409);
		}

		$p = permissions::new();
		$p->add('dialplan_delete', 'temp');
		$db->execute("delete from v_dialplan_details where dialplan_uuid = :d", ['d' => $uuid]);
		$db->execute("delete from v_dialplans where dialplan_uuid = :d", ['d' => $uuid]);
		$p->delete('dialplan_delete', 'temp');

		self::recarregar(self::nome_do_dominio($domain_uuid));

		return ['pesquisa' => $numero, 'removido' => true];
	}

	/**
	 * `database::save()` não lança exceção: campo que ele não reconhece faz a
	 * linha não entrar e a chamada volta como se tivesse dado certo.
	 */
	private static function exigir_gravado(database $db, string $oque): void {
		$codigo = (int) ($db->message['code'] ?? 0);
		if ($codigo !== 200) {
			responde(['erro' => "o PABX não gravou $oque",
				'detalhe' => $db->message['message'] ?? null], 500);
		}
	}

	private static function dialplan(string $domain_uuid, string $uuid, string $dominio,
		string $numero, string $nome, string $audio, string $descricao): array {

		// Os argumentos do `play_and_get_digits` são separados por ESPAÇO
		// (mod_dptools.c:2841), nesta ordem: mínimo, máximo, tentativas, espera,
		// terminadores, áudio, áudio-de-erro, variável, regex, espera-entre.
		//
		// Nome de áudio com espaço quebraria isto. Hoje é impossível porque
		// api_gravacao.php sanea o nome com [^A-Za-z0-9_-] -- quem relaxar
		// aquele regex quebra a pesquisa sem tocar em nenhum arquivo dela.
		$coleta = implode(' ', [
			'1', '1', (string) self::TENTATIVAS, (string) self::ESPERA_RESPOSTA, '#',
			$audio, $audio, 'nota',
			'^[' . self::NOTA_MINIMA . '-' . self::NOTA_MAXIMA . ']$',
			(string) self::ESPERA_ENTRE_DIGITOS,
		]);

		$xml  = '<extension name="' . xml::sanitize($nome) . '" continue="" uuid="' . xml::sanitize($uuid) . '">' . "\n";
		$xml .= '	<condition field="destination_number" expression="^' . xml::sanitize($numero) . '$">' . "\n";
		$xml .= '		<action application="answer" data=""/>' . "\n";
		// Mesma constante do anúncio: sem o silêncio a operadora descarta o
		// começo da frase, porque o caminho de áudio até o celular ainda não
		// está pronto quando o nosso 200 OK sai. `sleep` não serve -- espera sem
		// mandar pacote, e o buffer de jitter só sincroniza recebendo áudio.
		$xml .= '		<action application="playback" data="silence_stream://'
			. api_anuncio::ESPERA_ANTES_DO_AUDIO . '"/>' . "\n";
		$xml .= '		<action application="play_and_get_digits" data="' . xml::sanitize($coleta) . '"/>' . "\n";
		$xml .= '		<action application="lua" data="' . self::SCRIPT . '"/>' . "\n";
		$xml .= '		<action application="hangup" data=""/>' . "\n";
		$xml .= '	</condition>' . "\n";
		$xml .= '</extension>' . "\n";

		return [
			'dialplan_uuid'        => $uuid,
			'domain_uuid'          => $domain_uuid,
			'dialplan_name'        => $nome,
			'dialplan_number'      => $numero,
			'dialplan_context'     => $dominio,
			'dialplan_continue'    => 'false',
			'dialplan_xml'         => $xml,
			'dialplan_order'       => self::ORDEM_NO_PLANO,
			'dialplan_enabled'     => 'true',
			'dialplan_description' => $descricao,
			'app_uuid'             => self::APP_UUID,
		];
	}

	/** As quatro tabelas que dividem o plano de numeração, mais os planos. */
	private static function ocupado(string $domain_uuid, string $numero): bool {
		$consultas = [
			"select ivr_menu_uuid as u from v_ivr_menus where domain_uuid = :u and ivr_menu_extension = :n",
			"select extension_uuid as u from v_extensions where domain_uuid = :u and extension = :n",
			"select call_center_queue_uuid as u from v_call_center_queues where domain_uuid = :u and queue_extension = :n",
			"select ring_group_uuid as u from v_ring_groups where domain_uuid = :u and ring_group_extension = :n",
			"select dialplan_uuid as u from v_dialplans where domain_uuid = :u and dialplan_number = :n",
		];
		foreach ($consultas as $sql) {
			$achou = self::db()->select($sql, ['u' => $domain_uuid, 'n' => $numero], 'column');
			if (!empty($achou)) {
				return true;
			}
		}
		return false;
	}

	private static function proximo_livre(string $domain_uuid): ?string {
		for ($n = self::FAIXA_INICIO; $n <= self::FAIXA_FIM; $n++) {
			if (!self::ocupado($domain_uuid, (string) $n)) {
				return (string) $n;
			}
		}
		return null;
	}

	/**
	 * Plano gravado não basta: o FreeSWITCH serve o dialplan do cache, e o cache
	 * tem três variantes de chave -- só apagar a chave nua deixa a antiga viva.
	 * Mesma armadilha da fila e do anúncio.
	 */
	private static function recarregar(string $dominio): void {
		$cache = new cache();
		foreach (['dialplan:' . $dominio, $dominio . ':dialplan', 'dialplan'] as $chave) {
			$cache->delete($chave);
		}
		$socket = event_socket::create();
		if ($socket && $socket->is_connected()) {
			event_socket::api('reloadxml');
		}
	}
}
