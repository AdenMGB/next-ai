<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\WhiteBoardAI\AppInfo\Application::APP_ID, OCA\WhiteBoardAI\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\WhiteBoardAI\AppInfo\Application::APP_ID, OCA\WhiteBoardAI\AppInfo\Application::APP_ID . '-main');

?>

<div id="whiteboardai"></div>
