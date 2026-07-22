from __future__ import annotations

import logging

import httpx

from .settings import Settings

log = logging.getLogger(__name__)


class Summarizer:
	def __init__(self, settings: Settings) -> None:
		self.settings = settings

	def summarize(self, room_name: str, transcript_lines: list[str]) -> str:
		if not transcript_lines:
			return "No speech was captured during this call."

		body = {
			"model": self.settings.openrouter_model,
			"temperature": 0.3,
			"messages": [
				{
					"role": "system",
					"content": (
						"You summarize Nextcloud Talk meeting transcripts. "
						"Write clear markdown with sections: Overview, Key topics, "
						"Decisions, Action items. Be concise."
					),
				},
				{
					"role": "user",
					"content": (
						f"Meeting: {room_name}\n\nTranscript:\n"
						+ "\n".join(transcript_lines)
					),
				},
			],
		}
		headers = {
			"Authorization": f"Bearer {self.settings.openrouter_api_key}",
			"Content-Type": "application/json",
			"HTTP-Referer": "https://github.com/adenmgb/whiteboardai",
			"X-Title": "Whiteboard AI Talk Bot",
		}
		with httpx.Client(timeout=90.0) as client:
			resp = client.post(
				"https://openrouter.ai/api/v1/chat/completions",
				headers=headers,
				json=body,
			)
		resp.raise_for_status()
		payload = resp.json()
		text = payload.get("choices", [{}])[0].get("message", {}).get("content")
		if not isinstance(text, str) or not text.strip():
			raise RuntimeError("Empty summary from OpenRouter")
		return text.strip()
