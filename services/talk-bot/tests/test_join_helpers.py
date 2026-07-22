from talk_bot.join_helpers import (
	JOIN_BUTTON_SELECTORS,
	LEAVE_BUTTON_SELECTORS,
	build_call_url,
)


def test_build_call_url_direct():
	assert (
		build_call_url("https://cloud.example.com/", "abc123")
		== "https://cloud.example.com/call/abc123#direct-call"
	)


def test_build_call_url_plain():
	assert (
		build_call_url("https://cloud.example.com", "abc123", direct=False)
		== "https://cloud.example.com/call/abc123"
	)


def test_join_selectors_include_join_call_text():
	assert any("Join call" in s for s in JOIN_BUTTON_SELECTORS)
	assert any("Leave call" in s for s in LEAVE_BUTTON_SELECTORS)
