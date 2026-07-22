from __future__ import annotations

from datetime import datetime, timezone
from io import BytesIO
from pathlib import Path

from docx import Document


def build_docx(
	room_name: str,
	transcript_lines: list[str],
	summary: str,
	participants: list[str] | None = None,
	started_at: float | None = None,
	ended_at: float | None = None,
) -> bytes:
	doc = Document()
	doc.add_heading(f"Talk transcript — {room_name}", level=1)

	meta = doc.add_paragraph()
	if started_at:
		start = datetime.fromtimestamp(started_at, tz=timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
		meta.add_run(f"Started: {start}\n")
	if ended_at:
		end = datetime.fromtimestamp(ended_at, tz=timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
		meta.add_run(f"Ended: {end}\n")
	if participants:
		meta.add_run("Participants: " + ", ".join(participants))

	doc.add_heading("AI summary", level=2)
	for block in summary.split("\n"):
		doc.add_paragraph(block)

	doc.add_heading("Transcript", level=2)
	if not transcript_lines:
		doc.add_paragraph("(No speech captured)")
	else:
		for line in transcript_lines:
			doc.add_paragraph(line)

	buf = BytesIO()
	doc.save(buf)
	return buf.getvalue()


def write_docx_file(path: Path, content: bytes) -> None:
	path.parent.mkdir(parents=True, exist_ok=True)
	path.write_bytes(content)
