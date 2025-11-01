<?php

namespace App\Enums;

enum SubscriptionPaymentStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}