<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'user_id',
        'is_primary',
        'assigned_by',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'assigned_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}