<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('db:backup')->dailyAt('01:00');

// Refresh global stamp stats every 30 minutes for the admin panel KPIs
Schedule::job(new \App\Jobs\Billing\RefreshStampGlobalStatsJob)->everyThirtyMinutes();