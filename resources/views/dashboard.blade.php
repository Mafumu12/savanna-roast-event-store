<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Savanna Roast Co. — Event Store Dashboard</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f7f4ef; color: #2b2420; padding: 40px; }
  h1 { font-size: 22px; margin-bottom: 4px; }
  .subtitle { color: #8a7a6a; font-size: 14px; margin-bottom: 30px; }
  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 40px; }
  .stat-card { background: #fff; border-radius: 10px; padding: 20px; border: 1px solid #e0d9cd; }
  .stat-card .value { font-size: 28px; font-weight: 700; color: #8a5a2b; }
  .stat-card .label { font-size: 13px; color: #8a7a6a; margin-top: 4px; }
  table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; }
  th, td { text-align: left; padding: 10px 14px; font-size: 13px; border-bottom: 1px solid #f0ece4; }
  th { background: #2b2420; color: #fff; font-weight: 600; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
  .badge-cart { background: #e4ded0; color: #2b2420; }
  .badge-purchase { background: #d9ecd9; color: #1f4d1f; }
  .badge-view { background: #e8e8e8; color: #555; }
</style>
</head>
<body>

<h1>Savanna Roast Co. — Event Store</h1>
<p class="subtitle">First-party event data captured server-side, independent of GA4/TikTok · Phase 3 proof of concept</p>

<div class="stats">
  <div class="stat-card">
    <div class="value">{{ $totalEvents }}</div>
    <div class="label">Total events captured</div>
  </div>
  <div class="stat-card">
    <div class="value">{{ $addToCartCount }}</div>
    <div class="label">Add to cart</div>
  </div>
  <div class="stat-card">
    <div class="value">{{ $purchaseCount }}</div>
    <div class="label">Purchases</div>
  </div>
  <div class="stat-card">
    <div class="value">{{ $abandonmentRate }}%</div>
    <div class="label">Abandonment rate</div>
  </div>
  <div class="stat-card">
    <div class="value">{{ $abandonedCount }}</div>
    <div class="label">Currently abandoned (awaiting trigger)</div>
  </div>
  <div class="stat-card">
    <div class="value">{{ $recoveredCount }}</div>
    <div class="label">Recovery triggers fired</div>
  </div>
</div>

<table>
  <thead>
    <tr>
      <th>Event</th>
      <th>Session</th>
      <th>Product</th>
      <th>Value</th>
      <th>Event Time</th>
      <th>Recovery</th>
    </tr>
  </thead>
  <tbody>
    @forelse ($recentEvents as $event)
    <tr>
      <td>
        <span class="badge badge-{{ $event->event_type === 'add_to_cart' ? 'cart' : ($event->event_type === 'purchase' ? 'purchase' : 'view') }}">
          {{ $event->event_type }}
        </span>
      </td>
      <td>{{ Str::limit($event->session_id, 16) }}</td>
      <td>{{ $event->product_id ?? '—' }}</td>
      <td>{{ $event->value ? $event->currency . ' ' . $event->value : '—' }}</td>
      <td>{{ $event->event_time->diffForHumans() }}</td>
      <td>{{ $event->recovery_triggered ? '✅ Triggered' : '—' }}</td>
    </tr>
    @empty
    <tr><td colspan="6">No events captured yet.</td></tr>
    @endforelse
  </tbody>
</table>

</body>
</html>
