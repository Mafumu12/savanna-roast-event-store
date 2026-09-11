# Savanna Roast Co.: Event Store & Conversion Automation

A self-hosted backend that solves two problems most small e-commerce and service businesses have with their marketing data: incomplete ad tracking, and no automated way to recover lost sales or connect offline revenue back to the ads that generated it.

**Live deployment:** `https://limbalabs.duckdns.org/events-api/`

## The problem this solves

Ad platforms like TikTok only know what a business tells them. Two gaps show up constantly:

1. **Lost conversions.** Standard browser-based tracking scripts miss a meaningful share of real events due to ad blockers, browser privacy defaults, and network issues. The ad platform ends up optimizing on incomplete data, and it can't tell which of a business's ad campaigns are actually working.
2. **Invisible offline outcomes.** A customer clicks an ad, fills out a form, and the deal closes weeks later in a CRM. The ad platform never learns whether that click turned into real revenue, so it can't optimize toward the customers who actually convert, only toward people who filled out a form.

This project addresses both, using the same underlying data pipeline.

## What it actually does

This is a Laravel application with three connected capabilities:

**1. First-party event storage.** Every meaningful customer event (viewing a product, adding to cart, completing a purchase) is captured server-side and stored in a database this business owns outright, independent of any ad platform or analytics tool. This is the same data being sent to GA4 and TikTok, kept as a permanent, queryable record rather than disappearing into a third-party dashboard.

**2. Automated cart recovery.** A scheduled job checks every 15 minutes for carts that were started but never completed. When it finds one, it fires a recovery action (currently a log entry, with an optional webhook hook for connecting to a real email or SMS platform) so a lost sale gets a chance at recovery without a human needing to notice it manually.

**3. Offline conversion bridge (CRM to TikTok).** When a lead first arrives from a TikTok ad, the click identifier TikTok attaches to the URL is captured and stored against that contact in HubSpot. Weeks later, if that contact becomes a closed deal, a scheduled job detects the change in HubSpot, looks up the stored click identifier, and reports the real deal value back to TikTok as a conversion. This lets the ad platform's algorithm learn from actual revenue outcomes instead of just lead form fills.

## How the pieces connect

```
Website event (view, add to cart, purchase)
        |
        v
Server-side Google Tag Manager container
        |
        +---> Google Analytics 4
        +---> TikTok Conversions API
        +---> This application's /api/events endpoint
                    |
                    v
              Local event database
                    |
                    v
       Scheduled job checks every 15 minutes
       for carts with no matching purchase
                    |
                    v
       Recovery action triggered automatically


Separately:

HubSpot contact created (with TikTok click ID stored)
        |
        v
   Deal moves through the sales pipeline
        |
        v
   Deal marked Closed Won
        |
        v
Scheduled job detects this, looks up the stored
click ID, and reports the real deal value to
TikTok's Conversions API
```

The tracking pipeline (GA4, TikTok, event storage) runs independently of and alongside a business's existing analytics setup. Nothing about how GA4 or TikTok normally works is replaced or disrupted; this is an additional destination for the same event data.

## Current live setup

- **Location:** `/var/www/savanna-roast-event-store` on the production server (deliberately kept out of a user's home directory, since the web server process needs to be able to read it, and home directories are not readable by other system users by default)
- **Ownership:** shared between the deploying user and the web server's user/group, so both automated jobs and manual commands can write to logs and the database without permission conflicts
- **Web server:** Nginx and PHP-FPM, served at `https://limbalabs.duckdns.org/events-api/`
- **Database:** SQLite
- **Scheduler:** a real system cron entry runs Laravel's own scheduler every minute, which in turn runs the cart detection and HubSpot sync jobs on their own 15-minute cycles

### Nginx configuration reference

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

## Local setup

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
(remove or comment out the other `DB_*` lines)

Add these credentials (see below for where each comes from):
```
HUBSPOT_SERVICE_KEY=
TIKTOK_PIXEL_ID=
TIKTOK_ACCESS_TOKEN=
RECOVERY_WEBHOOK_URL=
```

In `config/services.php`, confirm these blocks exist:
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

Visit `http://localhost:8000/dashboard` to see the live event dashboard.

## Where credentials come from

| Variable | Source |
|---|---|
| `HUBSPOT_SERVICE_KEY` | HubSpot Settings, Development, Keys, Service keys. Required scopes: `crm.objects.deals.read`, `crm.objects.contacts.read`. This uses HubSpot's newer Service Keys system rather than the older Private Apps flow, which HubSpot is phasing out. |
| `TIKTOK_PIXEL_ID` / `TIKTOK_ACCESS_TOKEN` | TikTok Ads Manager, Events Manager, the relevant Pixel, Settings, Conversions API. |
| `RECOVERY_WEBHOOK_URL` | Optional. Any webhook-accepting URL (Zapier, Make, or a real automation platform) if the cart recovery trigger should actually notify somewhere, rather than only writing to the log. |

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
`event_time` is optional and defaults to the server's own receipt time if omitted.

**Abandoned cart detection:**
```bash
php artisan carts:detect-abandoned --minutes=0
```
`--minutes=0` treats any existing cart as immediately abandoned, useful for testing without waiting.

**HubSpot to TikTok sync:**
```bash
php artisan hubspot:sync-closed-deals
```
Requires a HubSpot deal in the Closed Won stage, associated with a contact that has a stored TikTok click ID. Check `storage/logs/laravel.log` for the "TikTok offline conversion sent" entry to confirm TikTok's response. A `code: 0` response means TikTok accepted the event.

Confirm both jobs are registered on schedule:
```bash
php artisan schedule:list
```

## Connecting the tracking pipeline

In the server-side Google Tag Manager container, a single HTTP Request tag fires alongside the existing GA4 and TikTok tags on the same triggers:

- Method: POST
- URL: `https://limbalabs.duckdns.org/events-api/api/events`
- Header: `Content-Type: application/json`
- Body (built using GTM's variable picker, not typed manually, since manually typed variable references do not reliably resolve in this tag type):
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

## Connecting the offline conversion bridge

1. A custom Contact property in HubSpot: `TikTok Click ID` (internal name `tiktok_click_id`, single-line text).
2. On the landing page, a small script reads the `ttclid` parameter TikTok appends to ad-click URLs and rewrites it into a `tiktok_click_id` query parameter before HubSpot's embedded form loads. HubSpot's form reads the parent page's URL and auto-fills any hidden field whose internal name matches a query parameter name.
3. New domains sending form submissions need to be added under HubSpot Settings, Tracking Code, Advanced Tracking, Additional site domains, or submissions get silently flagged as spam.
4. The `hubspot:sync-closed-deals` command checks for newly closed deals every 15 minutes and completes the loop back to TikTok.

## Deploying to a fresh server

```bash
git clone <this-repo-url> /var/www/savanna-roast-event-store
cd /var/www/savanna-roast-event-store
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force

sudo chown -R ubuntu:www-data /var/www/savanna-roast-event-store
sudo chmod -R 775 storage bootstrap/cache database
sudo usermod -a -G www-data ubuntu
```

Add the Nginx config above, then `sudo nginx -t && sudo systemctl reload nginx`.

Register the real cron entry:
```bash
crontab -e
```
```
* * * * * cd /var/www/savanna-roast-event-store && php artisan schedule:run >> /dev/null 2>&1
```

## A note on Git authentication

GitHub no longer accepts account passwords for git operations over HTTPS. Use a Personal Access Token instead (GitHub, Settings, Developer settings, Personal access tokens, Tokens classic, `repo` scope), entered in place of the password when prompted. To avoid re-entering it on every push:
```bash
git config --global credential.helper store
```
Avoid running git commands with `sudo` in this project, since it can create file ownership mismatches with the setup described above.
