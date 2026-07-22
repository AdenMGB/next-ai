from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Any

import httpx

from .settings import Settings

log = logging.getLogger(__name__)


@dataclass
class ActiveCall:
	token: str
	name: str
	participant_count: int


class TalkMonitor:
	def __init__(self, settings: Settings) -> None:
		self.settings = settings
		self._auth = (settings.bot_user, settings.bot_password)

	def _headers(self) -> dict[str, str]:
		return {
			"OCS-APIRequest": "true",
			"Accept": "application/json",
		}

	def list_active_calls(self) -> list[ActiveCall]:
		url = f"{self.settings.nextcloud_url}/ocs/v2.php/apps/spreed/api/v4/room"
		with httpx.Client(timeout=30.0, verify=True) as client:
			resp = client.get(url, auth=self._auth, headers=self._headers())
			resp.raise_for_status()
			payload = resp.json()
		rooms = payload.get("ocs", {}).get("data", [])
		active: list[ActiveCall] = []
		for room in rooms:
			if not isinstance(room, dict):
				continue
			# activeGuests / participantFlags / callFlag vary by Talk version
			token = str(room.get("token") or "")
			name = str(room.get("displayName") or room.get("name") or token)
			call_flag = int(room.get("callFlag") or 0)
			has_call = call_flag > 0 or bool(room.get("hasCall"))
			participant_count = int(room.get("participantCount") or 0)
			if token and has_call:
				active.append(
					ActiveCall(token=token, name=name, participant_count=participant_count)
				)
		log.info("Found %s active call(s)", len(active))
		return active

	def call_url(self, token: str) -> str:
		from .join_helpers import build_call_url

		return build_call_url(self.settings.nextcloud_url, token, direct=True)

	def participants(self, token: str) -> list[dict[str, Any]]:
		url = (
			f"{self.settings.nextcloud_url}/ocs/v2.php/apps/spreed/api/v4/room/"
			f"{token}/participants"
		)
		with httpx.Client(timeout=30.0, verify=True) as client:
			resp = client.get(url, auth=self._auth, headers=self._headers())
			if resp.status_code >= 400:
				return []
			payload = resp.json()
		data = payload.get("ocs", {}).get("data", [])
		return data if isinstance(data, list) else []
