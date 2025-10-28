{{-- 
  Usamos el componente 'mail::message'.
  Esto cargará automáticamente tu layout, header (con logo) y footer.
--}}
@component('mail::message')

{{-- Saludo --}}
# ¡Bienvenido a {{ config('app.name') }}, {{ $user->name }}!

¡Tu cuenta para **{{ $user->branch->subscription->business_name }}** ha sido creada con éxito!

Estamos emocionados de ayudarte a gestionar y hacer crecer tu negocio. Hemos activado un plan de prueba de 30 días con acceso a todas las funciones iniciales.

Para comenzar, simplemente haz clic en el botón de abajo e inicia sesión con el correo y la contraseña que registraste.

{{-- 
  Usamos el componente 'mail::button'.
  Esto usará automáticamente tu color naranja de 'default.css' 
  para el color 'primary'.
--}}
@component('mail::button', ['url' => route('login')])
Ir a mi cuenta
@endcomponent

Si has iniciado con **Google**, tu contraseña es el mismo correo que registraste. La contraseña la puedes cambiar desde tu perfil.

Si tienes alguna pregunta, no dudes en responder a este correo.

¡Gracias por unirte!<br>
El equipo de {{ config('app.name') }}

@endcomponent
