<?php

namespace App\Enums;

enum PromotionType: string
{
    case ITEM_DISCOUNT = 'ITEM_DISCOUNT';
    case CART_DISCOUNT = 'CART_DISCOUNT';
    case BUNDLE_PRICE = 'BUNDLE_PRICE';
    case BOGO = 'BOGO'; // Buy One, Get One
}