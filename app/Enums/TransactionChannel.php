<?php

namespace App\Enums;

enum TransactionChannel: string
{
    case POS = 'punto_de_venta';
    case ONLINE_STORE = 'tienda_en_linea';
    case SERVICE_ORDER = 'orden_de_servicio';
    case QUOTE = 'cotizacion';
    case MANUAL = 'manual';
    case BALANCE_PAYMENT = 'abono_a_saldo';
}