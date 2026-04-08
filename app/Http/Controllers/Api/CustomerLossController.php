<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\CustomerLoss;
use Illuminate\Support\Facades\DB;


class CustomerLossController extends Controller
{
    public function store(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'competitor_name' => ['nullable', 'string', 'max:255'],
            'lost_price' => ['nullable', 'numeric', 'min:0'],
            'lost_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        $loss = $customer->losses()->create([
            'created_by' => $request->user()->id,
            'reason' => $validated['reason'],
            'competitor_name' => $validated['competitor_name'] ?? null,
            'lost_price' => $validated['lost_price'] ?? null,
            'lost_at' => $validated['lost_at'] ?? now(),
            'note' => $validated['note'] ?? null,
        ]);

        $oldStatus = $customer->status;
        
        $customer->update([
            'status' => 'lost',
            'warning_level' => null,
            'warning_locked_by_admin' => false,
            'warning_updated_at' => now(),
        ]);

        $content = "Mất khách. Lý do: {$loss->reason}.";
        if ($loss->competitor_name) {
            $content .= " Đối thủ: {$loss->competitor_name}.";
        }
        if ($loss->lost_price !== null) {
            $content .= ' Giá bên khác: ' . number_format((float) $loss->lost_price, 0, ',', '.') . ' VND.';
        }
        if ($loss->note) {
            $content .= " Ghi chú: {$loss->note}";
        }

        $customer->activities()->create([
            'user_id' => $request->user()->id,
            'type' => 'customer_lost',
            'content' => $content,
            'activity_time' => now(),
        ]);

        if ($oldStatus !== 'lost') {
            $customer->activities()->create([
                'user_id' => $request->user()->id,
                'type' => 'status_change',
                'content' => "Chuyển trạng thái từ {$oldStatus} sang lost.",
                'activity_time' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Đã cập nhật mất khách.',
            'data' => $loss->fresh([
                'customer:id,company_name,contact_name,phone,email',
                'creator:id,name',
            ]),
        ], 201);
    }
    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $loss = $customer->losses()
            ->with([
                'creator:id,name',
                'customer:id,company_name,contact_name,phone,email',
            ])
            ->latest('id')
            ->first();

        if (!$loss) {
            return response()->json([
                'message' => 'Khách hàng chưa có dữ liệu mất khách.',
            ], 404);
        }

        return response()->json($loss);
    }
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        if (!$request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Chỉ admin mới được sửa thông tin mất khách.',
            ], 403);
        }

        $loss = $customer->losses()->latest('id')->first();

        if (!$loss) {
            return response()->json([
                'message' => 'Khách hàng chưa có dữ liệu mất khách.',
            ], 404);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'competitor_name' => ['nullable', 'string', 'max:255'],
            'lost_price' => ['nullable', 'numeric', 'min:0'],
            'lost_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        $loss->update([
            'reason' => $validated['reason'],
            'competitor_name' => $validated['competitor_name'] ?? null,
            'lost_price' => $validated['lost_price'] ?? null,
            'lost_at' => $validated['lost_at'] ?? null,
            'note' => $validated['note'] ?? null,
        ]);

        $customer->activities()->create([
            'user_id' => $request->user()->id,
            'type' => 'loss_updated',
            'content' => 'Admin đã cập nhật thông tin mất khách.',
            'activity_time' => now(),
        ]);

        return response()->json([
            'message' => 'Đã cập nhật thông tin mất khách.',
            'data' => $loss->fresh([
                'customer:id,company_name,contact_name,phone,email',
                'creator:id,name',
            ]),
        ]);
    }
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $keyword = trim((string) $request->input('keyword', ''));
        $createdBy = $request->input('created_by');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $recreated = $request->input('recreated'); // 1 | 0 | null

        $query = CustomerLoss::query()
            ->with([
                'customer:id,company_name,contact_name,phone,email,status',
                'creator:id,name',
                'recreatedCustomer:id,company_name,contact_name',
            ])
            ->when(!$user->isAdmin(), function ($q) use ($user) {
                $q->where('created_by', $user->id);
            })
            ->when($keyword !== '', function ($q) use ($keyword) {
                $q->where(function ($sub) use ($keyword) {
                    $sub->where('reason', 'like', "%{$keyword}%")
                        ->orWhere('competitor_name', 'like', "%{$keyword}%")
                        ->orWhere('note', 'like', "%{$keyword}%")
                        ->orWhereHas('customer', function ($customerQuery) use ($keyword) {
                            $customerQuery->where('company_name', 'like', "%{$keyword}%")
                                ->orWhere('contact_name', 'like', "%{$keyword}%")
                                ->orWhere('phone', 'like', "%{$keyword}%")
                                ->orWhere('email', 'like', "%{$keyword}%");
                        });
                });
            })
            ->when($user->isAdmin() && filled($createdBy), function ($q) use ($createdBy) {
                $q->where('created_by', $createdBy);
            })
            ->when(filled($dateFrom), function ($q) use ($dateFrom) {
                $q->whereDate('lost_at', '>=', $dateFrom);
            })
            ->when(filled($dateTo), function ($q) use ($dateTo) {
                $q->whereDate('lost_at', '<=', $dateTo);
            })
            ->when($recreated !== null && $recreated !== '', function ($q) use ($recreated) {
                if ((string) $recreated === '1') {
                    $q->whereNotNull('recreated_customer_id');
                } elseif ((string) $recreated === '0') {
                    $q->whereNull('recreated_customer_id');
                }
            })
            ->latest('id');

        $rows = $query->paginate($perPage)->withQueryString();

        return response()->json($rows);
    }
    public function detail(Request $request, CustomerLoss $loss): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin() && (int) $loss->created_by !== (int) $user->id) {
            return response()->json([
                'message' => 'Bạn không có quyền xem dữ liệu mất khách này.',
            ], 403);
        }

        return response()->json([
            'data' => $loss->load([
                'customer',
                'creator:id,name',
                'recreatedCustomer:id,company_name,contact_name,status',
            ]),
        ]);
    }
    public function createNewCustomer(Request $request, CustomerLoss $loss): JsonResponse
{
    $user = $request->user();

    if (!$user->isAdmin()) {
        return response()->json([
            'message' => 'Chỉ admin mới được tạo khách hàng mới từ khách đã mất.',
        ], 403);
    }

    if ($loss->recreated_customer_id) {
        return response()->json([
            'message' => 'Bản ghi mất khách này đã tạo khách hàng mới trước đó.',
            'data' => [
                'recreated_customer_id' => $loss->recreated_customer_id,
            ],
        ], 422);
    }

    $sourceCustomer = $loss->customer;

    if (!$sourceCustomer) {
        return response()->json([
            'message' => 'Không tìm thấy khách hàng nguồn.',
        ], 404);
    }

    $newCustomer = DB::transaction(function () use ($sourceCustomer, $loss, $user) {
        $customer = Customer::create([
            'parent_customer_id' => $sourceCustomer->id,
            'revived_from_type' => 'loss',
            'revived_from_id' => $loss->id,
            'is_recycled_lead' => true,
            'recycled_at' => now(),
            'recycled_by' => $user->id,

            'company_name' => $sourceCustomer->company_name,
            'contact_name' => $sourceCustomer->contact_name ?: 'Chưa có tên liên hệ',
            'phone' => $sourceCustomer->phone,
            'email' => $sourceCustomer->email,
            'lead_source_id' => $sourceCustomer->lead_source_id,
            'source_detail' => $sourceCustomer->source_detail,
            'campaign_name' => $sourceCustomer->campaign_name,
            'status' => 'new',
            'note' => $sourceCustomer->note,

            'created_by' => $user->id,
        ]);

        $loss->update([
            'recreated_customer_id' => $customer->id,
            'recreated_at' => now(),
            'recreated_by' => $user->id,
        ]);

        return $customer;
    });

    return response()->json([
        'message' => 'Đã tạo khách hàng mới từ khách đã mất.',
        'data' => $newCustomer,
    ], 201);
}
}