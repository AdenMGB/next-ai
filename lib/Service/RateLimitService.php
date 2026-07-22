<?php

declare(strict_types=1);

namespace OCA\WhiteBoardAI\Service;

use OCA\WhiteBoardAI\AppInfo\Application;
use OCP\ICacheFactory;
use RuntimeException;

class RateLimitService {
	public function __construct(
		private ICacheFactory $cacheFactory,
		private SettingsService $settingsService,
	) {
	}

	public function assertAllowed(string $userId): void {
		$limit = $this->settingsService->getRateLimitPerMinute();
		$cache = $this->cacheFactory->createDistributed(Application::APP_ID . '_rate');
		$key = 'gen_' . $userId;
		$bucket = $cache->get($key);
		if (!is_array($bucket)) {
			$bucket = ['count' => 0, 'windowStart' => time()];
		}

		$now = time();
		if ($now - (int)$bucket['windowStart'] >= 60) {
			$bucket = ['count' => 0, 'windowStart' => $now];
		}

		if ((int)$bucket['count'] >= $limit) {
			throw new RuntimeException('Rate limit exceeded. Try again in a minute.');
		}

		$bucket['count'] = (int)$bucket['count'] + 1;
		$cache->set($key, $bucket, 120);
	}
}
