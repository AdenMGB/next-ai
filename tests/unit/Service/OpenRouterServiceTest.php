<?php

declare(strict_types=1);

namespace OCA\WhiteBoardAI\Tests\Service;

use OCA\WhiteBoardAI\Service\ExcalidrawNormalizer;
use OCA\WhiteBoardAI\Service\OpenRouterService;
use OCA\WhiteBoardAI\Service\SettingsService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OpenRouterServiceTest extends TestCase {
	public function testGenerateRequiresApiKey(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getApiKey')->willReturn('');

		$service = new OpenRouterService(
			$this->createMock(IClientService::class),
			$settings,
			new ExcalidrawNormalizer(),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not configured');
		$service->generate('draw a box');
	}

	public function testGenerateParsesCompletion(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getApiKey')->willReturn('sk-test');
		$settings->method('getModel')->willReturn('openai/gpt-4o-mini');
		$settings->method('getMaxElements')->willReturn(80);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn(json_encode([
			'choices' => [[
				'message' => [
						'content' => json_encode([
							'mode' => 'append',
							'assistantMessage' => 'Here is a box.',
							'elements' => [
								['type' => 'rectangle', 'x' => 1, 'y' => 2, 'width' => 10, 'height' => 20],
							],
						]),
					],
				]],
			], JSON_THROW_ON_ERROR));

		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturn($response);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$service = new OpenRouterService($clientService, $settings, new ExcalidrawNormalizer());
		$result = $service->generate('draw a box', [], null, [
			['role' => 'user', 'content' => 'earlier'],
			['role' => 'assistant', 'content' => 'ok'],
		]);

		$this->assertSame('append', $result['mode']);
		$this->assertCount(1, $result['elements']);
		$this->assertSame('rectangle', $result['elements'][0]['type']);
		$this->assertSame('Here is a box.', $result['assistantMessage']);
	}

	public function testSanitizeHistoryKeepsUserAssistantAndCaps(): void {
		$service = new OpenRouterService(
			$this->createMock(IClientService::class),
			$this->createMock(SettingsService::class),
			new ExcalidrawNormalizer(),
		);

		$history = [];
		for ($i = 0; $i < 30; $i++) {
			$history[] = ['role' => 'user', 'content' => "u$i"];
			$history[] = ['role' => 'assistant', 'content' => "a$i"];
		}
		$history[] = ['role' => 'system', 'content' => 'nope'];
		$history[] = ['role' => 'user', 'content' => ''];

		$clean = $service->sanitizeHistory($history);
		$this->assertLessThanOrEqual(20, count($clean));
		foreach ($clean as $turn) {
			$this->assertContains($turn['role'], ['user', 'assistant']);
			$this->assertNotSame('', $turn['content']);
		}
	}
}
