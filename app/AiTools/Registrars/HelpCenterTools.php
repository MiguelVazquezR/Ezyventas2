<?php

namespace App\AiTools\Registrars;

use App\Services\HelpCenter\HelpGuideSearchService;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class HelpCenterTools implements ToolRegistrar
{
    public function definitions(Authenticatable $user): array
    {
        return [
            [
                'permission' => null,
                'category'   => 'help center',
                'tool'       => (new Tool)->as('search_help_guides')
                    ->for('Busca guías escritas del Centro de ayuda de EzyVentas y devuelve el enlace directo a cada guía que coincide. Úsala siempre que el usuario pregunte cómo realizar un proceso o pida ayuda paso a paso: cómo registrar una venta, cómo hacer un corte de caja, cómo facturar, cómo agregar un producto, cómo registrar un gasto, cómo crear una cotización, cómo configurar la tienda en línea, etc. Devuelve título, módulo, resumen, un fragmento del contenido y la URL de la guía. Responde con el enlace en formato markdown [Título de la guía](url) y nunca inventes URLs del centro de ayuda.')
                    ->withStringParameter('query', 'Lo que el usuario quiere aprender a hacer, con sus propias palabras (por ejemplo: "registrar una venta", "corte de caja", "facturar un ticket")')
                    ->withStringParameter('module', 'Opcional. Clave o nombre del módulo para acotar la búsqueda: pos, products, billing, cash-registers, expenses, quotes, services, customers, dashboard, transactions, promotions, online-store, subscription, settings, onboarding, financial-control, referrals', false)
                    ->using(function (string $query, ?string $module = null) {
                        return json_encode(
                            app(HelpGuideSearchService::class)->search($query, $module),
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        );
                    }),
            ],

            [
                'permission' => null,
                'category'   => 'help center',
                'tool'       => (new Tool)->as('list_help_center_modules')
                    ->for('Lista los módulos del Centro de ayuda que tienen guías escritas publicadas, con su descripción, cantidad de guías y enlace. Úsala cuando el usuario pregunte qué documentación o guías existen, o cuando search_help_guides no encuentre una guía y quieras ofrecerle el centro de ayuda completo organizado por módulo.')
                    ->using(function () {
                        $modules = app(HelpGuideSearchService::class)->modules();

                        return json_encode([
                            'message' => $modules === []
                                ? 'El centro de ayuda todavía no tiene guías publicadas.'
                                : 'Módulos del centro de ayuda con guías publicadas.',
                            'help_center_url' => route('help-center.index'),
                            'modules' => $modules,
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }),
            ],
        ];
    }
}
