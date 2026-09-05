<?php
namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DetectAbandonedCarts extends Command
{
    protected $signature = 'carts:detect-abandoned {--minutes=60 : How old an add_to_cart must be before it counts as abandoned}';

    protected $description = 'Find add_to_cart events with no matching purchase and fire the recovery trigger';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');

        $abandoned = Event::abandoned($minutes)->get();

        if ($abandoned->isEmpty()) {
            $this->info('No abandoned carts found.');
            return self::SUCCESS;
        }

        foreach ($abandoned as $cart) {
            $this->fireRecoveryTrigger($cart);

            $cart->update(['recovery_triggered' => true]);

            $this->info("Recovery triggered for session {$cart->session_id} (product: {$cart->product_id})");
        }

        $this->info("Processed {$abandoned->count()} abandoned cart(s).");

        return self::SUCCESS;
    }

    /**
     * This is a stub — it logs and (optionally) posts to a webhook URL,
     * standing in for a real email/SMS send via a provider like
     * Klaviyo, Twilio, or SendGrid.
     *
     * To wire in a real send: set RECOVERY_WEBHOOK_URL in .env and
     * point it at your provider's inbound webhook / automation trigger.
     * The payload below is already shaped as a generic, provider-agnostic
     * event so it should drop into most automation platforms with
     * minimal mapping.
     */
    private function fireRecoveryTrigger(Event $cart): void
    {
        $payload = [
            'event'        => 'cart_abandoned',
            'session_id'   => $cart->session_id,
            'product_id'   => $cart->product_id,
            'value'        => $cart->value,
            'currency'     => $cart->currency,
            'abandoned_at' => $cart->event_time->toIso8601String(),
        ];

        Log::info('Cart abandonment recovery trigger fired', $payload);

        $webhookUrl = config('services.recovery_webhook.url');

        if ($webhookUrl) {
            try {
                Http::timeout(5)->post($webhookUrl, $payload);
            } catch (\Throwable $e) {
                Log::warning('Recovery webhook failed', [
                    'error'      => $e->getMessage(),
                    'session_id' => $cart->session_id,
                ]);
            }
        }
    }
}
