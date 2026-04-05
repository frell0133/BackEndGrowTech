<?php

namespace App\Enums;

enum LicenseStatus: string
{
    case AVAILABLE = 'available';
    case TAKEN = 'taken';
    case RESERVED = 'reserved';
    case SOLD = 'sold';
    case DISABLED = 'disabled';
    case USED = 'used';
    case REVOKED = 'revoked';
}
