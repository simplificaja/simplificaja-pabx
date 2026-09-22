<?php
/**
 * URA: saudação e menu de opções.
 *
 * ATENÇÃO ao padrão de escrita aqui. Destinos e troncos usam
 * `$dialplan->xml()` para regerar o XML a partir das linhas da rota. A URA
 * NÃO: o `ivr_menu_edit.php` monta a string do `dialplan_xml` à mão e salva
 * direto (linhas 362-402), com `app_uuid` fixo e ordem 101. Seguir o padrão
 * dos destinos aqui produz uma URA que existe no banco, aparece na tela do
 * FusionPBX e não atende a ligação.
 */
class api_ura {

	/** O app de URA do FusionPBX. Sem ele a rota não aparece ligada à URA na
	 *  tela deles, e o cliente não consegue editar pelo painel dele. */
	const APP_UUID = 'a5788e9b-58bc-bd1b-df59-fff5d51253ab';

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

	/** Caminho absoluto do áudio. O valor entra no XML literalmente, então
	 *  precisa ser algo que o FreeSWITCH resolva sozinho. */
	private static function caminho_do_audio(string $dominio, string $arquivo): string {
		$socket = event_socket::create();
		$base = ($socket && $socket->is_connected())
			? trim((string) event_socket::api('global_getvar recordings_dir'))
			: '';
		if ($base === '') {
			responde(['erro' => 'FreeSWITCH não respondeu onde ficam as gravações'], 500);
		}
		$caminho = rtrim($base, '/') . '/' . $dominio . '/' . $arquivo;
		if (!file_exists($caminho)) {
			responde(['erro' => "gravação $arquivo não existe neste domínio"], 422);
		}
		return $caminho;
	}

	public static function criar(string $domain_uuid, array $dados): array {
		foreach (['nome', 'ramal', 'saudacao'] as $campo) {
			if (empty($dados[$campo])) {
				responde(['erro' => "$campo é obrigatório"], 422);
			}
		}
		$ramal = trim((string) $dados['ramal']);
		if (!ctype_digit($ramal)) {
			responde(['erro' => 'ramal da URA deve ser numérico'], 422);
		}

		$db = self::db();
		$dominio = self::nome_do_dominio($domain_uuid);

		$existe = $db->select(
			"select ivr_menu_uuid from v_ivr_menus where domain_uuid = :u and ivr_menu_extension = :e",
			['u' => $domain_uuid, 'e' => $ramal], 'column'
		);
		if (!empty($existe)) {
			responde(['erro' => "já existe URA no ramal $ramal"], 409);
		}
		$ocupado = $db->select(
			"select extension_uuid from v_extensions where domain_uuid = :u and extension = :e",
			['u' => $domain_uuid, 'e' => $ramal], 'column'
		);
		if (!empty($ocupado)) {
			responde(['erro' => "o ramal $ramal já é de um atendente"], 409);
		}

		// Dois audios: o primeiro toca na entrada (anuncio + opcoes), o segundo
		// nas repeticoes (so as opcoes). Quem nao mandar o segundo repete o
		// anuncio inteiro toda vez, que cansa.
		$saudacao = self::caminho_do_audio($dominio, (string) $dados['saudacao']);
		$repeticao = empty($dados['opcoes_audio'])
			? $saudacao
			: self::caminho_do_audio($dominio, (string) $dados['opcoes_audio']);
		$ivr_menu_uuid = uuid();
		$dialplan_uuid = uuid();

		$p = permissions::new();
		$permissoes = ['ivr_menu_add', 'ivr_menu_option_add', 'dialplan_add', 'dialplan_detail_add'];
		foreach ($permissoes as $permissao) {
			$p->add($permissao, 'temp');
		}

		$array['ivr_menus'][0] = [
			'ivr_menu_uuid'          => $ivr_menu_uuid,
			'domain_uuid'            => $domain_uuid,
			'dialplan_uuid'          => $dialplan_uuid,
			'ivr_menu_name'          => $dados['nome'],
			'ivr_menu_extension'     => $ramal,
			'ivr_menu_context'       => $dominio,
			'ivr_menu_greet_long'    => $saudacao,
			'ivr_menu_greet_short'   => $repeticao,
			'ivr_menu_timeout'       => (string) ($dados['espera'] ?? 5000),
			'ivr_menu_max_failures'  => '3',
			'ivr_menu_max_timeouts'  => '3',
			// Um digito, nao cinco: as opcoes sao de um digito so, e com 5 a URA
			// fica esperando 2,5s depois que a pessoa aperta -- parece travada.
			'ivr_menu_digit_len'     => '1',
			'ivr_menu_direct_dial'   => 'false',
			'ivr_menu_ringback'      => '${us-ring}',
			'ivr_menu_exit_app'      => 'transfer',
			'ivr_menu_exit_data'     => ($dados['saida'] ?? '') . ' XML ' . $dominio,
			'ivr_menu_enabled'       => 'true',
			'ivr_menu_description'   => $dados['descricao'] ?? '',
		];

		foreach (array_values($dados['opcoes'] ?? []) as $i => $opcao) {
			if (empty($opcao['digito']) || empty($opcao['destino'])) {
				continue;
			}
			$array['ivr_menus'][0]['ivr_menu_options'][$i] = [
				'ivr_menu_option_uuid'    => uuid(),
				'ivr_menu_uuid'           => $ivr_menu_uuid,
				'domain_uuid'             => $domain_uuid,
				'ivr_menu_option_digits'  => (string) $opcao['digito'],
				'ivr_menu_option_action'  => 'menu-exec-app',
				'ivr_menu_option_param'   => self::acao_da_opcao($domain_uuid, $dominio,
					(string) $opcao['destino'], (int) ($dados['espera_ramal'] ?? 25)),
				'ivr_menu_option_order'   => (string) (($i + 1) * 10),
				'ivr_menu_option_enabled' => 'true',
				'ivr_menu_option_description' => $opcao['descricao'] ?? '',
			];
		}

		$array['dialplans'][0] = self::dialplan($domain_uuid, $dialplan_uuid,
			$ivr_menu_uuid, $dados, $dominio);

		$db->save($array);
		foreach ($permissoes as $permissao) {
			$p->delete($permissao, 'temp');
		}

		self::publicar($dominio, $ivr_menu_uuid);

		return [
			'ura'    => $dados['nome'],
			'ramal'  => $ramal,
			'opcoes' => count($array['ivr_menus'][0]['ivr_menu_options'] ?? []),
		];
	}

	/**
	 * O que a tecla faz. A diferença entre `transfer` e `bridge` aqui é a
	 * diferença entre a ligação cair no silêncio e a pessoa ouvir o menu de
	 * novo.
	 *
	 * `transfer` entrega a chamada e encerra a URA: se o ramal está fora do ar,
	 * o bridge do `local_extension` falha, não há para onde voltar e a linha
	 * morre sem áudio nenhum -- medido numa ligação real, 40ms entre o bridge e
	 * o hangup.
	 *
	 * `bridge` mantém a URA no comando: o app volta quando a chamada não
	 * completa, e o `ivr` repete as opções. O teto de repetições é o
	 * `ivr_menu_max_failures` que já existe, então isto não abre loop infinito.
	 *
	 * Grupo e outra URA continuam com `transfer`: são rotas do dialplan, não
	 * usuários, e o grupo tem o próprio destino de "ninguém atendeu".
	 */
	private static function acao_da_opcao(string $domain_uuid, string $dominio,
		string $destino, int $espera): string {

		$e_ramal = self::db()->select(
			"select extension_uuid from v_extensions where domain_uuid = :u and extension = :e",
			['u' => $domain_uuid, 'e' => $destino], 'column'
		);
		if (empty($e_ramal)) {
			return 'transfer ' . $destino . ' XML ' . $dominio;
		}

		// `confirm=false` porque quem atende é o dono do ramal, não uma fila
		// que precisa aceitar; `leg_timeout` é o tanto que o telefone toca
		// antes de a URA retomar.
		return sprintf('bridge {leg_timeout=%d,confirm=false}user/%s@%s',
			$espera, $destino, $dominio);
	}

	/** O XML à mão, como eles fazem. `answer` antes do `ivr` porque URA sem
	 *  atender não toca áudio. */
	private static function dialplan(string $domain_uuid, string $dialplan_uuid,
		string $ivr_menu_uuid, array $dados, string $dominio): array {

		$ramal = xml::sanitize((string) $dados['ramal']);
		$xml  = '<extension name="' . xml::sanitize($dados['nome']) . '" continue="false" uuid="' . uuid() . '">' . "\n";
		$xml .= '	<condition field="destination_number" expression="^' . $ramal . '$">' . "\n";
		$xml .= '		<action application="answer" data=""/>' . "\n";
		$xml .= '		<action application="sleep" data="500"/>' . "\n";
		// As duas variaveis que fazem a opcao voltar ao menu em vez de matar a
		// ligacao. Medido: sem `continue_on_fail`, o `bridge` para um ramal
		// fora do ar derruba o canal com USER_NOT_REGISTERED e quem ligou ouve
		// a linha morrer. Sem `hangup_after_bridge`, o caminho feliz e que fica
		// errado -- terminada a conversa, o `ivr` retoma e a pessoa ouve o menu
		// de novo depois de ja ter sido atendida.
		//
		// Vao no canal, antes do `ivr`: sao variaveis de quem origina, entao
		// dentro das chaves do `bridge` nao valem -- la so entra o que e' da
		// perna de destino.
		$xml .= '		<action application="set" data="continue_on_fail=true"/>' . "\n";
		$xml .= '		<action application="set" data="hangup_after_bridge=true"/>' . "\n";
		$xml .= '		<action application="set" data="ivr_menu_uuid=' . xml::sanitize($ivr_menu_uuid) . '"/>' . "\n";
		$xml .= '		<action application="ivr" data="' . xml::sanitize($ivr_menu_uuid) . '"/>' . "\n";
		// O `ivr` devolve o controle quando estoura o limite de tentativas, e
		// sem acao depois dele o canal simplesmente cai -- a tela promete "se
		// nao digitar, vai para X" e nao acontecia nada. No caminho feliz esta
		// linha nao e alcancada: `hangup_after_bridge` encerra a ligacao assim
		// que a conversa termina.
		if (!empty($dados['saida'])) {
			$xml .= '		<action application="transfer" data="'
				. xml::sanitize((string) $dados['saida']) . ' XML ' . xml::sanitize($dominio) . '"/>' . "\n";
		}
		$xml .= '	</condition>' . "\n";
		$xml .= '</extension>' . "\n";

		return [
			'dialplan_uuid'        => $dialplan_uuid,
			'domain_uuid'          => $domain_uuid,
			'dialplan_name'        => $dados['nome'],
			'dialplan_number'      => $dados['ramal'],
			'dialplan_context'     => $dominio,
			'dialplan_continue'    => 'false',
			'dialplan_xml'         => $xml,
			'dialplan_order'       => '101',
			'dialplan_enabled'     => 'true',
			'dialplan_description' => $dados['descricao'] ?? '',
			'app_uuid'             => self::APP_UUID,
		];
	}

	/** Os DOIS caches. Esquecer o de ivr.conf deixa a URA antiga tocando, sem
	 *  erro nenhum (`ivr_menu_edit.php:433-435`). */
	private static function publicar(string $dominio, string $ivr_menu_uuid): void {
		$cache = new cache();
		$cache->delete('dialplan:' . $dominio);
		$cache->delete('configuration:ivr.conf:' . $ivr_menu_uuid);

		$socket = event_socket::create();
		if ($socket && $socket->is_connected()) {
			event_socket::api('reloadxml');
		}
	}

	public static function remover(string $domain_uuid, string $ramal): array {
		$db = self::db();
		$linha = $db->select(
			"select ivr_menu_uuid, dialplan_uuid from v_ivr_menus "
			."where domain_uuid = :u and ivr_menu_extension = :e",
			['u' => $domain_uuid, 'e' => $ramal], 'row'
		);
		if (empty($linha)) {
			responde(['erro' => "não existe URA no ramal $ramal"], 404);
		}

		$p = permissions::new();
		$permissoes = ['ivr_menu_delete', 'ivr_menu_option_delete', 'dialplan_delete'];
		foreach ($permissoes as $permissao) {
			$p->add($permissao, 'temp');
		}
		$db->execute("delete from v_ivr_menu_options where ivr_menu_uuid = :i",
			['i' => $linha['ivr_menu_uuid']]);
		$db->execute("delete from v_ivr_menus where ivr_menu_uuid = :i",
			['i' => $linha['ivr_menu_uuid']]);
		if (!empty($linha['dialplan_uuid'])) {
			$db->execute("delete from v_dialplans where dialplan_uuid = :d",
				['d' => $linha['dialplan_uuid']]);
		}
		foreach ($permissoes as $permissao) {
			$p->delete($permissao, 'temp');
		}

		self::publicar(self::nome_do_dominio($domain_uuid), $linha['ivr_menu_uuid']);
		return ['ura' => $ramal, 'removida' => true];
	}
}
