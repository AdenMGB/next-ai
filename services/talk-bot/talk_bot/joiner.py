from __future__ import annotations

import asyncio
import base64
import logging
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from playwright.async_api import Browser, Page, async_playwright

from .join_helpers import (
	IN_CALL_SELECTORS,
	JOIN_BUTTON_SELECTORS,
	LEAVE_BUTTON_SELECTORS,
	build_call_url,
)
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
		# Prefer #direct-call (Talk skips / auto-confirms device preview when supported).
		direct_url = build_call_url(self.settings.nextcloud_url, token, direct=True)
		target_url = direct_url if token in call_url else call_url

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
				viewport={"width": 1280, "height": 720},
			)
			page = await context.new_page()
			await page.add_init_script(CAPTURE_SCRIPT)

			await self._login(page)
			log.info("Opening call %s", target_url)
			await page.goto(target_url, wait_until="domcontentloaded", timeout=120000)
			await self._ensure_conversation_session(page, token)
			await self._wait_for_talk_shell(page)
			await self._dismiss_common_modals(page)

			in_call = False
			chunk_idx = 0
			join_attempts = 0
			while not should_stop():
				if not in_call:
					join_attempts += 1
					ok = await self._try_join_call(page)
					in_call = await self._looks_in_call(page)
					if ok and in_call:
						log.info("In call UI for %s after %s attempt(s)", token, join_attempts)
					elif join_attempts == 1 or join_attempts % 6 == 0:
						await self._debug_snapshot(page, f"join-attempt-{join_attempts}")
						log.warning(
							"Not in call yet (attempt %s); will keep retrying until call ends",
							join_attempts,
						)
				else:
					# Detect unexpected drop while API still says call is active
					if not await self._looks_in_call(page):
						log.warning("Lost call UI for %s; attempting re-join", token)
						in_call = False
						continue

				chunk_idx = await self._flush_chunks(page, result, chunk_idx)
				await asyncio.sleep(5)

			await self._flush_chunks(page, result, chunk_idx)
			result.ended_at = time.time()
			await browser.close()
		return result

	async def _login(self, page: Page) -> None:
		login_url = f"{self.settings.nextcloud_url}/login"
		await page.goto(login_url, wait_until="domcontentloaded", timeout=60000)
		if "/login" not in page.url:
			log.info("Already authenticated")
			return
		user = page.locator('input[name="user"], #user')
		password = page.locator('input[name="password"], #password')
		await user.fill(self.settings.bot_user)
		await password.fill(self.settings.bot_password)
		await page.locator('button[type="submit"], input[type="submit"]').first.click()
		try:
			await page.wait_for_url(lambda url: "/login" not in url, timeout=60000)
		except Exception:
			await page.wait_for_load_state("networkidle", timeout=60000)
		log.info("Logged in as %s", self.settings.bot_user)

	async def _ensure_conversation_session(self, page: Page, token: str) -> None:
		"""Join the Talk conversation session (required before Join call is enabled)."""
		url = (
			f"{self.settings.nextcloud_url}/ocs/v2.php/apps/spreed/api/v4/room/"
			f"{token}/participants/active"
		)
		try:
			resp = await page.request.post(
				url,
				headers={
					"OCS-APIRequest": "true",
					"Accept": "application/json",
				},
			)
			log.info(
				"Conversation session join HTTP %s for %s",
				resp.status,
				token,
			)
		except Exception as exc:
			log.warning("Conversation session join failed: %s", exc)
		await asyncio.sleep(1)

	async def _wait_for_talk_shell(self, page: Page) -> None:
		candidates = (
			"#content-vue",
			"#app-content-vue",
			".app-talk",
			"#call-container",
			"button.join-call",
			'button:has-text("Join call")',
			'button:has-text("Start call")',
			'button:has-text("Leave call")',
		)
		deadline = time.time() + 45
		while time.time() < deadline:
			for sel in candidates:
				loc = page.locator(sel).first
				try:
					if await loc.count() and await loc.is_visible(timeout=500):
						log.info("Talk UI ready (%s)", sel)
						return
				except Exception:
					continue
			await asyncio.sleep(1)
		log.warning("Talk shell did not become ready in time")

	async def _dismiss_common_modals(self, page: Page) -> None:
		# First-run / password / cookie style dialogs that block the call button.
		dismissers = (
			'button:has-text("Close")',
			'button:has-text("Dismiss")',
			'button:has-text("OK")',
			'button:has-text("Got it")',
			'[aria-label="Close"]',
		)
		for sel in dismissers:
			btn = page.locator(sel).first
			try:
				if await btn.count() and await btn.is_visible(timeout=400):
					await btn.click(timeout=2000)
					await asyncio.sleep(0.3)
			except Exception:
				continue

	async def _click_first_visible(self, page: Page, selectors: tuple[str, ...]) -> str | None:
		for sel in selectors:
			btn = page.locator(sel).first
			try:
				if not await btn.count():
					continue
				if not await btn.is_visible(timeout=1500):
					continue
				# Prefer enabled buttons
				disabled = await btn.get_attribute("disabled")
				aria_disabled = await btn.get_attribute("aria-disabled")
				if disabled is not None or aria_disabled == "true":
					log.info("Join candidate disabled: %s", sel)
					continue
				await btn.scroll_into_view_if_needed()
				await btn.click(timeout=5000, force=False)
				return sel
			except Exception as exc:
				log.debug("Click failed for %s: %s", sel, exc)
				continue
		# Last resort: role-based
		for name in ("Join call", "Start call", "Join"):
			try:
				btn = page.get_by_role("button", name=name).first
				if await btn.count() and await btn.is_visible(timeout=1000):
					await btn.click(timeout=5000)
					return f'role=button[name="{name}"]'
			except Exception:
				continue
		return None

	async def _try_join_call(self, page: Page) -> bool:
		"""Click Join/Start; Talk often opens media settings then needs a second click."""
		if await self._looks_in_call(page):
			return True

		await self._dismiss_common_modals(page)
		# Accept recording consent if shown
		for sel in (
			'label:has-text("recording")',
			'input[type="checkbox"]',
		):
			try:
				box = page.locator(sel).first
				if await box.count() and await box.is_visible(timeout=300):
					await box.click(timeout=1000)
			except Exception:
				pass

		clicked = await self._click_first_visible(page, JOIN_BUTTON_SELECTORS)
		if not clicked:
			log.warning("Could not find Join/Start call button")
			return False
		log.info("Clicked join via %s", clicked)
		await asyncio.sleep(1.5)

		if await self._looks_in_call(page):
			return True

		# Media settings / device preview: confirm Join again
		clicked2 = await self._click_first_visible(page, JOIN_BUTTON_SELECTORS)
		if clicked2:
			log.info("Confirmed join via %s (media settings)", clicked2)
			await asyncio.sleep(2)

		return await self._looks_in_call(page)

	async def _looks_in_call(self, page: Page) -> bool:
		try:
			for sel in LEAVE_BUTTON_SELECTORS:
				loc = page.locator(sel).first
				if await loc.count() and await loc.is_visible(timeout=400):
					return True
			for sel in IN_CALL_SELECTORS:
				loc = page.locator(sel).first
				if await loc.count() and await loc.is_visible(timeout=400):
					return True
			# Connecting state still counts as progress
			connecting = page.locator('button:has-text("Connecting")')
			if await connecting.count() and await connecting.is_visible(timeout=200):
				return True
			return False
		except Exception:
			return False

	async def _debug_snapshot(self, page: Page, label: str) -> None:
		try:
			path = self.workdir / f"{label}.png"
			await page.screenshot(path=str(path), full_page=True)
			buttons = await page.evaluate(
				"""() => Array.from(document.querySelectorAll('button'))
					.slice(0, 40)
					.map(b => ({
						text: (b.innerText || '').trim().slice(0, 80),
						aria: b.getAttribute('aria-label'),
						cls: (b.className || '').toString().slice(0, 80),
						disabled: b.disabled
					}))"""
			)
			log.info("Debug screenshot %s; sample buttons=%s", path, buttons)
		except Exception as exc:
			log.warning("Debug snapshot failed: %s", exc)

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
