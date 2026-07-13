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

    /**
     * Return category labels for tools the user has permission to use.
     *
     * @return array<int, string>
     */
    public function categoriesForUser(Authenticatable $user): array
    {
        if (method_exists($this->provider, 'categories')) {
            return $this->provider->categories($user);
        }

        return [];
    }
}
