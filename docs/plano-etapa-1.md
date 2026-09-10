# Etapa 1 — o cliente atende

> **Para quem for executar:** use `superpowers:executing-plans`. Os passos usam
> `- [ ]` para acompanhamento.

**Objetivo:** criar um cliente com telefone simples pelo painel do SimplificaJá,
sem abrir o FusionPBX — e conseguir verificar se ele nasceu inteiro.

**Arquitetura:** um app do FusionPBX (`app/simplificaja_api/`) que roda dentro
dele e escreve pelas classes dele. Nunca por SQL.

**Spec:** `api-design.md` neste mesmo diretório.

**Alvo:** FusionPBX `5.5`, commit `087fc2b98`.

## Restrições que valem para todas as tarefas

- **Nunca escrever no Postgres por fora.** Toda escrita segue os cinco passos do
  padrão provado: permissão temporária → `database->save()` → devolver permissão
  → `dialplan->xml()` → limpar cache. O exemplo que funciona está em
  `exemplos/criar-destino.php`.
- **O escopo vem sempre da chave**, nunca do corpo da requisição. Não existe
  caminho em que o chamador escolha o `domain_uuid`. É o único ponto por onde
  vazamento entre clientes entraria.
- **Nada de fork.** O app é uma pasta, o upstream fica intocado.
- **Verificação é pelo efeito no FreeSWITCH**, não pelo retorno HTTP. Testar por
  HTTP reproduz o erro da validação: tudo respondendo certo, nada funcionando.
- **Falhar alto.** Estado impossível estoura; nada de seguir com aviso.
- **PHP 8, no estilo do FusionPBX**: tabulação, `require` do
  `resources/require.php`, classes deles.
- Comandos no servidor: `ssh root@109.123.250.200`.

---

### Tarefa 1: Esqueleto do app e autenticação

Sem isto nenhum endpoint existe. Entrega: um `GET` autenticado respondendo.

**Arquivos:**
- Criar: `app/simplificaja_api/app_config.php`
- Criar: `app/simplificaja_api/index.php`
- Criar: `app/simplificaja_api/resources/classes/api_auth.php`

**Interfaces:**
- Produz: `api_auth::domain_uuid()` — devolve o domínio da chave ou encerra com 401.

- [ ] **Passo 1: declarar o app**

`app_config.php`, seguindo o formato dos apps do FusionPBX (ver
`app/ivr_menus/app_config.php`). Gerar um uuid novo — não copiar o de outro app:

```php
<?php
	$apps[$x]['name'] = "SimplificaJá API";
	$apps[$x]['uuid'] = "GERAR-UM-UUID-NOVO";
	$apps[$x]['category'] = "Switch";
	$apps[$x]['subcategory'] = "";
	$apps[$x]['version'] = "1.0";
	$apps[$x]['license'] = "Mozilla Public License 1.1";
	$apps[$x]['description']['en-us'] = "REST API consumed by the SimplificaJá admin panel.";
```

Gerar o uuid com `php -r 'echo uuid();'` dentro do FusionPBX, ou `uuidgen`.

- [ ] **Passo 2: a chave por domínio**

A chave mora como `default_setting` do domínio, categoria `simplificaja`,
subcategoria `api_key`. Criar a do domínio de teste:

```bash
ssh root@109.123.250.200
su - postgres -c "psql -d fusionpbx -c \"
  insert into v_domain_settings
    (domain_setting_uuid, domain_uuid, domain_setting_category,
     domain_setting_subcategory, domain_setting_name, domain_setting_value,
     domain_setting_enabled)
  select gen_random_uuid(), domain_uuid, 'simplificaja', 'api_key', 'text',
         encode(gen_random_bytes(24), 'hex'), true
  from v_domains where domain_name = 'pabx.simplificaja.com.br';\""
```

Conferir qual chave saiu:

```bash
su - postgres -c "psql -d fusionpbx -t -A -c \"
  select domain_setting_value from v_domain_settings
  where domain_setting_category = 'simplificaja';\""
```

- [ ] **Passo 3: a classe de autenticação**

```php
<?php
class api_auth {
	// Devolve o domain_uuid da chave. Encerra em 401 se não houver chave válida.
	// O escopo SEMPRE sai daqui -- nenhum endpoint aceita domain_uuid do corpo,
	// porque seria o caminho para um cliente alcançar o outro.
	public static function domain_uuid(): string {
		$enviada = $_SERVER['HTTP_X_API_KEY'] ?? '';
		if ($enviada === '') { self::recusa(); }

		$database = database::new(['db' => $GLOBALS['db'] ?? null]);
		$linhas = $database->select(
			"select domain_uuid, domain_setting_value from v_domain_settings "
			." where domain_setting_category = 'simplificaja' "
			." and domain_setting_subcategory = 'api_key' "
			." and domain_setting_enabled = true", [], 'all');

		foreach ($linhas ?? [] as $linha) {
			// hash_equals: comparação de string comum vaza tempo
			if (hash_equals($linha['domain_setting_value'], $enviada)) {
				return $linha['domain_uuid'];
			}
		}
		self::recusa();
	}

	private static function recusa(): void {
		http_response_code(401);
		header('Content-Type: application/json');
		echo json_encode(['erro' => 'chave inválida ou ausente']);
		exit;
	}
}
```

- [ ] **Passo 4: o roteador**

`index.php`. Um arquivo que despacha por caminho, sem framework — o FusionPBX
não tem um e trazer dependência para cá é dívida sem ganho.

```php
<?php
require dirname(__DIR__, 2) . "/resources/require.php";
require __DIR__ . "/resources/classes/api_auth.php";

header('Content-Type: application/json; charset=utf-8');

$domain_uuid = api_auth::domain_uuid();
$rota   = trim($_SERVER['PATH_INFO'] ?? '', '/');
$metodo = $_SERVER['REQUEST_METHOD'];

function responde($dados, int $codigo = 200): void {
	http_response_code($codigo);
	echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

switch ("$metodo $rota") {
	case 'GET ping':
		responde(['ok' => true, 'domain_uuid' => $domain_uuid]);
	default:
		responde(['erro' => "rota desconhecida: $metodo $rota"], 404);
}
```

- [ ] **Passo 5: instalar e verificar**

```bash
scp -r app/simplificaja_api root@109.123.250.200:/var/www/fusionpbx/app/
ssh root@109.123.250.200 "chown -R www-data:www-data /var/www/fusionpbx/app/simplificaja_api"
```

Com a chave certa:

```bash
curl -s https://pabx.simplificaja.com.br/app/simplificaja_api/index.php/ping \
  -H "X-Api-Key: A_CHAVE"
```

Esperado: `{"ok":true,"domain_uuid":"54c66587-..."}`

Sem chave e com chave errada:

```bash
curl -s -o /dev/null -w "%{http_code}\n" \
  https://pabx.simplificaja.com.br/app/simplificaja_api/index.php/ping
curl -s -o /dev/null -w "%{http_code}\n" \
  https://pabx.simplificaja.com.br/app/simplificaja_api/index.php/ping \
  -H "X-Api-Key: errada"
```

Esperado: `401` nos dois.

- [ ] **Passo 6: fechar o acesso no nginx**

Só o SimplificaJá fala com este app. No bloco do servidor:

```nginx
location /app/simplificaja_api/ {
    allow  IP_DO_SIMPLIFICAJA;
    deny   all;
    try_files $uri $uri/ /app/simplificaja_api/index.php$is_args$args;
}
```

Conferir que de fora dá `403` e de dentro continua `200`.

- [ ] **Passo 7: commit**

```bash
git add app/simplificaja_api
git commit -m "feat(api): esqueleto do app e autenticacao por chave de dominio"
```

---

### Tarefa 2: Leituras de domínio, ramais e troncos

O painel precisa mostrar antes de mudar. E são baratas: só leitura.

**Arquivos:**
- Criar: `app/simplificaja_api/resources/classes/api_leitura.php`
- Modificar: `app/simplificaja_api/index.php`

**Interfaces:**
- Consome: `api_auth::domain_uuid()` da Tarefa 1
- Produz: `api_leitura::dominio()`, `::ramais()`, `::troncos()`

- [ ] **Passo 1: a classe de leitura**

O registro ao vivo não está no banco — vem do FreeSWITCH pelo event socket, que
é como o próprio FusionPBX consulta.

```php
<?php
class api_leitura {
	public static function dominio(string $domain_uuid): array {
		$database = database::new(['db' => $GLOBALS['db'] ?? null]);
		$d = $database->select("select domain_name, domain_enabled from v_domains "
			." where domain_uuid = :u", ['u' => $domain_uuid], 'row');
		if (empty($d)) {
			// domínio da chave não existe: erro de implantação, estoura alto
			responde(['erro' => 'domínio da chave não existe'], 500);
		}
		return [
			'dominio'  => $d['domain_name'],
			'ativo'    => $d['domain_enabled'] === 't' || $d['domain_enabled'] === true,
			'ramais'   => (int) $database->select("select count(*) as n from v_extensions "
				." where domain_uuid = :u", ['u' => $domain_uuid], 'column'),
			'destinos' => (int) $database->select("select count(*) as n from v_destinations "
				." where domain_uuid = :u", ['u' => $domain_uuid], 'column'),
		];
	}

	public static function ramais(string $domain_uuid): array {
		$database = database::new(['db' => $GLOBALS['db'] ?? null]);
		$linhas = $database->select("select extension, description, enabled "
			." from v_extensions where domain_uuid = :u order by extension",
			['u' => $domain_uuid], 'all') ?? [];

		$registrados = self::registrados();
		foreach ($linhas as &$l) {
			$l['registrado'] = in_array($l['extension'], $registrados, true);
		}
		return $linhas;
	}

	// Quem está registrado agora, direto do FreeSWITCH.
	private static function registrados(): array {
		$esl = event_socket::create();
		if (!$esl) { return []; }
		$saida = event_socket::api('sofia status profile internal reg');
		preg_match_all('/Auth-User:\s+(\S+)/', $saida, $m);
		return $m[1] ?? [];
	}

	public static function troncos(string $domain_uuid): array {
		$database = database::new(['db' => $GLOBALS['db'] ?? null]);
		$linhas = $database->select("select gateway, proxy, username, enabled "
			." from v_gateways where domain_uuid = :u order by gateway",
			['u' => $domain_uuid], 'all') ?? [];

		$saida = event_socket::api('sofia status') ?: '';
		foreach ($linhas as &$l) {
			// REGED aparece na linha do gateway quando ele está registrado
			$l['registrado'] = (bool) preg_match(
				'/' . preg_quote($l['gateway'], '/') . '\s+.*REGED/', $saida);
		}
		return $linhas;
	}
}
```

- [ ] **Passo 2: as rotas**

No `switch` do `index.php`, antes do `default`:

```php
	case 'GET dominio':
		responde(api_leitura::dominio($domain_uuid));
	case 'GET ramais':
		responde(api_leitura::ramais($domain_uuid));
	case 'GET troncos':
		responde(api_leitura::troncos($domain_uuid));
```

- [ ] **Passo 3: verificar contra o PABX real**

```bash
API=https://pabx.simplificaja.com.br/app/simplificaja_api/index.php
curl -s $API/dominio -H "X-Api-Key: A_CHAVE"
curl -s $API/ramais  -H "X-Api-Key: A_CHAVE"
curl -s $API/troncos -H "X-Api-Key: A_CHAVE"
```

Esperado: o domínio `pabx.simplificaja.com.br`, os ramais `1001` e `1002`, e o
tronco `algar` com `registrado: true`.

Conferir o `registrado` contra a fonte, não contra si mesmo:

```bash
ssh root@109.123.250.200 "fs_cli -x 'sofia status gateway fbbe29c7-5af6-4ed4-9f51-be87549ec98a' | grep -E '^Status'"
```

- [ ] **Passo 4: verificar o isolamento**

Criar uma chave para um segundo domínio de teste e conferir que ela **não**
enxerga os ramais do primeiro. Se enxergar, parar tudo: é o defeito que não pode
existir.

- [ ] **Passo 5: commit**

```bash
git commit -am "feat(api): leitura de dominio, ramais e troncos"
```

---

### Tarefa 3: `/saude` — as checagens que pegam falha silenciosa

A tarefa que mais se paga. Cada checagem corresponde a uma falha real de
`armadilhas.md`, com o sintoma que ela produz.

**Arquivos:**
- Criar: `app/simplificaja_api/resources/classes/api_saude.php`
- Modificar: `app/simplificaja_api/index.php`

**Interfaces:**
- Produz: `api_saude::verificar(string $domain_uuid): array` — lista de
  `['checagem','ok','detalhe']`

- [ ] **Passo 1: a classe**

```php
<?php
class api_saude {
	public static function verificar(string $domain_uuid): array {
		$db = database::new(['db' => $GLOBALS['db'] ?? null]);
		$r = [];

		// 1. rotas sem XML gerado -- existem no banco e o FreeSWITCH não as vê
		$n = (int) $db->select("select count(*) as n from v_dialplans "
			." where domain_uuid = :u and coalesce(length(dialplan_xml),0) = 0",
			['u' => $domain_uuid], 'column');
		$r[] = ['checagem' => 'rotas com XML gerado', 'ok' => $n === 0,
			'detalhe' => $n === 0 ? null
				: "$n rota(s) sem XML: a ligação chega e não vai a lugar nenhum"];

		// 2. destino sem vínculo -- aparece na tela e nunca é servido
		$n = (int) $db->select("select count(*) as n from v_dialplans p "
			." where p.domain_uuid = :u and p.dialplan_context = 'public' "
			." and not exists (select 1 from v_destinations d "
			."   where d.dialplan_uuid = p.dialplan_uuid)",
			['u' => $domain_uuid], 'column');
		$r[] = ['checagem' => 'destinos vinculados', 'ok' => $n === 0,
			'detalhe' => $n === 0 ? null
				: "$n rota(s) de entrada sem linha em v_destinations"];

		// 3. auth-calls do perfil externo -- com true o PBX responde 407 ao tronco
		$v = $db->select("select s.sip_profile_setting_value from v_sip_profiles p "
			." join v_sip_profile_settings s on s.sip_profile_uuid = p.sip_profile_uuid "
			." where p.sip_profile_name = 'external' "
			." and s.sip_profile_setting_name = 'auth-calls'", [], 'column');
		$r[] = ['checagem' => 'perfil externo aceita o tronco', 'ok' => $v === 'false',
			'detalhe' => $v === 'false' ? null
				: 'auth-calls=true: o PBX responde 407 às chamadas da operadora'];

		// 4. IP do tronco liberado -- senão o Event Guard bane e tudo dá timeout
		foreach ($db->select("select distinct proxy from v_gateways "
			." where domain_uuid = :u and proxy is not null",
			['u' => $domain_uuid], 'all') ?? [] as $g) {
			$liberado = (int) $db->select("select count(*) as n "
				." from v_access_control_nodes n "
				." join v_access_controls a on a.access_control_uuid = n.access_control_uuid "
				." where a.access_control_name = 'providers' and n.node_type = 'allow' "
				." and n.node_cidr like :p", ['p' => $g['proxy'].'%'], 'column');
			$r[] = ['checagem' => "IP {$g['proxy']} liberado", 'ok' => $liberado > 0,
				'detalhe' => $liberado > 0 ? null
					: 'fora da lista providers: o Event Guard vai banir'];
		}

		// 5. troncos registrados
		$saida = event_socket::api('sofia status') ?: '';
		foreach ($db->select("select gateway from v_gateways where domain_uuid = :u",
			['u' => $domain_uuid], 'all') ?? [] as $g) {
			$ok = (bool) preg_match('/'.preg_quote($g['gateway'],'/').'\s+.*REGED/', $saida);
			$r[] = ['checagem' => "tronco {$g['gateway']} registrado", 'ok' => $ok,
				'detalhe' => $ok ? null : 'sem registro: não entra nem sai ligação'];
		}

		return ['tudo_certo' => !in_array(false, array_column($r, 'ok'), true),
		        'checagens'  => $r];
	}
}
```

- [ ] **Passo 2: a rota**

```php
	case 'GET saude':
		responde(api_saude::verificar($domain_uuid));
```

- [ ] **Passo 3: verificar com tudo certo**

```bash
curl -s $API/saude -H "X-Api-Key: A_CHAVE"
```

Esperado: `"tudo_certo": true`.

- [ ] **Passo 4: verificar quebrando de propósito**

Uma checagem que nunca falha não prova nada. Quebrar uma, ver a `/saude`
apontar, e desfazer:

```bash
ssh root@109.123.250.200 "iptables -A sip-auth-ip -s 177.11.50.217/32 -j DROP"
# a checagem do IP continua ok (ele está na lista providers), mas o tronco cai
sleep 40 && curl -s $API/saude -H "X-Api-Key: A_CHAVE"
ssh root@109.123.250.200 "iptables -D sip-auth-ip -s 177.11.50.217/32 -j DROP"
```

Esperado: `tronco algar registrado` com `ok: false` e o detalhe explicando.

Repetir para a rota sem XML, que é a falha mais traiçoeira:

```bash
ssh root@109.123.250.200 "su - postgres -c \"psql -d fusionpbx -c \\\"
  update v_dialplans set dialplan_xml = null
  where dialplan_name = 'entrada-2570-0191';\\\"\""
curl -s $API/saude -H "X-Api-Key: A_CHAVE"
```

Esperado: `rotas com XML gerado` falhando. **Depois desfazer salvando a rota
pela tela do FusionPBX** — é o único jeito de regerar o XML dela.

- [ ] **Passo 5: commit**

```bash
git commit -am "feat(api): verificacao de saude do tenant"
```

---

### Tarefa 4: `POST /ramais`

O primeiro endpoint de escrita. Escolhido antes dos outros porque é o mais
repetitivo na implantação e o mais fácil de verificar: ou o ramal registra, ou
não.

**Arquivos:**
- Criar: `app/simplificaja_api/resources/classes/api_ramal.php`
- Modificar: `app/simplificaja_api/index.php`

**Interfaces:**
- Produz: `api_ramal::criar(string $domain_uuid, array $dados): array` —
  devolve `['extension','senha']`

- [ ] **Passo 1: a classe**

Ramal é entidade do app `extensions`, que tem classe própria. Usar
`database->save()` com o array no formato deles e a permissão temporária.

```php
<?php
class api_ramal {
	public static function criar(string $domain_uuid, array $dados): array {
		$numero = trim($dados['extension'] ?? '');
		if ($numero === '') { responde(['erro' => 'extension é obrigatório'], 422); }

		$db = database::new(['db' => $GLOBALS['db'] ?? null]);

		$existe = (int) $db->select("select count(*) as n from v_extensions "
			." where domain_uuid = :u and extension = :e",
			['u' => $domain_uuid, 'e' => $numero], 'column');
		if ($existe > 0) { responde(['erro' => "ramal $numero já existe"], 409); }

		// senha forte: é credencial SIP exposta na internet
		$senha = base64_encode(random_bytes(15));

		$p = permissions::new();
		$p->add('extension_add', 'temp');
		$p->add('extension_edit', 'temp');

		$array['extensions'][0] = [
			'extension_uuid' => uuid(),
			'domain_uuid'    => $domain_uuid,
			'extension'      => $numero,
			'password'       => $senha,
			'user_context'   => $dados['contexto'] ?? null, // preenchido abaixo
			'description'    => $dados['descricao'] ?? '',
			'enabled'        => 'true',
		];
		// contexto é o nome do domínio; sem ele o ramal não bate no dialplan certo
		$array['extensions'][0]['user_context'] = $db->select(
			"select domain_name from v_domains where domain_uuid = :u",
			['u' => $domain_uuid], 'column');

		$db->save($array);

		$p->delete('extension_add', 'temp');
		$p->delete('extension_edit', 'temp');

		cache::delete('directory:' . $numero . '@' . $array['extensions'][0]['user_context']);

		return ['extension' => $numero, 'senha' => $senha];
	}
}
```

- [ ] **Passo 2: a rota**

```php
	case 'POST ramais':
		$corpo = json_decode(file_get_contents('php://input'), true) ?? [];
		responde(api_ramal::criar($domain_uuid, $corpo), 201);
```

- [ ] **Passo 3: criar um ramal**

```bash
curl -s -X POST $API/ramais -H "X-Api-Key: A_CHAVE" \
  -H "Content-Type: application/json" \
  -d '{"extension":"1050","descricao":"teste da API"}'
```

Esperado: `{"extension":"1050","senha":"..."}` com código 201.

- [ ] **Passo 4: verificar pelo efeito, não pelo retorno**

O retorno 201 não prova nada — a lição da validação. O que prova é o ramal
registrar de verdade. Usar o softphone de teste
(`scratchpad/softphone.html` do repositório do Chatwoot) ou um Zoiper com a
senha devolvida, e então:

```bash
ssh root@109.123.250.200 "fs_cli -x 'sofia status profile internal reg' | grep -c 'Auth-User:.*1050'"
```

Esperado: `1`.

- [ ] **Passo 5: verificar a recusa de duplicado**

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST $API/ramais \
  -H "X-Api-Key: A_CHAVE" -H "Content-Type: application/json" \
  -d '{"extension":"1050"}'
```

Esperado: `409`.

- [ ] **Passo 6: limpar e commitar**

```bash
ssh root@109.123.250.200 "su - postgres -c \"psql -d fusionpbx -c \\\"
  delete from v_extensions where extension = '1050';\\\"\""
git commit -am "feat(api): criacao de ramal"
```

---

### Tarefa 5: `POST /troncos`

**Arquivos:**
- Criar: `app/simplificaja_api/resources/classes/api_tronco.php`
- Modificar: `app/simplificaja_api/index.php`

**Interfaces:**
- Produz: `api_tronco::criar(string $domain_uuid, array $dados): array`

- [ ] **Passo 1: a classe**

Os valores que a validação provou necessários estão fixados aqui de propósito:
`profile=external` (com `auth-calls=false`), `extension_in_contact=true` (o
contato `gw+uuid` não bate com a conta) e `codec_prefs` em G.711, que é o que
operadora brasileira fala.

```php
<?php
class api_tronco {
	public static function criar(string $domain_uuid, array $dados): array {
		foreach (['nome','usuario','senha','host'] as $campo) {
			if (empty($dados[$campo])) { responde(['erro' => "$campo é obrigatório"], 422); }
		}

		$db = database::new(['db' => $GLOBALS['db'] ?? null]);
		$p  = permissions::new();
		$p->add('gateway_add', 'temp');
		$p->add('gateway_edit', 'temp');

		$gateway_uuid = uuid();
		$array['gateways'][0] = [
			'gateway_uuid'         => $gateway_uuid,
			'domain_uuid'          => $domain_uuid,
			'gateway'              => $dados['nome'],
			'username'             => $dados['usuario'],
			'password'             => $dados['senha'],
			'proxy'                => $dados['host'],
			'realm'                => $dados['realm'] ?? $dados['host'],
			'register'             => 'true',
			'register_transport'   => $dados['transporte'] ?? 'udp',
			'profile'              => 'external',
			'extension'            => $dados['usuario'],
			'extension_in_contact' => 'true',
			'codec_prefs'          => 'PCMA,PCMU',
			'enabled'              => 'true',
		];
		$db->save($array);

		$p->delete('gateway_add', 'temp');
		$p->delete('gateway_edit', 'temp');

		// libera o IP da operadora, senão o Event Guard bane e tudo dá timeout
		self::liberar_ip($db, $dados['host']);

		event_socket::api('reloadxml');
		event_socket::api('reloadacl');
		event_socket::api('sofia profile external rescan');

		return ['gateway_uuid' => $gateway_uuid, 'nome' => $dados['nome']];
	}

	private static function liberar_ip($db, string $host): void {
		if (!filter_var($host, FILTER_VALIDATE_IP)) { return; }
		$ja = (int) $db->select("select count(*) as n from v_access_control_nodes "
			." where node_cidr = :c", ['c' => $host.'/32'], 'column');
		if ($ja > 0) { return; }

		$acl = $db->select("select access_control_uuid from v_access_controls "
			." where access_control_name = 'providers'", [], 'column');
		if (empty($acl)) { responde(['erro' => "lista de acesso 'providers' não existe"], 500); }

		$p = permissions::new();
		$p->add('access_control_node_add', 'temp');
		$array['access_control_nodes'][0] = [
			'access_control_node_uuid' => uuid(),
			'access_control_uuid'      => $acl,
			'node_type'                => 'allow',
			'node_cidr'                => $host.'/32',
			'node_description'         => 'tronco criado pela API',
		];
		$db->save($array);
		$p->delete('access_control_node_add', 'temp');
	}
}
```

- [ ] **Passo 2: a rota**

```php
	case 'POST troncos':
		$corpo = json_decode(file_get_contents('php://input'), true) ?? [];
		responde(api_tronco::criar($domain_uuid, $corpo), 201);
```

- [ ] **Passo 3: criar um tronco de teste**

Usar as credenciais reais que já funcionam, com outro nome:

```bash
curl -s -X POST $API/troncos -H "X-Api-Key: A_CHAVE" \
  -H "Content-Type: application/json" \
  -d '{"nome":"teste-api","usuario":"75681","senha":"Isaac@dgv2026",
       "host":"177.11.50.217","realm":"nextbilling","transporte":"tcp"}'
```

- [ ] **Passo 4: verificar pelo registro**

```bash
sleep 15
ssh root@109.123.250.200 "fs_cli -x 'sofia status' | grep teste-api"
```

Esperado: a linha do gateway com `REGED`.

Conferir também que o IP entrou na lista:

```bash
curl -s $API/saude -H "X-Api-Key: A_CHAVE"
```

- [ ] **Passo 5: limpar e commitar**

Dois troncos com a mesma conta SIP disputam o registro — apagar o de teste:

```bash
ssh root@109.123.250.200 "su - postgres -c \"psql -d fusionpbx -c \\\"
  delete from v_gateways where gateway = 'teste-api';\\\"\"
  fs_cli -x 'sofia profile external killgw teste-api'"
git commit -am "feat(api): criacao de tronco por numero"
```

---

### Tarefa 6: `POST /destinos`

O endpoint que a validação provou ser possível. Segue exatamente
`exemplos/criar-destino.php`.

**Arquivos:**
- Criar: `app/simplificaja_api/resources/classes/api_destino.php`
- Modificar: `app/simplificaja_api/index.php`

**Interfaces:**
- Produz: `api_destino::criar(string $domain_uuid, array $dados): array`

- [ ] **Passo 1: a classe**

```php
<?php
class api_destino {
	public static function criar(string $domain_uuid, array $dados): array {
		$numero = trim($dados['numero'] ?? '');
		$destino = trim($dados['destino'] ?? '');   // ramal, grupo ou fila
		if ($numero === '' || $destino === '') {
			responde(['erro' => 'numero e destino são obrigatórios'], 422);
		}

		$db = database::new(['db' => $GLOBALS['db'] ?? null]);
		$dominio = $db->select("select domain_name from v_domains where domain_uuid = :u",
			['u' => $domain_uuid], 'column');

		$dialplan_uuid = uuid();
		$p = permissions::new();
		foreach (['dialplan_add','dialplan_edit','dialplan_detail_add',
		          'dialplan_detail_edit','destination_add','destination_edit'] as $perm) {
			$p->add($perm, 'temp');
		}

		$array['dialplans'][0] = [
			'dialplan_uuid'     => $dialplan_uuid,
			'domain_uuid'       => $domain_uuid,
			'app_uuid'          => 'c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4',
			'dialplan_name'     => 'entrada-'.$numero,
			'dialplan_number'   => $numero,
			'dialplan_context'  => 'public',
			'dialplan_continue' => 'false',
			'dialplan_order'    => '100',
			'dialplan_enabled'  => 'true',
			'dialplan_description' => $dados['descricao'] ?? '',
		];
		$d = 0;
		$array['dialplans'][0]['dialplan_details'][$d++] = [
			'dialplan_detail_uuid'  => uuid(), 'dialplan_uuid' => $dialplan_uuid,
			'domain_uuid'           => $domain_uuid,
			'dialplan_detail_tag'   => 'condition',
			'dialplan_detail_type'  => 'destination_number',
			'dialplan_detail_data'  => '^'.preg_quote($numero, '/').'$',
			'dialplan_detail_order' => '005', 'dialplan_detail_group' => '0',
		];
		$array['dialplans'][0]['dialplan_details'][$d++] = [
			'dialplan_detail_uuid'  => uuid(), 'dialplan_uuid' => $dialplan_uuid,
			'domain_uuid'           => $domain_uuid,
			'dialplan_detail_tag'   => 'action',
			'dialplan_detail_type'  => 'transfer',
			'dialplan_detail_data'  => $destino.' XML '.$dominio,
			'dialplan_detail_order' => '020', 'dialplan_detail_group' => '0',
		];
		$array['destinations'][0] = [
			'destination_uuid'    => uuid(),
			'domain_uuid'         => $domain_uuid,
			'dialplan_uuid'       => $dialplan_uuid,
			'destination_type'    => 'inbound',
			'destination_number'  => $numero,
			'destination_enabled' => 'true',
			'destination_description' => $dados['descricao'] ?? '',
		];
		$db->save($array);
		unset($array);

		foreach (['dialplan_add','dialplan_edit','dialplan_detail_add',
		          'dialplan_detail_edit','destination_add','destination_edit'] as $perm) {
			$p->delete($perm, 'temp');
		}

		// o passo que separa rota viva de rota morta
		$dialplan = new dialplan();
		$dialplan->source      = 'details';
		$dialplan->destination = 'database';
		$dialplan->context     = 'public';
		$dialplan->is_empty    = 'dialplan_xml';
		$dialplan->xml();

		cache::delete('dialplan:public');

		// conferir antes de dizer que deu certo
		$tam = (int) $db->select("select coalesce(length(dialplan_xml),0) as n "
			." from v_dialplans where dialplan_uuid = :u",
			['u' => $dialplan_uuid], 'column');
		if ($tam === 0) {
			responde(['erro' => 'destino criado mas o XML não foi gerado; rode /saude'], 500);
		}

		return ['numero' => $numero, 'destino' => $destino, 'xml_bytes' => $tam];
	}
}
```

- [ ] **Passo 2: a rota**

```php
	case 'POST destinos':
		$corpo = json_decode(file_get_contents('php://input'), true) ?? [];
		responde(api_destino::criar($domain_uuid, $corpo), 201);
```

- [ ] **Passo 3: criar**

```bash
curl -s -X POST $API/destinos -H "X-Api-Key: A_CHAVE" \
  -H "Content-Type: application/json" \
  -d '{"numero":"999000222","destino":"1001","descricao":"teste da API"}'
```

Esperado: `xml_bytes` maior que zero.

- [ ] **Passo 4: verificar que o FreeSWITCH enxerga**

```bash
ssh root@109.123.250.200 "rm -rf /var/cache/fusionpbx/*
  fs_cli -x \"bgapi originate {domain_name=pabx.simplificaja.com.br}loopback/999000222/public/XML &hangup()\"
  sleep 6
  grep -ac 'entrada-999000222' /var/log/freeswitch/freeswitch.log"
```

Esperado: maior que zero — a rota foi processada de verdade.

- [ ] **Passo 5: limpar e commitar**

```bash
ssh root@109.123.250.200 "su - postgres -c \"psql -d fusionpbx -c \\\"
  delete from v_destinations where destination_number = '999000222';
  delete from v_dialplan_details where dialplan_uuid in
    (select dialplan_uuid from v_dialplans where dialplan_number = '999000222');
  delete from v_dialplans where dialplan_number = '999000222';\\\"\""
git commit -am "feat(api): criacao de destino com XML gerado"
```

---

### Tarefa 7: `POST /dominio`

Deixada por último de propósito: é a mais complexa, e as tarefas anteriores
foram testadas contra o domínio que já existe. Agora que cada peça funciona,
criar o domínio é encadeá-las.

**Arquivos:**
- Criar: `app/simplificaja_api/resources/classes/api_dominio.php`
- Modificar: `app/simplificaja_api/index.php`

**Interfaces:**
- Consome: `api_ramal::criar`, `api_tronco::criar`, `api_destino::criar`
- Produz: `api_dominio::criar(array $dados): array`

- [ ] **Passo 1: entender como o FusionPBX cria domínio**

Antes de escrever, ler `core/domains/domain_edit.php` e ver o que ele dispara
além de inserir em `v_domains` — são os `app_defaults.php` dos 33 apps que
populam o domínio novo. É isso que faz o domínio nascer utilizável.

```bash
ssh root@109.123.250.200 "grep -nE 'app_defaults|domain_uuid|save' /var/www/fusionpbx/core/domains/domain_edit.php | head -20"
```

- [ ] **Passo 2: a classe**

Este endpoint **não usa a chave de domínio** — ele cria o domínio, então não há
domínio ainda. Usa uma chave de instância separada, guardada em
`default_settings` global, categoria `simplificaja`, subcategoria `admin_key`.

```php
<?php
class api_dominio {
	public static function criar(array $dados): array {
		$nome = trim($dados['dominio'] ?? '');
		if ($nome === '') { responde(['erro' => 'dominio é obrigatório'], 422); }

		$db = database::new(['db' => $GLOBALS['db'] ?? null]);
		$ja = (int) $db->select("select count(*) as n from v_domains where domain_name = :d",
			['d' => $nome], 'column');
		if ($ja > 0) { responde(['erro' => "domínio $nome já existe"], 409); }

		$domain_uuid = uuid();
		$p = permissions::new();
		$p->add('domain_add', 'temp');
		$array['domains'][0] = [
			'domain_uuid'        => $domain_uuid,
			'domain_name'        => $nome,
			'domain_enabled'     => 'true',
			'domain_description' => $dados['descricao'] ?? '',
		];
		$db->save($array);
		$p->delete('domain_add', 'temp');

		// aplica os app_defaults dos apps -- é o que faz o domínio nascer usável
		self::aplicar_padroes($domain_uuid);

		// chave de API própria do domínio, para o painel usar daqui em diante
		$chave = bin2hex(random_bytes(24));
		self::guardar_chave($db, $domain_uuid, $chave);

		event_socket::api('reloadxml');

		return ['domain_uuid' => $domain_uuid, 'dominio' => $nome, 'api_key' => $chave];
	}
}
```

O corpo de `aplicar_padroes` sai do que o Passo 1 revelar — não inventar: o
FusionPBX já faz isso na criação pela tela, e o objetivo é chamar o mesmo
caminho.

- [ ] **Passo 3: verificar**

```bash
curl -s -X POST $API/dominio -H "X-Api-Key: CHAVE_DE_INSTANCIA" \
  -H "Content-Type: application/json" \
  -d '{"dominio":"teste-api.pabx.simplificaja.com.br"}'
```

Depois, com a chave devolvida, encadear as outras tarefas: criar ramal, tronco e
destino nesse domínio novo, e rodar `/saude`.

**Esperado: `tudo_certo: true` num domínio criado inteiramente pela API.** É a
prova de que a Etapa 1 cumpriu o objetivo.

- [ ] **Passo 4: verificar o isolamento no domínio novo**

Com a chave do domínio novo, chamar `GET /ramais` e conferir que **não** aparece
o `1001` do domínio antigo. Se aparecer, parar tudo.

- [ ] **Passo 5: limpar e commitar**

```bash
ssh root@109.123.250.200 "su - postgres -c \"psql -d fusionpbx -c \\\"
  delete from v_domains where domain_name = 'teste-api.pabx.simplificaja.com.br';\\\"\""
git commit -am "feat(api): criacao de dominio de cliente"
```

---

## Ao terminar

A Etapa 1 está cumprida quando um domínio criado pela API, com ramal, tronco e
destino criados pela API, responde `tudo_certo: true` no `/saude` — e o ramal
registra de verdade no FreeSWITCH.

Aí o painel do SimplificaJá tem contra o que falar, e a Etapa 2 (gravações, URA
e fluxos) passa a fazer sentido.
