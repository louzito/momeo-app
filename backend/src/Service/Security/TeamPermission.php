<?php

declare(strict_types=1);

namespace App\Service\Security;

enum TeamPermission: string
{
    case Agenda = 'agenda';
    case Clients = 'clients';
    case Finances = 'finances';
    case Catalog = 'catalog';
    case Settings = 'settings';
}
