<?php

	$y = 0;
	$apps[$x]['menu'][$y]['title']['en-us'] = 'Overview';
	$apps[$x]['menu'][$y]['title']['pt-br'] = 'Visão geral';
	$apps[$x]['menu'][$y]['uuid'] = 'd24e33c8-0b88-4aaf-9c3c-5d996b641001';
	$apps[$x]['menu'][$y]['parent_uuid'] = 'b837c17d-e326-4c37-9205-417937a588a5';
	$apps[$x]['menu'][$y]['category'] = 'internal';
	$apps[$x]['menu'][$y]['icon'] = 'fa-house';
	$apps[$x]['menu'][$y]['path'] = '/app/simplificaja_portal/index.php?view=dashboard';
	$apps[$x]['menu'][$y]['order'] = '1';
	$apps[$x]['menu'][$y]['groups'][] = 'user';
	$y++;

	$apps[$x]['menu'][$y]['title']['en-us'] = 'Calls';
	$apps[$x]['menu'][$y]['title']['pt-br'] = 'Ligações';
	$apps[$x]['menu'][$y]['uuid'] = 'd24e33c8-0b88-4aaf-9c3c-5d996b641002';
	$apps[$x]['menu'][$y]['parent_uuid'] = '2fbe35e3-c82e-411f-b357-48e4c17d3add';
	$apps[$x]['menu'][$y]['category'] = 'internal';
	$apps[$x]['menu'][$y]['icon'] = 'fa-phone-volume';
	$apps[$x]['menu'][$y]['path'] = '/app/simplificaja_portal/index.php?view=calls';
	$apps[$x]['menu'][$y]['order'] = '1';
	$apps[$x]['menu'][$y]['groups'][] = 'user';
	$y++;

	$apps[$x]['menu'][$y]['title']['en-us'] = 'Extensions';
	$apps[$x]['menu'][$y]['title']['pt-br'] = 'Ramais';
	$apps[$x]['menu'][$y]['uuid'] = 'd24e33c8-0b88-4aaf-9c3c-5d996b641003';
	$apps[$x]['menu'][$y]['parent_uuid'] = '2fbe35e3-c82e-411f-b357-48e4c17d3add';
	$apps[$x]['menu'][$y]['category'] = 'internal';
	$apps[$x]['menu'][$y]['icon'] = 'fa-headset';
	$apps[$x]['menu'][$y]['path'] = '/app/simplificaja_portal/index.php?view=extensions';
	$apps[$x]['menu'][$y]['order'] = '2';
	$apps[$x]['menu'][$y]['groups'][] = 'user';

?>
