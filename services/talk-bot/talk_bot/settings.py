"""Talk call transcription bot for Nextcloud Talk + local Whisper / OpenRouter."""

from __future__ import annotations

import os
from dataclasses import dataclass

_VALID_BACKENDS = frozenset({"local", "openrouter"})


@dataclass(frozen=True)
class Settings:
	nextcloud_url: str
	bot_user: str
	bot_password: str
	openrouter_api_key: str
	openrouter_model: str
	openrouter_transcribe_model: str
	transcribe_backend: str
	whisper_model: str
	whisper_device: str
	whisper_compute_type: str
	notes_folder: str
	poll_interval_sec: int
	headless: bool

	@staticmethod
	def from_env() -> "Settings":
		url = os.environ.get("NEXTCLOUD_URL", "").rstrip("/")
		user = os.environ.get("BOT_USER", "")
		password = os.environ.get("BOT_PASSWORD", "")
		key = os.environ.get("OPENROUTER_API_KEY", "")
		if not url or not user or not password:
			raise RuntimeError("NEXTCLOUD_URL, BOT_USER, and BOT_PASSWORD are required")
		if not key:
			raise RuntimeError("OPENROUTER_API_KEY is required (used for call summaries)")

		backend = os.environ.get("TRANSCRIBE_BACKEND", "local").strip().lower()
		if backend not in _VALID_BACKENDS:
			raise RuntimeError(
				f"TRANSCRIBE_BACKEND must be one of: {', '.join(sorted(_VALID_BACKENDS))}"
			)

		return Settings(
			nextcloud_url=url,
			bot_user=user,
			bot_password=password,
			openrouter_api_key=key,
			openrouter_model=os.environ.get("OPENROUTER_MODEL", "openai/gpt-4o-mini"),
			openrouter_transcribe_model=os.environ.get(
				"OPENROUTER_TRANSCRIBE_MODEL", "openai/whisper-1"
			),
			transcribe_backend=backend,
			whisper_model=os.environ.get("WHISPER_MODEL", "small"),
			whisper_device=os.environ.get("WHISPER_DEVICE", "cpu"),
			whisper_compute_type=os.environ.get("WHISPER_COMPUTE_TYPE", "int8"),
			notes_folder=os.environ.get("NOTES_FOLDER", "/Talk transcripts"),
			poll_interval_sec=max(5, int(os.environ.get("POLL_INTERVAL_SEC", "10"))),
			headless=os.environ.get("HEADLESS", "1") not in ("0", "false", "False"),
		)
