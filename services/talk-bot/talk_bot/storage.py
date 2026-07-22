from __future__ import annotations

import logging
from urllib.parse import quote

import httpx

from .settings import Settings

log = logging.getLogger(__name__)


class WebDavStorage:
	def __init__(self, settings: Settings) -> None:
		self.settings = settings
		self._auth = (settings.bot_user, settings.bot_password)

	def _dav_base(self) -> str:
		user = quote(self.settings.bot_user)
		return f"{self.settings.nextcloud_url}/remote.php/dav/files/{user}"

	def ensure_folder(self, folder: str) -> None:
		# Create each path segment
		parts = [p for p in folder.strip("/").split("/") if p]
		path = ""
		with httpx.Client(timeout=60.0) as client:
			for part in parts:
				path += "/" + quote(part)
				url = self._dav_base() + path
				resp = client.request("MKCOL", url, auth=self._auth)
				# 201 created, 405 already exists
				if resp.status_code not in (201, 405, 409):
					log.debug("MKCOL %s -> %s", url, resp.status_code)

	def upload(self, folder: str, filename: str, content: bytes) -> str:
		self.ensure_folder(folder)
		remote = f"{folder.rstrip('/')}/{filename}".replace("//", "/")
		segments = [quote(p) for p in remote.strip("/").split("/")]
		url = self._dav_base() + "/" + "/".join(segments)
		with httpx.Client(timeout=120.0) as client:
			resp = client.put(
				url,
				auth=self._auth,
				content=content,
				headers={"Content-Type": "application/vnd.openxmlformats-officedocument.wordprocessingml.document"},
			)
			resp.raise_for_status()
		log.info("Uploaded %s", remote)
		return remote
