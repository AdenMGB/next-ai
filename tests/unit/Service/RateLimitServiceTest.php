<?php

declare(strict_types=1);

namespace OCA\WhiteBoardAI\Tests\Service;

use OCA\WhiteBoardAI\Service\RateLimitService;
use OCA\WhiteBoardAI\Service\SettingsService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RateLimitServiceTest extends TestCase {
	public function testAllowsUnderLimit(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$cache->expects($this->once())->method('set');

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRateLimitPerMinute')->willReturn(20);

		$service = new RateLimitService($cacheFactory, $settings);
		$service->assertAllowed('alice');
		$this->addToAssertionCount(1);
	}

	public function testBlocksWhenExceeded(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn([
			'count' => 20,
			'windowStart' => time(),
		]);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRateLimitPerMinute')->willReturn(20);

		$service = new RateLimitService($cacheFactory, $settings);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Rate limit exceeded');
		$service->assertAllowed('alice');
	}
}
