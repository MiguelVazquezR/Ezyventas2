<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bienvenido</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 0;
            color: #333;
        }
        .container {
            width: 90%;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            font-size: 24px;
            font-weight: bold;
            color: #222;
            text-align: center;
            padding: 20px 0;
        }
        .content {
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 30px;
            line-height: 1.6;
        }
        .content p {
            margin-bottom: 20px;
        }
        .button {
            display: inline-block;
            background-color: #f68c0f; /* Color primario de tu app */
            color: #ffffff;
            padding: 12px 25px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
        }
        .footer {
            text-align: center;
            padding-top: 20px;
            font-size: 12px;
            color: #888;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            ¡Bienvenido a {{ config('app.name') }}!
        </div>
        <div class="content">
            <p>Hola, {{ $user->name }}:</p>
            <p>¡Tu cuenta para <strong>{{ $user->branch->subscription->business_name }}</strong> ha sido creada con éxito!</p>
            <p>Estamos emocionados de ayudarte a gestionar y hacer crecer tu negocio. Hemos activado un plan de prueba de 30 días con acceso a todas las funciones iniciales.</p>
            <p>Para comenzar, simplemente haz clic en el botón de abajo e inicia sesión con el correo y la contraseña que registraste.</p>
            <p>Si has iniciado con Google, tu contraseña es el mismo correo que registraste. La contraseña la puedes cambiar desde tu perfil.</p>
            <p style="text-align: center; margin-top: 30px;">
                <a href="{{ route('login') }}" class="button">Ir a mi cuenta</a>
            </p>
            <p style="margin-top: 30px;">Si tienes alguna pregunta, no dudes en responder a este correo.</p>
            <p>¡Gracias por unirte!<br>El equipo de {{ config('app.name') }}</p>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} {{ config('app.name') }}. Todos los derechos reservados.
        </div>
    </div>
</body>
</html>