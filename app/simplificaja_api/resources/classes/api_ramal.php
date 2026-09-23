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

	/** Mínimo de caracteres numa senha escolhida à mão. Não é gosto: a
	 *  credencial fica exposta na internet e é varrida por robô -- a primeira
	 *  varredura a este PABX chegou 13 minutos depois da instalação. */
	const MINIMO_DA_SENHA = 8;

	/**
	 * Troca a senha de um ramal. Aceita uma escolhida, ou gera se não vier.
	 *
	 * O que NÃO se aceita são caracteres que quebram o registro, e isso não é
	 * política: a senha entra no XML do diretório que o FreeSWITCH lê, e aspas,
	 * sinais de maior/menor e `&` corrompem o documento. Espaço e `:` atrapalham
	 * o digest. O ramal deixaria de registrar sem erro que aponte para cá.
	 *
	 * Grava pelas classes do FusionPBX e limpa `directory:<ramal>@<domínio>`,
	 * como a criação faz -- o diretório servido ao FreeSWITCH sai do cache, e
	 * sem limpar o ramal continua aceitando a senha velha até o cache expirar.
	 */
	public static function trocar_senha(string $domain_uuid, string $numero, ?string $escolhida = null): array {
		$db = self::db();
		$linha = $db->select(
			"select extension_uuid from v_extensions where domain_uuid = :u and extension = :e",
			['u' => $domain_uuid, 'e' => $numero], 'row'
		);
		if (empty($linha)) {
			responde(['erro' => "ramal $numero não existe neste cliente"], 404);
		}

		$dominio = $db->select("select domain_name from v_domains where domain_uuid = :u",
			['u' => $domain_uuid], 'column');
		$senha = $escolhida === null || $escolhida === ''
			? self::senha()
			: self::validar_senha($escolhida);

		$p = permissions::new();
		$p->add('extension_edit', 'temp');
		// Numa variavel, nao literal: `database::save()` recebe por referencia.
		$array['extensions'][0] = [
			'extension_uuid' => $linha['extension_uuid'],
			'domain_uuid'    => $domain_uuid,
			'password'       => $senha,
		];
		$db->save($array);
		$p->delete('extension_edit', 'temp');

		$cache = new cache();
		$cache->delete('directory:' . $numero . '@' . $dominio);

		return ['extension' => $numero, 'senha' => $senha, 'dominio' => $dominio];
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

		self::exigir_que_nao_seja_o_ultimo($domain_uuid, $numero);
		self::exigir_que_nao_esteja_no_fluxo($domain_uuid, $numero);
		$vinculos = self::vinculos_de_fila($domain_uuid, $numero);

		$p = permissions::new();
		$permissoes = ['extension_delete', 'ring_group_destination_delete',
			'call_center_agent_delete', 'call_center_tier_delete'];
		foreach ($permissoes as $permissao) {
			$p->add($permissao, 'temp');
		}

		// O ramal sai dos grupos e das filas de que participava. Grupo e fila
		// continuam existindo: sai quem saiu, nao o departamento.
		$db->execute("delete from v_ring_group_destinations "
			."where domain_uuid = :u and destination_number = :e",
			['u' => $domain_uuid, 'e' => $numero]);
		foreach ($vinculos as $vinculo) {
			$db->execute("delete from v_call_center_tiers where call_center_agent_uuid = :a",
				['a' => $vinculo['call_center_agent_uuid']]);
			$db->execute("delete from v_call_center_agents where call_center_agent_uuid = :a",
				['a' => $vinculo['call_center_agent_uuid']]);
		}
		$db->execute("delete from v_extensions where extension_uuid = :x",
			['x' => $linha['extension_uuid']]);

		foreach ($permissoes as $permissao) {
			$p->delete($permissao, 'temp');
		}

		$cache = new cache();
		$cache->delete('directory:' . $numero . '@' . $dominio);
		$cache->delete('dialplan:' . $dominio);
		api_fila::limpar_config('configuration:callcenter.conf');

		// O mod_callcenter guarda o vinculo em memoria propria: apagar so no
		// banco deixa a fila entregando ligacao para um ramal que nao existe
		// mais, e a ligacao nao toca em lugar nenhum.
		$socket = event_socket::create();
		if ($socket && $socket->is_connected()) {
			foreach ($vinculos as $vinculo) {
				event_socket::api("callcenter_config tier del " . $vinculo['queue_extension']
					. "@$dominio " . $vinculo['call_center_agent_uuid']);
				event_socket::api("callcenter_config agent del " . $vinculo['call_center_agent_uuid']);
			}
			event_socket::api('reloadxml');
		}

		return ['extension' => $numero, 'removido' => true];
	}

	/**
	 * Recusa apagar ramal que uma tecla de URA ou um número de entrada apontam.
	 *
	 * Grupo e fila nao entram aqui: deles o ramal simplesmente sai, e o
	 * atendimento continua com quem ficou. Ja a tecla e o numero apontam para
	 * ELE -- apagar deixa a opcao existindo e levando a lugar nenhum, que e
	 * silencio na linha de quem ligou.
	 */
	private static function exigir_que_nao_esteja_no_fluxo(string $domain_uuid, string $numero): void {
		$db = self::db();
		$usos = [];

		$teclas = $db->select(
			"select m.ivr_menu_name, o.ivr_menu_option_digits as digito "
			."from v_ivr_menu_options o "
			."join v_ivr_menus m on m.ivr_menu_uuid = o.ivr_menu_uuid "
			."where m.domain_uuid = :u and o.ivr_menu_option_param like :p",
			['u' => $domain_uuid, 'p' => '%' . $numero . '%'], 'all'
		) ?? [];
		foreach ($teclas as $tecla) {
			$usos[] = 'a tecla ' . $tecla['digito'] . ' do menu ' . $tecla['ivr_menu_name'];
		}

		$saidas = $db->select(
			"select ivr_menu_name from v_ivr_menus "
			."where domain_uuid = :u and ivr_menu_exit_data like :p",
			['u' => $domain_uuid, 'p' => $numero . ' %'], 'all'
		) ?? [];
		foreach ($saidas as $saida) {
			$usos[] = 'o menu ' . $saida['ivr_menu_name'] . ' quando não digitam nada';
		}

		$numeros = $db->select(
			"select d.dialplan_number from v_dialplans d "
			."join v_dialplan_details t on t.dialplan_uuid = d.dialplan_uuid "
			."where d.domain_uuid = :u and t.dialplan_detail_type = 'transfer' "
			."and t.dialplan_detail_data like :p and d.app_uuid = :a",
			['u' => $domain_uuid, 'p' => $numero . ' %',
			 'a' => 'c03b422e-13a2-bcd8-e895-8a9782ae1f3e'], 'all'
		) ?? [];
		foreach ($numeros as $entrada) {
			$usos[] = 'o número ' . $entrada['dialplan_number'];
		}

		if (empty($usos)) {
			return;
		}

		responde(['erro' => "o ramal $numero ainda recebe ligação por: " . implode('; ', $usos)
			. '. Mude o destino antes de apagar, senão quem ligar cai no silêncio'], 409);
	}

	/** Em quais filas o ramal atende, com o uuid do atendente. */
	private static function vinculos_de_fila(string $domain_uuid, string $numero): array {
		return self::db()->select(
			"select t.call_center_agent_uuid, q.queue_extension "
			."from v_call_center_tiers t "
			."join v_call_center_agents a on a.call_center_agent_uuid = t.call_center_agent_uuid "
			."join v_call_center_queues q on q.call_center_queue_uuid = t.call_center_queue_uuid "
			."where t.domain_uuid = :u and a.agent_name = :e",
			['u' => $domain_uuid, 'e' => $numero], 'all'
		) ?? [];
	}

	/**
	 * Recusa apagar quem e o unico de uma fila.
	 *
	 * Fila sem atendente nao devolve erro: segura quem ligou na musica ate
	 * estourar o tempo. Quem apaga o ramal nao tem como ver isso acontecendo,
	 * entao a recusa aqui e o unico aviso possivel.
	 */
	private static function exigir_que_nao_seja_o_ultimo(string $domain_uuid, string $numero): void {
		// O `where` filtra o proprio ramal, entao contar as linhas do grupo
		// daria sempre 1. Quem precisa ser contado e o total de atendentes da
		// fila, numa subconsulta que nao passa por esse filtro.
		$sozinho = self::db()->select(
			"select q.queue_name from v_call_center_queues q "
			."join v_call_center_tiers t on t.call_center_queue_uuid = q.call_center_queue_uuid "
			."join v_call_center_agents a on a.call_center_agent_uuid = t.call_center_agent_uuid "
			."where q.domain_uuid = :u and a.agent_name = :e and ("
			."select count(*) from v_call_center_tiers t2 "
			."where t2.call_center_queue_uuid = q.call_center_queue_uuid) = 1",
			['u' => $domain_uuid, 'e' => $numero], 'all'
		) ?? [];
		if (empty($sozinho)) {
			return;
		}

		$nomes = implode(', ', array_column($sozinho, 'queue_name'));
		responde(['erro' => "o ramal $numero é o único que atende " . $nomes
			. '. Sem ele a fila fica sem ninguém e quem ligar espera na música '
			. 'até desistir -- ponha outra pessoa antes de apagar'], 409);
	}

	/** Senha aleatória sem caracteres que atrapalham em configuração de softphone. */
	private static function validar_senha(string $senha): string {
		if (mb_strlen($senha) < self::MINIMO_DA_SENHA) {
			responde(['erro' => 'a senha precisa de pelo menos '
				. self::MINIMO_DA_SENHA . ' caracteres'], 422);
		}
		if (preg_match('/["<>&:\s]/', $senha)) {
			responde(['erro' => 'a senha não pode ter espaço, dois-pontos, '
				. 'aspas, & ou sinais de maior/menor -- eles quebram o registro '
				. 'do ramal'], 422);
		}
		return $senha;
	}

	private static function senha(): string {
		$alfabeto = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$senha = '';
		for ($i = 0; $i < 20; $i++) {
			$senha .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
		}
		return $senha;
	}
}
