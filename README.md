# Whiteboard AI

Nextcloud app that adds an OpenRouter-powered AI chat panel to the official [Whiteboard](https://github.com/nextcloud/whiteboard) viewer, plus an optional **Talk join-bot** that transcribes calls and uploads `.docx` summaries.

One shared OpenRouter API key is configured by an admin and used by all whiteboard users (and by the Talk bot via env).

## Features

### Whiteboard AI
- Floating chat panel on `.whiteboard` files
- Multi-turn history (kept in the browser `sessionStorage`)
- Live scene context from IndexedDB
- Copies Excalidraw clipboard JSON — **press Ctrl+V / Cmd+V** on the board (download fallback if clipboard is blocked)

### Talk transcription bot (Docker)
- Dedicated bot user joins active Talk calls via Playwright/Chromium
- Captures remote WebRTC audio, **transcribes locally with Whisper `small`** (CPU), summarizes via OpenRouter
- Uploads a `.docx` (transcript + AI summary) to **`/Talk transcripts/`** via WebDAV

## Requirements

- Nextcloud **31–33**
- Official **whiteboard** app (for the panel)
- Official **spreed** (Talk) app (for the bot)
- An [OpenRouter](https://openrouter.ai/) API key

## Setup — Whiteboard app

1. Install into `custom_apps/whiteboardai` and enable:

```bash
occ app:enable whiteboard
occ app:enable whiteboardai
```

2. **Administration settings → Whiteboard AI**: set OpenRouter API key + model → **Save key & model**.

3. Open a whiteboard file → **Whiteboard AI** → chat. After each reply, focus the canvas and paste (`Ctrl+V` / `Cmd+V`).

### OCC (optional)

```bash
occ config:app:set whiteboardai openrouter_api_key --value="sk-or-..."
occ config:app:set whiteboardai openrouter_model --value="openai/gpt-4o-mini"
```

## Setup — Talk bot container

1. Create a Nextcloud user (e.g. `talkbot`) and an **app password**.
2. Add that user to each Talk conversation you want transcribed.
3. Publish or build the image, then merge compose:

```bash
# from this repo (or pull from GHCR after CI)
docker compose -f docker-compose.yml -f docker-compose.talk-bot.yml up -d
```

Example `.env` next to your compose files:

```env
NEXTCLOUD_URL=https://cloud.example.com
BOT_USER=talkbot
BOT_PASSWORD=xxxx-xxxx-xxxx-xxxx
OPENROUTER_API_KEY=sk-or-v1-...
OPENROUTER_MODEL=openai/gpt-4o-mini
TRANSCRIBE_BACKEND=local
WHISPER_MODEL=small
NOTES_FOLDER=/Talk transcripts
```

Set `TRANSCRIBE_BACKEND=openrouter` if you prefer cloud STT instead of local Whisper.
Image (after GitHub Actions runs on `main`):

`ghcr.io/<your-github-user-or-org>/whiteboardai-talk-bot:latest`

Local build:

```bash
docker build -t whiteboardai-talk-bot ./services/talk-bot
```

The bot needs outbound HTTPS to your Nextcloud URL and to `openrouter.ai` (for summaries). Use `shm_size: 2gb` (already in compose) for Chromium. On Ampere A1 / ARM, build the image on the server so you get `linux/arm64`.

## Development

```bash
pnpm install
pnpm run build
pnpm run lint

# Talk bot unit tests
cd services/talk-bot && pip install -r requirements.txt && pytest
```

## API

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api` or `/api/status` | Public status |
| `POST` | `/api/generate` | `{ prompt, history?, scene?, modeHint? }` → `{ mode, assistantMessage, clipboard }` |
| `PUT` | `/api/settings` | Admin settings |

## Security notes

- OpenRouter key stays server-side for the PHP app; the Talk bot receives it only via container env
- Generation is rate-limited per user in the PHP app
- Element payloads are sanitized before being returned to the client
