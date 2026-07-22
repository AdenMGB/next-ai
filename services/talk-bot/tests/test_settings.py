from talk_bot.settings import Settings
import os
import pytest


def test_settings_requires_env(monkeypatch):
	for key in ("NEXTCLOUD_URL", "BOT_USER", "BOT_PASSWORD", "OPENROUTER_API_KEY"):
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
