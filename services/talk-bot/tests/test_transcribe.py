from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest

from talk_bot.settings import Settings
from talk_bot.transcribe import Transcriber


def _settings(**overrides) -> Settings:
	base = dict(
		nextcloud_url="https://cloud.example.com",
		bot_user="talkbot",
		bot_password="secret",
		openrouter_api_key="sk-test",
		openrouter_model="openai/gpt-4o-mini",
		openrouter_transcribe_model="openai/whisper-1",
		transcribe_backend="local",
		whisper_model="small",
		whisper_device="cpu",
		whisper_compute_type="int8",
		notes_folder="/Talk transcripts",
		poll_interval_sec=10,
		headless=True,
	)
	base.update(overrides)
	return Settings(**base)


def test_local_transcribe_file(tmp_path: Path):
	audio = tmp_path / "tok_alice_0.webm"
	audio.write_bytes(b"\x00" * 200)

	segment = MagicMock()
	segment.text = " Hello world "
	model = MagicMock()
	model.transcribe.return_value = ([segment], None)

	t = Transcriber(_settings())
	with patch.object(t, "_get_local_model", return_value=model):
		line = t.transcribe_file(audio, "Alice")

	assert line == "[Alice] Hello world"
	model.transcribe.assert_called_once()


def test_local_skips_tiny_files(tmp_path: Path):
	audio = tmp_path / "tiny.webm"
	audio.write_bytes(b"x")
	t = Transcriber(_settings())
	assert t.transcribe_file(audio, "Alice") == ""


def test_openrouter_backend_posts_audio(tmp_path: Path):
	audio = tmp_path / "tok_bob_0.webm"
	audio.write_bytes(b"\x00" * 200)

	resp = MagicMock()
	resp.status_code = 200
	resp.json.return_value = {"text": "Hi there"}

	client = MagicMock()
	client.__enter__.return_value = client
	client.__exit__.return_value = False
	client.post.return_value = resp

	t = Transcriber(_settings(transcribe_backend="openrouter"))
	with patch("talk_bot.transcribe.httpx.Client", return_value=client):
		line = t.transcribe_file(audio, "Bob")

	assert line == "[Bob] Hi there"
	assert client.post.called


def test_transcribe_session_uses_speaker_labels(tmp_path: Path):
	audio = tmp_path / "room_s1_0.webm"
	audio.write_bytes(b"\x00" * 200)
	t = Transcriber(_settings())
	with patch.object(t, "transcribe_file", return_value="[Sam] Yo") as mock_tf:
		lines = t.transcribe_session([audio], {"s1": "Sam"})
	assert lines == ["[Sam] Yo"]
	mock_tf.assert_called_once_with(audio, "Sam")


def test_unknown_backend_raises(tmp_path: Path):
	audio = tmp_path / "x.webm"
	audio.write_bytes(b"\x00" * 200)
	t = Transcriber(_settings(transcribe_backend="bogus"))
	with pytest.raises(ValueError, match="Unknown transcribe backend"):
		t.transcribe_file(audio, "X")
