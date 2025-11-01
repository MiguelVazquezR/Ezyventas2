@component('mail::message')
# Nuevo pago pendiente de revisión

Se ha registrado un nuevo pago por transferencia que requiere tu aprobación.

**Detalles del pago:**
- **Negocio:** {{ $subscriptionName }}
- **Monto:** {{ $formattedAmount }}

Por favor, revisa el comprobante y aprueba o rechaza el pago desde el panel de administración.

@component('mail::button', ['url' => $reviewUrl])
Revisar pago
@endcomponent

Gracias,<br>
El equipo de {{ config('app.name') }}
@endcomponent
