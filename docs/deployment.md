# Deployment

The backend is the only component that needs hosting. The WordPress plugin installs into an existing site, and the Forma extension is static files.

## What the backend needs

| Requirement | Why |
|---|---|
| A stable HTTPS origin | `APS_CALLBACK_URL` must match the callback registered with Autodesk **exactly**. A changing URL breaks sign-in. |
| Outbound HTTPS | To reach Autodesk and your WordPress site. |
| A persistent secret | `ENCRYPTION_KEY` must survive restarts, or every stored Autodesk session is lost. |
| PostgreSQL, if scaling | Only needed to run more than one instance. |

It does **not** need inbound access from the public internet unless you want the Autodesk sign-in flow to be reachable from a browser — which you normally do, at least once.

## Docker Compose

The quickest complete setup, including PostgreSQL:

```bash
cd backend
cp .env.example .env      # fill in APS and WordPress values
docker compose up -d --build
```

`DATABASE_URL` is set by the compose file and overrides the one in `.env`. Set `POSTGRES_PASSWORD` in your shell or an `.env` file rather than accepting the default.

Check it:

```bash
curl -fsS localhost:3000/health
curl -fsS localhost:3000/ready
```

`/health` answers only "is the process up?". `/ready` additionally checks storage and whether an Autodesk session exists, and returns `503` when it cannot publish — that is the one to point an orchestrator at.

## Docker without Compose

```bash
docker build -t forma-backend ./backend
docker run -d --name forma-backend \
  -p 3000:3000 \
  --env-file backend/.env \
  -v forma-data:/app/data \
  forma-backend
```

The image runs as the unprivileged `node` user and uses `tini` as PID 1, so `SIGTERM` reaches the process and the graceful shutdown handler runs. Mount a volume at `/app/data` when not using PostgreSQL — that directory holds encrypted tokens and job history.

## Without containers

```bash
cd backend
npm ci --omit=dev
npm run build
node dist/index.js
```

A systemd unit:

```ini
[Unit]
Description=Forma Publisher backend
After=network-online.target

[Service]
Type=simple
User=forma
WorkingDirectory=/opt/forma/backend
EnvironmentFile=/opt/forma/backend/.env
ExecStart=/usr/bin/node dist/index.js
Restart=on-failure
RestartSec=5

# The service needs no write access beyond its own data directory.
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/opt/forma/backend/data

[Install]
WantedBy=multi-user.target
```

## Reverse proxy

Terminate TLS in front of the service. The example below also fixes the one thing that will silently weaken security if you get it wrong: the client address.

```nginx
server {
	listen 443 ssl http2;
	server_name forma.example.com;

	ssl_certificate     /etc/letsencrypt/live/forma.example.com/fullchain.pem;
	ssl_certificate_key /etc/letsencrypt/live/forma.example.com/privkey.pem;

	location / {
		proxy_pass         http://127.0.0.1:3000;
		proxy_http_version 1.1;
		proxy_set_header   Host              $host;
		proxy_set_header   X-Real-IP         $remote_addr;
		proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
		proxy_set_header   X-Forwarded-Proto $scheme;
	}

	# Metrics require the extension API key, but there is no reason to expose
	# them publicly as well.
	location /metrics {
		allow 10.0.0.0/8;
		deny  all;
		proxy_pass http://127.0.0.1:3000;
	}
}
```

**On the WordPress side**, the unverified-request limiter uses `REMOTE_ADDR` only, because forwarded-for headers are attacker controlled unless a trusted proxy rewrites them. If WordPress sits behind a proxy, configure the proxy or the host to set `REMOTE_ADDR` correctly, and apply per-IP limits at the proxy as well. Without that, every request appears to come from one address and the limiter loses its value.

## Autodesk application setup

1. Create an application in the Autodesk Platform Services developer portal.
2. Register the callback URL, exactly matching `APS_CALLBACK_URL`, for example `https://forma.example.com/auth/callback`.
3. Grant the scopes the service requests: `data:read` and `account:read`. It never writes.
4. Copy the client ID and secret into `.env`.
5. Visit `https://forma.example.com/auth/login` once and complete sign-in. Tokens are stored encrypted and refreshed automatically.

## Confirming the deployment

```bash
cd backend && npm run verify
```

This exercises configuration, the Autodesk session, hub and project access, canonical payload assembly and a signed round trip to WordPress, then reports which stage works. It publishes nothing. Run it after any configuration change — it is faster than discovering a problem at the first publish.

## Monitoring

`GET /metrics` returns Prometheus text and requires the `x-api-key` header.

| Metric | Use |
|---|---|
| `forma_seconds_since_last_success` | The most useful alert. `-1` means nothing has ever succeeded. |
| `forma_publish_jobs{status="failed"}` | Failures in recent history. |
| `forma_publish_queue_depth` | Sustained growth means jobs are not draining. |
| `forma_published_projects` | Projects currently recorded as published. |
| `forma_sync_tracked_projects` | How many are tracked for scheduled refresh. |

A reasonable first alert: `forma_seconds_since_last_success > 86400` on a site that publishes daily, paired with `forma_publish_jobs{status="failed"} > 0`.

Logs are structured JSON on stdout and stderr, with any key resembling a secret, token, password or signature redacted before output.

## Upgrading

Roll out the **WordPress plugin first**, then the backend. The canonical schema rejects unknown properties, so a newer backend sending a new field to an older plugin is refused. This order avoids that window.

## Backups

| Storage | What to back up |
|---|---|
| JSON file store | The `DATA_DIR` directory. **It contains secrets** — protect the backup at least as well as the service. |
| PostgreSQL | The `forma_documents` table, or the whole database. |

Also keep `ENCRYPTION_KEY` somewhere durable and separate. Restoring a data directory without it leaves every stored Autodesk token unreadable, and everyone has to sign in again.

Published WordPress content is not part of this. It lives in WordPress and is covered by your normal site backups.
