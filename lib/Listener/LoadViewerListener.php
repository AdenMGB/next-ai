<?php

declare(strict_types=1);

namespace OCA\WhiteBoardAI\Listener;

use OCA\Viewer\Event\LoadViewer;
use OCA\WhiteBoardAI\AppInfo\Application;
use OCA\WhiteBoardAI\Service\SettingsService;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * @template-implements IEventListener<LoadViewer>
 */
class LoadViewerListener implements IEventListener {
	public function __construct(
		private IInitialState $initialState,
		private SettingsService $settingsService,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof LoadViewer)) {
			return;
		}

		$this->initialState->provideInitialState(
			'panel-status',
			$this->settingsService->getPublicStatus(),
		);

		Util::addScript(Application::APP_ID, Application::APP_ID . '-panel');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-panel');
	}
}
