<?php
// Teste: dá para criar um destino pelas classes do FusionPBX e ter o
// dialplan_xml gerado, sem passar pela tela? Limpa tudo no fim.
require "/var/www/fusionpbx/resources/require.php";

$db = database::new(['db' => $db ?? null]);
$dominio = 'pabx.simplificaja.com.br';

// descobre o domain_uuid
$linha = $db->select("select domain_uuid from v_domains where domain_name = :d",
                     ['d' => $dominio], 'row');
$domain_uuid = $linha['domain_uuid'];
echo "dominio: $domain_uuid\n";

$dialplan_uuid    = uuid();
$destination_uuid = uuid();
$numero           = '999000111';

// 1. permissoes temporarias
$p = permissions::new();
foreach (['dialplan_add','dialplan_edit','dialplan_detail_add','dialplan_detail_edit',
          'destination_add','destination_edit'] as $perm) {
    $p->add($perm, 'temp');
}

// 2. monta e grava pelas classes
$array['dialplans'][0] = [
    'dialplan_uuid'    => $dialplan_uuid,
    'domain_uuid'      => $domain_uuid,
    'app_uuid'         => 'c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4',
    'dialplan_name'    => 'spike-teste',
    'dialplan_number'  => $numero,
    'dialplan_context' => 'public',
    'dialplan_continue'=> 'false',
    'dialplan_order'   => '100',
    'dialplan_enabled' => 'true',
    'dialplan_description' => 'teste temporario, pode apagar',
];
$d = 0;
$array['dialplans'][0]['dialplan_details'][$d++] = [
    'dialplan_detail_uuid' => uuid(), 'dialplan_uuid' => $dialplan_uuid,
    'domain_uuid' => $domain_uuid, 'dialplan_detail_tag' => 'condition',
    'dialplan_detail_type' => 'destination_number',
    'dialplan_detail_data' => '^'.$numero.'$',
    'dialplan_detail_order' => '005', 'dialplan_detail_group' => '0',
];
$array['dialplans'][0]['dialplan_details'][$d++] = [
    'dialplan_detail_uuid' => uuid(), 'dialplan_uuid' => $dialplan_uuid,
    'domain_uuid' => $domain_uuid, 'dialplan_detail_tag' => 'action',
    'dialplan_detail_type' => 'transfer',
    'dialplan_detail_data' => '1001 XML '.$dominio,
    'dialplan_detail_order' => '020', 'dialplan_detail_group' => '0',
];
$array['destinations'][0] = [
    'destination_uuid'    => $destination_uuid,
    'domain_uuid'         => $domain_uuid,
    'dialplan_uuid'       => $dialplan_uuid,
    'destination_type'    => 'inbound',
    'destination_number'  => $numero,
    'destination_enabled' => 'true',
    'destination_description' => 'teste temporario',
];
$db->save($array);
unset($array);

foreach (['dialplan_add','dialplan_edit','dialplan_detail_add','dialplan_detail_edit',
          'destination_add','destination_edit'] as $perm) {
    $p->delete($perm, 'temp');
}
echo "gravado\n";

// 3. gera o XML pelo caminho das classes
$dialplan = new dialplan();
$dialplan->source      = 'details';
$dialplan->destination = 'database';
$dialplan->context     = 'public';
$dialplan->is_empty    = 'dialplan_xml';
$dialplan->xml();

// 4. o teste
$r = $db->select("select coalesce(length(dialplan_xml),0) as tam from v_dialplans where dialplan_uuid = :u",
                 ['u' => $dialplan_uuid], 'row');
echo "RESULTADO: dialplan_xml tem {$r['tam']} bytes\n";
echo ($r['tam'] > 0 ? ">>> FUNCIONA: XML gerado pelas classes\n" : ">>> NAO FUNCIONA: XML continua vazio\n");

// 5. limpa
$db->execute("delete from v_destinations where destination_uuid = :u", ['u' => $destination_uuid]);
$db->execute("delete from v_dialplan_details where dialplan_uuid = :u", ['u' => $dialplan_uuid]);
$db->execute("delete from v_dialplans where dialplan_uuid = :u", ['u' => $dialplan_uuid]);
echo "limpo\n";
