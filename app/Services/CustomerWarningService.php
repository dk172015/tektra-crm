<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use Carbon\Carbon;

class CustomerWarningService
{
    public function refreshWarning(Customer $customer): Customer
    {
        $lastAssignmentAt = $customer->assignments()->max('assigned_at');
        $lastNoteOrStatusAt = $customer->activities()
            ->whereIn('type', ['note', 'status_change'])
            ->max('activity_time');

        $baseTime = $lastAssignmentAt ?: $customer->created_at;
        $lastFollowUpAt = $baseTime;

        if ($lastNoteOrStatusAt && Carbon::parse($lastNoteOrStatusAt)->gt(Carbon::parse($baseTime))) {
            $lastFollowUpAt = $lastNoteOrStatusAt;
        }

        $days = Carbon::parse($lastFollowUpAt)->startOfDay()->diffInDays(now()->startOfDay());

        $warningLevel = null;
        $lockedByAdmin = false;

        if ($days >= 4) {
            $warningLevel = 'red';
            $lockedByAdmin = true;
        } elseif ($days >= 3) {
            $warningLevel = 'yellow';
        }

        $customer->update([
            'warning_level' => $warningLevel,
            'warning_locked_by_admin' => $lockedByAdmin,
            'warning_updated_at' => now(),
        ]);

        return $customer->fresh();
    }

    public function resolveByAction(Customer $customer, User $actor, string $actionType, string $actionMessage): Customer
    {
        $canResolve = false;

        if ($customer->warning_level === 'yellow') {
            $canResolve = true;
        }

        if ($customer->warning_level === 'red' && $actor->isAdmin()) {
            $canResolve = true;
        }

        if ($canResolve) {
            $customer->update([
                'warning_level' => null,
                'warning_locked_by_admin' => false,
                'warning_updated_at' => now(),
            ]);

            $customer->activities()->create([
                'user_id' => $actor->id,
                'type' => 'warning_resolved',
                'content' => $actionMessage,
                'activity_time' => now(),
            ]);
        }

        return $customer->fresh();
    }
}