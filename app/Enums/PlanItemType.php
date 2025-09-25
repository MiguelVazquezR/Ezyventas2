<?php

namespace App\Enums;

enum PlanItemType: string
{
    case MODULE = 'module'; // Para funcionalidades completas como "Punto de Venta"
    case LIMIT = 'limit';   // Para ampliaciones de capacidad como "Usuario Adicional"
}