# Savanna Roast Co. — Event Store (Phases 3 & 5)

First-party event storage, automated abandoned-cart recovery, and an
offline-to-online conversion bridge between HubSpot and TikTok's
Conversions API — extending the server-side tracking infrastructure built
in Phases 1-2. See the main project brief
(`savanna-roast-tracking-infrastructure-brief.md`) for full context.

**Live deployment:** `https://limbalabs.duckdns.org/events-api/`

## What this is

Two things live in this one Laravel app:

**Phase 3 — Event store + cart recovery**
Receives a copy of every tracking event (`view_item`, `add_to_cart`,
`purchase`) from the existing server-side GTM container, stores it in a
first-party database, and automatically checks every 15 minutes for
abandoned carts — firing a recovery trigger (log + optional webhook) when
found. This is a parallel destination alongside GA4 and TikTok, not a
replacement for either.

**Phase 5 — HubSpot → TikTok offline conversion bridge**
Every 15 minutes, polls HubSpot's API for deals that just moved to Closed
Won, looks up the `tiktok_click_id` stored on the associated Contact
(captured at initial form submission via the Limba Labs landing page), and
fires a delayed `CompletePayment` event to TikTok's Conversions API with the
real, realized deal value. This is what lets ad platforms learn from actual
closed revenue instead of just lead-form fills — solving the same "offline
conversions are invisible to ad platforms" problem for Limba Labs' own
pipeline as a real, working example.

## Current live setup (as actually deployed)

- **Location:** `/var/www/savanna-roast-event-store` on the EC2 instance
  (moved here from `/home/ubuntu/` after discovering Nginx can't traverse
  into another user's home directory regardless of file permissions)
- **Ownership:** `ubuntu:www-data`, `775` on `storage/`, `bootstrap/cache/`,
  `database/` — shared so both Nginx/PHP-FPM and manual `artisan` commands
  can write without permission conflicts
- **Web server:** Nginx + PHP-FPM (`php8.5-fpm`), served at
  `https://limbalabs.duckdns.org/events-api/` via a path-based location
  block (see `nginx-config-reference.conf` below for the exact working
  config, since Laravel's routing inside a subpath needed specific
  `SCRIPT_FILENAME`/`SCRIPT_NAME`/`PATH_INFO` handling — this took a few
  iterations to get right)
- **Database:** SQLite (`database/database.sqlite`)
- **Scheduler:** real cron (`* * * * * cd .../event-store && php artisan
  schedule:run`) driving Laravel's own scheduler, which runs both
  `carts:detect-abandoned` and `hubspot:sync-closed-deals` every 15 minutes

### Reference Nginx config (path-based subdirectory serving)

```nginx
location /events-api {
    rewrite ^/events-api$ /events-api/ permanent;
}

location /events-api/ {
    alias /var/www/savanna-roast-event-store/public/;
    index index.php;
    try_files $uri $uri/ @events_api_fallback;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.5-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_param SCRIPT_FILENAME $request_filename;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }
}

location @events_api_fallback {
    rewrite ^/events-api/(.*)$ /events-api/index.php?/$1 last;
}
```

## Local setup (fresh clone / new machine)

```bash
git clone <this-repo-url> event-store
cd event-store
composer install
cp .env.example .env
php artisan key:generate
```

In `.env`, set:
```
DB_CONNECTION=sqlite
```
(remove/comment the other `DB_*` lines)

Add these (see **Credentials needed** below for where each comes from):
```
HUBSPOT_SERVICE_KEY=
TIKTOK_PIXEL_ID=
TIKTOK_ACCESS_TOKEN=
RECOVERY_WEBHOOK_URL=   # optional
```

In `config/services.php`, confirm these blocks exist (add if not):
```php
'hubspot' => [
    'service_key' => env('HUBSPOT_SERVICE_KEY'),
],
'tiktok' => [
    'pixel_id' => env('TIKTOK_PIXEL_ID'),
    'access_token' => env('TIKTOK_ACCESS_TOKEN'),
],
'recovery_webhook' => [
    'url' => env('RECOVERY_WEBHOOK_URL'),
],
```

Then:
```bash
touch database/database.sqlite
php artisan migrate
php artisan serve --host=0.0.0.0 --port=8000
```

Visit `http://localhost:8000/dashboard`.

## Credentials needed

| Variable | Where to get it |
|---|---|
| `HUBSPOT_SERVICE_KEY` | HubSpot → Settings → Development → Keys → Service keys. Scopes needed: `crm.objects.deals.read`, `crm.objects.contacts.read`. **Not** the legacy Private Apps path — HubSpot is deprecating that; use Service Keys. |
| `TIKTOK_PIXEL_ID` / `TIKTOK_ACCESS_TOKEN` | TikTok Ads Manager → Events Manager → your Pixel → Settings → Conversions API. Same credentials already used in the GTM server-side TikTok tags (Phase 2). |
| `RECOVERY_WEBHOOK_URL` | Optional. Any webhook-accepting URL (Zapier, Make, a real automation platform) if you want the cart-abandonment trigger to actually POST somewhere instead of only logging. |

## Testing each piece

**Event ingestion:**
```bash
curl -X POST http://localhost:8000/api/events \
  -H "Content-Type: application/json" \
  -d '{
    "event_id": "test-001",
    "event_type": "add_to_cart",
    "session_id": "session-abc",
    "product_id": "SR-001",
    "value": 185.00,
    "currency": "USD"
  }'
```
(`event_time` is optional — defaults to server receipt time if omitted,
since server-side GTM's variable picker has no reliable timestamp source.)

**Abandoned cart detection:**
```bash
php artisan carts:detect-abandoned --minutes=0
```
(`--minutes=0` forces any existing `add_to_cart` to count as abandoned
immediately, for testing without waiting.)

**HubSpot → TikTok sync:**
```bash
php artisan hubspot:sync-closed-deals
```
Requires a HubSpot Deal in "Closed Won" stage, associated with a Contact
that has `tiktok_click_id` set. Check `storage/logs/laravel.log` for the
`TikTok offline conversion sent` entry to confirm TikTok's API response
(`"code": 0` = accepted).

Confirm both are registered on schedule:
```bash
php artisan schedule:list
```

## Wiring into GTM (Phase 3, Session 2)

In `limbashop-server` (the server-side GTM container), an **HTTP Request**
tag (built-in type) fires alongside the existing GA4/TikTok tags:

- Method: POST
- URL: `https://limbalabs.duckdns.org/events-api/api/events`
- Header: `Content-Type: application/json`
- Body (**must be built via GTM's variable-picker autocomplete, not typed
  manually** — typed `{{Variable Name}}` text does not reliably resolve in
  this tag type's raw JSON editor):
  ```json
  {
    "event_id": "{{Event Data - event_id}}",
    "event_type": "{{Event Name}}",
    "session_id": "{{Event Data - client_id}}",
    "product_id": "SR-001",
    "value": {{Event Data - value}},
    "currency": "{{Event Data - currency}}"
  }
  ```
- Trigger: same `add_to_cart` / `purchase` triggers already firing GA4/TikTok

## Wiring into HubSpot + the landing page (Phase 5)

1. HubSpot custom Contact property: `TikTok Click ID`
   (`tiktok_click_id`, single-line text)
2. Landing page (`limbalabs.duckdns.org/agency/`) captures `?ttclid=` from
   the URL and rewrites it into `?tiktok_click_id=` before HubSpot's
   embedded form loads — HubSpot's iframe form reads the parent page's URL
   query string and auto-fills any hidden field whose internal name
   matches a query parameter name
3. **Domain whitelisting required:** HubSpot flags form submissions from
   unrecognized domains as spam by default. Add your domain under
   Settings → Tracking Code → Advanced Tracking → Additional site domains
4. `hubspot:sync-closed-deals` polls for Closed Won deals every 15 minutes
   and completes the loop

## Deployment (fresh EC2 / new server)

```bash
git clone <this-repo-url> /var/www/savanna-roast-event-store
cd /var/www/savanna-roast-event-store
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
# set DB_CONNECTION=sqlite and the credentials above in .env
touch database/database.sqlite
php artisan migrate --force

sudo chown -R ubuntu:www-data /var/www/savanna-roast-event-store
sudo chmod -R 775 storage bootstrap/cache database
sudo usermod -a -G www-data ubuntu   # then re-login or `newgrp www-data`
```

Add the Nginx config block from above, `sudo nginx -t && sudo systemctl
reload nginx`.

Register the real cron entry:
```bash
crontab -e
```
```
* * * * * cd /var/www/savanna-roast-event-store && php artisan schedule:run >> /dev/null 2>&1
```

## Git push authentication note

GitHub no longer accepts account passwords for git operations. Use a
Personal Access Token instead (GitHub → Settings → Developer settings →
Personal access tokens → Tokens (classic), `repo` scope), and use it in
place of your password when prompted. To avoid re-entering it every push:
```bash
git config --global credential.helper store
```
Also avoid `sudo` on git commands in this project — it can create
file-ownership mismatches with the `ubuntu:www-data` setup above.
