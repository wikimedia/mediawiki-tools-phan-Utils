<?php

$cfg = require __DIR__ . '/../src/phan-config-for-plugins.php';
$cfg['suppress_issue_types'] = array_merge(
	$cfg['suppress_issue_types'] ?? [],
	[ 'PhanUnreferencedClass', 'PhanUnreferencedProtectedMethod' ]
);
return $cfg;
