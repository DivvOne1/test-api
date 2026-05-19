<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationBatch extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'idempotency_key',
        'channel',
        'priority',
        'message',
        'total_recipients',
    ];

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
