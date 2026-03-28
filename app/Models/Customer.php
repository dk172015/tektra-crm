<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_name',
        'contact_name',
        'phone',
        'email',
        'lead_source_id',
        'source_detail',
        'campaign_name',
        'status',
        'note',
        'created_by',
        'warning_level',
        'warning_locked_by_admin',
        'warning_updated_at',
        'is_priority',
        'priority_marked_at',
        'priority_marked_by',
    ];

    protected $casts = [
        'warning_locked_by_admin' => 'boolean',
        'warning_updated_at' => 'datetime',
        'is_priority' => 'boolean',
        'priority_marked_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CustomerAssignment::class);
    }

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'customer_assignments')
            ->withPivot(['is_primary', 'assigned_by', 'assigned_at'])
            ->withTimestamps();
    }

    public function requirement(): HasOne
    {
        return $this->hasOne(CustomerRequirement::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CustomerActivity::class)->latest('activity_time');
    }

    public function latestActivity(): HasOne
    {
        return $this->hasOne(CustomerActivity::class)
            ->where('type', 'note')
            ->latestOfMany('activity_time');
    }

    public function viewings(): HasMany
    {
        return $this->hasMany(Viewing::class)->latest('viewing_time');
    }

    public function priorityMarker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'priority_marked_by');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('created_by', $user->id)
                ->orWhereHas('assignments', function (Builder $sub) use ($user) {
                    $sub->where('user_id', $user->id);
                });
        });
    }
}