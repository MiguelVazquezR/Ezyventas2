<?php

/*
|--------------------------------------------------------------------------
| Tutorials configuration
|--------------------------------------------------------------------------
|
| Modules available in the admin panel ("Tutoriales"). The key is what each
| page passes to the <TutorialHelp module="..."> component and what the
| database stores; the label is what users see (modal subtitle, admin select).
|
*/

return [

    'modules' => [
        'dashboard'         => 'Inicio',
        'pos'               => 'Punto de venta',
        'transactions'      => 'Historial de ventas',
        'products'          => 'Productos',
        'customers'         => 'Clientes',
        'quotes'            => 'Cotizaciones',
        'services'          => 'Servicios y órdenes',
        'expenses'          => 'Gastos',
        'cash-registers'    => 'Cajas',
        'financial-control' => 'Reporte financiero',
        'billing'           => 'Facturación',
        'online-store'      => 'Tienda en línea',
        'promotions'        => 'Promociones',
        'subscription'      => 'Suscripción',
        'referrals'         => 'Referidos',
        'settings'          => 'Configuraciones',
        'onboarding'        => 'Primeros pasos',
    ],

    // Maximum size (KB) for uploaded video files. Actual limit also depends on
    // the PHP configuration (upload_max_filesize / post_max_size).
    'max_upload_kb' => (int) env('TUTORIALS_MAX_UPLOAD_KB', 51200),

];
