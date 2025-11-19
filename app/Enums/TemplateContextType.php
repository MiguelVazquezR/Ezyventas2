<?php

namespace App\Enums;

enum TemplateContextType: string
{
    case TRANSACTION = 'transaction';
    case PRODUCT = 'product';
    case SERVICE_ORDER = 'service_order';
    case QUOTE = 'quote';
    case GENERAL = 'general'; // Para plantillas sin variables específicas (ej. solo logo y texto fijo)
}
