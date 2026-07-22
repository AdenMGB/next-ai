<?php

declare(strict_types=1);

namespace OCA\WhiteBoardAI\Controller;

use OCA\WhiteBoardAI\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * @psalm-suppress UnusedClass
 */
class SettingsController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private SettingsService $settingsService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Update admin OpenRouter settings
	 *
	 * @return DataResponse<Http::STATUS_OK, array{configured: bool, model: string, maxElements: int, rateLimitPerMinute: int}, array{}>
	 *
	 * 200: Settings saved
	 */
	#[AdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/settings')]
	public function update(): DataResponse {
		// Only replace the key when a non-empty value is sent.
		// Empty string means "keep current key".
		$apiKey = $this->request->getParam('apiKey');
		if (is_string($apiKey) && trim($apiKey) !== '') {
			$this->settingsService->setApiKey($apiKey);
		}

		$clearApiKey = $this->request->getParam('clearApiKey');
		if ($clearApiKey === true || $clearApiKey === 1 || $clearApiKey === '1' || $clearApiKey === 'true') {
			$this->settingsService->setApiKey('');
		}

		$model = $this->request->getParam('model');
		if (is_string($model) && trim($model) !== '') {
			$this->settingsService->setModel($model);
		}

		$maxElements = $this->request->getParam('maxElements');
		if (is_numeric($maxElements)) {
			$this->settingsService->setMaxElements((int)$maxElements);
		}

		$rateLimit = $this->request->getParam('rateLimitPerMinute');
		if (is_numeric($rateLimit)) {
			$this->settingsService->setRateLimitPerMinute((int)$rateLimit);
		}

		return new DataResponse([
			'configured' => $this->settingsService->hasApiKey(),
			'model' => $this->settingsService->getModel(),
			'maxElements' => $this->settingsService->getMaxElements(),
			'rateLimitPerMinute' => $this->settingsService->getRateLimitPerMinute(),
		]);
	}

	/**
	 * Public status for the AI panel (no secrets)
	 *
	 * @return DataResponse<Http::STATUS_OK, array{configured: bool, model: string, maxElements: int}, array{}>
	 *
	 * 200: Status returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/status')]
	public function status(): DataResponse {
		return new DataResponse($this->settingsService->getPublicStatus());
	}
}
