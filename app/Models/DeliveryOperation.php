<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryOperation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'operation_key',
        'bot_user_id',
        'message_id',
        'trace_id',
        'destination',
        'operation',
        'status',
        'external_message_id',
        'attempts',
        'last_error',
        'started_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
