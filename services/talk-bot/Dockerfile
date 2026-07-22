FROM mcr.microsoft.com/playwright/python:v1.49.1-jammy

WORKDIR /app

ENV PYTHONUNBUFFERED=1 \
	HEADLESS=1 \
	POLL_INTERVAL_SEC=10 \
	NOTES_FOLDER="/Talk transcripts" \
	TRANSCRIBE_BACKEND=local \
	WHISPER_MODEL=small \
	WHISPER_DEVICE=cpu \
	WHISPER_COMPUTE_TYPE=int8 \
	HF_HOME=/app/.cache/huggingface \
	XDG_CACHE_HOME=/app/.cache

COPY requirements.txt pyproject.toml ./
COPY talk_bot ./talk_bot

RUN pip install --no-cache-dir -r requirements.txt \
	&& pip install --no-cache-dir -e . \
	&& playwright install chromium \
	&& mkdir -p /app/.cache \
	&& python -c "from faster_whisper import WhisperModel; WhisperModel('small', device='cpu', compute_type='int8')" \
	&& chown -R pwuser:pwuser /app

# Playwright base image already has browser deps.
USER pwuser

CMD ["python", "-m", "talk_bot"]
