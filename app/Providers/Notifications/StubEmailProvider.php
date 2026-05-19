<?php

namespace App\Providers\Notifications;

use App\Contracts\NotificationProvider;
use App\Models\Notification;
use App\Services\ProviderPermanentException;
use App\Services\ProviderResult;
use App\Services\ProviderTemporaryException;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class StubEmailProvider implements NotificationProvider
{
    public function send(Notification $notification): ProviderResult
    {
        $this->guardFailures($notification);

        $providerKey = "provider:email:{$notification->id}";
        $existingMessageId = Redis::get($providerKey);

        if ($existingMessageId) {
            return new ProviderResult($existingMessageId, ['deduplicated' => true]);
        }

        $messageId = 'email-'.Str::uuid();
        Redis::set($providerKey, $messageId);

        return new ProviderResult($messageId);
    }

    private function guardFailures(Notification $notification): void
    {
        $subscriberId = $notification->subscriber_id;

        if (str_contains($subscriberId, 'invalid')) {
            throw new ProviderPermanentException('Invalid email recipient.');
        }

        if (str_contains($subscriberId, 'retry') && $notification->attempts <= 1) {
            throw new ProviderTemporaryException('Temporary email provider failure.');
        }
    }
}
