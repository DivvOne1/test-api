<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Notification extends Model
{
    use HasFactory;
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'notification_batch_id',
        'subscriber_id',
        'channel',
        'priority',
        'message',
        'status',
        'attempts',
        'provider_message_id',
        'last_error',
        'sent_at',
        'delivered_at',
        'dropped_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'dropped_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(NotificationBatch::class, 'notification_batch_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(NotificationStatusEvent::class)->orderBy('created_at');
    }
}
