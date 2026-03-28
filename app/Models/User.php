<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function createdCustomers(): HasMany
    {
        return $this->hasMany(Customer::class, 'created_by');
    }

    public function customerAssignments(): HasMany
    {
        return $this->hasMany(CustomerAssignment::class);
    }

    public function assignedCustomers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_assignments')
            ->withPivot(['is_primary', 'assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    public function customerActivities(): HasMany
    {
        return $this->hasMany(CustomerActivity::class);
    }

    public function createdViewings(): HasMany
    {
        return $this->hasMany(Viewing::class, 'created_by');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isSale(): bool
    {
        return $this->role === 'sale';
    }
}