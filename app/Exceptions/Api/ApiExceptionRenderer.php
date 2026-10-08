<?php

namespace App\Exceptions\Api;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Answers every /api/* request with a uniform JSON error.
 *
 * The web application renders Inertia error pages (see bootstrap/app.php), so
 * the API needs its own handling: same shape for all errors, Spanish messages
 * and never a stack trace.
 *
 * Error shape: { "message": "...", "errors": { "campo": ["..."] } }
 */
class ApiExceptionRenderer
{
    /**
     * Framework messages that must never reach the user in English.
     *
     * @var array<int, string>
     */
    private const FRAMEWORK_MESSAGES = [
        'This action is unauthorized.',
        'Unauthenticated.',
        'Not Found',
        'Server Error',
        'Too Many Attempts.',
        'Http Exception',
    ];

    public function register(Exceptions $exceptions): void
    {
        // Only the versioned mobile API is answered with JSON. The web keeps
        // its Inertia error pages untouched.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*')
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (!$this->isApiRequest($request)) {
                return null;
            }

            return response()->json(['message' => 'No autenticado.'], 401);
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (!$this->isApiRequest($request)) {
                return null;
            }

            return response()->json([
                'message' => $e->validator->errors()->first(),
                'errors' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (!$this->isApiRequest($request)) {
                return null;
            }

            return response()->json([
                'message' => $this->resolveMessage($e->getMessage(), 'Tu usuario no tiene permiso para esta acción.'),
            ], 403);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (!$this->isApiRequest($request)) {
                return null;
            }

            // Never expose "No query results for model [...]" to the app.
            $default = $e->getPrevious() instanceof ModelNotFoundException
                ? 'Recurso no encontrado.'
                : $this->resolveMessage($e->getMessage(), 'Recurso no encontrado.');

            return response()->json(['message' => $default], 404);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (!$this->isApiRequest($request)) {
                return null;
            }

            $status = $e->getStatusCode();

            return response()->json([
                'message' => $this->resolveMessage($e->getMessage(), $this->defaultMessageFor($status)),
            ], $status);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (!$this->isApiRequest($request)) {
                return null;
            }

            return response()->json([
                'message' => 'Ocurrió un error en el servidor. Inténtalo de nuevo.',
            ], 500);
        });
    }

    private function isApiRequest(Request $request): bool
    {
        return $request->is('api/*');
    }

    private function resolveMessage(string $message, string $default): string
    {
        $message = trim($message);

        if ($message === '' || in_array($message, self::FRAMEWORK_MESSAGES, true)) {
            return $default;
        }

        return $message;
    }

    private function defaultMessageFor(int $status): string
    {
        return match ($status) {
            400 => 'La solicitud no es válida.',
            401 => 'No autenticado.',
            403 => 'Tu usuario no tiene permiso para esta acción.',
            404 => 'Recurso no encontrado.',
            409 => 'La operación entra en conflicto con el estado actual del sistema.',
            419 => 'Tu sesión expiró. Inicia sesión de nuevo.',
            422 => 'Los datos enviados no son válidos.',
            429 => 'Demasiadas solicitudes. Espera un momento e inténtalo de nuevo.',
            500 => 'Ocurrió un error en el servidor. Inténtalo de nuevo.',
            503 => 'El servicio no está disponible por el momento.',
            default => 'Ocurrió un error al procesar la operación.',
        };
    }
}
