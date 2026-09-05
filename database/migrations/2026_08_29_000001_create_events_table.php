<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This table is the single source of truth for first-party events
     * captured from the tracking pipeline (server-side GTM forwards here
     * alongside GA4 and TikTok — see the HTTP Request tag in
     * limbashop-server for the sending side).
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();

            // Matches the event_id generated client-side in tracking.js —
            // the same ID used for TikTok deduplication (Project 1, Session A).
            // Indexed and unique-checked at write time to avoid double-counting
            // if the server-side tag retries a request.
            $table->string('event_id')->unique();

            // e.g. "view_item", "add_to_cart", "purchase"
            $table->string('event_type')->index();

            // Ties multiple events from the same visit together — this is what
            // lets the abandoned-cart job know an add_to_cart and a purchase
            // belong to the same shopping session.
            $table->string('session_id')->index();

            $table->string('product_id')->nullable();
            $table->decimal('value', 10, 2)->nullable();
            $table->string('currency', 3)->nullable();

            // The moment the event actually happened in the browser —
            // distinct from created_at, which is when our server received it.
            $table->timestamp('event_time');

            // Whether this add_to_cart has already been matched to a later
            // purchase, or already had a recovery trigger fired for it.
            // Prevents the scheduled job from re-processing the same cart
            // every time it runs.
            $table->boolean('recovery_triggered')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
