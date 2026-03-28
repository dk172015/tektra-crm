<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDeal extends Model
{
    protected $fillable = [
        'customer_id',
        'created_by',
        'contract_code',
        'building_name',
        'address',
        'area',
        'monthly_revenue',
        'lease_term_months',
        'signed_date',
        'start_date',
        'status',
        'note',
    ];

    protected $casts = [
        'area' => 'decimal:2',
        'monthly_revenue' => 'decimal:2',
        'signed_date' => 'date',
        'start_date' => 'date',
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