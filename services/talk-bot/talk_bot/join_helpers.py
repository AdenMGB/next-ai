"""Helpers for Nextcloud Talk call URLs and UI selectors."""

from __future__ import annotations

# Prefer specific Talk classes / roles; text matches cover older UIs.
JOIN_BUTTON_SELECTORS: tuple[str, ...] = (
	"button.join-call",
	'button.button-vue--success:has-text("Join call")',
	'button.button-vue--success:has-text("Start call")',
	'button:has-text("Join call")',
	'button:has-text("Start call")',
	'button:has-text("Join")',
	'[aria-label="Join call"]',
	'[aria-label="Start call"]',
	'[data-testid="call-button"]',
)

LEAVE_BUTTON_SELECTORS: tuple[str, ...] = (
	"button.leave-call",
	'button:has-text("Leave call")',
	'[aria-label="Leave call"]',
	'[aria-label*="Leave call"]',
)

IN_CALL_SELECTORS: tuple[str, ...] = (
	".call-button .leave-call",
	"#call-container",
	".call-view",
	".localCallParticipantModel",
	"div[data-peer-id]",
)


def build_call_url(nextcloud_url: str, token: str, *, direct: bool = True) -> str:
	base = nextcloud_url.rstrip("/")
	url = f"{base}/call/{token}"
	if direct:
		return f"{url}#direct-call"
	return url
