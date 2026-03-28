<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'sale'], true);
    }

    public function view(User $user, Customer $customer): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($customer->created_by === $user->id) {
            return true;
        }

        return $customer->assignments()->where('user_id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'sale'], true);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->view($user, $customer);
    }

    public function assign(User $user, Customer $customer): bool
    {
        return $user->isAdmin();
    }
}