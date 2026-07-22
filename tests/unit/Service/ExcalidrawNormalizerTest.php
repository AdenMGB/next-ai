<?php

declare(strict_types=1);

namespace OCA\WhiteBoardAI\Tests\Service;

use OCA\WhiteBoardAI\Service\ExcalidrawNormalizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExcalidrawNormalizerTest extends TestCase {
	private ExcalidrawNormalizer $normalizer;

	protected function setUp(): void {
		$this->normalizer = new ExcalidrawNormalizer();
	}

	public function testNormalizeGenerationPayloadAppendsValidElements(): void {
		$result = $this->normalizer->normalizeGenerationPayload([
			'mode' => 'append',
			'elements' => [
				[
					'type' => 'rectangle',
					'x' => 10,
					'y' => 20,
					'width' => 100,
					'height' => 50,
					'strokeColor' => '#ff0000',
				],
			],
		], 80);

		$this->assertSame('append', $result['mode']);
		$this->assertCount(1, $result['elements']);
		$this->assertSame('rectangle', $result['elements'][0]['type']);
		$this->assertSame(10.0, $result['elements'][0]['x']);
		$this->assertNotEmpty($result['elements'][0]['id']);
	}

	public function testNormalizeGenerationPayloadRejectsEmptyElements(): void {
		$this->expectException(RuntimeException::class);
		$this->normalizer->normalizeGenerationPayload([
			'mode' => 'append',
			'elements' => [
				['type' => 'not-a-real-type', 'x' => 0, 'y' => 0],
			],
		], 80);
	}

	public function testCompactSceneSkipsDeletedAndUnknown(): void {
		$compacted = $this->normalizer->compactScene([
			['id' => 'a', 'type' => 'text', 'x' => 1, 'y' => 2, 'width' => 3, 'height' => 4, 'text' => 'Hello', 'isDeleted' => false],
			['id' => 'b', 'type' => 'rectangle', 'x' => 0, 'y' => 0, 'isDeleted' => true],
			['id' => 'c', 'type' => 'magic', 'x' => 0, 'y' => 0],
		]);

		$this->assertCount(1, $compacted);
		$this->assertSame('Hello', $compacted[0]['text']);
	}

	public function testNormalizeCapsElementCount(): void {
		$elements = [];
		for ($i = 0; $i < 10; $i++) {
			$elements[] = ['type' => 'ellipse', 'x' => $i, 'y' => $i, 'width' => 10, 'height' => 10];
		}

		$result = $this->normalizer->normalizeGenerationPayload([
			'mode' => 'replace',
			'elements' => $elements,
		], 3);

		$this->assertSame('replace', $result['mode']);
		$this->assertCount(3, $result['elements']);
	}
}
