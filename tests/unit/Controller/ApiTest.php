<?php

declare(strict_types=1);

namespace OCA\WhiteBoardAI\Tests\Controller;

use OCA\WhiteBoardAI\AppInfo\Application;
use OCA\WhiteBoardAI\Controller\ApiController;
use OCA\WhiteBoardAI\Service\ExcalidrawNormalizer;
use OCA\WhiteBoardAI\Service\OpenRouterService;
use OCA\WhiteBoardAI\Service\RateLimitService;
use OCA\WhiteBoardAI\Service\SettingsService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApiTest extends TestCase {
	public function testIndexReturnsPublicStatus(): void {
		$request = $this->createMock(IRequest::class);
		$openRouter = $this->createMock(OpenRouterService::class);
		$settings = $this->createMock(SettingsService::class);
		$rateLimit = $this->createMock(RateLimitService::class);
		$session = $this->createMock(IUserSession::class);

		$settings->method('getPublicStatus')->willReturn([
			'configured' => true,
			'model' => 'openai/gpt-4o-mini',
			'maxElements' => 80,
		]);

		$controller = new ApiController(
			Application::APP_ID,
			$request,
			$openRouter,
			$settings,
			$rateLimit,
			new ExcalidrawNormalizer(),
			$session,
		);

		$data = $controller->index()->getData();
		$this->assertTrue($data['configured']);
		$this->assertSame('openai/gpt-4o-mini', $data['model']);
	}

	public function testGenerateRequiresPrompt(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key) => $key === 'prompt' ? '' : null
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('hasApiKey')->willReturn(true);

		$controller = new ApiController(
			Application::APP_ID,
			$request,
			$this->createMock(OpenRouterService::class),
			$settings,
			$this->createMock(RateLimitService::class),
			new ExcalidrawNormalizer(),
			$session,
		);

		$response = $controller->generate();
		$this->assertSame(400, $response->getStatus());
		$this->assertSame('Prompt is required', $response->getData()['error']);
	}

	public function testGenerateReturnsClipboardPayload(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key) {
				return match ($key) {
					'prompt' => 'draw a box',
					'modeHint' => 'append',
					'scene' => ['elements' => []],
					default => null,
				};
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('hasApiKey')->willReturn(true);

		$rateLimit = $this->createMock(RateLimitService::class);
		$rateLimit->expects($this->once())->method('assertAllowed')->with('alice');

		$openRouter = $this->createMock(OpenRouterService::class);
		$openRouter->method('generate')->willReturn([
			'mode' => 'append',
			'assistantMessage' => 'Drew a box. Paste with Ctrl+V.',
			'elements' => [
				[
					'id' => 'abc123',
					'type' => 'rectangle',
					'x' => 0,
					'y' => 0,
					'width' => 100,
					'height' => 50,
				],
			],
		]);

		$controller = new ApiController(
			Application::APP_ID,
			$request,
			$openRouter,
			$settings,
			$rateLimit,
			new ExcalidrawNormalizer(),
			$session,
		);

		$response = $controller->generate();
		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('append', $data['mode']);
		$this->assertSame('excalidraw/clipboard', $data['clipboard']['type']);
		$this->assertCount(1, $data['clipboard']['elements']);
		$this->assertSame('Drew a box. Paste with Ctrl+V.', $data['assistantMessage']);
	}

	public function testGenerateRateLimited(): void {
		$request = $this->createMock(IRequest::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('hasApiKey')->willReturn(true);

		$rateLimit = $this->createMock(RateLimitService::class);
		$rateLimit->method('assertAllowed')->willThrowException(
			new RuntimeException('Rate limit exceeded. Try again in a minute.')
		);

		$controller = new ApiController(
			Application::APP_ID,
			$request,
			$this->createMock(OpenRouterService::class),
			$settings,
			$rateLimit,
			new ExcalidrawNormalizer(),
			$session,
		);

		$response = $controller->generate();
		$this->assertSame(429, $response->getStatus());
	}

	public function testGenerateUnavailableWithoutKey(): void {
		$request = $this->createMock(IRequest::class);
		$user = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('hasApiKey')->willReturn(false);

		$controller = new ApiController(
			Application::APP_ID,
			$request,
			$this->createMock(OpenRouterService::class),
			$settings,
			$this->createMock(RateLimitService::class),
			new ExcalidrawNormalizer(),
			$session,
		);

		$response = $controller->generate();
		$this->assertSame(503, $response->getStatus());
	}
}
