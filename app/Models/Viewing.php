<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Viewing extends Model
{
    protected $fillable = [
        'customer_id',
        'property_id',
        'building_name',
        'address',
        'viewing_time',
        'status',
        'note',
        'created_by',
    ];

    protected $casts = [
        'viewing_time' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}