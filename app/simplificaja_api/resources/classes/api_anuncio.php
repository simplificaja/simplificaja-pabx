<?php

/**
 * Anúncio: toca um áudio e segue para um destino.
 *
 * O FusionPBX não tem app de anúncio -- ele tem URA, grupo, fila e condição de
 * horário. Antes isto era uma URA sem opções, e o atalho cobrava três coisas:
 * a URA espera DTMF, então sobrava silêncio no fim; qualquer tecla contava como
 * entrada inválida e pulava o áudio, sem ninguém ter escolhido isso; e o
 * anúncio aparecia misturado aos menus na tela do próprio FusionPBX.
 *
 * Aqui é um plano de discagem direto -- `answer`, `playback`, `transfer` --,
 * que é o que o Announcement do Issabel faz por baixo.
 */
class api_anuncio {

	/** Separa os anúncios dos outros planos de discagem nas listagens. */
	const APP_UUID = 'a6f1e4d2-7b30-4c58-9e11-8d2a5c7f3b04';

	/** Faixa própria: ramal é 1xxx, grupo 2xxx, fila e menu 9xxx. */
	const FAIXA_INICIO = 8000;
	const FAIXA_FIM = 8999;

	/** Depois do `answer` o outro lado ainda está abrindo o canal de voz, e o
	 *  começo do áudio some. Mesmo motivo da saudação da fila. */
	const ESPERA_ANTES_DO_AUDIO = 1000;

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
			."dialplan_enabled, coalesce(length(dialplan_xml), 0) as xml_bytes "
			."from v_dialplans where domain_uuid = :u and app_uuid = :a "
			."order by dialplan_number",
			['u' => $domain_uuid, 'a' => self::APP_UUID], 'all'
		) ?? [];

		// O áudio e o destino moram no XML: vêm de lá para a tela não precisar
		// de uma tabela paralela que sai de sincronia com o plano de discagem.
		foreach ($linhas as &$linha) {
			$xml = self::db()->select(
				"select dialplan_xml from v_dialplans where dialplan_uuid = :d",
				['d' => $linha['dialplan_uuid']], 'column'
			);
			$linha['audio'] = self::extrair($xml, 'playback');
			$destino = self::extrair($xml, 'transfer');
			$linha['destino'] = $destino === null ? null : explode(' ', $destino)[0];
		}
		return $linhas;
	}

	private static function extrair(?string $xml, string $aplicacao): ?string {
		if ($xml === null) {
			return null;
		}
		if (preg_match('/application="' . $aplicacao . '" data="([^"]*)"/', $xml, $m) !== 1) {
			return null;
		}
		return $m[1] === '' ? null : $m[1];
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
			responde(['erro' => 'número do anúncio deve ser numérico'], 422);
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
			(string) $dados['nome'], $audio,
			trim((string) ($dados['destino'] ?? '')),
			(string) ($dados['descricao'] ?? ''));
		$db->save($array);
		self::exigir_gravado($db, "o anúncio $numero");
		foreach (['dialplan_add', 'dialplan_detail_add'] as $permissao) {
			$p->delete($permissao, 'temp');
		}

		self::recarregar($dominio);

		return ['anuncio' => $dados['nome'], 'numero' => $numero, 'audio' => basename($audio)];
	}

	public static function remover(string $domain_uuid, string $numero): array {
		$db = self::db();
		$uuid = $db->select(
			"select dialplan_uuid from v_dialplans "
			."where domain_uuid = :u and app_uuid = :a and dialplan_number = :n",
			['u' => $domain_uuid, 'a' => self::APP_UUID, 'n' => $numero], 'column'
		);
		if (empty($uuid)) {
			responde(['erro' => "não existe anúncio no número $numero"], 404);
		}

		$p = permissions::new();
		$p->add('dialplan_delete', 'temp');
		$db->execute("delete from v_dialplan_details where dialplan_uuid = :d", ['d' => $uuid]);
		$db->execute("delete from v_dialplans where dialplan_uuid = :d", ['d' => $uuid]);
		$p->delete('dialplan_delete', 'temp');

		self::recarregar(self::nome_do_dominio($domain_uuid));

		return ['anuncio' => $numero, 'removido' => true];
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

	/** O plano de discagem inteiro. Sem `ivr`: nada de DTMF, nada de espera. */
	private static function dialplan(string $domain_uuid, string $uuid, string $dominio,
		string $numero, string $nome, string $audio, string $destino, string $descricao): array {

		$xml  = '<extension name="' . xml::sanitize($nome) . '" continue="" uuid="' . xml::sanitize($uuid) . '">' . "\n";
		$xml .= '	<condition field="destination_number" expression="^' . xml::sanitize($numero) . '$">' . "\n";
		$xml .= '		<action application="answer" data=""/>' . "\n";
		// `none` é o "Allow Skip = não" do Issabel: sem isto uma tecla durante o
		// áudio corta o anúncio, que é o defeito que a URA sem opções tinha.
		$xml .= '		<action application="set" data="playback_terminators=none"/>' . "\n";
		$xml .= '		<action application="sleep" data="' . self::ESPERA_ANTES_DO_AUDIO . '"/>' . "\n";
		$xml .= '		<action application="playback" data="' . xml::sanitize($audio) . '"/>' . "\n";
		if ($destino !== '' && $destino !== 'desligar') {
			$xml .= '		<action application="transfer" data="' . xml::sanitize($destino) . ' XML ' . $dominio . '"/>' . "\n";
		}
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
			// Antes das rotas de entrada (230) e depois dos ramais: o anúncio é
			// destino interno, alcançado por transferência.
			'dialplan_order'       => '220',
			'dialplan_enabled'     => 'true',
			'dialplan_description' => $descricao,
			'app_uuid'             => self::APP_UUID,
		];
	}

	/** As quatro tabelas que dividem o plano de numeração, mais os anúncios. */
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
	 * Plano gravado não basta: o FreeSWITCH serve o dialplan do cache, e o
	 * cache tem três variantes de chave -- só apagar a chave nua deixa a
	 * antiga viva. Mesma armadilha da fila.
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
