<?php

/*
|--------------------------------------------------------------------------
| Free plan
|--------------------------------------------------------------------------
|
| Every account created from the public registration form starts on this
| open-ended free plan: fixed resource limits, the essential modules enabled
| and no expiration date. There is no trial period, so nothing has to be
| renewed to keep operating.
|
| Consumed by: App\Services\Subscription\SubscriptionPlanService
|
*/

return [

    'plan' => [
        // User-facing plan name, always written in Spanish sentence case.
        'name' => 'Plan gratuito',

        // Free plans are versioned with a 50 year horizon so the subscription
        // never shows up as expired or about to expire.
        'horizon_years' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fixed limits
    |--------------------------------------------------------------------------
    |
    | Included quantity of every resource. Onboarding no longer allows editing
    | them: they can only be increased by purchasing extra plan items.
    |
    */

    'limits' => [
        'limit_branches' => 1,
        'limit_users' => 2,
        'limit_cash_registers' => 1,
        'limit_products' => 3000,
        'limit_services' => 200,
        'limit_print_templates' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default modules
    |--------------------------------------------------------------------------
    |
    | Modules enabled when the account is created. The onboarding wizard may
    | add more, but these always stay active.
    |
    */

    'default_modules' => [
        'module_pos',
        'module_financial_reports',
        'module_transactions',
        'module_products',
        'module_expenses',
        'module_customers',
        'module_services',
        'module_cash_registers',
        'module_settings',
        'module_billing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Referral program
    |--------------------------------------------------------------------------
    |
    | The referral program is switched off for now. Its code, routes and tables
    | stay in place, but while this flag is false the checkout resolves no
    | referral codes, applies no referral discount and registers no ReferralUsage,
    | and the frontend hides every referral entry (menu, topbar and checkout).
    |
    | Set SUBSCRIPTION_REFERRALS_ENABLED=true to turn it back on: the previous
    | behaviour is restored without changing application code.
    |
    */

    'referrals' => [
        'enabled' => env('SUBSCRIPTION_REFERRALS_ENABLED', false),
    ],

];
