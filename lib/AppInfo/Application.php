<?php

declare(strict_types=1);

namespace OCA\WhiteBoardAI\AppInfo;

use OCA\WhiteBoardAI\Listener\LoadViewerListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'whiteboardai';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		if (class_exists(\OCA\Viewer\Event\LoadViewer::class)) {
			$context->registerEventListener(
				\OCA\Viewer\Event\LoadViewer::class,
				LoadViewerListener::class,
			);
		}
	}

	public function boot(IBootContext $context): void {
	}
}
