<?php

namespace App\Enums;

/**
 * Type of PAC account.
 *
 * 'subaccount' — dealer subaccount we provision ourselves.
 * 'shared'     — external account provided by the reseller (Conectia) shared
 *                by multiple subscribers' RFCs; stamps are managed locally.
 */
enum PacAccountType: string
{
    case SUBACCOUNT = 'subaccount';
    case SHARED = 'shared';

    public function label(): string
    {
        return match ($this) {
            self::SUBACCOUNT => 'Subcuenta',
            self::SHARED     => 'Cuenta compartida',
        };
    }
}
