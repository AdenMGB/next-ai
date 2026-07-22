<?php

declare(strict_types=1);

namespace OCA\WhiteBoardAI\Service;

use OCA\WhiteBoardAI\AppInfo\Application;
use OCP\IAppConfig;

class SettingsService {
	public const KEY_API_KEY = 'openrouter_api_key';
	public const KEY_MODEL = 'openrouter_model';
	public const KEY_MAX_ELEMENTS = 'max_elements';
	public const KEY_RATE_LIMIT = 'rate_limit_per_minute';

	public const DEFAULT_MODEL = 'openai/gpt-4o-mini';
	public const DEFAULT_MAX_ELEMENTS = 80;
	public const DEFAULT_RATE_LIMIT = 20;

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function getApiKey(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_API_KEY, '');
	}

	public function setApiKey(string $apiKey): void {
		$apiKey = trim($apiKey);
		if ($apiKey === '') {
			$this->appConfig->deleteKey(Application::APP_ID, self::KEY_API_KEY);
			return;
		}

		$this->appConfig->setValueString(
			Application::APP_ID,
			self::KEY_API_KEY,
			$apiKey,
			false,
			true,
		);
	}

	public function hasApiKey(): bool {
		return $this->getApiKey() !== '';
	}

	public function getModel(): string {
		$model = trim($this->appConfig->getValueString(
			Application::APP_ID,
			self::KEY_MODEL,
			self::DEFAULT_MODEL,
		));

		return $model !== '' ? $model : self::DEFAULT_MODEL;
	}

	public function setModel(string $model): void {
		$model = trim($model);
		if ($model === '') {
			$model = self::DEFAULT_MODEL;
		}
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_MODEL, $model);
	}

	public function getMaxElements(): int {
		$value = $this->appConfig->getValueInt(
			Application::APP_ID,
			self::KEY_MAX_ELEMENTS,
			self::DEFAULT_MAX_ELEMENTS,
		);

		return max(1, min(200, $value));
	}

	public function setMaxElements(int $maxElements): void {
		$this->appConfig->setValueInt(
			Application::APP_ID,
			self::KEY_MAX_ELEMENTS,
			max(1, min(200, $maxElements)),
		);
	}

	public function getRateLimitPerMinute(): int {
		$value = $this->appConfig->getValueInt(
			Application::APP_ID,
			self::KEY_RATE_LIMIT,
			self::DEFAULT_RATE_LIMIT,
		);

		return max(1, min(120, $value));
	}

	public function setRateLimitPerMinute(int $limit): void {
		$this->appConfig->setValueInt(
			Application::APP_ID,
			self::KEY_RATE_LIMIT,
			max(1, min(120, $limit)),
		);
	}

	/**
	 * Public status payload (never includes the API key).
	 *
	 * @return array{configured: bool, model: string, maxElements: int}
	 */
	public function getPublicStatus(): array {
		return [
			'configured' => $this->hasApiKey(),
			'model' => $this->getModel(),
			'maxElements' => $this->getMaxElements(),
		];
	}
}
