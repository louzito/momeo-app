<?php

declare(strict_types=1);

namespace App\Security;

enum TeamRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Reception = 'reception';
    case Practitioner = 'practitioner';
}
