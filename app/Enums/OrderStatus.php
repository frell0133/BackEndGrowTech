<?php

namespace App\Enums;

enum OrderStatus: string
{
    case CREATED = 'created';
    case PENDING = 'pending';
    case PAID = 'paid';
    case FULFILLED = 'fulfilled';
    case CANCELLED = 'cancelled';
    case FAILED = 'failed';
    case EXPIRED = 'expired';
    case REFUNDED = 'refunded';
}
