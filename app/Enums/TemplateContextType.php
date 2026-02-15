<?php

namespace App\Enums;

enum TemplateContextType: string
{
    case POS = 'pos'; //solo mostrar en punto de venta
    case TRANSACTION = 'transaction'; //registro de transaccion
    case PRODUCT = 'product'; //registro de producto
    case SERVICE_ORDER = 'service_order';
    case QUOTE = 'quote';
    case CUSTOMER = 'customer';
    case GENERAL = 'general'; // Para plantillas sin variables específicas (ej. solo logo y texto fijo)
}
