<?php

namespace Ezyventas\AiAgent\Support;

use Ezyventas\AiAgent\Contracts\AiToolProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Prism\Prism\Tool;

class ToolRegistry
{
    public function __construct(private readonly AiToolProvider $provider) {}

    /**
     * Resolve tenant-aware tools for the given user.
     *
     * @param  Authenticatable  $user
     * @return array<int, Tool>
     */
    public function forUser(Authenticatable $user): array
    {
        return $this->provider->tools($user);
    }
}
