<?php

declare(strict_types=1);

namespace App\Security;

enum TeamPermission: string
{
    case Agenda = 'agenda';
    case Clients = 'clients';
    case Finances = 'finances';
    case Catalog = 'catalog';
    case Settings = 'settings';
}
