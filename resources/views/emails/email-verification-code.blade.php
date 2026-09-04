{{--
    Correo con el código OTP para verificar la cuenta.
    Usa el layout estándar 'mail::message' (logo + colores de la marca).
--}}
@component('mail::message')

# Tu código de verificación

Hola **{{ $user->name }}**,

Recibimos una solicitud para verificar tu cuenta en **{{ config('app.name') }}**.
Ingresa el siguiente código en la pantalla para continuar:

@component('mail::panel')
<div style="text-align:center; font-size:30px; letter-spacing:14px; font-weight:700; color:#f68c0f; line-height:1.2;">
    {{ $code }}
</div>
@endcomponent

Este código es válido por **10 minutos** y solo puede usarse una vez.

Si no solicitaste este código, puedes ignorar este correo de forma segura.

@endcomponent
