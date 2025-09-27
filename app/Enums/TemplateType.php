<?php

namespace App\Enums;

enum TemplateType: string
{
    case SALE_TICKET = 'ticket_venta';
    case LABEL = 'etiqueta';
    case QUOTE = 'cotizacion';
}
