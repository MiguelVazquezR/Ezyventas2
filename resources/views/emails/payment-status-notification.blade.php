@component('mail::message')

{{-- Saludo --}}
# Hola, {{ $userName }}.

@if ($status === 'approved')

## ¡Tu pago ha sido aprobado!

Te confirmamos que hemos recibido y aprobado tu pago por **${{ number_format($amount, 2) }}**.

Tu plan ha sido activado (o renovado) exitosamente. Ya puedes disfrutar de todas tus funciones.

@component('mail::button', ['url' => route('subscription.show')])
Ir a mi suscripción
@endcomponent

@elseif ($status === 'rejected')

## Tu pago fue rechazado

Lamentamos informarte que tu pago por **${{ number_format($amount, 2) }}** no pudo ser procesado.

**Motivo del rechazo:**
@component('mail::panel')
{{ $rejectionReason ?? 'No se proporcionó un motivo específico.' }}
@endcomponent

Por favor, accede a tu panel de suscripción para verificar la información y realizar el pago nuevamente.

@component('mail::button', ['url' => route('subscription.manage'), 'color' => 'error'])
Reintentar pago
@endcomponent

@endif

Gracias por tu comprensión,<br>
El equipo de {{ config('app.name') }}

@endcomponent
