<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class NvrDevice extends Model
{
    protected $fillable = [
        'name',
        'ip',
        'port',
        'site',
        'vendor',
        'channels',
        'enabled',
        'status',
        'latency_ms',
        'last_checked_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_checked_at' => 'datetime',
        ];
    }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(NvrHealthCheck::class);
    }
}
