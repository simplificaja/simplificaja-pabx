<?php
/**
 * Discagem de saída: o ramal liga para a rua.
 *
 * Padrão de escrita igual ao dos destinos, e pela mesma razão -- o
 * `dialplan_outbound_add.php` grava linhas em `v_dialplan_details` e chama
 * `$dialplan->xml()` para regerar a coluna que o FreeSWITCH lê. É o oposto da
 * URA e do grupo, que montam o XML à mão.
 */
class api_saida {

	/** app_uuid do app Outbound Routes do FusionPBX. */
	const APP_OUTBOUND = '8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3';

	/**
	 * As regras, na ordem em que o FreeSWITCH tenta.
	 *
	 * O 0800 vem antes de propósito: a regra geral tira o zero da frente, e
	 * `08001234567` viraria `8001234567`, que tem 10 dígitos começando em 8 e
	 * casa como se fosse DDD 80. A ordem é o que impede isso.
	 *
	 * Não há regra para 0300, 0500 nem 0900: são tarifados e é por onde sai a
	 * fraude quando um ramal vaza. Quem precisar, cria depois sabendo o risco.
	 */
	const REGRAS = [
		// Com codigo do pais. Nao e' hipotese: o contato do Chatwoot e' guardado
		// em E164, entao clicar para ligar no painel manda `+5519995566277`.
		//
		// Exige 12 ou 13 digitos depois do 55 justamente porque `55` tambem e'
		// DDD do Rio Grande do Sul -- `55 99988-7766` tem 11 digitos e cai na
		// regra nacional, como deve.
		['nome' => 'saida-e164',   'ordem' => '180', 'padrao' => '^\+?55([1-9][0-9]{9,10})$'],
		['nome' => 'saida-0800',   'ordem' => '190', 'padrao' => '^(0800\d{6,7})$'],
		['nome' => 'saida-brasil', 'ordem' => '200', 'padrao' => '^0?([1-9][0-9]{9,10})$'],
	];

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

	/**
	 * O tronco por onde a chamada sai. O bridge endereça o gateway pelo UUID,
	 * não pelo nome: nome se repete entre domínios e o FreeSWITCH pegaria o do
	 * vizinho -- chamada do cliente A saindo pela conta do cliente B.
	 */
	private static function gateway(string $domain_uuid, ?string $nome): array {
		$db = self::db();
		if (!empty($nome)) {
			$linha = $db->select(
				"select gateway_uuid, gateway from v_gateways "
				."where domain_uuid = :u and gateway = :g",
				['u' => $domain_uuid, 'g' => $nome], 'row'
			);
			if (empty($linha)) {
				responde(['erro' => "não existe tronco chamado $nome neste cliente"], 422);
			}
			return $linha;
		}

		$todos = $db->select(
			"select gateway_uuid, gateway from v_gateways where domain_uuid = :u order by gateway",
			['u' => $domain_uuid], 'all'
		) ?? [];
		if (count($todos) !== 1) {
			responde(['erro' => count($todos) === 0
				? 'cliente sem tronco; cadastre o número antes de liberar a saída'
				: 'cliente tem mais de um tronco; diga qual em `tronco`'], 422);
		}
		return $todos[0];
	}

	public static function criar(string $domain_uuid, array $dados): array {
		$dominio = self::nome_do_dominio($domain_uuid);
		$gateway = self::gateway($domain_uuid, $dados['tronco'] ?? null);

		// Só dígitos: o valor entra num `set` do dialplan e vira o número que
		// aparece no visor de quem recebe. Operadora recusa o que não
		// reconhece como da conta.
		$caller_id = preg_replace('/\D/', '', (string) ($dados['caller_id'] ?? ''));
		if ($caller_id === '') {
			responde(['erro' => 'caller_id é obrigatório: é o número que o cliente apresenta'], 422);
		}

		self::remover_silencioso($domain_uuid);

		$p = permissions::new();
		$permissoes = ['dialplan_add', 'dialplan_edit', 'dialplan_detail_add', 'dialplan_detail_edit'];
		foreach ($permissoes as $permissao) {
			$p->add($permissao, 'temp');
		}

		$array = [];
		foreach (self::REGRAS as $i => $regra) {
			$array['dialplans'][$i] = self::rota($domain_uuid, $dominio, $gateway,
				$caller_id, $regra);
		}
		self::db()->save($array);

		foreach ($permissoes as $permissao) {
			$p->delete($permissao, 'temp');
		}

		self::gerar_xml($dominio);

		// Conferir antes de dizer que deu certo: rota sem XML existe no banco,
		// aparece na tela do FusionPBX e o FreeSWITCH não enxerga.
		$vazias = (int) self::db()->select(
			"select count(*) as n from v_dialplans where domain_uuid = :u "
			."and app_uuid = :a and coalesce(length(dialplan_xml), 0) = 0",
			['u' => $domain_uuid, 'a' => self::APP_OUTBOUND], 'column'
		);
		if ($vazias > 0) {
			responde(['erro' => 'rota de saída criada mas o XML não foi gerado; rode /saude'], 500);
		}

		return [
			'tronco'    => $gateway['gateway'],
			'caller_id' => $caller_id,
			'regras'    => count(self::REGRAS),
		];
	}

	private static function rota(string $domain_uuid, string $dominio, array $gateway,
		string $caller_id, array $regra): array {

		$dialplan_uuid = uuid();
		$d = 0;
		$linha = function (string $tag, string $tipo, string $dado, string $ordem,
			bool $inline = false) use (&$d, $dialplan_uuid, $domain_uuid) {
			$detalhe = [
				'dialplan_detail_uuid'  => uuid(),
				'dialplan_uuid'         => $dialplan_uuid,
				'domain_uuid'           => $domain_uuid,
				'dialplan_detail_tag'   => $tag,
				'dialplan_detail_type'  => $tipo,
				'dialplan_detail_data'  => $dado,
				'dialplan_detail_order' => $ordem,
				'dialplan_detail_group' => '0',
			];
			if ($inline) {
				$detalhe['dialplan_detail_inline'] = 'true';
			}
			$d++;
			return $detalhe;
		};

		$detalhes = [];
		$detalhes[] = $linha('condition', 'destination_number', $regra['padrao'], '005');
		// `export` e nao `set`: o registrador le a direcao na perna que desliga,
		// e sem exportar ela nao alcanca a perna do tronco.
		$detalhes[] = $linha('action', 'export', 'call_direction=outbound', '010', true);
		$detalhes[] = $linha('action', 'set', 'effective_caller_id_number=' . $caller_id, '015', true);
		$detalhes[] = $linha('action', 'set', 'effective_caller_id_name=' . $caller_id, '020', true);
		// Sem isto a perna do ramal sobrevive ao fim da conversa e fica muda.
		$detalhes[] = $linha('action', 'set', 'hangup_after_bridge=true', '025', true);
		$detalhes[] = $linha('action', 'bridge',
			'sofia/gateway/' . $gateway['gateway_uuid'] . '/$1', '030');

		return [
			'dialplan_uuid'        => $dialplan_uuid,
			'domain_uuid'          => $domain_uuid,
			'dialplan_name'        => $regra['nome'],
			'dialplan_number'      => '',
			'dialplan_context'     => $dominio,
			'dialplan_continue'    => 'false',
			'dialplan_order'       => $regra['ordem'],
			'dialplan_enabled'     => 'true',
			'dialplan_description' => 'SimplificaJa - discagem de saida',
			'app_uuid'             => self::APP_OUTBOUND,
			'dialplan_details'     => $detalhes,
		];
	}

	private static function gerar_xml(string $dominio): void {
		$dialplan = new dialplan();
		$dialplan->source      = 'details';
		$dialplan->destination = 'database';
		$dialplan->context     = $dominio;
		$dialplan->is_empty    = 'dialplan_xml';
		$dialplan->xml();

		$cache = new cache();
		$cache->delete('dialplan:' . $dominio);

		$socket = event_socket::create();
		if ($socket && $socket->is_connected()) {
			event_socket::api('reloadxml');
		}
	}

	/** Refazer é a única forma de editar: a API não tem PUT, e deixar a rota
	 *  antiga ao lado da nova faz a chamada sair pela que o FreeSWITCH achar
	 *  primeiro. */
	private static function remover_silencioso(string $domain_uuid): void {
		$db = self::db();
		$rotas = $db->select(
			"select dialplan_uuid from v_dialplans where domain_uuid = :u and app_uuid = :a",
			['u' => $domain_uuid, 'a' => self::APP_OUTBOUND], 'all'
		) ?? [];
		if (empty($rotas)) {
			return;
		}

		$p = permissions::new();
		$p->add('dialplan_delete', 'temp');
		$p->add('dialplan_detail_delete', 'temp');
		foreach ($rotas as $rota) {
			$db->execute("delete from v_dialplan_details where dialplan_uuid = :p",
				['p' => $rota['dialplan_uuid']]);
			$db->execute("delete from v_dialplans where dialplan_uuid = :p",
				['p' => $rota['dialplan_uuid']]);
		}
		$p->delete('dialplan_delete', 'temp');
		$p->delete('dialplan_detail_delete', 'temp');
	}

	public static function remover(string $domain_uuid): array {
		$dominio = self::nome_do_dominio($domain_uuid);
		self::remover_silencioso($domain_uuid);
		self::gerar_xml($dominio);
		return ['saida' => 'removida'];
	}

	public static function ler(string $domain_uuid): array {
		$rotas = self::db()->select(
			"select p.dialplan_name, p.dialplan_order, p.dialplan_enabled, "
			."coalesce(length(p.dialplan_xml), 0) as xml_bytes, "
			."(select dialplan_detail_data from v_dialplan_details x "
			." where x.dialplan_uuid = p.dialplan_uuid "
			."   and x.dialplan_detail_data like 'effective_caller_id_number=%' limit 1) as caller_id, "
			."(select dialplan_detail_data from v_dialplan_details x "
			." where x.dialplan_uuid = p.dialplan_uuid and x.dialplan_detail_tag = 'condition' limit 1) as padrao "
			."from v_dialplans p where p.domain_uuid = :u and p.app_uuid = :a "
			."order by p.dialplan_order",
			['u' => $domain_uuid, 'a' => self::APP_OUTBOUND], 'all'
		) ?? [];

		foreach ($rotas as &$rota) {
			$rota['caller_id'] = str_replace('effective_caller_id_number=', '',
				(string) $rota['caller_id']);
		}
		return $rotas;
	}
}
