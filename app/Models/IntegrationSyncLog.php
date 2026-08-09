<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationSyncLog extends Model
{
    protected $table = 'integration_sync_logs';

    protected $fillable = [
        'service_name',
        'endpoint',
        'requested_at',
        'status',
        'records_count',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'details' => 'array',
        ];
    }
}
