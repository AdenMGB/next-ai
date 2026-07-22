from talk_bot.docx_writer import build_docx


def test_build_docx_contains_sections():
	raw = build_docx(
		room_name="Standup",
		transcript_lines=["[Alice] Hello", "[Bob] Hi there"],
		summary="## Overview\nA short standup.",
		participants=["Alice", "Bob"],
		started_at=1_700_000_000,
		ended_at=1_700_000_600,
	)
	assert raw[:2] == b"PK"  # zip/docx magic
	assert len(raw) > 1000
