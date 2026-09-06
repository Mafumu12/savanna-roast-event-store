<?php

use App\Console\Commands\DetectAbandonedCarts;
use Illuminate\Support\Facades\Schedule;
use App\Console\Commands\SyncHubspotClosedDeals;
// Runs every 15 minutes. Any cart added-to but not purchased within
// 60 minutes (the command's default) gets flagged and the recovery
// trigger fires exactly once per cart (see recovery_triggered column).
Schedule::command(DetectAbandonedCarts::class)->everyFifteenMinutes();
Schedule::command(SyncHubspotClosedDeals::class)->everyFifteenMinutes();
