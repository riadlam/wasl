# Deploying Wasl

## Processes

Wasl needs three long-running processes besides the web server:

| Process | Config | Purpose |
| --- | --- | --- |
| Campaign workers | `supervisor/wasl-worker.conf` (`wasl-worker-campaigns`) | Draft, render and schedule AI campaign slots |
| General workers | `supervisor/wasl-worker.conf` (`wasl-worker-default`) | Queues `webhooks,social,ai,default`: webhooks, inbox replies, image jobs |
| Scheduler | `supervisor/wasl-scheduler.conf` | Runs `campaigns:dispatch-due` every 5 minutes and writes the health heartbeat |

```bash
sudo cp deploy/supervisor/*.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
```

Run exactly one scheduler. Workers can scale horizontally.

## Release steps

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link
npm ci && npm run build
php artisan config:cache && php artisan route:cache
php artisan queue:restart
```

`php artisan storage:link` is required so campaign / channel logos under `storage/app/public` are reachable at `/storage/...` (Telegram can still send files from disk without the link).

`queue:restart` makes workers pick up new code after the current job finishes.

## Environment

Copy the new keys from `.env.example`:

- `QUEUE_CONNECTION` must not be `sync` in production; campaigns rely on delayed retries.
- `CAMPAIGNS_LOOKAHEAD_MINUTES` (default 180): how far ahead each slot is written and queued on SocialAPI.
- `CAMPAIGNS_REDISPATCH_AFTER_MINUTES` (default 30): a dispatched slot still pending after this is queued again.
- `CAMPAIGNS_QUEUE` (default `campaigns`): must match the worker `--queue` list.
- `CAMPAIGNS_LOG_CHANNEL` (default `campaigns`): writes `storage/logs/campaigns-YYYY-MM-DD.log`.
- `WASL_MCP_RATE_PER_MINUTE` (default 120): rate limit per token on `POST /api/mcp`.
- `SOCAPI_MCP_ENABLED=true` and `SOCAPI_KEY` are required for campaigns to publish.

## Monitoring

`GET /api/health/queue` returns 200 when healthy and 503 with a `problems` list otherwise:

- `scheduler_stale`: no scheduler heartbeat for 5 minutes.
- `worker_backlog`: a job has waited over 15 minutes (database queue only).
- `campaign_slots_overdue`: an active campaign has a slot 10 minutes past its time that never ran.

Point an uptime monitor at it. Failed jobs are listed with `php artisan queue:failed`.

## Wasl MCP

External MCP clients call `POST /api/mcp` (JSON-RPC 2.0, protocol `2025-03-26`) with
`Authorization: Bearer wasl_...`. Tokens are created in Settings, Integrations, and are shown once.
Tokens only see read tools for the catalog, delivery, orders and campaigns; customer-facing
and mutating tools are never exposed externally.
