<?php

declare(strict_types=1);

namespace App\Security;

/** Compatibility for AdminUser roles serialized in sessions before #99. */
class_alias(\App\Service\Security\TeamRole::class, __NAMESPACE__.'\\TeamRole');
