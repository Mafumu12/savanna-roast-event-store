<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $fillable = [
        'event_id',
        'event_type',
        'session_id',
        'product_id',
        'value',
        'currency',
        'event_time',
        'recovery_triggered',
    ];

    protected $casts = [
        'event_time'         => 'datetime',
        'value'              => 'decimal:2',
        'recovery_triggered' => 'boolean',
    ];

    /**
     * Scope: add_to_cart events older than $minutes, that don't yet have
     * a matching purchase in the same session, and haven't already had
     * a recovery trigger fired.
     *
     * This is the core query the abandoned-cart detection job runs.
     */
    public function scopeAbandoned(Builder $query, int $minutes = 60): Builder
    {
        return $query
            ->where('event_type', 'add_to_cart')
            ->where('recovery_triggered', false)
            ->where('event_time', '<=', now()->subMinutes($minutes))
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('events as purchases')
                    ->whereColumn('purchases.session_id', 'events.session_id')
                    ->where('purchases.event_type', 'purchase');
            });
    }
}
