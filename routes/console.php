<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('db:backup')->dailyAt('01:00');

// Refresh global stamp stats every 30 minutes for the admin panel KPIs
Schedule::job(new \App\Jobs\Billing\RefreshStampGlobalStatsJob)->everyThirtyMinutes();

// Reconciliación diaria de cuentas "normal": saldo esperado local vs PAC real
Schedule::job(new \App\Jobs\Billing\ReconcileSharedAccountBalancesJob)->dailyAt('04:00');

// Fase 3: re-check cancelations pending the receiver's acceptance and notify
// the subscriber when they are resolved (accepted / rejected / expired).
Schedule::job(new \App\Jobs\Billing\RefreshPendingCancelationsJob)->hourly();

// Fase 3: preventive alerts (low stamp balance, CSD about to expire).
Schedule::job(new \App\Jobs\Billing\CheckPreventiveAlertsJob)->dailyAt('08:00');