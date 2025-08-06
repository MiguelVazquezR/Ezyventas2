<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case ACTIVE = 'activo';
    case EXPIRED = 'expirado';
    case SUSPENDED = 'suspendido';
}