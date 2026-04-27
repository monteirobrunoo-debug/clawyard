FROM python:3.12-slim

WORKDIR /srv/hp-history

# Build deps for asyncpg + pypdf only.
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential libpq-dev curl ca-certificates \
 && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app
COPY scripts ./scripts

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1

EXPOSE 8088

# uvicorn directly — no gunicorn worker pool. The app is I/O bound;
# more processes than CPU cores hurt latency. Scale with the droplet
# vertically instead of forking.
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8088", "--proxy-headers", "--forwarded-allow-ips", "*"]
