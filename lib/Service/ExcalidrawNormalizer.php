<?php

declare(strict_types=1);

namespace OCA\WhiteBoardAI\Service;

use RuntimeException;

class ExcalidrawNormalizer {
	private const ALLOWED_TYPES = [
		'rectangle',
		'ellipse',
		'diamond',
		'triangle',
		'line',
		'arrow',
		'text',
		'freedraw',
		'frame',
		'embeddable',
	];

	private const ALLOWED_KEYS = [
		'id', 'type', 'x', 'y', 'width', 'height', 'angle',
		'strokeColor', 'backgroundColor', 'fillStyle', 'strokeWidth',
		'strokeStyle', 'roughness', 'opacity', 'groupIds', 'frameId',
		'roundness', 'seed', 'version', 'versionNonce', 'isDeleted',
		'boundElements', 'updated', 'link', 'locked', 'text', 'fontSize',
		'fontFamily', 'textAlign', 'verticalAlign', 'containerId',
		'originalText', 'autoResize', 'lineHeight', 'points', 'lastCommittedPoint',
		'startBinding', 'endBinding', 'startArrowhead', 'endArrowhead',
		'name', 'index',
	];

	/**
	 * @param mixed $payload
	 * @return array{mode: string, elements: list<array<string, mixed>>, assistantMessage: string}
	 */
	public function normalizeGenerationPayload(mixed $payload, int $maxElements): array {
		if (!is_array($payload)) {
			throw new RuntimeException('AI response must be a JSON object');
		}

		$mode = isset($payload['mode']) && is_string($payload['mode'])
			? strtolower(trim($payload['mode']))
			: 'append';

		if (!in_array($mode, ['append', 'replace'], true)) {
			$mode = 'append';
		}

		$elements = $payload['elements'] ?? null;
		if (!is_array($elements)) {
			throw new RuntimeException('AI response must include an elements array');
		}

		$normalized = [];
		foreach ($elements as $element) {
			if (!is_array($element)) {
				continue;
			}
			$clean = $this->normalizeElement($element);
			if ($clean !== null) {
				$normalized[] = $clean;
			}
			if (count($normalized) >= $maxElements) {
				break;
			}
		}

		if ($normalized === []) {
			throw new RuntimeException('AI response did not contain any valid Excalidraw elements');
		}

		$assistantMessage = '';
		if (isset($payload['assistantMessage']) && is_string($payload['assistantMessage'])) {
			$assistantMessage = trim(mb_substr($payload['assistantMessage'], 0, 500));
		}
		if ($assistantMessage === '') {
			$count = count($normalized);
			$assistantMessage = $mode === 'replace'
				? "Updated the scene with {$count} shape(s). Paste with Ctrl+V / Cmd+V."
				: "Added {$count} shape(s). Paste onto the board with Ctrl+V / Cmd+V.";
		}

		return [
			'mode' => $mode,
			'elements' => $normalized,
			'assistantMessage' => $assistantMessage,
		];
	}

	/**
	 * Compact scene elements for prompt context.
	 *
	 * @param list<mixed> $elements
	 * @return list<array<string, mixed>>
	 */
	public function compactScene(array $elements, int $maxElements = 120): array {
		$compacted = [];
		foreach ($elements as $element) {
			if (!is_array($element)) {
				continue;
			}
			if (($element['isDeleted'] ?? false) === true) {
				continue;
			}
			$type = $element['type'] ?? null;
			if (!is_string($type) || !in_array($type, self::ALLOWED_TYPES, true)) {
				continue;
			}

			$item = [
				'id' => (string)($element['id'] ?? ''),
				'type' => $type,
				'x' => $this->toFloat($element['x'] ?? 0),
				'y' => $this->toFloat($element['y'] ?? 0),
				'width' => $this->toFloat($element['width'] ?? 0),
				'height' => $this->toFloat($element['height'] ?? 0),
			];

			if (isset($element['text']) && is_string($element['text']) && $element['text'] !== '') {
				$item['text'] = mb_substr($element['text'], 0, 500);
			}
			if (isset($element['strokeColor']) && is_string($element['strokeColor'])) {
				$item['strokeColor'] = $element['strokeColor'];
			}
			if (isset($element['backgroundColor']) && is_string($element['backgroundColor'])) {
				$item['backgroundColor'] = $element['backgroundColor'];
			}
			if (isset($element['angle'])) {
				$item['angle'] = $this->toFloat($element['angle']);
			}
			if (isset($element['startBinding']) || isset($element['endBinding'])) {
				$item['startBinding'] = $element['startBinding'] ?? null;
				$item['endBinding'] = $element['endBinding'] ?? null;
			}

			$compacted[] = $item;
			if (count($compacted) >= $maxElements) {
				break;
			}
		}

		return $compacted;
	}

	/**
	 * @param array<string, mixed> $element
	 * @return array<string, mixed>|null
	 */
	public function normalizeElement(array $element): ?array {
		$type = $element['type'] ?? null;
		if (!is_string($type) || !in_array($type, self::ALLOWED_TYPES, true)) {
			return null;
		}

		$clean = [];
		foreach (self::ALLOWED_KEYS as $key) {
			if (array_key_exists($key, $element)) {
				$clean[$key] = $element[$key];
			}
		}

		$clean['type'] = $type;
		$clean['id'] = $this->ensureId(isset($clean['id']) && is_string($clean['id']) ? $clean['id'] : null);
		$clean['x'] = $this->toFloat($clean['x'] ?? 0);
		$clean['y'] = $this->toFloat($clean['y'] ?? 0);
		$clean['width'] = $this->toFloat($clean['width'] ?? ($type === 'text' ? 100 : 120));
		$clean['height'] = $this->toFloat($clean['height'] ?? ($type === 'text' ? 25 : 80));
		$clean['angle'] = $this->toFloat($clean['angle'] ?? 0);
		$clean['strokeColor'] = $this->sanitizeColor($clean['strokeColor'] ?? '#1e1e1e', '#1e1e1e');
		$clean['backgroundColor'] = $this->sanitizeColor($clean['backgroundColor'] ?? 'transparent', 'transparent');
		$clean['fillStyle'] = in_array($clean['fillStyle'] ?? '', ['hachure', 'cross-hatch', 'solid', 'zigzag'], true)
			? $clean['fillStyle']
			: 'solid';
		$clean['strokeWidth'] = max(0.5, $this->toFloat($clean['strokeWidth'] ?? 2));
		$clean['strokeStyle'] = in_array($clean['strokeStyle'] ?? '', ['solid', 'dashed', 'dotted'], true)
			? $clean['strokeStyle']
			: 'solid';
		$clean['roughness'] = (int)($clean['roughness'] ?? 1);
		$clean['opacity'] = max(0, min(100, (int)($clean['opacity'] ?? 100)));
		$clean['groupIds'] = is_array($clean['groupIds'] ?? null) ? array_values($clean['groupIds']) : [];
		$clean['frameId'] = $clean['frameId'] ?? null;
		$clean['roundness'] = $clean['roundness'] ?? null;
		$clean['seed'] = isset($clean['seed']) && is_int($clean['seed']) ? $clean['seed'] : random_int(1, 2_147_483_647);
		$clean['version'] = isset($clean['version']) && is_int($clean['version']) ? max(1, $clean['version']) : 1;
		$clean['versionNonce'] = isset($clean['versionNonce']) && is_int($clean['versionNonce'])
			? $clean['versionNonce']
			: random_int(1, 2_147_483_647);
		$clean['isDeleted'] = false;
		$clean['boundElements'] = is_array($clean['boundElements'] ?? null) ? $clean['boundElements'] : null;
		$clean['updated'] = isset($clean['updated']) && is_numeric($clean['updated'])
			? (int)$clean['updated']
			: (int)(microtime(true) * 1000);
		$clean['link'] = is_string($clean['link'] ?? null) ? $clean['link'] : null;
		$clean['locked'] = (bool)($clean['locked'] ?? false);

		if ($type === 'text') {
			$text = isset($clean['text']) && is_string($clean['text']) ? $clean['text'] : 'Text';
			$clean['text'] = $text;
			$clean['originalText'] = isset($clean['originalText']) && is_string($clean['originalText'])
				? $clean['originalText']
				: $text;
			$clean['fontSize'] = max(10, $this->toFloat($clean['fontSize'] ?? 20));
			$clean['fontFamily'] = (int)($clean['fontFamily'] ?? 1);
			$clean['textAlign'] = in_array($clean['textAlign'] ?? '', ['left', 'center', 'right'], true)
				? $clean['textAlign']
				: 'left';
			$clean['verticalAlign'] = in_array($clean['verticalAlign'] ?? '', ['top', 'middle', 'bottom'], true)
				? $clean['verticalAlign']
				: 'top';
			$clean['containerId'] = $clean['containerId'] ?? null;
			$clean['autoResize'] = (bool)($clean['autoResize'] ?? true);
			$clean['lineHeight'] = $this->toFloat($clean['lineHeight'] ?? 1.25);
		}

		if (in_array($type, ['line', 'arrow', 'freedraw'], true)) {
			$points = $clean['points'] ?? [[0, 0], [100, 0]];
			if (!is_array($points) || count($points) < 2) {
				$points = [[0, 0], [100, 0]];
			}
			$clean['points'] = array_values(array_map(static function ($point): array {
				if (!is_array($point) || count($point) < 2) {
					return [0.0, 0.0];
				}
				return [(float)$point[0], (float)$point[1]];
			}, $points));
			$clean['lastCommittedPoint'] = null;
		}

		if ($type === 'arrow') {
			$clean['startArrowhead'] = $clean['startArrowhead'] ?? null;
			$clean['endArrowhead'] = $clean['endArrowhead'] ?? 'arrow';
			$clean['startBinding'] = $clean['startBinding'] ?? null;
			$clean['endBinding'] = $clean['endBinding'] ?? null;
		}

		return $clean;
	}

	private function ensureId(?string $id): string {
		if ($id !== null && $id !== '' && preg_match('/^[A-Za-z0-9_-]{4,64}$/', $id) === 1) {
			return $id;
		}

		return bin2hex(random_bytes(10));
	}

	private function toFloat(mixed $value): float {
		if (is_int($value) || is_float($value)) {
			return (float)$value;
		}
		if (is_string($value) && is_numeric($value)) {
			return (float)$value;
		}
		return 0.0;
	}

	private function sanitizeColor(mixed $value, string $default): string {
		if (!is_string($value)) {
			return $default;
		}
		$value = trim($value);
		if ($value === 'transparent') {
			return 'transparent';
		}
		if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) === 1) {
			return $value;
		}
		return $default;
	}
}
