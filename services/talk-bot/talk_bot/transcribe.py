from __future__ import annotations

import logging
from pathlib import Path
from typing import Any

import httpx

from .settings import Settings

log = logging.getLogger(__name__)


class Transcriber:
	def __init__(self, settings: Settings) -> None:
		self.settings = settings
		self._local_model: Any | None = None

	def _get_local_model(self) -> Any:
		if self._local_model is None:
			from faster_whisper import WhisperModel

			log.info(
				"Loading Whisper model %s (%s/%s)",
				self.settings.whisper_model,
				self.settings.whisper_device,
				self.settings.whisper_compute_type,
			)
			self._local_model = WhisperModel(
				self.settings.whisper_model,
				device=self.settings.whisper_device,
				compute_type=self.settings.whisper_compute_type,
			)
		return self._local_model

	def transcribe_file(self, path: Path, speaker: str) -> str:
		"""Transcribe an audio chunk (local Whisper by default, or OpenRouter)."""
		if not path.exists() or path.stat().st_size < 100:
			return ""

		backend = self.settings.transcribe_backend
		if backend == "local":
			return self._transcribe_local(path, speaker)
		if backend == "openrouter":
			return self._transcribe_openrouter(path, speaker)
		raise ValueError(f"Unknown transcribe backend: {backend}")

	def _transcribe_local(self, path: Path, speaker: str) -> str:
		try:
			model = self._get_local_model()
			segments, _info = model.transcribe(str(path), beam_size=1, vad_filter=True)
			text = " ".join(seg.text.strip() for seg in segments if seg.text).strip()
			if text:
				return f"[{speaker}] {text}"
		except Exception as exc:
			log.warning("Local transcription error for %s: %s", path, exc)
		return ""

	def _transcribe_openrouter(self, path: Path, speaker: str) -> str:
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
