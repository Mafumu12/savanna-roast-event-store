<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncHubspotClosedDeals extends Command
{
    protected $signature = 'hubspot:sync-closed-deals {--minutes=15 : How far back to check for recently closed deals}';

    protected $description = 'Poll HubSpot for deals that just moved to Closed Won, and fire a delayed TikTok CompletePayment event using the ttclid captured at initial contact';

    private const TIKTOK_TEST_EVENT_CODE = null; // set to e.g. "TEST12345" while testing

    public function handle(): int
    {
        $serviceKey = config('services.hubspot.service_key');

        if (! $serviceKey) {
            $this->error('HUBSPOT_SERVICE_KEY is not set in .env');
            return self::FAILURE;
        }

        $minutes = (int) $this->option('minutes');
        $since = now()->subMinutes($minutes)->valueOf(); // HubSpot expects epoch millis

        // Step 1: find deals that reached Closed Won recently.
        // "closedwon" is HubSpot's internal stage ID for the default pipeline —
        // if you're using a custom pipeline/stage name, confirm the actual
        // internal ID via GET /crm/v3/pipelines/deals first.
        $response = Http::withToken($serviceKey)
            ->post('https://api.hubapi.com/crm/v3/objects/deals/search', [
                'filterGroups' => [[
                    'filters' => [
                        ['propertyName' => 'dealstage', 'operator' => 'EQ', 'value' => 'closedwon'],
                        ['propertyName' => 'hs_lastmodifieddate', 'operator' => 'GTE', 'value' => $since],
                    ],
                ]],
                'properties' => ['dealname', 'amount', 'dealstage', 'closedate'],
            ]);

        if ($response->failed()) {
            $this->error('HubSpot deals search failed: ' . $response->body());
            Log::error('HubSpot deals search failed', ['response' => $response->body()]);
            return self::FAILURE;
        }

        $deals = $response->json('results', []);

        if (empty($deals)) {
            $this->info('No newly closed-won deals found.');
            return self::SUCCESS;
        }

        foreach ($deals as $deal) {
            $this->processDeal($deal, $serviceKey);
        }

        $this->info('Processed ' . count($deals) . ' closed-won deal(s).');

        return self::SUCCESS;
    }

    private function processDeal(array $deal, string $serviceKey): void
    {
        $dealId = $deal['id'];
        $amount = $deal['properties']['amount'] ?? null;

        // Step 2: get the contact associated with this deal, to find their
        // stored tiktok_click_id (captured at initial form submission).
        $assocResponse = Http::withToken($serviceKey)
            ->get("https://api.hubapi.com/crm/v3/objects/deals/{$dealId}", [
                'associations' => 'contacts',
                'properties' => 'dealname,amount',
            ]);

        $contactId = data_get(
            $assocResponse->json(),
            'associations.contacts.results.0.id'
        );

        if (! $contactId) {
            $this->warn("Deal {$dealId} has no associated contact — skipping.");
            return;
        }

        $contactResponse = Http::withToken($serviceKey)
            ->get("https://api.hubapi.com/crm/v3/objects/contacts/{$contactId}", [
                'properties' => 'email,tiktok_click_id',
            ]);

        $ttclid = data_get($contactResponse->json(), 'properties.tiktok_click_id');
        $email = data_get($contactResponse->json(), 'properties.email');

        if (! $ttclid) {
            $this->warn("Contact {$contactId} (deal {$dealId}) has no stored ttclid — cannot attribute to TikTok, skipping.");
            return;
        }

        $this->fireTiktokConversion($ttclid, $email, $amount, $dealId);
    }

    /**
     * Sends a delayed CompletePayment event to TikTok's Events API using the
     * ttclid captured weeks earlier at initial contact, with the real closed
     * deal value — this is what lets TikTok's algorithm optimize toward
     * actual paying clients rather than raw form-fills.
     */
    private function fireTiktokConversion(string $ttclid, ?string $email, ?string $amount, string $dealId): void
    {
        $pixelId = config('services.tiktok.pixel_id');
        $accessToken = config('services.tiktok.access_token');

        if (! $pixelId || ! $accessToken) {
            Log::warning('TikTok credentials not configured — skipping conversion send', ['deal_id' => $dealId]);
            return;
        }

        $payload = [
            'event_source' => 'web',
            'event_source_id' => $pixelId,
            'data' => [[
                'event' => 'CompletePayment',
                'event_time' => now()->timestamp,
                'event_id' => 'hubspot-deal-' . $dealId, // stable, unique per deal
                'user' => array_filter([
                    'ttclid' => $ttclid,
                    'email' => $email ? hash('sha256', strtolower(trim($email))) : null,
                ]),
                'properties' => array_filter([
                    'value' => $amount ? (float) $amount : null,
                    'currency' => 'USD',
                ]),
            ]],
        ];

        if (self::TIKTOK_TEST_EVENT_CODE) {
            $payload['test_event_code'] = self::TIKTOK_TEST_EVENT_CODE;
        }

        $response = Http::withHeaders(['Access-Token' => $accessToken])
            ->post('https://business-api.tiktok.com/open_api/v1.3/event/track/', $payload);

        Log::info('TikTok offline conversion sent', [
            'deal_id' => $dealId,
            'ttclid' => $ttclid,
            'status' => $response->status(),
            'response' => $response->json(),
        ]);

        $this->info("Fired TikTok CompletePayment for deal {$dealId} (ttclid: {$ttclid})");
    }
}
