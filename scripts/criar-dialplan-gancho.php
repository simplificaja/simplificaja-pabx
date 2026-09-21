<?php
/**
 * Cria o plano de discagem GLOBAL que invoca o gancho de desligamento.
 *
 * Por que no banco e nao num arquivo: em 21/09/2026 o gancho sumiu sozinho.
 * O `POST /dominio` chama `aplicar_padroes()` -> `domains::upgrade()`, que
 * restaura os scripts do FusionPBX a partir da fonte. A invocacao morava em
 * `app/hangup/index.lua` e foi apagada junto -- criar um cliente novo
 * derrubava o registro de chamada de todos os outros, em silencio.
 *
 * Plano de discagem e dado, nao arquivo: o upgrade regenera o XML a partir
 * dele em vez de apagar.
 *
 * Usa `api_hangup_hook` com ordem 20, depois do `local_extension` (10), entao
 * sobrescreve o do FusionPBX de proposito -- e o script chama o dele antes do
 * nosso. `execute_on_hangup` nao colidiria, mas nao dispara: so o
 * api_hangup_hook roda de verdade no teardown.
 */
require_once '/var/www/fusionpbx/resources/require.php';

$database = database::new(['db' => $GLOBALS['db'] ?? null]);
$nome = 'simplificaja_registro_de_chamada';

$existe = $database->select(
	"select dialplan_uuid from v_dialplans where dialplan_name = :n and domain_uuid is null",
	['n' => $nome], 'column'
);
if (!empty($existe)) {
	echo "[dialplan] $nome ja existe\n";
	exit(0);
}

$dialplan_uuid = uuid();
$p = permissions::new();
foreach (['dialplan_add', 'dialplan_detail_add'] as $permissao) {
	$p->add($permissao, 'temp');
}

$array['dialplans'][0] = [
	'dialplan_uuid'        => $dialplan_uuid,
	'domain_uuid'          => null,
	'dialplan_name'        => $nome,
	'dialplan_context'     => 'global',
	// Depois do local_extension (10) para nao correr antes de o dominio estar
	// resolvido, e cedo o bastante para valer em toda chamada.
	'dialplan_order'       => '20',
	'dialplan_continue'    => 'true',
	'dialplan_enabled'     => 'true',
	'dialplan_description' => 'SimplificaJá: registra a chamada no painel ao desligar',
];
// Acao fora de <condition> e ignorada pelo FreeSWITCH: o plano existe, aparece
// na tela, e nao faz nada. A condicao casa qualquer destino, so para existir.
$array['dialplans'][0]['dialplan_details'][0] = [
	'dialplan_detail_uuid'  => uuid(),
	'dialplan_uuid'         => $dialplan_uuid,
	'dialplan_detail_tag'   => 'condition',
	'dialplan_detail_type'  => '${destination_number}',
	'dialplan_detail_data'  => '^.*$',
	'dialplan_detail_break' => 'never',
	'dialplan_detail_order' => '5',
];
$array['dialplans'][0]['dialplan_details'][1] = [
	'dialplan_detail_uuid'  => uuid(),
	'dialplan_uuid'         => $dialplan_uuid,
	'dialplan_detail_tag'   => 'action',
	'dialplan_detail_type'  => 'export',
	'dialplan_detail_data'  => 'api_hangup_hook=lua chatwoot_hangup.lua',
	'dialplan_detail_order' => '10',
];

$database->save($array);
foreach (['dialplan_add', 'dialplan_detail_add'] as $permissao) {
	$p->delete($permissao, 'temp');
}

// O FreeSWITCH le a coluna `dialplan_xml`, que so o codigo do FusionPBX
// preenche. Sem isto a rota existe no banco, aparece na tela e e invisivel.
$dialplan = new dialplan();
$dialplan->source = 'details';
$dialplan->destination = 'database';
$dialplan->uuid = $dialplan_uuid;
$dialplan->is_empty = 'dialplan_xml';
$dialplan->xml();

$cache = new cache();
$cache->delete('dialplan:global');

echo "[dialplan] $nome criado\n";
