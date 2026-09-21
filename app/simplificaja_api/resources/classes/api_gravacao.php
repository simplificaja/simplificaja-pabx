<?php
/**
 * Gravações de áudio do domínio: saudação de URA, anúncio, espera.
 *
 * Mínimo de propósito: subir, converter, listar e apagar. Gravar pelo
 * navegador e editar áudio são produto de edição de som, não disto.
 */
class api_gravacao {

	const DIRETORIO = '/var/lib/freeswitch/storage/recordings';

	private static function db() {
		return database::new(['db' => $GLOBALS['db'] ?? null]);
	}

	private static function nome_do_dominio(string $domain_uuid): string {
		$nome = self::db()->select(
			"select domain_name from v_domains where domain_uuid = :u",
			['u' => $domain_uuid], 'column'
		);
		if (empty($nome)) {
			responde(['erro' => 'domínio da chave não existe'], 500);
		}
		return $nome;
	}

	public static function criar(string $domain_uuid, array $dados): array {
		// Só o que vira nome de arquivo: o valor entra num caminho e num
		// comando, então nada de barra, espaço ou ponto-ponto.
		$nome = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($dados['nome'] ?? ''));
		if ($nome === '' || empty($dados['audio'])) {
			responde(['erro' => 'nome e audio são obrigatórios'], 422);
		}

		$dominio = self::nome_do_dominio($domain_uuid);
		$pasta = self::DIRETORIO . '/' . $dominio;
		if (!is_dir($pasta) && !mkdir($pasta, 0770, true)) {
			responde(['erro' => "não consegui criar $pasta"], 500);
		}
		@chown($pasta, 'www-data');
		@chgrp($pasta, 'www-data');

		$arquivo = $nome . '.wav';
		$destino = $pasta . '/' . $arquivo;
		if (file_exists($destino)) {
			responde(['erro' => "já existe uma gravação chamada $nome"], 409);
		}

		$bruto = base64_decode((string) $dados['audio'], true);
		if ($bruto === false || $bruto === '') {
			responde(['erro' => 'audio não é base64 válido'], 422);
		}

		// O sox precisa de arquivo, e o formato de entrada pode ser qualquer
		// um que ele leia -- WAV, MP3, o que o cliente tiver.
		$origem = tempnam(sys_get_temp_dir(), 'audio');
		file_put_contents($origem, $bruto);

		// 8 kHz mono 16 bits: o que o FreeSWITCH toca sem reamostrar.
		exec(sprintf('sox %s -r 8000 -c 1 -b 16 %s 2>&1',
			escapeshellarg($origem), escapeshellarg($destino)), $saida, $codigo);
		unlink($origem);

		if ($codigo !== 0 || !file_exists($destino)) {
			responde(['erro' => 'não consegui converter: ' . implode(' ', $saida)], 422);
		}
		@chown($destino, 'www-data');
		@chgrp($destino, 'www-data');

		self::guardar($domain_uuid, $arquivo, $destino, (string) ($dados['descricao'] ?? ''));

		return [
			'gravacao' => $arquivo,
			'dominio'  => $dominio,
			'bytes'    => filesize($destino),
		];
	}

	/**
	 * O app do FusionPBX grava o arquivo em disco E o conteúdo em
	 * `recording_base64` (recordings/recording_edit.php:269). O base64 é o que
	 * a tela usa para tocar. Salvar só o arquivo produz uma gravação que
	 * funciona na chamada e não toca no painel -- falha silenciosa.
	 *
	 * O base64 sai do arquivo JÁ CONVERTIDO, não do original: divergir é ter
	 * um áudio no painel e outro na ligação.
	 */
	private static function guardar(string $domain_uuid, string $arquivo,
		string $caminho, string $descricao): void {
		$p = permissions::new();
		$p->add('recording_add', 'temp');

		$array['recordings'][0] = [
			'recording_uuid'        => uuid(),
			'domain_uuid'           => $domain_uuid,
			'recording_filename'    => $arquivo,
			'recording_name'        => $arquivo,
			'recording_description' => $descricao,
			'recording_base64'      => base64_encode(file_get_contents($caminho)),
		];

		self::db()->save($array);
		$p->delete('recording_add', 'temp');
	}

	public static function remover(string $domain_uuid, string $nome): array {
		$arquivo = preg_replace('/[^A-Za-z0-9_.-]/', '', $nome);
		$db = self::db();

		$uuid = $db->select(
			"select recording_uuid from v_recordings "
			."where domain_uuid = :u and recording_filename = :f",
			['u' => $domain_uuid, 'f' => $arquivo], 'column'
		);
		if (empty($uuid)) {
			responde(['erro' => "não existe gravação chamada $arquivo"], 404);
		}

		$p = permissions::new();
		$p->add('recording_delete', 'temp');
		$db->delete(['recordings' => [0 => ['recording_uuid' => $uuid]]]);
		$p->delete('recording_delete', 'temp');

		// O arquivo sai depois da linha: se a remoção do banco falhar, o áudio
		// continua lá e a gravação segue funcionando.
		$caminho = self::DIRETORIO . '/' . self::nome_do_dominio($domain_uuid) . '/' . $arquivo;
		if (file_exists($caminho)) {
			unlink($caminho);
		}

		return ['gravacao' => $arquivo, 'removida' => true];
	}
}
