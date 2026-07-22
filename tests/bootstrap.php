<?php

declare(strict_types=1);

$nextcloudBootstrap = __DIR__ . '/../../../tests/bootstrap.php';
$vendorAutoload = __DIR__ . '/../vendor/autoload.php';

if (is_readable($vendorAutoload)) {
	require_once $vendorAutoload;
}

if (is_readable($nextcloudBootstrap)) {
	require_once $nextcloudBootstrap;
	\OC_App::loadApp(OCA\WhiteBoardAI\AppInfo\Application::APP_ID);
	if (class_exists(OC_Hook::class)) {
		OC_Hook::clear();
	}
}
