<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'actor_id',
    'actor_name',
    'actor_role',
    'module',
    'action',
    'record_type',
    'record_id',
    'record_code',
    'result',
    'description',
    'before_values',
    'after_values',
    'request_id',
    'ip_address',
    'route_name',
    'http_method',
    'source',
])]
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'before_values' => 'array',
            'after_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (AuditEvent $event): void {
            throw new LogicException('Audit entries cannot be updated.');
        });

        static::deleting(function (AuditEvent $event): void {
            throw new LogicException('Audit entries cannot be deleted.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}