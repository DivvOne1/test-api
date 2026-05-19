<?php

namespace App\Enums;

enum NotificationPriority: string
{
    case Transactional = 'transactional';
    case Marketing = 'marketing';

    public function amqpPriority(): int
    {
        return $this === self::Transactional ? 10 : 1;
    }
}
