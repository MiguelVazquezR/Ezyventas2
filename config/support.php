<?php

/*
|--------------------------------------------------------------------------
| Support content (mobile app)
|--------------------------------------------------------------------------
|
| The web modal (SupportModal.vue) and the mobile support screen show the same
| channels and help topics. Keeping them here means they can be updated without
| publishing a new version of the app.
|
| Consumed by: GET /api/v1/support
|
*/

return [
    'title' => 'Centro de soporte',
    'subtitle' => 'Estamos aquí para ayudarte',
    'message' => 'Puedes solicitar soporte técnico, reportar algún error, sugerir mejoras al sistema o proponer nuevas funcionalidades. Con gusto lo evaluaremos.',

    'schedule' => [
        ['label' => 'Lunes a viernes', 'hours' => '8:00 AM — 7:00 PM'],
        ['label' => 'Sábados', 'hours' => '9:00 AM — 3:00 PM'],
    ],

    'channels' => [
        [
            'type' => 'email',
            'label' => 'Correo electrónico',
            'value' => 'notificaciones@ezyventas.com',
            'url' => 'mailto:notificaciones@ezyventas.com',
        ],
        [
            'type' => 'whatsapp',
            'label' => 'WhatsApp',
            'value' => '+52 33 2170 5650',
            'url' => 'https://wa.me/5213321705650',
        ],
    ],

    'help_topics' => [
        [
            'id' => 'steps',
            'title' => 'Primeros pasos',
            'description' => 'Configura tu cuenta y realiza tu primera venta.',
        ],
        [
            'id' => 'billing',
            'title' => 'Facturación',
            'description' => 'Gestiona tus pagos, facturas y suscripciones.',
        ],
        [
            'id' => 'account',
            'title' => 'Mi cuenta',
            'description' => 'Actualiza tu perfil, seguridad y preferencias.',
        ],
        [
            'id' => 'inventory',
            'title' => 'Inventario',
            'description' => 'Controla productos, stock y sucursales.',
        ],
    ],
];
