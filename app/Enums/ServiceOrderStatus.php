<?php

namespace App\Enums;

enum ServiceOrderStatus: string
{
    case PENDING = 'pendiente';
    case IN_PROGRESS = 'en_progreso';
    case WAITING_FOR_PARTS = 'esperando_refaccion';
    case FINISHED = 'terminado';
    case DELIVERED = 'entregado';
    case CANCELLED = 'cancelado';
}