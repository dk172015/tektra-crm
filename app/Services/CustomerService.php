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

        $assignedUsers = array_values(array_unique(array_map('intval', $assignedUsers)));

        if (!in_array($primaryUserId, $assignedUsers, true)) {
            throw ValidationException::withMessages([
                'primary_user_id' => 'Sale chính phải nằm trong danh sách assigned_users.',
            ]);
        }

        return DB::transaction(function () use ($customer, $assignedUsers, $primaryUserId, $actor) {
            $oldAssignments = $customer->assignments()
                ->with('user:id,name')
                ->where('is_active', true)
                ->get();

            $oldNames = $oldAssignments->pluck('user.name')->filter()->implode(', ');

            $allAssignments = $customer->assignments()
                ->orderByDesc('id')
                ->get();

            $currentActiveAssignments = $allAssignments
                ->where('is_active', true)
                ->keyBy('user_id');

            $currentActiveUserIds = $currentActiveAssignments->keys()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $toDeactivate = array_diff($currentActiveUserIds, $assignedUsers);
            $toActivateOrKeep = $assignedUsers;

            // 1) Tắt những sale không còn trong danh sách mới
            if (!empty($toDeactivate)) {
                CustomerAssignment::query()
                    ->where('customer_id', $customer->id)
                    ->whereIn('user_id', $toDeactivate)
                    ->where('is_active', true)
                    ->update([
                        'is_active' => false,
                        'is_primary' => false,
                        'ended_at' => now(),
                    ]);
            }

            // 2) Với từng sale trong danh sách mới:
            foreach ($toActivateOrKeep as $userId) {
                $userId = (int) $userId;

                $activeRow = CustomerAssignment::query()
                    ->where('customer_id', $customer->id)
                    ->where('user_id', $userId)
                    ->where('is_active', true)
                    ->latest('id')
                    ->first();

                if ($activeRow) {
                    $activeRow->update([
                        'is_primary' => $userId === (int) $primaryUserId,
                        'ended_at' => null,
                    ]);
                    continue;
                }

                // Không có active row -> tìm row cũ inactive để bật lại
                $inactiveRow = CustomerAssignment::query()
                    ->where('customer_id', $customer->id)
                    ->where('user_id', $userId)
                    ->where('is_active', false)
                    ->latest('id')
                    ->first();

                if ($inactiveRow) {
                    $inactiveRow->update([
                        'is_active' => true,
                        'is_primary' => $userId === (int) $primaryUserId,
                        'ended_at' => null,
                        'assigned_by' => $actor->id,
                        'assigned_at' => now(),
                    ]);
                    continue;
                }

                // Chưa từng có record -> tạo mới
                CustomerAssignment::create([
                    'customer_id' => $customer->id,
                    'user_id' => $userId,
                    'role' => 'main',
                    'is_active' => true,
                    'ended_at' => null,
                    'is_primary' => $userId === (int) $primaryUserId,
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                ]);
            }

            // 3) Đảm bảo chỉ có 1 sale chính active
            CustomerAssignment::query()
                ->where('customer_id', $customer->id)
                ->where('is_active', true)
                ->where('user_id', '!=', $primaryUserId)
                ->update([
                    'is_primary' => false,
                ]);

            CustomerAssignment::query()
                ->where('customer_id', $customer->id)
                ->where('user_id', $primaryUserId)
                ->where('is_active', true)
                ->update([
                    'is_primary' => true,
                ]);

            $newAssignments = $customer->assignments()
                ->with('user:id,name')
                ->where('is_active', true)
                ->get();

            $newNames = $newAssignments->pluck('user.name')->filter()->implode(', ');
            $primaryName = $newAssignments->firstWhere('is_primary', true)?->user?->name ?? null;

            CustomerActivity::create([
                'customer_id' => $customer->id,
                'user_id' => $actor->id,
                'type' => 'assignment_change',
                'content' => "Điều chỉnh phân công.\nTrước: [{$oldNames}] | Sau: [{$newNames}] | Sale chính: {$primaryName}",
                'activity_time' => now(),
            ]);

            return $customer->fresh([
                'creator:id,name',
                'leadSource:id,code,name',
                'assignedUsers:id,name,email',
                'assignments.user:id,name,email',
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
    public function addSupportSale(Customer $customer, int $userId, User $actor): Customer
    {
        if (!$actor->isAdmin()) {
            abort(403, 'Bạn không có quyền thêm sale phối hợp.');
        }

        $active = $customer->assignments()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->first();

        if ($active) {
            throw ValidationException::withMessages([
                'user_id' => 'Sale này đang phụ trách khách hàng.',
            ]);
        }

        $inactive = $customer->assignments()
            ->where('user_id', $userId)
            ->where('is_active', false)
            ->latest('id')
            ->first();

        if ($inactive) {
            $inactive->update([
                'is_active' => true,
                'is_primary' => false,
                'ended_at' => null,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);
        } else {
            CustomerAssignment::create([
                'customer_id' => $customer->id,
                'user_id' => $userId,
                'role' => 'main',
                'is_active' => true,
                'ended_at' => null,
                'is_primary' => false,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);
        }

        CustomerActivity::create([
            'customer_id' => $customer->id,
            'user_id' => $actor->id,
            'type' => 'assignment_change',
            'content' => 'Thêm sale phối hợp.',
            'activity_time' => now(),
        ]);

        return $customer->fresh([
            'creator:id,name',
            'leadSource:id,code,name',
            'assignedUsers:id,name,email',
            'assignments.user:id,name,email',
            'requirement',
        ]);
    }

    public function changePrimarySale(Customer $customer, int $primaryUserId, User $actor): Customer
    {
        if (!$actor->isAdmin()) {
            abort(403, 'Bạn không có quyền đổi sale chính.');
        }

        DB::transaction(function () use ($customer, $primaryUserId, $actor) {
            $activeTarget = $customer->assignments()
                ->where('user_id', $primaryUserId)
                ->where('is_active', true)
                ->first();

            if (!$activeTarget) {
                $inactiveTarget = $customer->assignments()
                    ->where('user_id', $primaryUserId)
                    ->where('is_active', false)
                    ->latest('id')
                    ->first();

                if ($inactiveTarget) {
                    $inactiveTarget->update([
                        'is_active' => true,
                        'ended_at' => null,
                        'assigned_by' => $actor->id,
                        'assigned_at' => now(),
                    ]);
                } else {
                    CustomerAssignment::create([
                        'customer_id' => $customer->id,
                        'user_id' => $primaryUserId,
                        'role' => 'main',
                        'is_active' => true,
                        'ended_at' => null,
                        'is_primary' => false,
                        'assigned_by' => $actor->id,
                        'assigned_at' => now(),
                    ]);
                }
            }

            $customer->assignments()
                ->where('is_active', true)
                ->update(['is_primary' => false]);

            $customer->assignments()
                ->where('user_id', $primaryUserId)
                ->where('is_active', true)
                ->update(['is_primary' => true]);

            $primaryName = User::whereKey($primaryUserId)->value('name');

            CustomerActivity::create([
                'customer_id' => $customer->id,
                'user_id' => $actor->id,
                'type' => 'assignment_change',
                'content' => "Đổi sale chính thành {$primaryName}.",
                'activity_time' => now(),
            ]);
        });

        return $customer->fresh([
            'creator:id,name',
            'leadSource:id,code,name',
            'assignedUsers:id,name,email',
            'assignments.user:id,name,email',
            'requirement',
        ]);
    }
}