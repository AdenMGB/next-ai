<?php

declare(strict_types=1);

namespace OCA\WhiteBoardAI\Controller;

use OCA\WhiteBoardAI\Service\ExcalidrawNormalizer;
use OCA\WhiteBoardAI\Service\OpenRouterService;
use OCA\WhiteBoardAI\Service\RateLimitService;
use OCA\WhiteBoardAI\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private OpenRouterService $openRouterService,
		private SettingsService $settingsService,
		private RateLimitService $rateLimitService,
		private ExcalidrawNormalizer $normalizer,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Generate or edit Excalidraw elements via OpenRouter
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_TOO_MANY_REQUESTS|Http::STATUS_SERVICE_UNAVAILABLE|Http::STATUS_UNAUTHORIZED, array{mode?: string, clipboard?: array{type: string, elements: list<array<string, mixed>>}, assistantMessage?: string, error?: string, configured?: bool}, array{}>
	 *
	 * 200: Generation succeeded
	 * 400: Invalid request
	 * 429: Rate limited
	 * 503: OpenRouter not configured or upstream failure
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/generate')]
	public function generate(): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if (!$this->settingsService->hasApiKey()) {
			return new DataResponse(
				['error' => 'OpenRouter API key is not configured', 'configured' => false],
				Http::STATUS_SERVICE_UNAVAILABLE,
			);
		}

		try {
			$this->rateLimitService->assertAllowed($user->getUID());
		} catch (RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_TOO_MANY_REQUESTS);
		}

		$prompt = $this->request->getParam('prompt');
		if (!is_string($prompt) || trim($prompt) === '') {
			return new DataResponse(['error' => 'Prompt is required'], Http::STATUS_BAD_REQUEST);
		}

		$modeHint = $this->request->getParam('modeHint');
		$modeHint = is_string($modeHint) ? $modeHint : null;

		$historyParam = $this->request->getParam('history');
		$history = is_array($historyParam) ? $historyParam : [];

		$scene = $this->request->getParam('scene');
		$elements = [];
		if (is_array($scene) && isset($scene['elements']) && is_array($scene['elements'])) {
			$elements = $this->normalizer->compactScene($scene['elements']);
		} elseif (is_array($this->request->getParam('elements'))) {
			/** @var list<mixed> $rawElements */
			$rawElements = $this->request->getParam('elements');
			$elements = $this->normalizer->compactScene($rawElements);
		}

		try {
			$result = $this->openRouterService->generate(
				trim($prompt),
				$elements,
				$modeHint,
				$history,
			);
		} catch (RuntimeException $e) {
			$status = str_contains(strtolower($e->getMessage()), 'not configured')
				? Http::STATUS_SERVICE_UNAVAILABLE
				: Http::STATUS_BAD_REQUEST;
			return new DataResponse(['error' => $e->getMessage()], $status);
		}

		return new DataResponse([
			'mode' => $result['mode'],
			'assistantMessage' => $result['assistantMessage'],
			'clipboard' => [
				'type' => 'excalidraw/clipboard',
				'elements' => $result['elements'],
			],
		]);
	}

	/**
	 * Public status for clients
	 *
	 * @return DataResponse<Http::STATUS_OK, array{configured: bool, model: string, maxElements: int}, array{}>
	 *
	 * 200: Status returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api')]
	public function index(): DataResponse {
		return new DataResponse($this->settingsService->getPublicStatus());
	}
}
