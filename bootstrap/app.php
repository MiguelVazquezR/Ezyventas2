<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Session\TokenMismatchException; // Importante: Importar esta excepción
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\CheckOnboardingStatus::class,
            \App\Http\Middleware\CheckSubscriptionStatus::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        
        // 1. Manejar TokenMismatchException (Error 419 / CSRF) explícitamente
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            return Inertia::render('Error', [
                'status' => 419,
                // MODIFICADO: Pasamos la URL anterior para que el botón 'Recargar' sepa volver al formulario
                'redirectUrl' => url()->previous() 
            ])
            ->toResponse($request)
            ->setStatusCode(419);
        });

        // 2. Manejar excepciones HTTP estándar
        $exceptions->render(function (HttpException $e, Request $request) {
            $status = $e->getStatusCode();

            // Solo se activa para los códigos de error que queremos personalizar.
            if (!in_array($status, [403, 404, 419, 500, 503])) {
                return; // Deja que Laravel maneje los demás errores.
            }

            return Inertia::render('Error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();