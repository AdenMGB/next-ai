from talk_bot.settings import Settings
import pytest


def test_settings_requires_env(monkeypatch):
	for key in (
		"NEXTCLOUD_URL",
		"BOT_USER",
		"BOT_PASSWORD",
		"OPENROUTER_API_KEY",
		"TRANSCRIBE_BACKEND",
		"WHISPER_MODEL",
	):
		monkeypatch.delenv(key, raising=False)
	with pytest.raises(RuntimeError):
		Settings.from_env()


def test_settings_from_env(monkeypatch):
	monkeypatch.setenv("NEXTCLOUD_URL", "https://cloud.example.com/")
	monkeypatch.setenv("BOT_USER", "talkbot")
	monkeypatch.setenv("BOT_PASSWORD", "secret")
	monkeypatch.setenv("OPENROUTER_API_KEY", "sk-test")
	s = Settings.from_env()
	assert s.nextcloud_url == "https://cloud.example.com"
	assert s.notes_folder == "/Talk transcripts"
	assert s.transcribe_backend == "local"
	assert s.whisper_model == "small"
	assert s.whisper_device == "cpu"
	assert s.whisper_compute_type == "int8"


def test_settings_openrouter_transcribe(monkeypatch):
	monkeypatch.setenv("NEXTCLOUD_URL", "https://cloud.example.com")
	monkeypatch.setenv("BOT_USER", "talkbot")
	monkeypatch.setenv("BOT_PASSWORD", "secret")
	monkeypatch.setenv("OPENROUTER_API_KEY", "sk-test")
	monkeypatch.setenv("TRANSCRIBE_BACKEND", "openrouter")
	monkeypatch.setenv("OPENROUTER_TRANSCRIBE_MODEL", "openai/whisper-1")
	s = Settings.from_env()
	assert s.transcribe_backend == "openrouter"


def test_settings_rejects_bad_backend(monkeypatch):
	monkeypatch.setenv("NEXTCLOUD_URL", "https://cloud.example.com")
	monkeypatch.setenv("BOT_USER", "talkbot")
	monkeypatch.setenv("BOT_PASSWORD", "secret")
	monkeypatch.setenv("OPENROUTER_API_KEY", "sk-test")
	monkeypatch.setenv("TRANSCRIBE_BACKEND", "cloud")
	with pytest.raises(RuntimeError, match="TRANSCRIBE_BACKEND"):
		Settings.from_env()
