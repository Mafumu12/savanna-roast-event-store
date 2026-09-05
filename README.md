# Savanna Roast Co. — Event Store (Phase 3)

First-party event storage + abandoned cart detection, extending the server-side
tracking infrastructure built in Phases 1-2. See the main project brief
(`savanna-roast-tracking-infrastructure-brief.md`) for full context.

## What this is

A Laravel app that receives a copy of every tracking event (view_item,
add_to_cart, purchase) from the existing server-side GTM container, stores it
in a first-party database you own, and periodically checks for abandoned
carts — firing a recovery trigger (webhook/log stub) when found.

This does not replace GA4 or TikTok tracking — it's a parallel destination,
same pattern as adding TikTok alongside GA4 in Phase 2.

## Setup

You've already run `composer create-project laravel/laravel event-store` and
`php artisan install:api` — this project targets your existing Laravel 12
project as-is. `bootstrap/app.php` already has `api:` routing registered,
and `routes/api.php` already has the default Sanctum `/user` route, which
this project's `routes/api.php` preserves alongside the new `/events` route.

**1. Copy these files into your project**, creating folders if they don't
exist yet:

```
database/migrations/2026_08_29_000001_create_events_table.php
app/Models/Event.php
app/Http/Controllers/EventController.php
app/Console/Commands/DetectAbandonedCarts.php
routes/api.php          (replace — merges with the default Sanctum route)
routes/web.php          (replace)
routes/console.php      (replace)
resources/views/dashboard.blade.php
```

**2. Configure SQLite:**

```powershell
type nul > database\database.sqlite
```

In `.env`, set:
```
DB_CONNECTION=sqlite
```
(Remove or comment out the other `DB_*` lines below it.)

**3. Run the migration:**

```powershell
php artisan migrate
```

**4. Confirm the route registered:**

```powershell
php artisan route:list
```

You should now see `POST api/events` in the list alongside the existing
routes.

**5. (Optional) Set a recovery webhook URL** in `.env` if you want the
abandoned-cart trigger to actually POST somewhere (e.g., a Zapier/Make
webhook, or a real automation platform):

```
RECOVERY_WEBHOOK_URL=https://your-webhook-url-here
```

And in `config/services.php`, add:
```php
'recovery_webhook' => [
    'url' => env('RECOVERY_WEBHOOK_URL'),
],
```

**6. Test locally:**

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

Visit `http://localhost:8000/dashboard`.

Test the endpoint directly:
```bash
curl -X POST http://localhost:8000/api/events \
  -H "Content-Type: application/json" \
  -d '{
    "event_id": "test-001",
    "event_type": "add_to_cart",
    "session_id": "session-abc",
    "product_id": "SR-001",
    "value": 185.00,
    "currency": "USD",
    "event_time": "2026-08-29T12:00:00Z"
  }'
```

**7. Test the abandoned-cart command manually:**

```bash
php artisan carts:detect-abandoned --minutes=0
```
(`--minutes=0` forces it to treat any add_to_cart as immediately abandoned,
useful for testing without waiting an hour.)

## Wiring in GTM (Session 2 of Phase 3)

In `limbashop-server` (the existing server-side GTM container), add a new
tag:
- Type: HTTP Request (or Custom Template if using a community HTTP tag)
- Method: POST
- URL: `https://your-domain/api/events`
- Body: JSON matching the shape in the curl example above, built from the
  same Event Data variables already in use for `event_id`, `customer_email`,
  etc.
- Trigger: same `add_to_cart` / `purchase` / `view_item` triggers already
  firing the GA4 and TikTok tags

## Deployment (once working locally + committed to git)

On the EC2:
```bash
git clone <your-repo-url> event-store
cd event-store
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force
```

Then serve it via Nginx (a new `location /events-api/` block proxying to
PHP-FPM, or `php artisan serve` behind its own subdomain — same pattern as
the existing `sst-server`/`sst-preview` split).

Register the scheduler in a real cron entry (`crontab -e`):
```
* * * * * cd /path/to/event-store && php artisan schedule:run >> /dev/null 2>&1
```
