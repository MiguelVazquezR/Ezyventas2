<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case COMPLETED = 'completado';
    case PENDING = 'pendiente'; // Pendiente de pago (crédito)
    case CANCELLED = 'cancelado';
    case REFUNDED = 'reembolsado';
    case ON_LAYAWAY = 'apartado';
    case CHANGED = 'cambiado';
    
    // Nuevos estatus logísticos
    case TO_DELIVER = 'por_entregar'; // Pedido recibido, stock reservado, esperando envío
    case IN_TRANSIT = 'en_ruta';      // En manos del repartidor
    case DELIVERED_UNPAID = 'entregado_por_pagar'; // Se entregó pero no se ha liquidado (crédito contra entrega)
}