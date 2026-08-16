<?php

namespace App\Enums;

/**
 * Lifecycle status of a PAC account.
 *
 * - pending_request:    the subscriber requested the account; an admin
 *                       still has to coordinate the setup with the reseller.
 * - pending_activation: the admin has the credentials but the PAC has not
 *                       validated them yet (or they are being entered).
 * - active:             credentials validated against the PAC.
 * - inactive:           deactivated.
 */
enum PacAccountStatus: string
{
    case PENDING_REQUEST = 'pending_request';
    case PENDING_ACTIVATION = 'pending_activation';
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::PENDING_REQUEST    => 'Pendiente de solicitud',
            self::PENDING_ACTIVATION => 'Pendiente de activación',
            self::ACTIVE             => 'Activa',
            self::INACTIVE           => 'Inactiva',
        };
    }
}
