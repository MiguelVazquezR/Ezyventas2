<?php

namespace App\AiTools\Registrars;

use App\Models\User;
use App\Services\SubscriptionStatusService;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class SubscriptionTools implements ToolRegistrar
{
    /**
     * Subscription billing data is owner-only: mirror the SubscriptionController
     * restriction, where users with roles (employees) cannot access the
     * subscription panel. Owners are users without roles.
     */
    public function definitions(Authenticatable $user): array
    {
        if (! $user instanceof User || $user->roles()->exists()) {
            return [];
        }

        return [
            [
                'permission' => null,
                'category'   => 'subscription and billing',
                'tool'       => (new Tool)->as('subscription_status')
                    ->for('Consulta el estado de la suscripción de EzyVentas del negocio: estado actual, plan contratado (módulos, límites y costo mensual), fecha de vencimiento con días restantes, monto pendiente de pago o estimado de renovación, descuentos vigentes e historial reciente de pagos a EzyVentas.

Úsala cuando el usuario pregunte cuándo vence su suscripción, cuánto debe pagar para renovarla, qué plan tiene contratado, en qué estado está su pago o quiera consultar el historial de pagos de la plataforma. No requiere parámetros: usa automáticamente la suscripción del usuario autenticado.

Al responder: usa español y montos en pesos mexicanos (MXN). Distingue claramente entre un pago pendiente (ya enviado y en revisión) y el estimado de renovación (cálculo con los precios vigentes cuando no hay un pago en proceso). Si la suscripción vence hoy o ya venció, indícalo junto con el monto de renovación estimado. Si aparece un pago rechazado, menciona el motivo y sugiere reintentar la renovación desde la sección "Mi suscripción". Nunca inventes montos ni fechas que no aparezcan en el resultado.')
                    ->using(function () use ($user) {
                        $subscription = $user->subscription;

                        if (! $subscription) {
                            return json_encode([
                                'error' => 'No se encontró una suscripción asociada a tu cuenta.',
                            ], JSON_UNESCAPED_UNICODE);
                        }

                        return json_encode(
                            app(SubscriptionStatusService::class)->snapshot($subscription),
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                        );
                    }),
            ],
        ];
    }
}
