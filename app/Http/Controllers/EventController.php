<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class EventController extends Controller
{
    /**
     * Receives events forwarded from the server-side GTM container
     * (see the "HTTP Request" tag in limbashop-server — it fires
     * alongside the existing GA4 and TikTok tags, same trigger,
     * new destination).
     *
     * This endpoint is intentionally tolerant of duplicate event_ids
     * (GTM tags can retry on transient failure) — a duplicate is
     * treated as a no-op success, not an error, so retries never
     * inflate the event count.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'event_id' => 'required|string|max:100',
                'event_type' => 'required|string|in:view_item,add_to_cart,purchase',
                'session_id' => 'required|string|max:100',
                'product_id' => 'nullable|string|max:100',
                'value' => 'nullable|numeric',
                'currency' => 'nullable|string|size:3',
                // Optional: GTM's server-side variable picker has no reliable
                // client-side timestamp source, so this defaults to the
                // server's own receipt time when omitted (see below).
                'event_time' => 'nullable|date',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }

        $event = Event::firstOrCreate(
            ['event_id' => $validated['event_id']],
            array_merge($validated, [
                'event_time' => $validated['event_time'] ?? now(),
            ])
        );

        return response()->json([
            'status' => 'ok',
            'event_id' => $event->event_id,
            'duplicate' => ! $event->wasRecentlyCreated,
        ], $event->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Simple dashboard data — powers the proof-of-concept view.
     * Not paginated or optimized; this is a demo, not a production
     * analytics product.
     */
    public function dashboard()
    {
        $totalEvents = Event::count();
        $addToCartCount = Event::where('event_type', 'add_to_cart')->count();
        $purchaseCount = Event::where('event_type', 'purchase')->count();
        $abandonedCount = Event::abandoned()->count();
        $recoveredCount = Event::where('recovery_triggered', true)->count();

        $abandonmentRate = $addToCartCount > 0
            ? round((($addToCartCount - $purchaseCount) / $addToCartCount) * 100, 1)
            : 0;

        $recentEvents = Event::orderBy('event_time', 'desc')->limit(20)->get();

        return view('dashboard', compact(
            'totalEvents',
            'addToCartCount',
            'purchaseCount',
            'abandonedCount',
            'recoveredCount',
            'abandonmentRate',
            'recentEvents',
        ));
    }
}
