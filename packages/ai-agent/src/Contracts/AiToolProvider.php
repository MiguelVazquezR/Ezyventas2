<?php

namespace Ezyventas\AiAgent\Contracts;

use Ezyventas\AiAgent\Schema\Tool;
use Illuminate\Contracts\Auth\Authenticatable;

interface AiToolProvider
{
    /**
     * Return the list of tools available to the authenticated user.
     *
     * Each tool closure MUST derive tenant scoping (subscription_id / branch_id)
     * from $user server-side — never from a tool parameter supplied by the LLM.
     *
     * @param  Authenticatable  $user
     * @return array<int, Tool>
     */
    public function tools(Authenticatable $user): array;
}
