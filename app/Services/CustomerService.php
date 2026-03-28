<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerActivity;
use App\Models\CustomerAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public function createCustomer(array $data, User $actor): Customer
    {
        return DB::transaction(function () use ($data, $actor) {
            $customer = Customer::create([
                'company_name' => $data['company_name'] ?? null,
                'contact_name' => $data['contact_name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'lead_source_id' => $data['lead_source_id'] ?? null,
                'source_detail' => $data['source_detail'] ?? null,
                'campaign_name' => $data['campaign_name'] ?? null,
                'status' => $data['status'] ?? 'new',
                'note' => $data['note'] ?? null,
                'created_by' => $actor->id,
            ]);

            if (!empty($data['requirement'])) {
                $customer->requirement()->create([
                    'preferred_location' => $data['requirement']['preferred_location'] ?? null,
                    'area_min' => $data['requirement']['area_min'] ?? null,
                    'area_max' => $data['requirement']['area_max'] ?? null,
                    'budget_min' => $data['requirement']['budget_min'] ?? null,
                    'budget_max' => $data['requirement']['budget_max'] ?? null,
                    'move_in_date' => $data['requirement']['move_in_date'] ?? null,
                    'lease_term_months' => $data['requirement']['lease_term_months'] ?? null,
                    'special_requirements' => $data['requirement']['special_requirements'] ?? null,
                ]);
            }

            if ($actor->isSale()) {
                CustomerAssignment::create([
                    'customer_id' => $customer->id,
                    'user_id' => $actor->id,
                    'is_primary' => true,
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                ]);
            }

            if ($actor->isAdmin() && !empty($data['assigned_users'])) {
                $assignedUsers = array_values(array_unique($data['assigned_users']));
                $primaryUserId = isset($data['primary_user_id']) ? (int) $data['primary_user_id'] : null;

                if ($primaryUserId !== null && !in_array($primaryUserId, $assignedUsers, true)) {
                    throw ValidationException::withMessages([
                        'primary_user_id' => 'Sale chính phải nằm trong danh sách assigned_users.',
                    ]);
                }

                foreach ($assignedUsers as $userId) {
                    CustomerAssignment::create([
                        'customer_id' => $customer->id,
                        'user_id' => $userId,
                        'is_primary' => $primaryUserId !== null && (int) $userId === $primaryUserId,
                        'assigned_by' => $actor->id,
                        'assigned_at' => now(),
                    ]);
                }
            }

            CustomerActivity::create([
                'customer_id' => $customer->id,
                'user_id' => $actor->id,
                'type' => 'note',
                'content' => 'Tạo khách hàng mới.',
                'activity_time' => now(),
            ]);

            return $customer->load([
                'creator:id,name',
                'leadSource:id,code,name',
                'assignedUsers:id,name,email',
                'requirement',
            ]);
        });
    }

    public function syncAssignments(Customer $customer, array $assignedUsers, int $primaryUserId, User $actor): Customer
    {
        if (!$actor->isAdmin()) {
            abort(403, 'Bạn không có quyền phân công khách hàng.');
        }

        $assignedUsers = array_values(array_unique($assignedUsers));

        if (!in_array($primaryUserId, $assignedUsers, true)) {
            throw ValidationException::withMessages([
                'primary_user_id' => 'Sale chính phải nằm trong danh sách assigned_users.',
            ]);
        }

        return DB::transaction(function () use ($customer, $assignedUsers, $primaryUserId, $actor) {
            $oldAssignments = $customer->assignments()->with('user:id,name')->get();
            $oldNames = $oldAssignments->pluck('user.name')->filter()->implode(', ');

            $customer->assignments()->delete();

            foreach ($assignedUsers as $userId) {
                CustomerAssignment::create([
                    'customer_id' => $customer->id,
                    'user_id' => $userId,
                    'is_primary' => (int) $userId === $primaryUserId,
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                ]);
            }

            $newNames = User::whereIn('id', $assignedUsers)->pluck('name')->implode(', ');
            $primaryName = User::whereKey($primaryUserId)->value('name');

            CustomerActivity::create([
                'customer_id' => $customer->id,
                'user_id' => $actor->id,
                'type' => 'assignment_change',
                'content' => "Điều chỉnh phân công. Trước: [{$oldNames}] | Sau: [{$newNames}] | Sale chính: {$primaryName}",
                'activity_time' => now(),
            ]);

            return $customer->fresh([
                'creator:id,name',
                'leadSource:id,code,name',
                'assignedUsers:id,name,email',
                'requirement',
            ]);
        });
    }

    public function updateRequirement(Customer $customer, array $data, User $actor): Customer
    {
        $customer->requirement()->updateOrCreate(
            ['customer_id' => $customer->id],
            [
                'preferred_location' => $data['preferred_location'] ?? null,
                'area_min' => $data['area_min'] ?? null,
                'area_max' => $data['area_max'] ?? null,
                'budget_min' => $data['budget_min'] ?? null,
                'budget_max' => $data['budget_max'] ?? null,
                'move_in_date' => $data['move_in_date'] ?? null,
                'lease_term_months' => $data['lease_term_months'] ?? null,
                'special_requirements' => $data['special_requirements'] ?? null,
            ]
        );

        CustomerActivity::create([
            'customer_id' => $customer->id,
            'user_id' => $actor->id,
            'type' => 'note',
            'content' => 'Cập nhật nhu cầu thuê.',
            'activity_time' => now(),
        ]);

        return $customer->fresh([
            'creator:id,name',
            'leadSource:id,code,name',
            'assignedUsers:id,name,email',
            'requirement',
        ]);
    }
}