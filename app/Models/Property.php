<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    use HasFactory;

    protected $fillable = [
        'building_name',
        'address',
        'district',
        'area',
        'price_per_m2',
        'status',
    ];

    public function viewings(): HasMany
    {
        return $this->hasMany(Viewing::class);
    }
}