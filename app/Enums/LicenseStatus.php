<?php

namespace App\Enums;

enum LicenseStatus: string
{
    case AVAILABLE = 'available';
    case USED = 'used';
    case REVOKED = 'revoked';
}
