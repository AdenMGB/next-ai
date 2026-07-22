from __future__ import annotations

import logging
from pathlib import Path

import httpx

from .settings import Settings

log = logging.getLogger(__name__)


class Transcriber:
	def __init__(self, settings: Settings) -> None:
		self.settings = settings

	def transcribe_file(self, path: Path, speaker: str) -> str:
		"""Transcribe an audio chunk via OpenRouter (OpenAI-compatible audio endpoint)."""
		if not path.exists() or path.stat().st_size < 100:
			return ""

		# OpenRouter audio transcription — try Whisper-compatible endpoint
		url = "https://openrouter.ai/api/v1/audio/transcriptions"
		headers = {
			"Authorization": f"Bearer {self.settings.openrouter_api_key}",
			"HTTP-Referer": "https://github.com/adenmgb/whiteboardai",
			"X-Title": "Whiteboard AI Talk Bot",
		}
		try:
			with path.open("rb") as fh:
				files = {"file": (path.name, fh, "audio/webm")}
				data = {"model": self.settings.openrouter_transcribe_model}
				with httpx.Client(timeout=120.0) as client:
					resp = client.post(url, headers=headers, files=files, data=data)
			if resp.status_code >= 400:
				log.warning("Transcription failed %s: %s", resp.status_code, resp.text[:200])
				return ""
			payload = resp.json()
			text = payload.get("text") if isinstance(payload, dict) else None
			if isinstance(text, str) and text.strip():
				return f"[{speaker}] {text.strip()}"
		except Exception as exc:
			log.warning("Transcription error for %s: %s", path, exc)
		return ""

	def transcribe_session(
		self, audio_paths: list[Path], speaker_labels: dict[str, str]
	) -> list[str]:
		lines: list[str] = []
		for path in audio_paths:
			# filename: token_speaker_idx.webm
			parts = path.stem.split("_")
			speaker_id = parts[1] if len(parts) >= 3 else "speaker"
			label = speaker_labels.get(speaker_id, speaker_id)
			line = self.transcribe_file(path, label)
			if line:
				lines.append(line)
		return lines
