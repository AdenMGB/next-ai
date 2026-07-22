<?php

declare(strict_types=1);

namespace OCA\WhiteBoardAI\Service;

use OCP\Http\Client\IClientService;
use RuntimeException;

class OpenRouterService {
	private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';
	private const MAX_HISTORY_TURNS = 20;

	public function __construct(
		private IClientService $clientService,
		private SettingsService $settingsService,
		private ExcalidrawNormalizer $normalizer,
	) {
	}

	/**
	 * @param list<array<string, mixed>> $sceneElements
	 * @param list<array{role: string, content: string}> $history
	 * @return array{mode: string, elements: list<array<string, mixed>>, assistantMessage: string}
	 */
	public function generate(
		string $prompt,
		array $sceneElements = [],
		?string $modeHint = null,
		array $history = [],
	): array {
		$apiKey = $this->settingsService->getApiKey();
		if ($apiKey === '') {
			throw new RuntimeException('OpenRouter API key is not configured');
		}

		$prompt = trim($prompt);
		if ($prompt === '') {
			throw new RuntimeException('Prompt must not be empty');
		}
		if (mb_strlen($prompt) > 4000) {
			throw new RuntimeException('Prompt is too long (max 4000 characters)');
		}

		$maxElements = $this->settingsService->getMaxElements();
		$compactScene = $this->normalizer->compactScene($sceneElements, $maxElements);
		$modeHint = $modeHint !== null ? strtolower(trim($modeHint)) : null;
		if ($modeHint !== null && !in_array($modeHint, ['append', 'replace'], true)) {
			$modeHint = null;
		}

		$messages = [
			[
				'role' => 'system',
				'content' => $this->buildSystemPrompt($maxElements),
			],
		];

		foreach ($this->sanitizeHistory($history) as $turn) {
			$messages[] = $turn;
		}

		$messages[] = [
			'role' => 'user',
			'content' => $this->buildUserPrompt($prompt, $compactScene, $modeHint),
		];

		$body = [
			'model' => $this->settingsService->getModel(),
			'messages' => $messages,
			'temperature' => 0.4,
			'response_format' => [
				'type' => 'json_schema',
				'json_schema' => [
					'name' => 'excalidraw_generation',
					'strict' => true,
					'schema' => $this->getResponseSchema(),
				],
			],
		];

		try {
			$raw = $this->requestCompletion($apiKey, $body);
		} catch (RuntimeException $e) {
			$message = strtolower($e->getMessage());
			$canFallback = str_contains($message, 'response_format')
				|| str_contains($message, 'json_schema')
				|| str_contains($message, 'status 400');
			if (!$canFallback) {
				throw $e;
			}
			$body['response_format'] = ['type' => 'json_object'];
			$raw = $this->requestCompletion($apiKey, $body);
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			throw new RuntimeException('Failed to parse AI JSON response');
		}

		return $this->normalizer->normalizeGenerationPayload($decoded, $maxElements);
	}

	/**
	 * @param list<mixed> $history
	 * @return list<array{role: string, content: string}>
	 */
	public function sanitizeHistory(array $history): array {
		$clean = [];
		foreach ($history as $item) {
			if (!is_array($item)) {
				continue;
			}
			$role = $item['role'] ?? null;
			$content = $item['content'] ?? null;
			if (!is_string($role) || !in_array($role, ['user', 'assistant'], true)) {
				continue;
			}
			if (!is_string($content) || trim($content) === '') {
				continue;
			}
			$clean[] = [
				'role' => $role,
				'content' => mb_substr(trim($content), 0, 2000),
			];
		}

		if (count($clean) > self::MAX_HISTORY_TURNS) {
			$clean = array_slice($clean, -self::MAX_HISTORY_TURNS);
		}

		return $clean;
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function requestCompletion(string $apiKey, array $body): string {
		$client = $this->clientService->newClient();
		$response = $client->post(self::ENDPOINT, [
			'headers' => [
				'Authorization' => 'Bearer ' . $apiKey,
				'Content-Type' => 'application/json',
				'HTTP-Referer' => 'https://github.com/adenmgb/whiteboardai',
				'X-Title' => 'Nextcloud Whiteboard AI',
			],
			'body' => json_encode($body, JSON_THROW_ON_ERROR),
			'timeout' => 90,
		]);

		$status = $response->getStatusCode();
		$content = $response->getBody();
		if ($status < 200 || $status >= 300) {
			throw new RuntimeException('OpenRouter request failed with status ' . $status . ': ' . mb_substr($content, 0, 300));
		}

		$payload = json_decode($content, true);
		if (!is_array($payload)) {
			throw new RuntimeException('Invalid OpenRouter response');
		}

		$text = $payload['choices'][0]['message']['content'] ?? null;
		if (!is_string($text) || trim($text) === '') {
			throw new RuntimeException('OpenRouter returned an empty completion');
		}

		return $this->extractJsonObject($text);
	}

	private function extractJsonObject(string $text): string {
		$text = trim($text);
		if (str_starts_with($text, '```')) {
			$text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
			$text = preg_replace('/\s*```$/', '', $text) ?? $text;
			$text = trim($text);
		}

		if (str_starts_with($text, '{')) {
			return $text;
		}

		$start = strpos($text, '{');
		$end = strrpos($text, '}');
		if ($start === false || $end === false || $end <= $start) {
			throw new RuntimeException('AI response did not contain a JSON object');
		}

		return substr($text, $start, $end - $start + 1);
	}

	private function buildSystemPrompt(int $maxElements): string {
		return <<<PROMPT
You are a whiteboard drawing assistant in a multi-turn chat.
Return ONLY a JSON object with keys:
- mode: "append" to add new shapes, or "replace" to rewrite/edit the existing scene
- elements: array of Excalidraw element objects (max {$maxElements})
- assistantMessage: short friendly reply for the chat UI (1-2 sentences) explaining what you drew

Rules:
- Use prior chat history for continuity (follow-ups like "make it blue" refer to previous shapes)
- Use element types: rectangle, ellipse, diamond, triangle, line, arrow, text, freedraw, frame
- Include coordinates (x,y), width, height, strokeColor, backgroundColor, fillStyle, strokeWidth
- For text elements include text, fontSize, fontFamily
- For arrows/lines include points as [[x,y], ...] relative to the element origin
- When editing, prefer mode "replace", preserve existing element ids that remain, omit deleted ids
- When creating new content on an empty board, use mode "append"
- Do not include markdown fences or commentary outside the JSON object
PROMPT;
	}

	/**
	 * @param list<array<string, mixed>> $compactScene
	 */
	private function buildUserPrompt(string $prompt, array $compactScene, ?string $modeHint): string {
		$parts = ['User request: ' . $prompt];
		if ($modeHint !== null) {
			$parts[] = 'Preferred mode: ' . $modeHint;
		}
		if ($compactScene === []) {
			$parts[] = 'Current scene: empty';
		} else {
			$parts[] = 'Current scene elements (compact JSON):';
			$parts[] = json_encode($compactScene, JSON_THROW_ON_ERROR);
			$parts[] = 'Use this context to edit or extend the existing drawing.';
		}
		return implode("\n\n", $parts);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getResponseSchema(): array {
		return [
			'type' => 'object',
			'additionalProperties' => false,
			'required' => ['mode', 'elements', 'assistantMessage'],
			'properties' => [
				'mode' => [
					'type' => 'string',
					'enum' => ['append', 'replace'],
				],
				'assistantMessage' => [
					'type' => 'string',
				],
				'elements' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'additionalProperties' => true,
						'required' => ['type', 'x', 'y'],
						'properties' => [
							'type' => ['type' => 'string'],
							'id' => ['type' => 'string'],
							'x' => ['type' => 'number'],
							'y' => ['type' => 'number'],
							'width' => ['type' => 'number'],
							'height' => ['type' => 'number'],
							'text' => ['type' => 'string'],
							'strokeColor' => ['type' => 'string'],
							'backgroundColor' => ['type' => 'string'],
							'points' => ['type' => 'array'],
						],
					],
				],
			],
		];
	}
}
