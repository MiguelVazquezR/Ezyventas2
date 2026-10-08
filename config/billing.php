<?php

/*
|--------------------------------------------------------------------------
| Billing module configuration (Fase 3)
|--------------------------------------------------------------------------
|
| Thresholds for the preventive alerts shown to subscribers (dashboard /
| fiscal profile views) and evaluated by the daily CheckPreventiveAlertsJob.
|
*/

return [

    // Available stamps at or below this number trigger a low-balance alert.
    'low_stamp_threshold' => (int) env('BILLING_LOW_STAMP_THRESHOLD', 5),

    // Days before the CSD expiry date that trigger an alert. The daily job
    // notifies once per milestone; expired certificates are notified once too.
    'csd_expiry_warning_days' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('BILLING_CSD_WARNING_DAYS', '30,15,5'))
    ))),

];
