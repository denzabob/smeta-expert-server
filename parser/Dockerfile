FROM python:3.11-slim

# ==================== ANTI-LOOP NOTICE ====================
# This container does NOT auto-start parsing.
# Parser is started ONLY via explicit API request:
#   POST /api/parsing/sessions
# 
# Container provides the parser runtime but does NOT execute
# parsing on startup. Laravel queue worker dispatches jobs
# that invoke the parser when needed.
# ==========================================================

WORKDIR /app

RUN apt-get update && \
    apt-get install -y \
        wget \
        xvfb \
        libgtk-3-0 \
        libnss3 \
        libxss1 \
        libasound2 \
        libxt6 \
        libx11-xcb1 \
        fonts-liberation \
        libgl1 && \
    rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

RUN python -m playwright install chromium

COPY . .

# NO CMD that starts parsing - container is invoked by Laravel Job
# Default CMD does nothing (keeps container ready for exec)
CMD ["tail", "-f", "/dev/null"]