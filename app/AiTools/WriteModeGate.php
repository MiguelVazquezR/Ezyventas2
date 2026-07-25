<?php

namespace App\AiTools;

/**
 * Per-request gate that controls whether the AI agent is allowed
 * to execute write operations (create, update, delete).
 *
 * By default, write mode is DISABLED. The user must explicitly
 * activate it through the chat drawer toggle before any
 * mutating tool can execute.
 *
 * This is a request-scoped singleton — it resets on every HTTP request.
 */
class WriteModeGate
{
    private bool $enabled = false;

    /**
     * Enable write mode for the current request.
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Disable write mode for the current request.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Check whether write operations are currently allowed.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Human-readable reason why write mode is disabled,
     * returned to the LLM so it can instruct the user.
     */
    public function rejectionMessage(): string
    {
        return 'Modo escritura no activado. Para crear, editar o eliminar registros, activa el modo escritura desde el candado en el panel del asistente.';
    }
}