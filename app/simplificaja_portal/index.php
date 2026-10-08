<?php

/** Read-only customer portal for dashboard, call history and extension status. */
require_once dirname(__DIR__, 2).'/resources/require.php';
require_once 'resources/check_auth.php';
require_once dirname(__DIR__).'/registrations/resources/classes/registrations.php';
require_once __DIR__.'/resources/classes/portal_data.php';

if (!permission_exists('xml_cdr_view')) {
	http_response_code(403);
	exit('access denied');
}

if (empty($_SESSION['domain_uuid'])) {
	http_response_code(403);
	exit('domain required');
}

$portal = new simplificaja_portal_data($database);
$view = $_GET['view'] ?? 'dashboard';
if (!in_array($view, ['dashboard', 'calls', 'extensions', 'ratings'], true)) {
	$view = 'dashboard';
}
if (in_array($view, ['calls', 'ratings'], true) && !permission_exists('xml_cdr_view')) {
	http_response_code(403);
	exit('access denied');
}

$timezone = new DateTimeZone('America/Sao_Paulo');
$now = new DateTimeImmutable('now', $timezone);
$since_24_hours = $now->modify('-24 hours');
$extensions = $portal->extensions();
$registered_extensions = ($view === 'dashboard' || $view === 'extensions') ? $portal->registrations() : [];
$active_calls = ($view === 'dashboard' || $view === 'extensions') ? $portal->active_calls($extensions) : [];

$extension_numbers = [];
foreach ($extensions as $extension) {
	$extension_numbers[(string) $extension['extension']] = $extension;
}
$total_extensions = count($extensions);
$busy_count = count(array_intersect_key($active_calls, $extension_numbers));
$registered_count = is_array($registered_extensions) ? count(array_intersect_key($registered_extensions, $extension_numbers)) : null;
$available_count = $registered_count === null ? null : max(0, $registered_count - $busy_count);
$offline_count = $registered_count === null ? null : max(0, $total_extensions - $registered_count);

$missed_count = 0;
$recent_missed_calls = [];
$total_calls = 0;
$volume = array_fill(0, 24, 0);
$volume_path = '';
$maximo_volume = 0;
$volume_area = '';
$history = [];
// O ternario testava o valor JA com o padrao ('7', que passa na lista) e depois
// convertia a chave crua -- ausente, (int) null = 0. Abrir a aba sem `?days=`
// pedia periodo de ZERO dias, e a tela dizia "nenhuma ligacao" com ligacao no
// banco. Achado em 08/10/2026 olhando a tela, nao o codigo.
$dias_pedidos = (string) ($_GET['days'] ?? '7');
$history_days = in_array($dias_pedidos, ['1', '7', '30'], true) ? (int) $dias_pedidos : 7;
$history_status = in_array($_GET['status'] ?? '', ['missed', 'answered'], true) ? $_GET['status'] : '';
$history_direction = in_array($_GET['direction'] ?? '', ['inbound', 'outbound'], true) ? $_GET['direction'] : '';
$history_search = trim(substr((string) ($_GET['search'] ?? ''), 0, 60));

if ($view === 'dashboard') {
	$total_calls = $portal->call_count($since_24_hours);
	$missed_count = $portal->call_count($since_24_hours, true);
	$recent_missed_calls = $portal->missed_calls($since_24_hours);
	$volume_rows = $portal->call_volume($since_24_hours);
	$hour_start = $now->setTime((int) $now->format('H'), 0, 0)->modify('-23 hours');
	$hour_positions = [];
	for ($i = 0; $i < 24; $i++) {
		$hour_positions[$hour_start->modify('+'.$i.' hours')->getTimestamp()] = $i;
	}
	foreach ($volume_rows as $row) {
		$epoch = (int) ($row['hour_epoch'] ?? 0);
		if (isset($hour_positions[$epoch])) {
			$volume[$hour_positions[$epoch]] = (int) $row['total'];
		}
	}
	$maximum = max(1, ...$volume);
	// Exposto para a tela: o grafico sozinho mostra a forma, nao a grandeza.
	$maximo_volume = max($volume);
	$points = [];
	foreach ($volume as $index => $count) {
		$x = 12 + ($index * (676 / 23));
		$y = 84 - (($count / $maximum) * 66);
		$points[] = [round($x, 2), round($y, 2)];
	}
	$volume_path = 'M'.implode(' L', array_map(static fn ($point) => $point[0].' '.$point[1], $points));
	$volume_area = $volume_path.' L688 96 L12 96 Z';
}
elseif ($view === 'calls') {
	$history = $portal->call_history($history_days, $history_status, $history_direction, $history_search);
}
elseif ($view === 'ratings') {
	$since_ratings = $now->modify('-30 days');
	$rating_summary = $portal->rating_summary($since_ratings);
	$rating_distribution = $portal->rating_distribution($since_ratings);
	$rating_by_extension = $portal->rating_by_extension($since_ratings);
	$rating_by_question = $portal->rating_by_question($since_ratings);
	$rating_history = $portal->rating_history(30, $history_search);
	// Taxa de resposta: quantos avaliaram, de quantos foram atendidos. Sem
	// consulta nova -- `call_count` ja existe e ja respeita o escopo.
	$answered_calls = $portal->call_count($since_ratings) - $portal->call_count($since_ratings, true);
	$rating_rate = $answered_calls > 0
		? (int) round(((int) $rating_summary['respostas'] / $answered_calls) * 100)
		: null;
}

$call_routes = $view === 'dashboard' ? $portal->call_routes() : [];
$page_titles = ['dashboard' => 'Visão geral', 'calls' => 'Ligações', 'extensions' => 'Ramais', 'ratings' => 'Avaliações'];
$current_title = $page_titles[$view];
$username = (string) ($_SESSION['username'] ?? 'Usuário');
$logo_url = '/themes/simplificaja/images/logo_thumbnail.svg';
$e = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$is_true = static fn ($value): bool => in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
$display_time = static function ($value) use ($timezone): string {
	if (empty($value)) {
		return '—';
	}
	try {
		return (new DateTimeImmutable((string) $value))->setTimezone($timezone)->format('d/m H:i');
	} catch (Throwable $exception) {
		return '—';
	}
};

?><!doctype html>
<html lang="pt-BR">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= $e($current_title) ?> · SimplificaJá PABX</title>
	<link rel="stylesheet" href="/resources/bootstrap/css/bootstrap.min.css.php">
	<link rel="stylesheet" href="/resources/fontawesome/css/all.min.css.php">
	<link rel="stylesheet" href="/themes/default/css.php">
	<style>
		:root{--sj-purple:#5c2ee6;--sj-side:#252a32;--sj-ink:#30343a;--sj-muted:#737980;--sj-line:#d8dce0;--sj-green:#348253;--sj-red:#ba443e}
		*{box-sizing:border-box}html,body{height:100%;margin:0}body{background:#edf0f2;color:#262a2e;font:14px Arial,Helvetica,sans-serif;text-align:left}button,input,select{font:inherit}button{cursor:pointer}
		#sj-portal{height:100vh;min-height:580px;display:flex;overflow:hidden;background:#f2f4f5}
		.sj-side{position:relative;z-index:4;flex:0 0 60px;width:60px;overflow:hidden;background:var(--sj-side);transition:flex-basis .3s cubic-bezier(.16,1,.3,1),width .3s cubic-bezier(.16,1,.3,1)}.sj-side.expanded{flex-basis:220px;width:220px}.sj-brand{height:73px;display:flex;align-items:center;justify-content:center}.sj-brand img{width:23px;height:23px;object-fit:contain}
		.sj-nav-button{appearance:none;display:flex;width:60px;height:44px;align-items:center;justify-content:flex-start;gap:13px;margin:3px 0;padding:0 21px;border:0;border-radius:5px;background:transparent;color:#e5e7eb;text-decoration:none;white-space:nowrap;transition:width .3s cubic-bezier(.16,1,.3,1),padding .3s cubic-bezier(.16,1,.3,1),background-color .18s ease,color .18s ease}.sj-nav-button i{flex:0 0 18px;font-size:16px;text-align:center;transition:transform .22s ease,color .18s ease}.sj-nav-label{overflow:hidden;width:0;opacity:0;transform:translateX(-5px);font-size:14px;transition:width .28s cubic-bezier(.16,1,.3,1),opacity .18s ease,transform .24s ease}.sj-side.expanded .sj-nav-button{width:220px;padding-right:16px;padding-left:21px}.sj-side.expanded .sj-nav-label{width:155px;opacity:1;transform:translateX(0);transition-delay:.04s}.sj-nav-button:hover{background:#ffffff12;color:#fff}.sj-nav-button.active{background:transparent;color:#fff}.sj-nav-button:focus-visible{outline:2px solid #b59bff;outline-offset:-3px}
		.sj-active-marker{position:absolute;z-index:2;top:95px;left:7px;width:7px;height:7px;border-radius:50%;background:#8b5cf6;box-shadow:0 0 8px #8b5cf699;opacity:0;pointer-events:none;transform:translateY(-50%);transition:top .32s cubic-bezier(.33,1,.68,1),opacity .12s ease}.sj-active-marker.land-down{animation:sj-marker-land-down .18s cubic-bezier(.33,1,.68,1) both}.sj-active-marker.land-up{animation:sj-marker-land-up .18s cubic-bezier(.33,1,.68,1) both}@keyframes sj-marker-land-down{0%,100%{translate:0 0}45%{translate:0 2px}}@keyframes sj-marker-land-up{0%,100%{translate:0 0}45%{translate:0 -2px}}
		.sj-main{display:flex;min-width:0;flex:1;flex-direction:column}.sj-header{z-index:3;height:60px;flex:0 0 60px;display:flex;align-items:center;justify-content:space-between;padding:0 20px 0 15px;background:#fff;box-shadow:0 2px 7px #d0d8e5;color:#555b61;font-size:12px}.sj-header-left{display:flex;align-items:center;gap:15px}.sj-header-left>i{color:#626a70}.sj-crumb{color:#6d747a}.sj-crumb strong{color:#373d42;font-weight:600}
		.sj-account{position:relative}.sj-account-button{display:flex;align-items:center;gap:7px;border:0;border-radius:3px;padding:6px 8px;background:transparent;color:#4b5258;font-size:12px}.sj-account-button:hover,.sj-account-button[aria-expanded=true]{background:#f0f2f4}.sj-account-button>i:first-child{font-size:18px;color:#666e74}.sj-account-menu{position:absolute;top:calc(100% + 5px);right:0;width:178px;padding:5px;border:1px solid #d4d8db;border-radius:3px;background:#fff;box-shadow:0 5px 14px #191f2429}.sj-account-menu[hidden]{display:none}.sj-account-menu a{display:flex;align-items:center;gap:9px;border-radius:2px;padding:9px 8px;color:#42494e;text-decoration:none;font-size:12px}.sj-account-menu a:hover{background:#f1f3f5}.sj-separator{height:1px;margin:4px 0;background:#e4e7e9}
		.sj-workspace{display:flex;min-height:0;flex:1}.sj-page{min-width:0;width:100%;padding:14px 16px 24px;overflow:auto}.sj-action{min-height:43px;margin:0 0 13px;padding-bottom:8px;display:flex;align-items:center;justify-content:space-between;gap:10px;border-bottom:1px solid #d0d4d7}.sj-title{color:#30363b;font-size:17px;font-weight:500}.sj-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:1px 0 12px}.sj-muted{color:#737a80;font-size:11px}.sj-select,.sj-input{min-height:30px;padding:5px 8px;border:1px solid #cbd0d4;border-radius:3px;background:#fff;color:#42484d;font-size:11px}.sj-button{border:1px solid #c9ced2;border-radius:3px;padding:6px 9px;background:#f8f9fa;color:#353a3f;font-size:11px}.sj-button i{margin-right:5px;color:#626a70}
		.sj-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.sj-card{min-width:0;padding:11px;border:1px solid #d0d5d9;border-radius:3px;background:#fff}.sj-card.wide{grid-column:span 2}.sj-card-head{display:flex;align-items:center;justify-content:space-between;gap:8px;color:#555d63;font-size:11px;font-weight:600}.sj-card-head i{color:#696f76;font-size:13px}.sj-stats{display:flex;align-items:flex-end;gap:18px;margin-top:10px}.sj-stat strong{display:block;color:#31373c;font-size:23px;font-weight:400;line-height:1}.sj-stat span{display:block;margin-top:5px;color:#727a80;font-size:9px}.sj-stat.green strong{color:#27834d}.sj-stat.orange strong{color:#bd6c38}.sj-stat.purple strong{color:var(--sj-purple)}.sj-ext-bar{height:6px;display:flex;overflow:hidden;margin-top:11px;border-radius:4px;background:#e9ecee}.sj-ext-bar span{background:#52a977}.sj-ext-note{margin-top:8px;color:#70777d;font-size:9px}.sj-live-call{display:flex;justify-content:space-between;gap:8px;padding:8px 0;border-top:1px solid #e7eaec;color:#40474d;font-size:10px}.sj-live-call:first-of-type{margin-top:8px}.sj-live-call i{color:#39855a}.sj-live-call small{color:#777f85;font-size:9px;white-space:nowrap}
		.sj-missed-list{margin-top:7px}.sj-missed{display:grid;grid-template-columns:minmax(100px,1fr) minmax(110px,1.3fr) minmax(70px,.8fr) auto;align-items:center;gap:7px;padding:7px 0;border-top:1px solid #e7eaec;color:#42494e;font-size:10px}.sj-missed:first-child{border-top:0}.sj-missed strong{font-weight:600}.sj-missed span:nth-child(2){color:#687078}.sj-missed time{color:#858c91;font-size:9px;white-space:nowrap}.sj-badge{display:inline-block;min-width:54px;padding:3px 5px;border-radius:10px;text-align:center;font-size:9px;white-space:nowrap}.sj-badge.missed{background:#fff0ef;color:var(--sj-red)}.sj-badge.answered{background:#eaf6ee;color:var(--sj-green)}.sj-link{border:0;padding:0;background:transparent;color:var(--sj-purple);font-size:10px;text-decoration:underline;text-underline-offset:2px}
		.sj-chart-card,.sj-flow-card{margin-top:9px;padding:11px;border:1px solid #d0d5d9;border-radius:3px;background:#fff}.sj-chart{display:block;width:100%;height:95px;margin-top:8px;overflow:hidden}.sj-chart-wrap{position:relative;padding-left:38px}.sj-chart-y{position:absolute;left:0;width:30px;transform:translateY(-50%);color:#41484e;font-size:11px;font-weight:600;text-align:right;font-variant-numeric:tabular-nums;line-height:1}.sj-gridline{stroke:#e1e4e7;stroke-width:1;vector-effect:non-scaling-stroke}.sj-area{fill:#5c2ee6;fill-opacity:.09}.sj-line{fill:none;stroke:#5c2ee6;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;vector-effect:non-scaling-stroke}.sj-chart-labels{display:flex;justify-content:space-between;margin-top:2px;padding-left:38px;color:#81888e;font-size:9px}.sj-flow-head{display:flex;align-items:center;justify-content:space-between;gap:8px;color:#3c4248;font-size:11px;font-weight:600}.sj-flow-row{display:grid;grid-template-columns:minmax(120px,1fr) 18px minmax(130px,1fr);align-items:center;gap:7px;margin-top:10px;padding:8px;border:1px solid #e2e4e6;border-radius:3px;background:#fff}.sj-flow-node{min-width:0}.sj-flow-node small{display:block;color:#82898f;font-size:9px}.sj-flow-node strong{display:block;margin-top:3px;overflow-wrap:anywhere;color:#444b50;font-size:10px;font-weight:600}.sj-flow-arrow{color:#998fc4;text-align:center;font-size:10px}.sj-note{margin-top:8px;color:#80878c;font-size:9px}
		.sj-ext-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin-bottom:11px}.sj-ext-stat{padding:10px 11px;border:1px solid #d0d5d9;border-radius:3px;background:#fff}.sj-ext-stat span{display:block;color:#747c82;font-size:10px}.sj-ext-stat strong{display:block;margin-top:5px;color:#343a40;font-size:19px;font-weight:400}.sj-ext-stat.available strong{color:var(--sj-green)}.sj-ext-stat.busy strong{color:var(--sj-purple)}.sj-ext-stat.offline strong{color:#8a9095}.sj-ext-controls{display:flex;align-items:center;justify-content:space-between;gap:9px;margin-bottom:10px}.sj-ext-filters{display:flex;flex-wrap:wrap;gap:5px}.sj-ext-filter{border:1px solid #cbd0d4;border-radius:3px;padding:6px 9px;background:#fff;color:#596168;font-size:10px}.sj-ext-filter.active{border-color:#b9a9f4;background:#f3f0ff;color:var(--sj-purple)}.sj-ext-board{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:9px}.sj-ext-card{min-width:0;padding:11px;border:1px solid #d0d5d9;border-left:3px solid #58a878;border-radius:3px;background:#fff}.sj-ext-card.busy{border-left-color:var(--sj-purple)}.sj-ext-card.offline,.sj-ext-card.unknown{border-left-color:#b8bec3}.sj-ext-card[hidden]{display:none}.sj-ext-top{display:flex;align-items:center;justify-content:space-between;gap:6px}.sj-ext-number{color:#353c41;font-size:15px;font-weight:600}.sj-ext-state{display:inline-flex;align-items:center;gap:4px;color:#43865c;font-size:9px;white-space:nowrap}.sj-ext-state:before{width:6px;height:6px;border-radius:50%;background:#55a875;content:""}.sj-ext-card.busy .sj-ext-state{color:#6550b3}.sj-ext-card.busy .sj-ext-state:before{background:var(--sj-purple)}.sj-ext-card.offline .sj-ext-state,.sj-ext-card.unknown .sj-ext-state{color:#858c91}.sj-ext-card.offline .sj-ext-state:before,.sj-ext-card.unknown .sj-ext-state:before{background:#aeb4b9}.sj-ext-person{margin-top:7px;color:#596168;font-size:11px}.sj-ext-detail{min-height:27px;margin-top:5px;color:#858c91;font-size:9px;line-height:1.5}.sj-empty{padding:25px;border:1px solid #d0d5d9;border-radius:3px;background:#fff;color:#737a80;text-align:center;font-size:12px}
		.sj-table-tools{display:flex;align-items:center;justify-content:space-between;gap:9px;margin-bottom:10px}.sj-filters{display:flex;flex-wrap:wrap;gap:6px}.sj-search{position:relative;width:min(250px,45%)}.sj-search i{position:absolute;top:9px;left:9px;color:#80878d;font-size:11px}.sj-search input{width:100%;height:30px;padding:5px 9px 5px 26px;border:1px solid #cbd0d4;border-radius:3px;background:#fff;font-size:11px}.sj-table-wrap{overflow-x:auto;border:1px solid #d0d5d9;border-radius:3px;background:#fff}.sj-table{width:100%;min-width:650px;border-collapse:collapse}.sj-table th{padding:9px 10px;background:#f4f5f6;color:#626a70;text-align:left;font-size:10px;font-weight:600}.sj-table td{padding:10px;border-top:1px solid #e5e8ea;color:#41484e;font-size:11px}.sj-table-footer{display:flex;justify-content:space-between;gap:10px;padding:9px 2px;color:#767e84;font-size:10px}
		@media(max-width:760px){.sj-header{z-index:6}.sj-side.expanded{position:absolute;top:0;bottom:0;left:0;width:210px;flex:0 0 60px;box-shadow:8px 0 18px #1f28302b}.sj-page{padding:11px 9px 18px}.sj-grid{grid-template-columns:1fr}.sj-card.wide{grid-column:auto}.sj-table-tools{align-items:stretch;flex-direction:column}.sj-search{width:100%}.sj-ext-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.sj-ext-controls{align-items:stretch;flex-direction:column}.sj-ext-controls .sj-search{width:100%}}
		@media(max-width:480px){.sj-side,.sj-nav-button{width:52px;flex-basis:52px}.sj-side.expanded{width:min(210px,calc(100vw - 52px));flex-basis:52px}.sj-side.expanded .sj-nav-button{width:min(210px,calc(100vw - 52px))}.sj-header{min-width:0;padding:0 8px}.sj-header-left{gap:9px}.sj-crumb{font-size:10px}.sj-account-button{gap:4px;padding:6px 4px;font-size:10px}.sj-account-button span{display:none}.sj-toolbar{align-items:flex-start;flex-direction:column}.sj-missed{grid-template-columns:minmax(0,1fr) auto;gap:5px 8px}.sj-missed strong{grid-column:1;grid-row:1}.sj-missed span:nth-child(2){grid-column:1;grid-row:2}.sj-missed time{grid-column:2;grid-row:1}.sj-missed .sj-badge{grid-column:2;grid-row:2}.sj-flow-row{grid-template-columns:1fr;gap:4px}.sj-flow-arrow{transform:rotate(90deg)}}
	</style>
	<style>
		.sj-menu-toggle{display:grid;width:34px;height:34px;place-items:center;border:0;border-radius:3px;background:transparent;color:#626a70;cursor:pointer}.sj-menu-toggle:hover,.sj-menu-toggle[aria-expanded="true"]{background:#eef0f2;color:var(--sj-purple)}.sj-menu-toggle:focus-visible{outline:2px solid var(--sj-purple);outline-offset:1px}
		.sj-header{font-size:14px}.sj-crumb{font-size:13px}.sj-account-button{font-size:14px}.sj-account-menu a{font-size:14px}
		.sj-page{font-size:14px;transition:opacity .14s ease}.sj-page.is-loading{opacity:.55;pointer-events:none}.sj-title{font-size:21px}.sj-muted{font-size:13px}.sj-select,.sj-input,.sj-button{min-height:36px;padding:7px 10px;font-size:13px}.sj-button{padding:7px 11px}
		.sj-card{padding:14px}.sj-card-head{font-size:14px}.sj-stat strong{font-size:29px}.sj-stat span{font-size:12px}.sj-ext-note{font-size:12px}.sj-live-call{padding:10px 0;font-size:13px}.sj-live-call small{font-size:11px}
		.sj-missed{gap:9px;padding:10px 0;font-size:13px}.sj-missed time{font-size:11px}.sj-badge{min-width:62px;padding:4px 7px;font-size:11px}.sj-link{font-size:12px}
		.sj-chart-card,.sj-flow-card{padding:14px}.sj-chart{height:112px}.sj-chart-labels{font-size:11px}.sj-chart-y{font-size:13px}.sj-flow-head{font-size:14px}.sj-flow-row{padding:10px}.sj-flow-node small{font-size:11px}.sj-flow-node strong{font-size:13px}.sj-note{font-size:11px}
		.sj-ext-stat{padding:13px}.sj-ext-stat span{font-size:12px}.sj-ext-stat strong{font-size:23px}.sj-ext-filter{padding:8px 11px;font-size:12px}.sj-ext-card{padding:13px}.sj-ext-number{font-size:17px}.sj-ext-state{font-size:11px}.sj-ext-person{font-size:13px}.sj-ext-detail{font-size:11px}
		.sj-search input{height:36px;padding:7px 10px 7px 29px;font-size:13px}.sj-search i{top:12px}.sj-table th{padding:11px 12px;font-size:12px}.sj-table td{padding:12px;font-size:13px}.sj-table-footer{font-size:12px}.sj-empty{font-size:14px}
		@media(max-width:480px){.sj-crumb{font-size:12px}.sj-account-button{font-size:13px}.sj-flow-head{align-items:flex-start;flex-direction:column}}
		@media(prefers-reduced-motion:reduce){.sj-side,.sj-nav-button,.sj-nav-button i,.sj-nav-label,.sj-active-marker,.sj-page{transition:none!important}.sj-active-marker.land-down,.sj-active-marker.land-up{animation:none!important}}
	</style>
	<style>
	/* ---------------------------------------------------------------------
	   Refino visual, aprovado em preview em 08/10/2026.
	   Bloco separado de proposito: o CSS acima vem minificado numa linha so,
	   e editar la dentro e onde se quebra tela sem perceber. Aqui e adicao.
	   A paleta nao muda -- o problema era densidade e hierarquia, nao cor.
	   --------------------------------------------------------------------- */

	/* Arial era o padrao do tema do FusionPBX, nao uma escolha. A pilha do
	   sistema deixa a tela parecer nativa sem inventar personalidade. */
	body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
	     -webkit-font-smoothing:antialiased}

	/* Numero em coluna que dança a cada atualizacao parece instavel, e esta e
	   uma tela que fica aberta o dia inteiro. */
	.sj-stat strong,.sj-ext-stat strong,.sj-table td,.sj-chart-y,.sj-badge,
	.sj-faixa .v,.sj-dist b,.sj-med{font-variant-numeric:tabular-nums}

	/* A lateral fica RECOLHIDA por padrao -- preferencia do Isaac, 08/10/2026.
	   Eu a tinha deixado aberta achando que so icone nao diz o que e cada coisa;
	   o botao de menu continua expandindo, e cada item ja tem `title`. */

	/* --- faixa de numeros: quatro cartoes altos viram uma linha de leitura --- */
	.sj-faixa{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));
	          margin-bottom:12px;border:1px solid var(--sj-line);border-radius:3px;background:#fff}
	.sj-faixa .cel{min-width:0;padding:13px 16px;border-left:1px solid var(--sj-line)}
	.sj-faixa .cel:first-child{border-left:0}
	.sj-faixa .k{color:var(--sj-muted);font-size:11px;text-transform:uppercase;letter-spacing:.4px}
	.sj-faixa .v{margin-top:5px;font-size:25px;font-weight:600;line-height:1;letter-spacing:-.5px}
	.sj-faixa .c{margin-top:5px;color:var(--sj-muted);font-size:11.5px}
	.sj-faixa .v.ok{color:var(--sj-green)}
	.sj-faixa .v.idle{color:#9aa1a8}
	/* Estado desconhecido vira texto. Antes saiam dois travessoes coloridos, que
	   estavam certos no codigo e pareciam defeito na tela. */
	.sj-faixa .v.vazio{color:#9aa1a8;font-size:15px;font-weight:500;letter-spacing:0}
	@media(max-width:760px){
		.sj-faixa{grid-template-columns:repeat(2,minmax(0,1fr))}
		.sj-faixa .cel:nth-child(3){border-left:0}
		.sj-faixa .cel:nth-child(n+3){border-top:1px solid var(--sj-line)}
	}

	/* --- avaliacoes: uma nota focal, com a taxa como contexto dela --- */
	.sj-nota-topo{display:grid;grid-template-columns:240px 1fr;margin-bottom:12px;
	              border:1px solid var(--sj-line);border-radius:3px;background:#fff}
	.sj-nota{padding:16px 18px;border-right:1px solid var(--sj-line)}
	.sj-nota .k{color:var(--sj-muted);font-size:11px;text-transform:uppercase;letter-spacing:.4px}
	.sj-nota .v{display:flex;align-items:baseline;gap:5px;margin-top:6px}
	.sj-nota .v b{font-size:38px;font-weight:600;line-height:1;letter-spacing:-1px;
	              font-variant-numeric:tabular-nums}
	.sj-nota .v i{color:var(--sj-muted);font-size:14px;font-style:normal}
	.sj-nota .c{margin-top:7px;color:var(--sj-muted);font-size:11.5px;line-height:1.55}
	.sj-estrelas{margin-top:7px;color:#d8b43c;font-size:12px;letter-spacing:1px}
	.sj-estrelas span{color:#d9dde1}
	.sj-dist{padding:14px 18px}
	.sj-dist .k{margin-bottom:9px;color:var(--sj-muted);font-size:11px;
	            text-transform:uppercase;letter-spacing:.4px}
	.sj-dist .lin{display:grid;grid-template-columns:52px 1fr 42px;align-items:center;
	              gap:10px;margin-bottom:5px}
	.sj-dist .lin:last-child{margin-bottom:0}
	.sj-dist em{color:#5c646b;font-size:11.5px;font-style:normal}
	.sj-dist .bar{height:7px;border-radius:2px;background:#eef0f2;overflow:hidden}
	.sj-dist .bar span{display:block;height:100%;border-radius:2px}
	.sj-dist b{text-align:right;color:#4b5258;font-size:11.5px;font-weight:600}
	.sj-dist .lin.zero em,.sj-dist .lin.zero b{color:#aab0b6}
	@media(max-width:760px){
		.sj-nota-topo{grid-template-columns:1fr}
		.sj-nota{border-right:0;border-bottom:1px solid var(--sj-line)}
	}

	/* A nota e numero, nao etiqueta: nada de pilula larga para dizer "5,00". */
	.sj-med{font-weight:600}
	.sj-med.ok{color:var(--sj-green)}
	.sj-med.mid{color:#8a5a12}
	.sj-med.bad{color:var(--sj-red)}
	.sj-table td.r,.sj-table th.r{text-align:right}
	.sj-chip{display:inline-block;min-width:22px;padding:1px 6px;border-radius:2px;
	         text-align:center;font-size:11.5px;font-weight:600;font-variant-numeric:tabular-nums}
	.sj-chip.ok{background:#e9f2ec;color:var(--sj-green)}
	.sj-chip.mid{background:#f6efdc;color:#8a5a12}
	.sj-chip.bad{background:#f7e9e8;color:var(--sj-red)}

	/* Estado vazio em uma linha, nao num cartao de 90px de altura. */
	.sj-vazio{padding:13px 16px;color:var(--sj-muted);font-size:12.5px}
	</style>
</head>
<body>
<div id="sj-portal">
	<aside class="sj-side" id="sjSide" aria-label="Navegação principal">
		<div class="sj-brand"><img src="<?= $e($logo_url) ?>" alt="SimplificaJá"></div>
		<span class="sj-active-marker" id="sjActiveMarker" aria-hidden="true"></span>
		<a class="sj-nav-button <?= $view === 'dashboard' ? 'active' : '' ?>" data-view="dashboard" href="?view=dashboard" title="Visão geral" <?= $view === 'dashboard' ? 'data-secao-ativa="true" aria-current="page"' : '' ?>><i class="fas fa-house"></i><span class="sj-nav-label">Visão geral</span></a>
		<a class="sj-nav-button <?= $view === 'calls' ? 'active' : '' ?>" data-view="calls" href="?view=calls" title="Ligações" <?= $view === 'calls' ? 'data-secao-ativa="true" aria-current="page"' : '' ?>><i class="fas fa-phone-volume"></i><span class="sj-nav-label">Ligações</span></a>
		<a class="sj-nav-button <?= $view === 'extensions' ? 'active' : '' ?>" data-view="extensions" href="?view=extensions" title="Ramais" <?= $view === 'extensions' ? 'data-secao-ativa="true" aria-current="page"' : '' ?>><i class="fas fa-headset"></i><span class="sj-nav-label">Ramais</span></a>
		<a class="sj-nav-button <?= $view === 'ratings' ? 'active' : '' ?>" data-view="ratings" href="?view=ratings" title="Avaliações" <?= $view === 'ratings' ? 'data-secao-ativa="true" aria-current="page"' : '' ?>><i class="fas fa-star"></i><span class="sj-nav-label">Avaliações</span></a>
	</aside>
	<main class="sj-main">
		<header class="sj-header">
		<div class="sj-header-left"><button class="sj-menu-toggle" id="sjMenuButton" type="button" aria-label="Expandir menu" aria-expanded="false" aria-controls="sjSide"><i class="fas fa-bars" aria-hidden="true"></i></button><span class="sj-crumb"><span>PABX</span> &nbsp;›&nbsp; <strong><?= $e($current_title) ?></strong></span></div>
			<div class="sj-account">
				<button class="sj-account-button" id="sjAccountButton" type="button" aria-expanded="false"><i class="fas fa-circle-user"></i><span><?= $e($username) ?></span><i class="fas fa-chevron-down" style="font-size:9px"></i></button>
				<div class="sj-account-menu" id="sjAccountMenu" hidden><a href="/core/users/user_profile.php"><i class="fas fa-user"></i> Meu perfil</a><div class="sj-separator"></div><a href="/logout.php"><i class="fas fa-right-from-bracket"></i> Sair</a></div>
			</div>
		</header>
		<div class="sj-workspace">
			<div class="sj-page" id="sjPage">
			<?php if ($view === 'dashboard'): ?>
				<div class="sj-action"><span class="sj-title">Visão geral</span></div>
				<div class="sj-toolbar"><span class="sj-muted">Resumo da operação telefônica · últimas 24 horas</span><span class="sj-muted"><?= number_format($total_calls, 0, ',', '.') ?> ligações no período</span></div>
				<div class="sj-faixa">
					<div class="cel">
						<div class="k">Ligações recebidas</div>
						<div class="v"><?= number_format($total_calls, 0, ',', '.') ?></div>
						<div class="c">nas últimas 24 horas</div>
					</div>
					<div class="cel">
						<div class="k">Perdidas</div>
						<div class="v <?= $missed_count > 0 ? '' : 'ok' ?>"><?= $missed_count ?></div>
						<div class="c"><?= $missed_count > 0 ? 'ficaram sem resposta' : 'ninguém ficou sem resposta' ?></div>
					</div>
					<div class="cel">
						<div class="k">Ramais</div>
						<?php if ($registered_count === null): ?>
							<?php /* So entra aqui quando o Event Socket nao respondeu. Zero
							      ramal conectado e uma resposta, e cai no `else`. */ ?>
							<div class="v vazio">não foi possível consultar</div>
							<div class="c"><?= $total_extensions ?> <?= $total_extensions == 1 ? 'ramal cadastrado' : 'ramais cadastrados' ?></div>
						<?php elseif ($registered_count === 0): ?>
							<div class="v idle">0<span style="color:#9aa1a8;font-size:15px;font-weight:500"> / <?= $total_extensions ?></span></div>
							<div class="c">nenhum telefone conectado agora</div>
						<?php else: ?>
							<div class="v <?= $registered_count > 0 ? 'ok' : 'idle' ?>"><?= $registered_count ?><span style="color:#9aa1a8;font-size:15px;font-weight:500"> / <?= $total_extensions ?></span></div>
							<div class="c"><?= $registered_count == 1 ? 'ramal online' : 'ramais online' ?></div>
						<?php endif; ?>
					</div>
					<div class="cel">
						<div class="k">Em ligação agora</div>
						<div class="v <?= $busy_count > 0 ? '' : 'idle' ?>"><?= $busy_count ?></div>
						<div class="c"><?= $busy_count > 0 ? 'ramais ocupados' : 'nenhuma chamada ativa' ?></div>
					</div>
				</div>
				<section class="sj-chart-card" style="margin-top:0">
					<div class="sj-card-head"><span>Ligações perdidas</span><span class="sj-muted">últimas 24 horas</span></div>
					<?php if (empty($recent_missed_calls)): ?>
						<div class="sj-vazio">Nenhuma ligação perdida no período.
							<a class="sj-link" href="?view=calls&amp;status=missed&amp;days=1" style="margin-left:6px">Ver histórico</a></div>
					<?php else: ?>
						<div class="sj-missed-list" style="padding:0 16px">
						<?php foreach ($recent_missed_calls as $call): ?>
						<div class="sj-missed"><strong><?= $e($call['caller_id_number'] ?: $call['caller_id_name'] ?: 'Número indisponível') ?></strong><span>Ligou para <?= $e($call['caller_destination'] ?: $call['destination_number'] ?: '—') ?></span><time><?= $e($display_time($call['start_stamp'])) ?></time><span class="sj-badge missed">Perdida</span></div>
						<?php endforeach; ?>
						</div>
						<div style="display:flex;justify-content:flex-end;padding:9px 16px;border-top:1px solid var(--sj-line)"><a class="sj-link" href="?view=calls&amp;status=missed&amp;days=1">Ver histórico de ligações</a></div>
					<?php endif; ?>
				</section>
				<section class="sj-chart-card">
					<div class="sj-card-head"><span>Volume de chamadas</span><span class="sj-muted">pico de <?= $maximo_volume ?> <?= $maximo_volume == 1 ? 'ligação' : 'ligações' ?> em uma hora · últimas 24 horas</span></div>
					<?php /* Grade em 18/51/84 porque e onde ficam o pico, a metade e o zero
					    na escala do desenho (`y = 84 - (n/maximo)*66`). Antes estava
					    em 20/50/80, que nao correspondia a valor nenhum -- a linha
					    subia e nao dava para saber ate quanto. */ ?>
					<div class="sj-chart-wrap">
						<svg class="sj-chart" viewBox="0 0 700 104" preserveAspectRatio="none" role="img" aria-label="Volume de chamadas nas últimas 24 horas, pico de <?= $maximo_volume ?> por hora">
							<line class="sj-gridline" x1="0" y1="18" x2="700" y2="18"/><line class="sj-gridline" x1="0" y1="51" x2="700" y2="51"/><line class="sj-gridline" x1="0" y1="84" x2="700" y2="84"/>
							<path class="sj-area" d="<?= $e($volume_area) ?>"/><path class="sj-line" d="<?= $e($volume_path) ?>"/>
						</svg>
						<?php /* 18/104 = 17.3%, 51/104 = 49.0%, 84/104 = 80.8% */ ?>
						<span class="sj-chart-y" style="top:17.3%"><?= $maximo_volume ?></span>
						<span class="sj-chart-y" style="top:49.0%"><?= (int) round($maximo_volume / 2) ?></span>
						<span class="sj-chart-y" style="top:80.8%">0</span>
					</div>
					<div class="sj-chart-labels"><span>24h atrás</span><span>18h</span><span>12h</span><span>6h</span><span>Agora</span></div>
				</section>
				<section class="sj-chart-card">
					<div class="sj-card-head"><span>Para onde vão as ligações</span><span class="sj-muted"><?= count($call_routes) ?> <?= count($call_routes) == 1 ? 'número de entrada' : 'números de entrada' ?></span></div>
					<?php if (empty($call_routes)): ?>
						<div class="sj-vazio">Nenhum número de entrada habilitado nesta empresa.</div>
					<?php else: ?>
					<div class="sj-table-wrap" style="border:0;border-radius:0"><table class="sj-table" style="min-width:0">
						<thead><tr><th style="width:220px">Quem liga disca</th><th>Caminho da ligação</th></tr></thead>
						<tbody>
						<?php foreach ($call_routes as $route): ?>
						<tr>
							<td>
								<strong><?= $e((string) $route['numero_amigavel']) ?></strong>
								<?php if (!empty($route['entregue_por'])): ?>
									<span style="display:block;color:#858c91;font-size:11px">a operadora entrega por <?= $e((string) $route['entregue_por']) ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if (empty($route['caminho'])): ?>
									<span style="color:#8a5a12">este número ainda não leva a lugar nenhum</span>
								<?php else: ?>
									<?php foreach ($route['caminho'] as $i => $parada): ?>
										<?php if ($i > 0): ?><span style="color:#a9a2c9;padding:0 4px">→</span><?php endif; ?><?= $e((string) $parada) ?>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
						</tbody></table></div>
					<?php endif; ?>
				</section>

			<?php elseif ($view === 'extensions'): ?>
				<div class="sj-action"><span class="sj-title">Ramais</span></div>
				<div class="sj-toolbar"><span class="sj-muted">Mapa de disponibilidade dos ramais</span><span class="sj-muted">Atualizado ao abrir a tela</span></div>
				<div class="sj-ext-summary">
					<div class="sj-ext-stat"><span>Total de ramais visíveis</span><strong><?= $total_extensions ?></strong></div>
					<?php /* Travessao solto parece tela quebrada. Quando o servidor SIP nao
					         respondeu, a tela diz isso em palavra -- igual na Visao geral. */ ?>
					<div class="sj-ext-stat available"><span>Disponíveis</span><?= $available_count === null ? '<strong style="font-size:14px;color:#9aa1a8">não consultado</strong>' : '<strong>'.$available_count.'</strong>' ?></div>
					<div class="sj-ext-stat busy"><span>Em ligação</span><strong><?= $busy_count ?></strong></div>
					<div class="sj-ext-stat offline"><span>Offline</span><?= $offline_count === null ? '<strong style="font-size:14px;color:#9aa1a8">não consultado</strong>' : '<strong>'.$offline_count.'</strong>' ?></div>
				</div>
				<div class="sj-ext-controls">
					<div class="sj-ext-filters" role="group" aria-label="Filtrar ramais">
						<button class="sj-ext-filter active" type="button" data-extension-filter="all">Todos (<?= $total_extensions ?>)</button>
						<button class="sj-ext-filter" type="button" data-extension-filter="available">Disponíveis<?= $available_count === null ? '' : ' ('.$available_count.')' ?></button>
						<button class="sj-ext-filter" type="button" data-extension-filter="busy">Em ligação (<?= $busy_count ?>)</button>
						<button class="sj-ext-filter" type="button" data-extension-filter="offline">Offline<?= $offline_count === null ? '' : ' ('.$offline_count.')' ?></button>
					</div>
					<label class="sj-search"><i class="fas fa-magnifying-glass"></i><input id="extensionSearch" type="search" placeholder="Buscar ramal ou pessoa" aria-label="Buscar ramal ou pessoa"></label>
				</div>
				<div class="sj-ext-board" id="extensionBoard">
					<?php foreach ($extensions as $extension):
						$number = (string) $extension['extension'];
						$person = trim(($extension['directory_first_name'] ?? '').' '.($extension['directory_last_name'] ?? ''));
						if ($person === '') { $person = (string) ($extension['description'] ?: 'Ramal '.$number); }
						$is_busy = isset($active_calls[$number]);
						$is_registered = is_array($registered_extensions) && isset($registered_extensions[$number]);
						$state = $is_busy ? 'busy' : ($registered_extensions === null ? 'unknown' : ($is_registered ? 'available' : 'offline'));
						$state_label = $is_busy ? 'Em ligação' : ($registered_extensions === null ? 'Indisponível' : ($is_registered ? 'Disponível' : 'Offline'));
						$detail = $is_busy ? 'Ligação em andamento' : ($registered_extensions === null ? 'Estado SIP não consultado' : ($is_registered ? 'Registrado no sistema' : 'Aparelho desconectado'));
						$search_terms = strtolower($number.' '.$person);
					?>
					<article class="sj-ext-card <?= $state ?>" data-state="<?= $state ?>" data-search="<?= $e($search_terms) ?>"><div class="sj-ext-top"><strong class="sj-ext-number"><?= $e($number) ?></strong><span class="sj-ext-state"><?= $state_label ?></span></div><div class="sj-ext-person"><?= $e($person) ?></div><div class="sj-ext-detail"><?= $e($detail) ?></div></article>
					<?php endforeach; ?>
				</div>
				<div class="sj-empty" id="extensionEmpty" hidden>Nenhum ramal corresponde ao filtro.</div>
				<?php if ($registered_extensions === null && !empty($extensions)): ?><div class="sj-note">O estado de registro não foi retornado pelo servidor SIP; os estados aparecerão quando a consulta SIP estiver disponível.</div><?php endif; ?>

			<?php elseif ($view === 'ratings'): ?>
				<div class="sj-action"><span class="sj-title">Avaliações</span></div>
				<div class="sj-toolbar"><span class="sj-muted">Nota que quem ligou digitou no fim do atendimento · últimos 30 dias</span><span class="sj-muted"><?= (int) $rating_summary['respostas'] ?> <?= (int) $rating_summary['respostas'] == 1 ? 'resposta' : 'respostas' ?></span></div>
				<?php
					// A nota e o assunto da tela; a taxa de resposta e contexto dela.
					// Antes as duas tinham o mesmo peso e competiam.
					$media = $rating_summary['media'] === null ? null : (float) $rating_summary['media'];
					$classe_media = $media === null ? '' : ($media >= 4 ? 'ok' : ($media < 3 ? 'bad' : 'mid'));
					$cheias = $media === null ? 0 : (int) round($media);
					$por_nota = [];
					foreach ($rating_distribution as $faixa) { $por_nota[(int) $faixa['nota']] = (int) $faixa['total']; }
					$maior_nota = $por_nota ? max($por_nota) : 0;
				?>
				<div class="sj-nota-topo">
					<div class="sj-nota">
						<div class="k">Nota média</div>
						<div class="v"><b class="sj-med <?= $classe_media ?>"><?= $media === null ? '—' : $e(number_format($media, 2, ',', '')) ?></b><i>de 5</i></div>
						<div class="sj-estrelas"><?= str_repeat('★', $cheias) ?><span><?= str_repeat('★', 5 - $cheias) ?></span></div>
						<div class="c">
							<b style="color:var(--sj-ink)"><?= (int) $rating_summary['respostas'] ?></b>
							<?= $answered_calls > 0 ? 'de '.(int) $answered_calls.' atendimentos avaliados' : 'avaliações no período' ?><br>
							<span style="color:#8a9097">quem desligou sem digitar não entra na média</span>
						</div>
					</div>
					<div class="sj-dist">
						<div class="k">Distribuição das notas</div>
						<?php foreach ([5, 4, 3, 2, 1] as $n): $qt = $por_nota[$n] ?? 0; ?>
						<div class="lin <?= $qt === 0 ? 'zero' : '' ?>">
							<em>★ <?= $n ?></em>
							<div class="bar"><span style="width:<?= $maior_nota > 0 ? round(($qt / $maior_nota) * 100) : 0 ?>%;background:<?= $n >= 4 ? 'var(--sj-green)' : ($n == 3 ? '#c9a227' : ($n == 2 ? '#c47a3c' : 'var(--sj-red)')) ?>"></span></div>
							<b><?= $qt ?></b>
						</div>
						<?php endforeach; ?>
					</div>
				</div>

				<section class="sj-chart-card" style="margin-top:0">
					<div class="sj-card-head"><span>Por pergunta</span><span class="sj-muted">média de cada pergunta da pesquisa</span></div>
					<?php if (empty($rating_by_question)): ?><div class="sj-vazio">Nenhuma avaliação no período.</div><?php else: ?>
					<div class="sj-table-wrap" style="border:0;border-radius:0"><table class="sj-table" style="min-width:0">
						<thead><tr><th>Pergunta</th><th class="r" style="width:120px">Respostas</th><th class="r" style="width:100px">Média</th></tr></thead>
						<tbody>
						<?php foreach ($rating_by_question as $pergunta): $m = (float) $pergunta['media']; ?>
						<tr><td><?= $e((string) $pergunta['pergunta']) ?></td><td class="r"><?= (int) $pergunta['respostas'] ?></td>
							<td class="r sj-med <?= $m >= 4 ? 'ok' : ($m < 3 ? 'bad' : 'mid') ?>"><?= $e(number_format($m, 2, ',', '')) ?></td></tr>
						<?php endforeach; ?>
						</tbody></table></div>
					<?php endif; ?>
				</section>

				<section class="sj-chart-card">
					<div class="sj-card-head"><span>Por atendente</span><span class="sj-muted">menor nota primeiro</span></div>
					<?php if (empty($rating_by_extension)): ?><div class="sj-vazio">Nenhuma avaliação no período.</div><?php else: ?>
					<div class="sj-table-wrap" style="border:0;border-radius:0"><table class="sj-table" style="min-width:0">
						<thead><tr><th>Ramal</th><th class="r" style="width:120px">Respostas</th><th class="r" style="width:100px">Média</th></tr></thead>
						<tbody>
						<?php foreach ($rating_by_extension as $linha): $m = (float) $linha['media']; ?>
						<tr><td><strong><?= $e((string) $linha['ramal']) ?></strong></td><td class="r"><?= (int) $linha['respostas'] ?></td>
							<td class="r sj-med <?= $m >= 4 ? 'ok' : ($m < 3 ? 'bad' : 'mid') ?>"><?= $e(number_format($m, 2, ',', '')) ?></td></tr>
						<?php endforeach; ?>
						</tbody></table></div>
					<?php endif; ?>
				</section>
				<form class="sj-table-tools" method="get">
					<input type="hidden" name="view" value="ratings">
					<label class="sj-search"><i class="fas fa-magnifying-glass"></i><input type="search" name="search" value="<?= $e($history_search) ?>" placeholder="Buscar número ou ramal" aria-label="Buscar número ou ramal"></label>
				</form>
				<div class="sj-table-wrap"><table class="sj-table"><thead><tr><th>Data e hora</th><th>Número</th><th>Pergunta</th><th>Ramal</th><th>Fila</th><th>Nota</th></tr></thead><tbody>
				<?php foreach ($rating_history as $avaliacao): $nota = (int) $avaliacao['nota']; ?>
				<tr><td><?= $e($display_time($avaliacao['criado_em'])) ?></td><td><strong><?= $e((string) ($avaliacao['telefone'] ?: 'Número indisponível')) ?></strong></td><td><?= $e((string) ($avaliacao['pergunta'] ?: '—')) ?></td><td><?= $e((string) ($avaliacao['ramal'] ?: '—')) ?></td><td><?= $e((string) ($avaliacao['fila'] ?: '—')) ?></td><td><span class="sj-badge <?= $nota >= 4 ? 'answered' : ($nota < 3 ? 'missed' : '') ?>"><?= $nota ?></span></td></tr>
				<?php endforeach; if (empty($rating_history)): ?><tr><td colspan="6" style="text-align:center;color:#737a80">Nenhuma avaliação ainda. A pesquisa só toca quando o atendente encerra a ligação.</td></tr><?php endif; ?>
				</tbody></table></div>
				<div class="sj-table-footer"><span><?= count($rating_history) ?> avaliação(ões) · últimos 30 dias</span><span>Exibindo até 200 registros</span></div>
			<?php else: ?>
				<div class="sj-action"><span class="sj-title">Ligações</span></div>
				<div class="sj-toolbar"><span class="sj-muted">Consulte chamadas recebidas, realizadas e perdidas.</span><form method="get"><input type="hidden" name="view" value="calls"><select class="sj-select" name="days" onchange="this.form.submit()"><option value="1" <?= $history_days === 1 ? 'selected' : '' ?>>Últimas 24 horas</option><option value="7" <?= $history_days === 7 ? 'selected' : '' ?>>Últimos 7 dias</option><option value="30" <?= $history_days === 30 ? 'selected' : '' ?>>Últimos 30 dias</option></select><input type="hidden" name="status" value="<?= $e($history_status) ?>"><input type="hidden" name="direction" value="<?= $e($history_direction) ?>"><input type="hidden" name="search" value="<?= $e($history_search) ?>"></form></div>
				<form class="sj-table-tools" method="get">
					<input type="hidden" name="view" value="calls"><input type="hidden" name="days" value="<?= $history_days ?>">
					<div class="sj-filters"><select class="sj-select" name="status"><option value="">Todas as situações</option><option value="missed" <?= $history_status === 'missed' ? 'selected' : '' ?>>Perdidas</option><option value="answered" <?= $history_status === 'answered' ? 'selected' : '' ?>>Atendidas</option></select><select class="sj-select" name="direction"><option value="">Todas as ligações</option><option value="inbound" <?= $history_direction === 'inbound' ? 'selected' : '' ?>>Recebidas</option><option value="outbound" <?= $history_direction === 'outbound' ? 'selected' : '' ?>>Realizadas</option></select><button class="sj-button" type="submit"><i class="fas fa-filter"></i>Filtrar</button></div>
					<label class="sj-search"><i class="fas fa-magnifying-glass"></i><input type="search" name="search" value="<?= $e($history_search) ?>" placeholder="Buscar número ou ramal" aria-label="Buscar número ou ramal"></label>
				</form>
				<div class="sj-table-wrap"><table class="sj-table"><thead><tr><th>Data e hora</th><th>Número</th><th>Direção</th><th>Ramal</th><th>Duração</th><th>Situação</th></tr></thead><tbody>
				<?php foreach ($history as $call): $missed = $is_true($call['missed_call'] ?? false); $direction_label = $call['direction'] === 'inbound' ? 'Recebida' : ($call['direction'] === 'outbound' ? 'Realizada' : 'Interna'); ?>
				<tr><td><?= $e($display_time($call['start_stamp'])) ?></td><td><strong><?= $e($call['caller_id_number'] ?: $call['caller_id_name'] ?: 'Número indisponível') ?></strong></td><td><?= $direction_label ?><?= !empty($call['caller_destination']) ? ' · '.$e($call['caller_destination']) : '' ?></td><td><?= $e($call['extension'] ?? '—') ?></td><td><?= $missed ? '—' : ((int) $call['duration'] >= 3600 ? floor((int) $call['duration'] / 3600).':'.gmdate('i:s', (int) $call['duration'] % 3600) : gmdate('i:s', (int) $call['duration'])) ?></td><td><span class="sj-badge <?= $missed ? 'missed' : 'answered' ?>"><?= $missed ? 'Perdida' : 'Atendida' ?></span></td></tr>
				<?php endforeach; if (empty($history)): ?><tr><td colspan="6" style="text-align:center;color:#737a80">Nenhuma ligação encontrada para estes filtros.</td></tr><?php endif; ?>
				</tbody></table></div>
				<div class="sj-table-footer"><span><?= count($history) ?> ligações · período de <?= $history_days ?> dia(s)</span><span>Exibindo até 200 registros</span></div>
			<?php endif; ?>
			</div>
		</div>
	</main>
</div>
<script>
(() => {
	const menuButton = document.getElementById('sjMenuButton');
	const sideMenu = document.getElementById('sjSide');
	const activeMarker = document.getElementById('sjActiveMarker');
	const accountButton = document.getElementById('sjAccountButton');
	const accountMenu = document.getElementById('sjAccountMenu');
	const page = document.getElementById('sjPage');
	const crumbTitle = document.querySelector('.sj-crumb strong');
	const viewLinks = [...sideMenu.querySelectorAll('.sj-nav-button[data-view]')];
	let previousView = '';
	let navigationController = null;

	try {
		const expanded = localStorage.getItem('sjSideMenuExpanded') === 'true';
		previousView = localStorage.getItem('sjActivePortalView') || '';
		sideMenu.classList.toggle('expanded', expanded);
		menuButton.setAttribute('aria-expanded', String(expanded));
		menuButton.setAttribute('aria-label', expanded ? 'Recolher menu' : 'Expandir menu');
	} catch (error) {
		// Navegadores com armazenamento local bloqueado continuam usando o menu normalmente.
	}

	const markerCenter = link => link.getBoundingClientRect().top - sideMenu.getBoundingClientRect().top + link.offsetHeight / 2;
	const moveMarker = (link, start = Number.parseFloat(activeMarker.style.top)) => {
		if (!link) return;
		const destination = markerCenter(link);
		if (!Number.isFinite(start) || Math.abs(destination - start) < 1) {
			activeMarker.style.transitionDuration = '0ms';
			activeMarker.style.top = `${destination}px`;
			activeMarker.style.opacity = '1';
			return;
		}
		const distance = Math.abs(destination - start);
		const duration = Math.min(460, Math.max(200, distance * 1.6));
		activeMarker.style.transitionDuration = '0ms';
		activeMarker.style.top = `${start}px`;
		activeMarker.style.opacity = '1';
		activeMarker.classList.remove('land-down', 'land-up');
		activeMarker.offsetHeight;
		requestAnimationFrame(() => requestAnimationFrame(() => {
			activeMarker.style.transitionDuration = `${duration}ms`;
			activeMarker.style.top = `${destination}px`;
			activeMarker.style.animationDelay = `${Math.max(0, duration - 60)}ms`;
			activeMarker.classList.add(destination > start ? 'land-down' : 'land-up');
			window.setTimeout(() => activeMarker.classList.remove('land-down', 'land-up'), duration + 220);
		}));
	};

	const initialActive = sideMenu.querySelector('[data-secao-ativa]');
	const previousLink = viewLinks.find(link => link.dataset.view === previousView);
	moveMarker(initialActive, previousLink && previousView !== initialActive?.dataset.view ? markerCenter(previousLink) : NaN);
	try {
		if (initialActive) localStorage.setItem('sjActivePortalView', initialActive.dataset.view);
	} catch (error) {
		// O marcador continua indicando a tela ativa sem armazenamento local.
	}

	const updateActiveNavigation = activeView => {
		let activeLink = null;
		viewLinks.forEach(link => {
			const active = link.dataset.view === activeView;
			link.classList.toggle('active', active);
			if (active) {
				link.setAttribute('data-secao-ativa', 'true');
				link.setAttribute('aria-current', 'page');
				activeLink = link;
			} else {
				link.removeAttribute('data-secao-ativa');
				link.removeAttribute('aria-current');
			}
		});
		moveMarker(activeLink);
		try {
			if (activeLink) localStorage.setItem('sjActivePortalView', activeView);
		} catch (error) {
			// A navegação continua mesmo sem armazenamento local.
		}
	};

	const initializeExtensionFilters = () => {
		let selectedState = 'all';
		const search = page.querySelector('#extensionSearch');
		if (!search) return;
		const cards = [...page.querySelectorAll('.sj-ext-card')];
		const filters = [...page.querySelectorAll('[data-extension-filter]')];
		const empty = page.querySelector('#extensionEmpty');
		const update = () => {
			const query = search.value.toLocaleLowerCase('pt-BR').trim();
			let visible = 0;
			cards.forEach(card => {
				const show = (selectedState === 'all' || card.dataset.state === selectedState) && (!query || card.dataset.search.includes(query));
				card.hidden = !show;
				if (show) visible++;
			});
			empty.hidden = visible > 0;
		};
		filters.forEach(button => button.addEventListener('click', () => {
			selectedState = button.dataset.extensionFilter;
			filters.forEach(filter => filter.classList.toggle('active', filter === button));
			update();
		}));
		search.addEventListener('input', update);
	};

	const navigate = async (url, { push = true } = {}) => {
		const target = new URL(url, window.location.href);
		if (target.origin !== window.location.origin) {
			window.location.assign(target.href);
			return;
		}
		navigationController?.abort();
		const controller = new AbortController();
		navigationController = controller;
		page.classList.add('is-loading');
		page.setAttribute('aria-busy', 'true');
		try {
			const response = await fetch(target.href, {
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'XMLHttpRequest' },
				signal: controller.signal,
			});
			const markup = await response.text();
			const nextDocument = new DOMParser().parseFromString(markup, 'text/html');
			const nextPage = nextDocument.querySelector('#sjPage');
			const nextActive = nextDocument.querySelector('[data-secao-ativa]');
			if (!response.ok || !nextPage || !nextActive) {
				window.location.assign(target.href);
				return;
			}

			page.innerHTML = nextPage.innerHTML;
			page.scrollTop = 0;
			document.title = nextDocument.title;
			const nextTitle = nextDocument.querySelector('.sj-crumb strong');
			if (nextTitle) crumbTitle.textContent = nextTitle.textContent;
			updateActiveNavigation(nextActive.dataset.view);
			initializeExtensionFilters();
			if (push && target.href !== window.location.href) history.pushState({ sjPortal: true }, '', target.href);
		} catch (error) {
			if (error.name !== 'AbortError') window.location.assign(target.href);
		} finally {
			if (navigationController === controller) {
				page.classList.remove('is-loading');
				page.removeAttribute('aria-busy');
				navigationController = null;
			}
		}
	};

	menuButton.addEventListener('click', () => {
		const expanded = sideMenu.classList.toggle('expanded');
		menuButton.setAttribute('aria-expanded', String(expanded));
		menuButton.setAttribute('aria-label', expanded ? 'Recolher menu' : 'Expandir menu');
		try {
			localStorage.setItem('sjSideMenuExpanded', String(expanded));
		} catch (error) {
			// O estado continua valendo até a navegação atual.
		}
	});
	accountButton.addEventListener('click', () => {
		accountMenu.hidden = !accountMenu.hidden;
		accountButton.setAttribute('aria-expanded', String(!accountMenu.hidden));
	});
	document.addEventListener('click', event => {
		const link = event.target.closest('a[href]');
		if (link && event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey && !link.hasAttribute('download')) {
			const target = new URL(link.href, window.location.href);
			if (target.origin === window.location.origin && target.pathname === window.location.pathname && target.searchParams.has('view')) {
				event.preventDefault();
				navigate(target.href);
			}
		}
		if (!event.target.closest('.sj-account')) {
			accountMenu.hidden = true;
			accountButton.setAttribute('aria-expanded', 'false');
		}
	});
	document.addEventListener('submit', event => {
		const form = event.target;
		if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'get' || !form.querySelector('input[name="view"]')) return;
		event.preventDefault();
		const target = new URL(window.location.href);
		target.search = new URLSearchParams(new FormData(form)).toString();
		navigate(target.href);
	});
	window.addEventListener('popstate', () => navigate(window.location.href, { push: false }));
	initializeExtensionFilters();
})();
</script>
</body>
</html>
