<?php

namespace App\Enums;

enum PromotionEffectType: string
{
    case FIXED_DISCOUNT = 'FIXED_DISCOUNT';
    case PERCENTAGE_DISCOUNT = 'PERCENTAGE_DISCOUNT';
    case SET_PRICE = 'SET_PRICE';
    case FREE_ITEM = 'FREE_ITEM';
}