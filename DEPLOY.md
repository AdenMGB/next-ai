# Deploy guide — Whiteboard AI + Talk bot

This covers:

1. The **Nextcloud PHP app** (`whiteboardai`) — AI chat on whiteboards  
2. The **Talk join-bot** Docker service — call transcription → `.docx` in Files  

---

## Prerequisites

| Piece | Notes |
|--------|--------|
| Nextcloud **31–33** | Matches `appinfo/info.xml` |
| **whiteboard** app | Official Nextcloud Whiteboard |
| **spreed** (Talk) | Only needed for the Talk bot |
| OpenRouter API key | [openrouter.ai](https://openrouter.ai/) |
| Apps directory mapped into Docker | e.g. `./custom_apps:/var/www/html/custom_apps` |

---

## Part 1 — Install the Nextcloud app

### 1. Build (on your PC)

```powershell
cd C:\Users\Aden\Documents\whiteboardai
pnpm install
pnpm run build
```

Or use the ready zip: `C:\Users\Aden\Documents\whiteboardai-deploy.zip`

### 2. Copy into the mapped apps dir

Folder name must be exactly **`whiteboardai`**.

**SCP example:**

```bash
scp whiteboardai-deploy.zip user@server:/tmp/
ssh user@server
cd /path/to/nextcloud/custom_apps   # your mapped volume
unzip -o /tmp/whiteboardai-deploy.zip
# creates ./whiteboardai/
```

**Windows robocopy** (if the volume is local):

```powershell
robocopy C:\Users\Aden\Documents\whiteboardai D:\nextcloud\custom_apps\whiteboardai /E /XD node_modules .git vendor-bin tests .github services\talk-bot\.venv
```

You need at least: `appinfo/`, `lib/`, `templates/`, `img/`, `js/`, `css/`.

### 3. Fix ownership (Docker)

```bash
docker exec -u root <nextcloud-container> chown -R www-data:www-data /var/www/html/custom_apps/whiteboardai
```

### 4. Enable apps

```bash
docker exec -u www-data <nextcloud-container> php occ app:enable whiteboard
docker exec -u www-data <nextcloud-container> php occ app:enable whiteboardai
```

Or: **Apps** in the Nextcloud UI → enable **Whiteboard** and **White Board A I**.

### 5. Configure OpenRouter (admin)

**Administration settings → Whiteboard AI**

- OpenRouter API key  
- OpenRouter model (e.g. `openai/gpt-4o-mini`)  
- **Save key & model**

Or via OCC:

```bash
docker exec -u www-data <nextcloud-container> php occ config:app:set whiteboardai openrouter_api_key --value="sk-or-v1-..."
docker exec -u www-data <nextcloud-container> php occ config:app:set whiteboardai openrouter_model --value="openai/gpt-4o-mini"
```

### 6. Use it

1. Open a `.whiteboard` file in Files  
2. Click **Whiteboard AI**  
3. Chat as usual  
4. When shapes are ready, focus the canvas and press **Ctrl+V** / **Cmd+V**

---

## Part 2 — Talk transcription bot (Docker)

The bot is a **separate container**. It logs in as a Nextcloud user, joins active Talk calls, **transcribes locally with Whisper `small`** (CPU), summarizes via OpenRouter, and uploads a `.docx` to **`/Talk transcripts/`**.

### 1. Create a bot user

1. Nextcloud → Users → create e.g. `talkbot`  
2. Security → create an **app password** for that user  
3. In each Talk conversation you want transcribed, **add `talkbot` as a participant**

### 2. Image

**Option A — GHCR** (after you push the repo and CI runs on `main`):

```text
ghcr.io/<your-github-username>/whiteboardai-talk-bot:latest
```

Update `image:` in `docker-compose.talk-bot.yml` if your GitHub owner is not `adenmgb`.

**Option B — build locally on the server:**

```bash
cd /path/to/whiteboardai
docker build -t whiteboardai-talk-bot ./services/talk-bot
```

Then set in compose:

```yaml
image: whiteboardai-talk-bot
```

(or keep `build:` as in the shipped compose file).

### 3. Env file

Next to your Nextcloud compose, create/extend `.env`:

```env
NEXTCLOUD_URL=https://cloud.example.com
BOT_USER=talkbot
BOT_PASSWORD=xxxx-xxxx-xxxx-xxxx
OPENROUTER_API_KEY=sk-or-v1-...
OPENROUTER_MODEL=openai/gpt-4o-mini
TRANSCRIBE_BACKEND=local
WHISPER_MODEL=small
WHISPER_DEVICE=cpu
WHISPER_COMPUTE_TYPE=int8
NOTES_FOLDER=/Talk transcripts
POLL_INTERVAL_SEC=10
```

- **Transcription** defaults to local `faster-whisper` (`WHISPER_MODEL=small`). Audio stays on your server.
- **Summaries** still use OpenRouter (`OPENROUTER_API_KEY` + `OPENROUTER_MODEL`).
- To use cloud STT instead: `TRANSCRIBE_BACKEND=openrouter` and optionally `OPENROUTER_TRANSCRIBE_MODEL=openai/whisper-1`.
- On Ampere A1 / ARM, build the image on the server (`docker build ...`) so you get `linux/arm64`.

`NEXTCLOUD_URL` must be reachable **from inside the bot container** (public HTTPS URL, or internal Docker DNS like `http://nextcloud`).

### 4. Start with your Nextcloud stack

Copy `docker-compose.talk-bot.yml` next to your main compose (or reference the path in the repo):

```bash
docker compose -f docker-compose.yml -f docker-compose.talk-bot.yml up -d
```

Check logs:

```bash
docker compose -f docker-compose.yml -f docker-compose.talk-bot.yml logs -f whiteboardai-talk-bot
```

### 5. Network tips

If Nextcloud is only on an internal compose network:

```yaml
# in docker-compose.talk-bot.yml under whiteboardai-talk-bot:
networks:
  - nextcloud_network   # use the same network name as your nextcloud service
environment:
  NEXTCLOUD_URL: http://nextcloud   # service name, not public URL
```

Chromium needs enough shared memory — compose already sets `shm_size: "2gb"`.

### 6. Use it

1. Start a call in a Talk room that includes `talkbot`  
2. Bot joins (muted / silent A/V)  
3. When the call ends, a file appears under the bot user’s Files:  
   **`/Talk transcripts/<room>-YYYYMMDD-HHMM.docx`**  
4. Share that folder or move files as needed  

---

## Part 3 — GitHub Actions (bot image)

Workflow: [`.github/workflows/talk-bot-image.yml`](.github/workflows/talk-bot-image.yml)

On push to `main` (when `services/talk-bot/**` changes) or tag `talk-bot-v*`:

- Builds `services/talk-bot/Dockerfile`  
- Pushes to `ghcr.io/<repository_owner>/whiteboardai-talk-bot`

After the first successful run:

```bash
docker pull ghcr.io/<owner>/whiteboardai-talk-bot:latest
```

If the package is private, log in:

```bash
echo $GITHUB_TOKEN | docker login ghcr.io -u USERNAME --password-stdin
```

---

## Updating

### App only

```powershell
pnpm run build
# re-copy js/, css/, lib/, appinfo/ (or re-upload zip)
docker exec -u www-data <nc> php occ maintenance:repair
# hard-refresh browser
```

### Talk bot only

```bash
docker compose -f docker-compose.yml -f docker-compose.talk-bot.yml pull
docker compose -f docker-compose.yml -f docker-compose.talk-bot.yml up -d whiteboardai-talk-bot
```

---

## Troubleshooting

| Symptom | Check |
|---------|--------|
| App missing in Apps list | Folder name `whiteboardai`, ownership `www-data`, correct apps path |
| AI panel missing | Whiteboard file open in Viewer; `whiteboardai` enabled; rebuild `js/` |
| “API key not configured” | Admin → Whiteboard AI; save key |
| Paste does nothing | Focus Excalidraw canvas, then Ctrl+V; or use downloaded `.excalidraw` |
| Bot never joins | `talkbot` in the room; call actually started; logs show poll; URL reachable |
| Bot can’t login | App password (not account password); SSO may need extra setup |
| Empty transcript | Mic/WebRTC capture limited without HPB; check bot logs for transcription errors |
| DOCX missing | WebDAV permissions for bot user; folder `/Talk transcripts` |
| Chromium crash | Increase `shm_size`; don’t drop `--disable-dev-shm-usage` |

---

## Security checklist

- [ ] OpenRouter key only in Nextcloud appconfig / bot `.env` (never in git)  
- [ ] Talk bot uses an **app password**, restricted user  
- [ ] Bot only added to rooms that should be recorded  
- [ ] Participants know the room may be transcribed  
- [ ] GHCR package visibility set intentionally (public vs private)  
