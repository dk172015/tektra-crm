<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerActivity extends Model
{
    protected $fillable = [
        'customer_id',
        'user_id',
        'type',
        'content',
        'activity_time',
    ];

    protected $casts = [
        'activity_time' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}