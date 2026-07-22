from __future__ import annotations

import asyncio
import base64
import logging
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from playwright.async_api import Browser, Page, async_playwright

from .settings import Settings

log = logging.getLogger(__name__)

CAPTURE_SCRIPT = """
() => {
  if (window.__wbaiCaptureInstalled) return;
  window.__wbaiCaptureInstalled = true;
  window.__wbaiChunks = [];

  const OriginalRTC = window.RTCPeerConnection;
  if (!OriginalRTC) return;

  window.RTCPeerConnection = function(...args) {
    const pc = new OriginalRTC(...args);
    pc.addEventListener('track', (ev) => {
      if (ev.track && ev.track.kind === 'audio') {
        const stream = new MediaStream([ev.track]);
        try {
          const rec = new MediaRecorder(stream, { mimeType: 'audio/webm;codecs=opus' });
          const speaker = (ev.streams && ev.streams[0] && ev.streams[0].id) || ev.track.id;
          rec.ondataavailable = (e) => {
            if (e.data && e.data.size > 0) {
              window.__wbaiChunks.push({ speaker, blob: e.data, t: Date.now() });
            }
          };
          rec.start(4000);
        } catch (err) {
          console.warn('wbai MediaRecorder failed', err);
        }
      }
    });
    return pc;
  };
  window.RTCPeerConnection.prototype = OriginalRTC.prototype;
}
"""


@dataclass
class SessionResult:
	token: str
	room_name: str
	audio_paths: list[Path] = field(default_factory=list)
	speaker_labels: dict[str, str] = field(default_factory=dict)
	started_at: float = field(default_factory=time.time)
	ended_at: float | None = None


class CallJoiner:
	def __init__(self, settings: Settings, workdir: Path) -> None:
		self.settings = settings
		self.workdir = workdir
		self.workdir.mkdir(parents=True, exist_ok=True)

	async def join_and_record(
		self,
		call_url: str,
		token: str,
		room_name: str,
		should_stop,
	) -> SessionResult:
		result = SessionResult(token=token, room_name=room_name)
		async with async_playwright() as p:
			browser: Browser = await p.chromium.launch(
				headless=self.settings.headless,
				args=[
					"--use-fake-ui-for-media-stream",
					"--use-fake-device-for-media-stream",
					"--autoplay-policy=no-user-gesture-required",
					"--disable-dev-shm-usage",
				],
			)
			context = await browser.new_context(
				permissions=["microphone", "camera"],
				ignore_https_errors=True,
			)
			page = await context.new_page()
			await page.add_init_script(CAPTURE_SCRIPT)

			await self._login(page)
			log.info("Opening call %s", call_url)
			await page.goto(call_url, wait_until="domcontentloaded", timeout=120000)
			await self._try_join_call(page)

			chunk_idx = 0
			while not should_stop():
				await asyncio.sleep(5)
				chunk_idx = await self._flush_chunks(page, result, chunk_idx)
				still_in_call = await self._looks_in_call(page)
				if not still_in_call:
					await asyncio.sleep(8)
					if not await self._looks_in_call(page):
						log.info("Call UI gone; ending session for %s", token)
						break

			await self._flush_chunks(page, result, chunk_idx)
			result.ended_at = time.time()
			await browser.close()
		return result

	async def _login(self, page: Page) -> None:
		login_url = f"{self.settings.nextcloud_url}/login"
		await page.goto(login_url, wait_until="domcontentloaded", timeout=60000)
		if "/login" not in page.url:
			return
		user = page.locator('input[name="user"], #user')
		password = page.locator('input[name="password"], #password')
		await user.fill(self.settings.bot_user)
		await password.fill(self.settings.bot_password)
		await page.locator('button[type="submit"], input[type="submit"]').first.click()
		await page.wait_for_load_state("networkidle", timeout=60000)
		log.info("Logged in as %s", self.settings.bot_user)

	async def _try_join_call(self, page: Page) -> None:
		selectors = [
			'button:has-text("Join call")',
			'button:has-text("Start call")',
			'[data-testid="call-button"]',
			'button.top-bar__button',
		]
		for sel in selectors:
			btn = page.locator(sel).first
			try:
				if await btn.count() and await btn.is_visible(timeout=2000):
					await btn.click(timeout=5000)
					log.info("Clicked join via %s", sel)
					await asyncio.sleep(2)
					return
			except Exception:
				continue
		log.warning("Could not find Join call button; continuing anyway")

	async def _looks_in_call(self, page: Page) -> bool:
		try:
			leave = page.locator('button:has-text("Leave call"), [aria-label*="Leave"]')
			if await leave.count():
				return True
			videos = page.locator("video")
			return await videos.count() > 0
		except Exception:
			return False

	async def _flush_chunks(
		self, page: Page, result: SessionResult, start_idx: int
	) -> int:
		try:
			exported: list[dict[str, Any]] = await page.evaluate(
				"""async () => {
				const batch = window.__wbaiChunks || [];
				window.__wbaiChunks = [];
				const out = [];
				for (const c of batch) {
					const buf = await c.blob.arrayBuffer();
					const bytes = new Uint8Array(buf);
					let binary = '';
					for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
					out.push({ speaker: c.speaker, t: c.t, b64: btoa(binary) });
				}
				return out;
			}"""
			)
			idx = start_idx
			for item in exported or []:
				raw = base64.b64decode(item["b64"])
				speaker = str(item.get("speaker") or "speaker")
				path = self.workdir / f"{result.token}_{speaker}_{idx}.webm"
				path.write_bytes(raw)
				result.audio_paths.append(path)
				result.speaker_labels.setdefault(speaker, speaker)
				idx += 1
			return idx
		except Exception as exc:
			log.warning("Flush chunks failed: %s", exc)
			return start_idx
