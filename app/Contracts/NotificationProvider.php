<?php

namespace App\Contracts;

use App\Models\Notification;
use App\Services\ProviderResult;

interface NotificationProvider
{
    public function send(Notification $notification): ProviderResult;
}
