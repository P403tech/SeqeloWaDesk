# Seqelo Railway architecture

Saved **27 Sep 2026** from a live check of Railway project `discerning-spirit`, environment **production**, region **SFO**, Hobby plan.

**Do not implement the “when we have traffic” section until we have real load.** Correctness items (domains, APP_URL, queue worker, node-bridge persistence) can ship earlier if we choose.

Interactive copy: Cursor canvas `railway-architecture.canvas.tsx` in this workspace.

Do not paste secrets into this file. Env **keys** and non-secret **values** only.

---

## Current topology

```
Browsers  www.apps.seqelo.com
Meta / TikTok / Shopify webhooks
        |
        v
SeqeloWaDesk   php artisan serve :8080
  1 replica, SFO, no volume, GitHub P403tech/SeqeloWaDesk
        |
        +----> MySQL 9   mysql-volume  /var/lib/mysql
        |                  449 / 5000 MB, SFO, no public domain
        |
        +----> seqelo-media-sin   Railway Bucket (Singapore)
        |
        +----> node-bridge   public HTTPS, no volume, last deploy 22 Aug 2026
                 |
                 +---- WhatsApp (Baileys)
```

| Service | Runtime | Replicas | Persist | Public edge |
|---|---|---|---|---|
| SeqeloWaDesk | `php:8.2-cli`, `php artisan serve` | 1 × SFO | none (ephemeral) | `www.apps.seqelo.com`, `seqelowadesk-production.up.railway.app` |
| MySQL | `mysql:9`, innodb pool 1G, binlog off | 1 × SFO | `mysql-volume` | private |
| node-bridge | `node:22`, `node index.js` | 1 | none | `node-bridge-production-e950.up.railway.app` |
| seqelo-media-sin | Tigris / S3 | Singapore | object storage | S3 via `AWS_*` on Laravel |

### Laravel boot (`railway-start.sh`)

1. Map `MYSQLHOST` → `DB_*`
2. `php artisan migrate --force`
3. Restore install marker if users exist
4. `FlowTemplateSeeder` every start
5. `php artisan serve --host=0.0.0.0 --port=$PORT`

Health check: `GET /up`, timeout 300s, restart on failure (max 10). Image also installs Node 22 only to `npm run build` at image build, then drops `node_modules`.

### Non-secret production config (SeqeloWaDesk)

| Key | Value |
|---|---|
| APP_ENV | production |
| APP_DEBUG | false |
| APP_INSTALLED | true |
| APP_URL | `https://seqelowadesk-production.up.railway.app` (not the custom domain) |
| RAILWAY_PUBLIC_DOMAIN / RAILWAY_STATIC_URL | `www.apps.seqelo.com` |
| SERVER_URL | `https://node-bridge-production-e950.up.railway.app` |
| CACHE_STORE | database |
| SESSION_DRIVER | database |
| QUEUE_CONNECTION | database |
| DB_CONNECTION | mysql |
| LOG_CHANNEL | stderr |
| AWS_BUCKET | `seqelo-media-sin-9aou9lax` |
| AWS_DEFAULT_REGION | auto |

node-bridge: `APP_URL` / `APP_DOMAIN_NAME` still the Laravel `*.up.railway.app` host. `DOMAIN_NAME` is the bridge’s own Railway URL. CLI showed **no GitHub repo** on the service.

Apex `apps.seqelo.com` is not on this Railway service (Porkbun `pixie` SSL mismatch). Only `www` is attached, port 8080.

---

## Correctness (safe before traffic)

These are not scale work. They fix broken or fragile behaviour.

1. Set `APP_URL` (and bridge `APP_URL` / `APP_DOMAIN_NAME`) to `https://www.apps.seqelo.com`.
2. Add apex `apps.seqelo.com` on SeqeloWaDesk; point Porkbun ALIAS/ANAME at Railway, not `pixie.porkbun.com`.
3. Run a **queue worker** (`php artisan queue:work`). Jobs sit in MySQL with nobody consuming them.
4. Volume on node-bridge for Baileys session files; re-link the service to GitHub (`WaDesk/node` root).
5. Call the bridge over Railway private DNS instead of the public URL when ready.
6. Stop or gate `FlowTemplateSeeder` on every boot.

Keep uploads on the bucket. Do **not** add a volume on SeqeloWaDesk for media.

---

## When we have traffic

Implement in this order. Stay on **one Laravel replica** until Redis (or equivalent) is live.

1. **nginx + php-fpm** (or Caddy) instead of `php artisan serve`. Artisan serve is single-threaded; one slow AI/Meta call blocks login and inbox.
2. **Redis** plugin: `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`. Move jobs off MySQL.
3. Dedicated **queue worker** service from the same image (if not already added in correctness).
4. **Colocate media** with compute: SFO bucket, or move SeqeloWaDesk + MySQL to Singapore.
5. Replicas / Hobby → paid only after Redis sessions and a real healthcheck on the bridge.
6. MySQL: keep binlog off on a single instance; enable only if adding DB replicas. Watch `mysql-volume` toward 5 GB.

Target shape:

```
www + apex
    -> SeqeloWaDesk web (nginx + php-fpm)
    -> Queue worker (artisan queue:work)
    -> Redis (cache, session, queue)
    -> MySQL 9 (app data only, volume)
    -> Bucket same region as compute
    -> node-bridge private DNS + session volume + GitHub deploys + healthcheck
```

---

## Files that define today’s deploy

- `Dockerfile` — `php:8.2-cli-bookworm`
- `railway.toml` — Dockerfile build, `sh railway-start.sh`, `/up`
- `railway-start.sh` — migrate then artisan serve
- `node/Dockerfile`, `node/railway.toml` — `node index.js`, no healthcheck
