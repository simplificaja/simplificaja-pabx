<?php

/**
 * Fila de atendimento (mod_callcenter).
 *
 * A diferença para o grupo de toque é o que acontece com a SEGUNDA ligação: o
 * grupo toca em todo mundo e, se ninguém pode atender, acabou; a fila segura
 * quem ligou com música e entrega na ordem em que chegou. É o caso normal de
 * escritório em dia cheio, não a exceção.
 *
 * Escreve pelas classes do FusionPBX, nunca por SQL: o XML servido ao
 * FreeSWITCH é gerado pelo código deles. Ver docs/armadilhas.md.
 */
class api_fila {
	// `ring-all` toca em todos ao mesmo tempo; `sequentially-by-agent-order`
	// segue a ordem em que os ramais entraram na fila; `longest-idle-agent` manda
	// para quem ficou mais tempo sem atender.
	const ESTRATEGIAS = [
		'todos' => 'ring-all',
		'ordem' => 'sequentially-by-agent-order',
		'justa' => 'longest-idle-agent',
	];


	/** App do call center no FusionPBX -- o mesmo que a tela deles grava. */
	const APP_UUID = '95788e50-9500-079e-2807-fd530b0ea370';

	/** Segundos tocando em cada atendente antes de a fila passar adiante. */
	const TOQUE = 30;

	/** Quanto tempo quem ligou espera quando NENHUM atendente está disponível.
	 *  Sem isto a ligação fica presa na música até a pessoa desistir. */
	const ESPERA_SEM_ATENDENTE = 90;

	/** Espera máxima padrão antes de cair no destino de estouro. */
	const ESPERA_MAXIMA = 300;

	/**
	 * Anúncio periódico: existe só para NÃO ser nulo.
	 *
	 * O mod_callcenter deste servidor chama, quando quem ligou sai da fila
	 * (atendido OU desistindo), `switch_ivr_stop_displace_session(sessao,
	 * queue->announce)` sem checar se `announce` é nulo -- e essa função usa o
	 * ponteiro direto como chave de hashtable. Ponteiro nulo ali é SIGSEGV no
	 * processo inteiro: o FreeSWITCH morre no instante em que o atendente
	 * atende, o `systemd` levanta de novo em dois segundos, e o que se vê é
	 * "a ligação não tem voz". Backtrace: `switch_hash_default(ky=0x0)` <-
	 * `switch_channel_get_private(key=0x0)` <- `switch_ivr_stop_displace_session
	 * (file=0x0)` <- `callcenter_function` em mod_callcenter.c:3307.
	 *
	 * A release 1.10.12 protege a chamada (`queue->announce && ...`); este
	 * servidor roda um snapshot de git posterior, onde a checagem não existe no
	 * caminho de saída da fila. Enquanto o build for esse, toda fila precisa
	 * nascer com o campo preenchido.
	 *
	 * Silêncio, e não um áudio do cliente, porque o anúncio periódico não é um
	 * recurso que oferecemos: com frequência zero ele nunca toca, e um caminho
	 * de arquivo aqui só criaria dependência de gravação que ninguém pediu.
	 */
	const ANUNCIO_INOFENSIVO = 'silence_stream://1000';

	/** Zero = o anúncio periódico nunca toca. Ver ANUNCIO_INOFENSIVO. */
	const ANUNCIO_FREQUENCIA = 0;

	/**
	 * Atendente nunca sai de campo sozinho.
	 *
	 * O mod_callcenter tira de cena quem não atendeu N vezes, some com quem
	 * recusou e segura quem acabou de desligar. Isso existe para operação com
	 * supervisor olhando painel. Num escritório de cinco pessoas o efeito é
	 * outro: o telefone "para de tocar" sem ninguém ter mexido em nada, e a
	 * ligação some. Esses três zeros são a diferença entre a fila funcionar
	 * sozinha e virar chamado para nós.
	 */
	const SEM_AUTODESLIGAR = [
		'max_no_answer'       => 0,
		'reject_delay_time'   => 0,
		'busy_delay_time'     => 0,
		'no_answer_delay_time' => 0,
	];

	/**
	 * Apaga a configuração do cache do FreeSWITCH.
	 *
	 * São TRÊS chaves, não uma: o FusionPBX grava a mesma configuração também
	 * prefixada e sufixada pelo nome do host (`remove_config_from_cache()` em
	 * resources/switch.php). Apagar só a nua deixa o módulo lendo a versão
	 * velha -- e a fila recém-criada responde "Queue not found" a um
	 * `queue load` que parece certo.
	 */
	public static function limpar_config(string $nome): void {
		$cache = new cache();
		$cache->delete($nome);
		$cache->delete(gethostname() . ':' . $nome);
		$cache->delete($nome . ':' . gethostname());
	}

	private static function db() {
		return database::new(['db' => $GLOBALS['db'] ?? null]);
	}

	private static function nome_do_dominio(string $domain_uuid): string {
		$dominio = self::db()->select(
			"select domain_name from v_domains where domain_uuid = :u",
			['u' => $domain_uuid], 'column'
		);
		if (empty($dominio)) {
			responde(['erro' => 'domínio da chave não existe'], 500);
		}
		return $dominio;
	}

	/** O número da fila não pode colidir com ramal, grupo, URA ou outra fila:
	 *  o FreeSWITCH atende pelo primeiro plano que casar, e a colisão aparece
	 *  como "ligação vai para o lugar errado", não como erro. */
	private static function exigir_numero_livre(string $domain_uuid, string $ramal): void {
		$db = self::db();
		$checagens = [
			['ramal',  "select count(*) from v_extensions where domain_uuid = :u and extension = :e"],
			['grupo',  "select count(*) from v_ring_groups where domain_uuid = :u and ring_group_extension = :e"],
			['URA',    "select count(*) from v_ivr_menus where domain_uuid = :u and ivr_menu_extension = :e"],
			['fila',   "select count(*) from v_call_center_queues where domain_uuid = :u and queue_extension = :e"],
		];
		foreach ($checagens as [$quem, $sql]) {
			if ((int) $db->select($sql, ['u' => $domain_uuid, 'e' => $ramal], 'column') > 0) {
				responde(['erro' => "o número $ramal já é $quem"], 409);
			}
		}
	}

	/**
	 * O atendente da fila é o ramal. Um por ramal, reaproveitado entre filas:
	 * a mesma pessoa em Comercial e Suporte é UM atendente em duas filas, e
	 * não dois. Duplicar faria o telefone dela tocar duas vezes pela mesma
	 * ligação.
	 */
	private static function atendente(string $domain_uuid, string $dominio, string $ramal): string {
		$db = self::db();
		$uuid = $db->select(
			"select call_center_agent_uuid from v_call_center_agents "
			."where domain_uuid = :u and agent_name = :n",
			['u' => $domain_uuid, 'n' => $ramal], 'column'
		);
		if (!empty($uuid)) {
			return $uuid;
		}

		$uuid = uuid();
		$p = permissions::new();
		$p->add('call_center_agent_add', 'temp');
		$array = [];
		$array['call_center_agents'][0] = [
			'call_center_agent_uuid' => $uuid,
			'domain_uuid'            => $domain_uuid,
			'agent_name'             => $ramal,
			'agent_type'             => 'callback',
			'agent_call_timeout'     => self::TOQUE,
			'agent_contact'          => self::contato($dominio, $ramal),
			// Sempre disponível: ver o comentário de SEM_AUTODESLIGAR.
			'agent_status'           => 'Available',
			'agent_wrap_up_time'     => 0,
		];
		// A coluna leva o prefixo `agent_`; o comando do mod_callcenter, não.
		// Guardar o nome curto na constante e prefixar aqui evita as duas
		// listas saírem de sincronia.
		foreach (self::SEM_AUTODESLIGAR as $campo => $valor) {
			$array['call_center_agents'][0]['agent_' . $campo] = $valor;
		}
		$db->save($array);
		self::exigir_gravado($db, "atendente do ramal $ramal");
		$p->delete('call_center_agent_add', 'temp');

		return $uuid;
	}

	/**
	 * O "Olá, bem-vindo à empresa X, aguarde" que toca antes da música.
	 *
	 * Fila sem saudação atende e cai direto na espera: quem ligou fica ouvindo
	 * música sem saber se chegou no lugar certo. Quando a fila vem depois de
	 * uma URA a saudação já foi dada lá, e aí este campo fica vazio de
	 * propósito.
	 */
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

	private static function contato(string $dominio, string $ramal): string {
		return '{call_timeout=' . self::TOQUE . ',sip_invite_domain=' . $dominio . '}'
			. 'user/' . $ramal . '@' . $dominio;
	}

	public static function criar(string $domain_uuid, array $dados): array {
		$nome  = trim((string) ($dados['nome'] ?? ''));
		$ramal = trim((string) ($dados['ramal'] ?? ''));
		if ($nome === '')  { responde(['erro' => 'nome é obrigatório'], 422); }
		if ($ramal === '') { responde(['erro' => 'ramal é obrigatório'], 422); }
		if (!ctype_digit($ramal)) {
			responde(['erro' => 'número da fila deve ser numérico'], 422);
		}

		$ramais = array_values(array_filter(array_map('trim', (array) ($dados['ramais'] ?? []))));
		if (empty($ramais)) {
			responde(['erro' => 'a fila precisa de pelo menos um ramal'], 422);
		}

		$db = self::db();
		$dominio = self::nome_do_dominio($domain_uuid);
		self::exigir_numero_livre($domain_uuid, $ramal);

		// Mesmo vocabulario do grupo (`todos` / `ordem`), mais a distribuicao
		// justa, que e o pedido comum de quem tem equipe: quem ficou mais tempo
		// sem atender recebe a proxima. Sem lista branca, um valor errado grava
		// lixo em `queue_strategy` e a fila para de distribuir sem acusar erro.
		$chave = (string) ($dados['estrategia'] ?? 'todos');
		if (!isset(self::ESTRATEGIAS[$chave])) {
			responde(['erro' => 'estrategia deve ser "todos", "ordem" ou "justa"'], 422);
		}
		$estrategia = self::ESTRATEGIAS[$chave];

		// `local_stream://default` nao resolve para nada nesta instalacao -- nao ha
		// diretorio de musica e o mod_local_stream nem esta carregado, entao a
		// espera virava silencio. A musica passa a sair das gravacoes do proprio
		// cliente, que e o certo num produto multi-tenant: stream compartilhado
		// tocaria o audio de um cliente na espera de outro.
		$musica = empty($dados['musica'])
			? 'silence_stream://-1'
			: self::caminho_do_audio($dominio, (string) $dados['musica']);

		$saudacao = empty($dados['saudacao'])
			? ''
			: self::caminho_do_audio($dominio, (string) $dados['saudacao']);

		$existentes = $db->select(
			"select extension from v_extensions where domain_uuid = :u",
			['u' => $domain_uuid], 'all'
		) ?? [];
		$fantasmas = array_diff($ramais, array_column($existentes, 'extension'));
		if (!empty($fantasmas)) {
			responde(['erro' => 'ramal não existe: ' . implode(', ', $fantasmas)], 422);
		}

		$fila_uuid     = uuid();
		$dialplan_uuid = uuid();

		$permissoes = ['call_center_queue_add', 'dialplan_add', 'dialplan_edit'];
		$p = permissions::new();
		foreach ($permissoes as $permissao) {
			$p->add($permissao, 'temp');
		}

		$array = [];
		$array['call_center_queues'][0] = [
			'call_center_queue_uuid'            => $fila_uuid,
			'domain_uuid'                       => $domain_uuid,
			'dialplan_uuid'                     => $dialplan_uuid,
			'queue_name'                        => $nome,
			'queue_extension'                   => $ramal,
			// Toca em todos ao mesmo tempo: é o que o escritório espera, e é o
			// comportamento do grupo de toque mais a sala de espera.
			'queue_strategy'                    => $estrategia,
			'queue_moh_sound'                   => $musica,
			'queue_max_wait_time'               => (int) ($dados['espera_maxima'] ?? self::ESPERA_MAXIMA),
			'queue_max_wait_time_with_no_agent' => self::ESPERA_SEM_ATENDENTE,
			'queue_tier_rules_apply'            => 'false',
			'queue_tier_rule_no_agent_no_wait'  => 'false',
			'queue_timeout_action'              => self::estouro($dados, $dominio),
			'queue_cid_prefix'                  => $nome,
			// Não é recurso: é o que impede o FreeSWITCH de morrer quando a
			// ligação sai da fila. Ver ANUNCIO_INOFENSIVO.
			'queue_announce_sound'              => self::ANUNCIO_INOFENSIVO,
			'queue_announce_frequency'          => self::ANUNCIO_FREQUENCIA,
			'queue_context'                     => $dominio,
			'queue_greeting'                    => $saudacao,
			'queue_description'                 => (string) ($dados['descricao'] ?? ''),
		];
		$array['dialplans'][0] = self::dialplan($domain_uuid, $dialplan_uuid, $fila_uuid,
			$nome, $ramal, $dominio, $dados, $saudacao);
		$db->save($array);
		unset($array);

		foreach ($permissoes as $permissao) {
			$p->delete($permissao, 'temp');
		}

		$atendentes = [];
		foreach ($ramais as $i => $numero) {
			$atendentes[$numero] = self::atendente($domain_uuid, $dominio, $numero);
			self::tier($domain_uuid, $fila_uuid, $atendentes[$numero], $nome, $numero, $i + 1);
		}

		self::publicar($dominio, $ramal, $atendentes);

		return ['fila' => $nome, 'ramal' => $ramal, 'ramais' => count($ramais)];
	}

	/** Para onde vai quem esperou demais. Sem destino, desliga -- e é melhor
	 *  desligar do que deixar a pessoa na música para sempre. */
	private static function estouro(array $dados, string $dominio): string {
		$destino = trim((string) ($dados['estouro'] ?? ''));
		return $destino === '' ? '' : "transfer:$destino XML $dominio";
	}

	private static function tier(string $domain_uuid, string $fila_uuid, string $agente_uuid,
		string $nome, string $ramal, int $posicao): void {

		$p = permissions::new();
		$p->add('call_center_tier_add', 'temp');
		$array = [];
		$array['call_center_tiers'][0] = [
			'call_center_tier_uuid'  => uuid(),
			'domain_uuid'            => $domain_uuid,
			'call_center_queue_uuid' => $fila_uuid,
			'call_center_agent_uuid' => $agente_uuid,
			'agent_name'             => $ramal,
			'queue_name'             => $nome,
			'tier_level'             => 1,
			'tier_position'          => $posicao,
		];
		$db = self::db();
		$db->save($array);
		self::exigir_gravado($db, "ramal $ramal na fila $nome");
		$p->delete('call_center_tier_add', 'temp');
	}

	/**
	 * Gravou ou explode.
	 *
	 * `database::save()` não lança exceção: campo que ele não reconhece faz a
	 * linha inteira não entrar e a chamada volta como se tivesse dado certo. Foi
	 * assim que a primeira fila nasceu sem nenhum atendente -- respondendo 201 e
	 * mandando a ligação para um número que não tocava em ninguém.
	 */
	private static function exigir_gravado(database $db, string $oque): void {
		$codigo = (int) ($db->message['code'] ?? 0);
		if ($codigo !== 200) {
			responde(['erro' => "o PABX não gravou $oque",
				'detalhe' => $db->message['message'] ?? null], 500);
		}
	}

	/** O XML à mão, como eles fazem na tela de fila. O `callcenter` é chamado
	 *  pelo NÚMERO da fila, não pelo nome: o nome é só rótulo no painel. */
	private static function dialplan(string $domain_uuid, string $dialplan_uuid, string $fila_uuid,
		string $nome, string $ramal, string $dominio, array $dados, string $saudacao): array {

		$xml  = '<extension name="' . xml::sanitize($nome) . '" continue="" uuid="' . xml::sanitize($dialplan_uuid) . '">' . "\n";
		$xml .= '	<condition field="destination_number" expression="^([^#]+#)(.*)$" break="never">' . "\n";
		$xml .= '		<action application="set" data="caller_id_name=$2"/>' . "\n";
		$xml .= '	</condition>' . "\n";
		$xml .= '	<condition field="destination_number" expression="^(callcenter\+)?' . xml::sanitize($ramal) . '$">' . "\n";
		$xml .= '		<action application="answer" data=""/>' . "\n";
		$xml .= '		<action application="set" data="call_center_queue_uuid=' . xml::sanitize($fila_uuid) . '"/>' . "\n";
		$xml .= '		<action application="set" data="queue_extension=' . xml::sanitize($ramal) . '"/>' . "\n";
		$xml .= '		<action application="set" data="hangup_after_bridge=true"/>' . "\n";
		// `hold_music` e variavel GLOBAL no FusionPBX, apontando para
		// `local_stream://default`. Num produto multi-tenant isso e duplamente
		// errado: nesta instalacao o stream nem existe (a espera virava silencio)
		// e, se existisse, tocaria a mesma musica para todos os clientes. Cada
		// fila passa a fixar a sua na propria chamada.
		$xml .= '		<action application="set" data="hold_music=' . xml::sanitize($musica) . '"/>' . "\n";
		$xml .= '		<action application="set" data="record_stereo=true"/>' . "\n";
		if ($saudacao !== '') {
			// Silêncio de verdade, não `sleep`: o `sleep` espera sem mandar
			// pacote, e o buffer de jitter da operadora só sincroniza quando
			// começa a receber áudio -- então o começo da saudação é que se
			// perdia. Mesmo defeito que apareceu no anúncio.
			$xml .= '		<action application="playback" data="silence_stream://1500"/>' . "\n";
			$xml .= '		<action application="playback" data="' . xml::sanitize($saudacao) . '"/>' . "\n";
		}
		$xml .= '		<action application="callcenter" data="' . xml::sanitize($ramal) . '@' . $dominio . '"/>' . "\n";

		$estouro = trim((string) ($dados['estouro'] ?? ''));
		if ($estouro !== '') {
			$xml .= '		<action application="transfer" data="' . xml::sanitize($estouro) . ' XML ' . $dominio . '"/>' . "\n";
		}
		$xml .= '		<action application="hangup" data=""/>' . "\n";
		$xml .= '	</condition>' . "\n";
		$xml .= '</extension>' . "\n";

		return [
			'dialplan_uuid'        => $dialplan_uuid,
			'domain_uuid'          => $domain_uuid,
			'dialplan_name'        => $nome,
			'dialplan_number'      => $ramal,
			'dialplan_context'     => $dominio,
			'dialplan_continue'    => 'false',
			'dialplan_xml'         => $xml,
			'dialplan_order'       => '230',
			'dialplan_enabled'     => 'true',
			'dialplan_description' => (string) ($dados['descricao'] ?? ''),
			'app_uuid'             => self::APP_UUID,
		];
	}

	/**
	 * Gravar no banco não basta: o mod_callcenter guarda fila, atendente e
	 * vínculo em memória. Sem estes comandos a fila existe na tela e a ligação
	 * cai num número que não toca em ninguém.
	 */
	private static function publicar(string $dominio, string $ramal, array $atendentes): void {
		$cache = new cache();
		$cache->delete('dialplan:' . $dominio);
		self::limpar_config('configuration:callcenter.conf');

		$socket = event_socket::create();
		if (!$socket || !$socket->is_connected()) {
			return;
		}

		event_socket::api('reloadxml');
		event_socket::api("callcenter_config queue load $ramal@$dominio");

		foreach ($atendentes as $numero => $uuid) {
			event_socket::api("callcenter_config agent add $uuid 'callback'");
			event_socket::api("callcenter_config agent set contact $uuid '" . self::contato($dominio, (string) $numero) . "'");
			event_socket::api("callcenter_config agent set status $uuid 'Available'");
			foreach (self::SEM_AUTODESLIGAR as $campo => $valor) {
				event_socket::api("callcenter_config agent set $campo $uuid $valor");
			}
			event_socket::api("callcenter_config tier add $ramal@$dominio $uuid 1 1");
		}
	}

	/**
	 * Edita apagando e recriando, e nao no lugar.
	 *
	 * A fila nao vive so no banco: o mod_callcenter guarda fila, atendente e
	 * vinculo num armazenamento proprio que sobrevive a recarga do modulo.
	 * Atualizar as linhas deixaria o que esta em memoria valendo, e a ligacao
	 * seguiria a configuracao antiga sem a tela acusar nada. `remover` ja sabe
	 * descarregar isso; `criar` ja sabe montar tudo de novo.
	 *
	 * A ordem importa: tudo o que pode ser recusado e conferido ANTES de
	 * apagar, senao um nome invalido deixaria o cliente sem a fila que tinha.
	 */
	public static function editar(string $domain_uuid, array $dados): array {
		$ramal = trim((string) ($dados['ramal'] ?? ''));
		if ($ramal === '') {
			responde(['erro' => 'ramal é obrigatório'], 422);
		}
		if (empty($dados['nome'])) {
			responde(['erro' => 'nome é obrigatório'], 422);
		}
		$chave = (string) ($dados['estrategia'] ?? 'todos');
		if (!isset(self::ESTRATEGIAS[$chave])) {
			responde(['erro' => 'estrategia deve ser "todos", "ordem" ou "justa"'], 422);
		}

		$db = self::db();
		$existe = $db->select(
			"select call_center_queue_uuid from v_call_center_queues "
			."where domain_uuid = :u and queue_extension = :e",
			['u' => $domain_uuid, 'e' => $ramal], 'column'
		);
		if (empty($existe)) {
			responde(['erro' => "não existe fila no número $ramal"], 404);
		}

		self::remover($domain_uuid, $ramal);
		return self::criar($domain_uuid, $dados);
	}

	public static function remover(string $domain_uuid, string $ramal): array {
		$db = self::db();
		$linha = $db->select(
			"select call_center_queue_uuid, dialplan_uuid, queue_name from v_call_center_queues "
			."where domain_uuid = :u and queue_extension = :e",
			['u' => $domain_uuid, 'e' => $ramal], 'row'
		);
		if (empty($linha)) {
			responde(['erro' => "não existe fila no número $ramal"], 404);
		}

		$dominio = self::nome_do_dominio($domain_uuid);

		// Lido ANTES de apagar: o mod_callcenter guarda fila, atendente e
		// vínculo num armazenamento próprio, que sobrevive até a recarga do
		// módulo. Apagar só no banco deixa o vínculo vivo lá dentro, e o
		// telefone continua tocando por uma fila que a tela já não mostra.
		$vinculos = $db->select(
			"select call_center_agent_uuid from v_call_center_tiers where call_center_queue_uuid = :f",
			['f' => $linha['call_center_queue_uuid']], 'all'
		) ?? [];

		$permissoes = ['call_center_queue_delete', 'call_center_tier_delete', 'dialplan_delete'];
		$p = permissions::new();
		foreach ($permissoes as $permissao) {
			$p->add($permissao, 'temp');
		}
		$db->execute("delete from v_call_center_tiers where call_center_queue_uuid = :f",
			['f' => $linha['call_center_queue_uuid']]);
		$db->execute("delete from v_call_center_queues where call_center_queue_uuid = :f",
			['f' => $linha['call_center_queue_uuid']]);
		if (!empty($linha['dialplan_uuid'])) {
			$db->execute("delete from v_dialplan_details where dialplan_uuid = :d",
				['d' => $linha['dialplan_uuid']]);
			$db->execute("delete from v_dialplans where dialplan_uuid = :d",
				['d' => $linha['dialplan_uuid']]);
		}
		foreach ($permissoes as $permissao) {
			$p->delete($permissao, 'temp');
		}

		// O atendente não é apagado: ele é do ramal, não da fila, e pode estar
		// em outra.
		$cache = new cache();
		$cache->delete('dialplan:' . $dominio);
		self::limpar_config('configuration:callcenter.conf');
		$socket = event_socket::create();
		if ($socket && $socket->is_connected()) {
			event_socket::api('reloadxml');
			foreach ($vinculos as $vinculo) {
				event_socket::api("callcenter_config tier del $ramal@$dominio "
					. $vinculo['call_center_agent_uuid']);
			}
			event_socket::api("callcenter_config queue unload $ramal@$dominio");
		}

		return ['fila' => $linha['queue_name'], 'ramal' => $ramal, 'removida' => true];
	}
}
