<?php

namespace App\Enums;

enum OrganizationAdministrationEventKind: string
{
    case Created = 'created';
    case Renamed = 'renamed';
}
