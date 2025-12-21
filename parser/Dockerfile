FROM python:3.11-slim

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

CMD ["python", "main.py"]