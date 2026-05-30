<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Reviewed = 'reviewed';
    case InPreparation = 'in_preparation';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Reviewed => 'En revisión',
            self::InPreparation => 'En preparación',
            self::Ready => 'Listo',
            self::Delivered => 'Entregado',
            self::Cancelled => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Reviewed => 'info',
            self::InPreparation => 'info',
            self::Ready => 'success',
            self::Delivered => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Returns the allowed next statuses from the current one.
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Reviewed, self::Cancelled],
            self::Reviewed => [self::InPreparation, self::Cancelled],
            self::InPreparation => [self::Ready, self::Cancelled],
            self::Ready => [self::Delivered, self::Cancelled],
            self::Delivered => [],
            self::Cancelled => [],
        };
    }
}
