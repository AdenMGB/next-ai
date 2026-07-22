FROM mcr.microsoft.com/playwright/python:v1.49.1-jammy

WORKDIR /app

ENV PYTHONUNBUFFERED=1 \
	HEADLESS=1 \
	POLL_INTERVAL_SEC=10 \
	NOTES_FOLDER=/Talk transcripts

COPY requirements.txt pyproject.toml ./
COPY talk_bot ./talk_bot

RUN pip install --no-cache-dir -r requirements.txt \
	&& pip install --no-cache-dir -e . \
	&& playwright install chromium

# Playwright base image already has browser deps.
USER pwuser

CMD ["python", "-m", "talk_bot"]
