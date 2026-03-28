<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerRequirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'preferred_location',
        'area_min',
        'area_max',
        'budget_min',
        'budget_max',
        'move_in_date',
        'lease_term_months',
        'special_requirements',
    ];

    protected function casts(): array
    {
        return [
            'move_in_date' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}