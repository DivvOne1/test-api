<?php

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBulkNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::enum(NotificationChannel::class)],
            'priority' => ['required', Rule::enum(NotificationPriority::class)],
            'message' => ['required', 'string', 'max:5000'],
            'recipient_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'recipient_ids.*' => ['required', 'string', 'max:255', 'distinct'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}
