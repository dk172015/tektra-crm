<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDeal extends Model
{
    protected $fillable = [
        'customer_id',
        'created_by',
        'closer_user_id',
        'project_code',
        'building_name',
        'address',
        'floor',
        'area',
        'rental_price',
        'contract_term_months',
        'first_payment_date',
        'brokerage_fee',
        'note',
        'status',
        'signed_at',
    ];

    protected $casts = [
        'area' => 'decimal:2',
        'rental_price' => 'decimal:2',
        'brokerage_fee' => 'decimal:2',
        'first_payment_date' => 'date',
        'signed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closer_user_id');
    }
}