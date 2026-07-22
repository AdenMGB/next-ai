from __future__ import annotations

import asyncio
import logging
import signal
import tempfile
import time
from datetime import datetime, timezone
from pathlib import Path

from .docx_writer import build_docx
from .joiner import CallJoiner
from .monitor import TalkMonitor
from .settings import Settings
from .storage import WebDavStorage
from .summarize import Summarizer
from .transcribe import Transcriber

logging.basicConfig(
	level=logging.INFO,
	format="%(asctime)s %(levelname)s %(name)s: %(message)s",
)
log = logging.getLogger("talk_bot")


class Bot:
	def __init__(self, settings: Settings) -> None:
		self.settings = settings
		self.monitor = TalkMonitor(settings)
		self.transcriber = Transcriber(settings)
		self.summarizer = Summarizer(settings)
		self.storage = WebDavStorage(settings)
		self._stop = False
		self._active_tokens: set[str] = set()

	def request_stop(self, *_args) -> None:
		log.info("Stop requested")
		self._stop = True

	async def run(self) -> None:
		log.info("Talk bot starting; polling every %ss", self.settings.poll_interval_sec)
		while not self._stop:
			try:
				await self._tick()
			except Exception:
				log.exception("Poll tick failed")
			await asyncio.sleep(self.settings.poll_interval_sec)
		log.info("Talk bot stopped")

	async def _tick(self) -> None:
		calls = self.monitor.list_active_calls()
		for call in calls:
			if call.token in self._active_tokens:
				continue
			self._active_tokens.add(call.token)
			asyncio.create_task(self._handle_call(call.token, call.name))

	async def _handle_call(self, token: str, room_name: str) -> None:
		log.info("Joining call %s (%s)", room_name, token)
		workdir = Path(tempfile.mkdtemp(prefix=f"wbai-{token}-"))
		joiner = CallJoiner(self.settings, workdir)
		call_url = self.monitor.call_url(token)

		def should_stop() -> bool:
			if self._stop:
				return True
			# Re-check if call still active
			try:
				active = {c.token for c in self.monitor.list_active_calls()}
				return token not in active
			except Exception:
				return False

		try:
			session = await joiner.join_and_record(
				call_url=call_url,
				token=token,
				room_name=room_name,
				should_stop=should_stop,
			)
			participants_raw = self.monitor.participants(token)
			names = []
			for p in participants_raw:
				n = p.get("displayName") or p.get("name")
				if n:
					names.append(str(n))

			lines = self.transcriber.transcribe_session(
				session.audio_paths, session.speaker_labels
			)
			summary = self.summarizer.summarize(room_name, lines)
			docx = build_docx(
				room_name=room_name,
				transcript_lines=lines,
				summary=summary,
				participants=names,
				started_at=session.started_at,
				ended_at=session.ended_at or time.time(),
			)
			stamp = datetime.now(tz=timezone.utc).strftime("%Y%m%d-%H%M")
			safe_name = "".join(c if c.isalnum() or c in "-_ " else "_" for c in room_name)[:60]
			filename = f"{safe_name}-{stamp}.docx"
			self.storage.upload(self.settings.notes_folder, filename, docx)
			log.info("Finished call %s → %s", token, filename)
		except Exception:
			log.exception("Failed handling call %s", token)
		finally:
			self._active_tokens.discard(token)


def main() -> None:
	settings = Settings.from_env()
	bot = Bot(settings)
	loop = asyncio.new_event_loop()
	asyncio.set_event_loop(loop)
	for sig in (signal.SIGINT, signal.SIGTERM):
		try:
			loop.add_signal_handler(sig, bot.request_stop)
		except NotImplementedError:
			signal.signal(sig, lambda *_: bot.request_stop())
	loop.run_until_complete(bot.run())


if __name__ == "__main__":
	main()
