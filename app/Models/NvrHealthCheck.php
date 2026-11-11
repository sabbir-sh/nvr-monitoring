<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NvrHealthCheck extends Model
{
    protected $fillable = [
        'nvr_device_id',
        'status',
        'latency_ms',
        'message',
        'checked_at',
    ];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(NvrDevice::class, 'nvr_device_id');
    }
}
