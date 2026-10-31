@component('mail::message')
# Tu cuenta se eliminará por inactividad

No hemos registrado inicios de sesión en **{{ $subscriptionName }}** durante un tiempo prolongado.

Para proteger tu espacio, eliminaremos la información de tu negocio
(productos, servicios, ventas, clientes, cotizaciones, órdenes de servicio y facturas)
el **{{ $purgeDate }}**, es decir, dentro de **{{ $daysUntilPurge }} días**.

Tu cuenta y tu acceso se conservarán: si quieres mantener tu información,
solo inicia sesión antes de esa fecha y todo quedará como hasta ahora.

@component('mail::button', ['url' => route('login')])
Iniciar sesión
@endcomponent

Si ya no necesitas la cuenta, no tienes que hacer nada.

Gracias,<br>
El equipo de {{ config('app.name') }}
@endcomponent
