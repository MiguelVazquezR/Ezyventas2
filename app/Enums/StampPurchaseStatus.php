<?php

namespace App\Enums;

enum StampPurchaseStatus: string
{
    case PENDING = 'pending';
    case AWAITING_REVIEW = 'awaiting_review';
    case APPROVED = 'approved';
    case AWAITING_RESELLER = 'awaiting_reseller';
    case REJECTED = 'rejected';
    case FAILED = 'failed';
    case STAMPS_APPLIED = 'stamps_applied';
}
