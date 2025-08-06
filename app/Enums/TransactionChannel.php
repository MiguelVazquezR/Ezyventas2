<?php

namespace App\Enums;

enum TransactionChannel: string
{
    case POS = 'pos';
    case ONLINE_STORE = 'online_store';
}