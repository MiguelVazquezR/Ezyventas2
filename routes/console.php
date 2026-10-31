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

// Account hibernation: warn the owner 7 days before the purge, then delete the
// business data of accounts without sign-ins for 90 days. Both jobs also check
// account_lifecycle.inactivity.enabled before doing anything.
if (config('account_lifecycle.inactivity.enabled')) {
    Schedule::job(new \App\Jobs\Lifecycle\SendAccountLifecycleWarningsJob)->dailyAt('09:00');
    Schedule::job(new \App\Jobs\Lifecycle\PurgeInactiveAccountsJob)->dailyAt('09:30');
}