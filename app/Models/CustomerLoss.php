<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLoss extends Model
{
    protected $fillable = [
        'customer_id',
        'created_by',
        'reason',
        'competitor_name',
        'lost_price',
        'lost_at',
        'note',
    ];

    protected $casts = [
        'lost_price' => 'decimal:2',
        'lost_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}