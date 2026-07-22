<?php

declare(strict_types=1);

namespace OCA\WhiteBoardAI\Settings;

use OCA\WhiteBoardAI\AppInfo\Application;
use OCA\WhiteBoardAI\Service\SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private SettingsService $settingsService,
		private IInitialState $initialState,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('admin-settings', [
			'configured' => $this->settingsService->hasApiKey(),
			'model' => $this->settingsService->getModel(),
			'maxElements' => $this->settingsService->getMaxElements(),
			'rateLimitPerMinute' => $this->settingsService->getRateLimitPerMinute(),
		]);

		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-admin');

		return new TemplateResponse(
			Application::APP_ID,
			'admin',
			[],
			TemplateResponse::RENDER_AS_BLANK,
		);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}
