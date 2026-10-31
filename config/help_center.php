<?php

/*
|--------------------------------------------------------------------------
| Help center configuration
|--------------------------------------------------------------------------
|
| Presentation data for the help center ("Centro de ayuda"). Module keys and
| labels come from config/tutorials.php so written guides and videos always
| stay aligned. Only descriptions and icons live here.
|
*/

return [

    'descriptions' => [
        'dashboard'         => 'Resumen de ventas, alertas y pendientes del día.',
        'pos'               => 'Cómo registrar ventas, cobrar y emitir tickets.',
        'transactions'      => 'Consulta, cobra y cancela ventas registradas.',
        'products'          => 'Crea productos, precios, variantes y stock.',
        'customers'         => 'Registra clientes, crédito y datos fiscales.',
        'quotes'            => 'Crea cotizaciones y conviértelas en ventas.',
        'services'          => 'Servicios, órdenes de servicio y seguimiento.',
        'expenses'          => 'Registra gastos y sus categorías.',
        'cash-registers'    => 'Apertura, movimientos y corte de caja.',
        'financial-control' => 'Ingresos, egresos y reportes financieros.',
        'billing'           => 'Facturación, timbrado y complementos de pago.',
        'online-store'      => 'Configura tu tienda y gestiona pedidos.',
        'promotions'        => 'Descuentos y promociones por producto.',
        'subscription'      => 'Tu plan, pagos y comprobantes.',
        'referrals'         => 'Programa de referidos y recompensas.',
        'settings'          => 'Sucursales, usuarios, roles y preferencias.',
        'onboarding'        => 'Primeros pasos para dejar tu negocio listo.',
    ],

    // Icons mirror the sidebar menu (Layouts/AppMenu.vue) so every module keeps
    // the same visual identity in the help center and in the navigation.
    'icons' => [
        'dashboard'         => 'pi-home',
        'pos'               => 'pi-shop',
        'transactions'      => 'pi-history',
        'products'          => 'pi-barcode',
        'customers'         => 'pi-users',
        'quotes'            => 'pi-file-check',
        'services'          => 'pi-wrench',
        'expenses'          => 'pi-arrow-up-right',
        'cash-registers'    => 'pi-dollar',
        'financial-control' => 'pi-chart-bar',
        'billing'           => 'pi-file',
        'online-store'      => 'pi-globe',
        'promotions'        => 'pi-tag',
        'subscription'      => 'pi-star',
        'referrals'         => 'pi-share-alt',
        'settings'          => 'pi-cog',
        'onboarding'        => 'pi-flag',
    ],

];
