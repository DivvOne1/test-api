<?php

namespace App\Enums;

enum ProviderFailureType: string
{
    case Temporary = 'temporary';
    case Permanent = 'permanent';
}
