<?php

declare(strict_types=1);

namespace OCA\WhiteBoardAI\Tests\Service;

use OCA\WhiteBoardAI\AppInfo\Application;
use OCA\WhiteBoardAI\Service\SettingsService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

final class SettingsServiceTest extends TestCase {
	public function testPublicStatusNeverIncludesApiKey(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				self::assertSame(Application::APP_ID, $app);
				return match ($key) {
					SettingsService::KEY_API_KEY => 'sk-secret',
					SettingsService::KEY_MODEL => 'openai/gpt-4o-mini',
					default => $default,
				};
			}
		);
		$appConfig->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default = 0) {
				return match ($key) {
					SettingsService::KEY_MAX_ELEMENTS => 80,
					SettingsService::KEY_RATE_LIMIT => 20,
					default => $default,
				};
			}
		);

		$service = new SettingsService($appConfig);
		$status = $service->getPublicStatus();

		$this->assertTrue($status['configured']);
		$this->assertSame('openai/gpt-4o-mini', $status['model']);
		$this->assertArrayNotHasKey('apiKey', $status);
		$this->assertArrayNotHasKey('openrouter_api_key', $status);
	}

	public function testSetApiKeyDeletesWhenEmpty(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())
			->method('deleteKey')
			->with(Application::APP_ID, SettingsService::KEY_API_KEY);

		$service = new SettingsService($appConfig);
		$service->setApiKey('   ');
	}

	public function testSetApiKeyStoresSensitive(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())
			->method('setValueString')
			->with(
				Application::APP_ID,
				SettingsService::KEY_API_KEY,
				'sk-test',
				false,
				true,
			);

		$service = new SettingsService($appConfig);
		$service->setApiKey(' sk-test ');
	}
}
