<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CustomerService;
use App\Services\CustomerWarningService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(
        private CustomerService $customerService,
        private CustomerWarningService $warningService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $user = $request->user();
        $activeStatuses = ['new', 'consulting', 'viewing', 'negotiating', 'deposit'];
        $closedStatuses = ['contracted', 'lost'];

        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        $query = Customer::query()
            ->with([
                'creator:id,name,email',
                'leadSource:id,code,name',
                'assignedUsers:id,name,email',
                'requirement',
                'latestActivity.user:id,name',
                'priorityMarker:id,name',
            ])
            ->withMax('assignments as last_assignment_at', 'assigned_at')
            ->withMax([
                'activities as last_note_or_status_at' => function ($q) {
                    $q->whereIn('type', ['note', 'status_change']);
                }
            ], 'activity_time')
            ->visibleTo($user)
            ->orderByDesc('is_priority')
            ->orderByDesc('priority_marked_at')
            ->latest();
            
        if (!$request->filled('date_from') && !$request->filled('date_to') && !$request->filled('status')) {
            $query->where(function ($q) use ($activeStatuses, $closedStatuses, $startOfMonth, $endOfMonth) {
                $q->whereIn('status', $activeStatuses)
                ->orWhere(function ($sub) use ($closedStatuses, $startOfMonth, $endOfMonth) {
                    $sub->whereIn('status', $closedStatuses)
                        ->whereBetween('updated_at', [$startOfMonth, $endOfMonth]);
                });
            });
        }
        if ($request->filled('status')) {
            $status = (string) $request->input('status');
            $query->where('status', $status);

            if (
                in_array($status, ['contracted', 'lost'], true) &&
                !$request->filled('date_from') &&
                !$request->filled('date_to')
            ) {
                $query->whereBetween('updated_at', [$startOfMonth, $endOfMonth]);
            }
        }

        if ($request->filled('lead_source_id')) {
            $query->where('lead_source_id', $request->integer('lead_source_id'));
        }

        if ($request->filled('sale_user_id')) {
            $saleUserId = $request->integer('sale_user_id');

            $query->whereHas('assignments', function ($q) use ($saleUserId) {
                $q->where('user_id', $saleUserId);
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));

            $query->where(function ($q) use ($keyword) {
                $q->where('company_name', 'like', "%{$keyword}%")
                    ->orWhere('contact_name', 'like', "%{$keyword}%")
                    ->orWhere('phone', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%");
            });
        }

        $result = $query->paginate((int) $request->get('per_page', 500));

        $result->setCollection(
            $result->getCollection()->map(function ($item) {
                $lastAssignmentAt = $item->last_assignment_at
                    ? Carbon::parse($item->last_assignment_at)
                    : Carbon::parse($item->created_at);

                $lastNoteOrStatusAt = $item->last_note_or_status_at
                    ? Carbon::parse($item->last_note_or_status_at)
                    : null;

                $lastFollowUpAt = $lastAssignmentAt;

                if ($lastNoteOrStatusAt && $lastNoteOrStatusAt->gt($lastAssignmentAt)) {
                    $lastFollowUpAt = $lastNoteOrStatusAt;
                }

                $item->last_follow_up_at = $lastFollowUpAt->toDateTimeString();
                $item->warning_days = $item->warning_level
                    ? $lastFollowUpAt->copy()->startOfDay()->diffInDays(now()->startOfDay())
                    : null;

                return $item;
            })
        );

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Customer::class);

        $validated = $request->validate([
            'company_name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'lead_source_id' => ['nullable', 'integer', 'exists:lead_sources,id'],
            'source_detail' => ['nullable', 'string', 'max:255'],
            'campaign_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:new,consulting,viewing,negotiating,deposit,contracted,lost'],
            'note' => ['nullable', 'string'],

            'assigned_users' => ['nullable', 'array'],
            'assigned_users.*' => ['integer', 'exists:users,id'],
            'primary_user_id' => ['nullable', 'integer', 'exists:users,id'],

            'requirement' => ['nullable', 'array'],
            'requirement.preferred_location' => ['nullable', 'string', 'max:255'],
            'requirement.area_min' => ['nullable', 'integer', 'min:0'],
            'requirement.area_max' => ['nullable', 'integer', 'min:0'],
            'requirement.budget_min' => ['nullable', 'numeric', 'min:0'],
            'requirement.budget_max' => ['nullable', 'numeric', 'min:0'],
            'requirement.move_in_date' => ['nullable', 'date'],
            'requirement.lease_term_months' => ['nullable', 'integer', 'min:1'],
            'requirement.special_requirements' => ['nullable', 'string'],
        ]);

        $customer = $this->customerService->createCustomer($validated, $request->user());

        $this->warningService->refreshWarning($customer);

        return response()->json(
            $customer->load([
                'creator:id,name,email',
                'leadSource:id,code,name',
                'assignedUsers:id,name,email',
                'requirement',
                'latestActivity.user:id,name',
                'priorityMarker:id,name',
            ]),
            201
        );
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $customer->load([
            'creator:id,name,email',
            'leadSource:id,code,name',
            'assignedUsers:id,name,email',
            'requirement',
            'latestActivity.user:id,name',
            'activities.user:id,name',
            'viewings.property:id,building_name,address,district,area,price_per_m2,status',
            'viewings.creator:id,name',
            'priorityMarker:id,name',
        ]);

        $lastAssignmentAt = $customer->assignments()->max('assigned_at');
        $lastNoteOrStatusAt = $customer->activities()
            ->whereIn('type', ['note', 'status_change'])
            ->max('activity_time');

        $baseTime = $lastAssignmentAt ?: $customer->created_at;
        $lastFollowUpAt = $baseTime;

        if ($lastNoteOrStatusAt && Carbon::parse($lastNoteOrStatusAt)->gt(Carbon::parse($baseTime))) {
            $lastFollowUpAt = $lastNoteOrStatusAt;
        }

        $customer->last_follow_up_at = Carbon::parse($lastFollowUpAt)->toDateTimeString();
        $customer->warning_days = $customer->warning_level
            ? Carbon::parse($lastFollowUpAt)->startOfDay()->diffInDays(now()->startOfDay())
            : null;

        return response()->json($customer);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'company_name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'lead_source_id' => ['nullable', 'integer', 'exists:lead_sources,id'],
            'source_detail' => ['nullable', 'string', 'max:255'],
            'campaign_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:new,consulting,viewing,negotiating,deposit,contracted,lost'],
            'note' => ['nullable', 'string'],
        ]);

        $oldStatus = $customer->status;

        $customer->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            $customer->activities()->create([
                'user_id' => $request->user()->id,
                'type' => 'status_change',
                'content' => "Chuyển trạng thái từ {$oldStatus} sang {$validated['status']}.",
                'activity_time' => now(),
            ]);
        }

        if ($customer->warning_level) {
            $message = $customer->warning_level === 'red'
                ? 'Admin xử lý cảnh báo đỏ bằng cập nhật thông tin khách hàng.'
                : 'Xử lý cảnh báo vàng bằng cập nhật thông tin khách hàng.';

            $this->warningService->resolveByAction(
                $customer,
                $request->user(),
                'customer_update',
                $message
            );
        }

        return response()->json(
            $customer->fresh([
                'creator:id,name,email',
                'leadSource:id,code,name',
                'assignedUsers:id,name,email',
                'requirement',
                'latestActivity.user:id,name',
                'priorityMarker:id,name',
            ])
        );
    }

    public function updateStatus(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'status' => ['required', 'in:new,consulting,viewing,negotiating,deposit,contracted,lost'],
        ]);

        $oldStatus = $customer->status;
        $newStatus = $validated['status'];

        if (
            in_array($oldStatus, ['contracted', 'lost'], true) &&
            !$request->user()->isAdmin()
        ) {
            return response()->json([
                'message' => 'Chỉ admin mới được thay đổi trạng thái của khách hàng đã chốt hoặc mất khách.'
            ], 403);
        }

        $this->cleanupClosedDataWhenStatusChanged($customer, $oldStatus, $newStatus);

        $customer->update([
            'status' => $newStatus,
        ]);

        $this->logClosedDataCleanup($customer, $request->user()->id, $oldStatus, $newStatus);

        if ($oldStatus !== $validated['status']) {
            $customer->activities()->create([
                'user_id' => $request->user()->id,
                'type' => 'status_change',
                'content' => "Chuyển trạng thái từ {$oldStatus} sang {$validated['status']}.",
                'activity_time' => now(),
            ]);
        }

        if ($customer->warning_level) {
            $message = $customer->warning_level === 'red'
                ? 'Admin xử lý cảnh báo đỏ bằng cập nhật trạng thái.'
                : 'Xử lý cảnh báo vàng bằng cập nhật trạng thái.';

            $this->warningService->resolveByAction(
                $customer,
                $request->user(),
                'status_change',
                $message
            );
        }

        return response()->json(
            $customer->fresh([
                'creator:id,name,email',
                'leadSource:id,code,name',
                'assignedUsers:id,name,email',
                'requirement',
                'latestActivity.user:id,name',
                'priorityMarker:id,name',
            ])
        );
    }

    public function assign(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('assign', $customer);

        $validated = $request->validate([
            'assigned_users' => ['required', 'array', 'min:1'],
            'assigned_users.*' => ['integer', 'exists:users,id'],
            'primary_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $customer = $this->customerService->syncAssignments(
            $customer,
            $validated['assigned_users'],
            (int) $validated['primary_user_id'],
            $request->user()
        );

        if ($customer->warning_level) {
            $message = $customer->warning_level === 'red'
                ? 'Admin xử lý cảnh báo đỏ bằng điều chỉnh phân công sale.'
                : 'Xử lý cảnh báo vàng bằng điều chỉnh phân công sale.';

            $this->warningService->resolveByAction(
                $customer,
                $request->user(),
                'assignment_change',
                $message
            );
        }

        return response()->json([
            'message' => 'Cập nhật phân công thành công.',
            'data' => $customer->load([
                'creator:id,name,email',
                'leadSource:id,code,name',
                'assignedUsers:id,name,email',
                'requirement',
                'latestActivity.user:id,name',
                'priorityMarker:id,name',
            ]),
        ]);
    }

    public function updateRequirement(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);
        if (
            in_array($customer->status, ['contracted', 'lost'], true) &&
            !$request->user()->isAdmin()
        ) {
            return response()->json([
                'message' => 'Khách hàng ở trạng thái đã chốt hoặc mất khách, sale chỉ được xem.'
            ], 403);
        }

        $validated = $request->validate([
            'preferred_location' => ['nullable', 'string', 'max:255'],
            'area_min' => ['nullable', 'integer', 'min:0'],
            'area_max' => ['nullable', 'integer', 'min:0'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0'],
            'move_in_date' => ['nullable', 'date'],
            'lease_term_months' => ['nullable', 'integer', 'min:1'],
            'special_requirements' => ['nullable', 'string'],
        ]);

        $customer = $this->customerService->updateRequirement($customer, $validated, $request->user());

        if ($customer->warning_level) {
            $message = $customer->warning_level === 'red'
                ? 'Admin xử lý cảnh báo đỏ bằng cập nhật nhu cầu thuê.'
                : 'Xử lý cảnh báo vàng bằng cập nhật nhu cầu thuê.';

            $this->warningService->resolveByAction(
                $customer,
                $request->user(),
                'requirement_update',
                $message
            );
        }

        return response()->json($customer);
    }

    public function togglePriority(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $newValue = !$customer->is_priority;

        $customer->update([
            'is_priority' => $newValue,
            'priority_marked_at' => $newValue ? now() : null,
            'priority_marked_by' => $newValue ? $request->user()->id : null,
        ]);

        $customer->activities()->create([
            'user_id' => $request->user()->id,
            'type' => 'priority_change',
            'content' => $newValue
                ? 'Đánh dấu khách hàng quan trọng cần theo dõi.'
                : 'Bỏ đánh dấu khách hàng quan trọng.',
            'activity_time' => now(),
        ]);

        return response()->json([
            'message' => $newValue ? 'Đã đánh dấu ưu tiên.' : 'Đã bỏ đánh dấu ưu tiên.',
            'data' => $customer->fresh([
                'creator:id,name,email',
                'leadSource:id,code,name',
                'assignedUsers:id,name,email',
                'requirement',
                'latestActivity.user:id,name',
                'priorityMarker:id,name',
            ]),
        ]);
    }
    private function cleanupClosedDataWhenStatusChanged(Customer $customer, string $oldStatus, string $newStatus): void
    {
        if ($oldStatus === $newStatus) {
            return;
        }

        if ($oldStatus === 'contracted' && $newStatus !== 'contracted') {
            $customer->deals()->delete();
        }

        if ($oldStatus === 'lost' && $newStatus !== 'lost') {
            $customer->losses()->delete();
        }
    }

    private function logClosedDataCleanup(Customer $customer, int $userId, string $oldStatus, string $newStatus): void
    {
        if ($oldStatus === $newStatus) {
            return;
        }

        if ($oldStatus === 'contracted' && $newStatus !== 'contracted') {
            $customer->activities()->create([
                'user_id' => $userId,
                'type' => 'deal_removed',
                'content' => 'Đã xóa dữ liệu hợp đồng do chuyển khách khỏi trạng thái contracted.',
                'activity_time' => now(),
            ]);
        }

        if ($oldStatus === 'lost' && $newStatus !== 'lost') {
            $customer->activities()->create([
                'user_id' => $userId,
                'type' => 'loss_removed',
                'content' => 'Đã xóa dữ liệu mất khách do chuyển khách khỏi trạng thái lost.',
                'activity_time' => now(),
            ]);
        }
    }
}