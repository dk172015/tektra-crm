<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\CustomerDeal;
use Illuminate\Support\Facades\DB;


class CustomerDealController extends Controller
{
    public function store(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'project_code' => ['nullable', 'string', 'max:255'],
            'building_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'rental_price' => ['nullable', 'numeric', 'min:0'],
            'contract_term_months' => ['nullable', 'integer', 'min:1'],
            'deposit_date' => ['nullable', 'date'],
            'first_payment_date' => ['nullable', 'date'],
            'brokerage_fee' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
            'closer_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'has_vat' => ['required', 'boolean'],
            'vat_revenue' => ['nullable', 'numeric', 'min:0'],
            'back_fee' => ['nullable', 'numeric', 'min:0'],
        ]);
        $hasVat = (bool) $validated['has_vat'];
        $brokerage = $validated['brokerage_fee'] ?? 0;
        $vatRevenue = $validated['vat_revenue'] ?? null;
        $backFee = $validated['back_fee'] ?? 0;

        $netRevenue = $hasVat
            ? ($vatRevenue ?? 0)
            : $brokerage;

        $finalRevenue = $netRevenue - $backFee;
        $actor = $request->user();

        $closerUserId = $actor->isAdmin()
            ? ($validated['closer_user_id'] ?? null)
            : $actor->id;

        if (!$closerUserId) {
            return response()->json([
                'message' => 'Vui lòng chọn sale chốt hợp đồng.',
            ], 422);
        }

        $deal = $customer->deals()->create([
            'created_by' => $actor->id,
            'closer_user_id' => $closerUserId,
            'project_code' => $validated['project_code'] ?? null,
            'building_name' => $validated['building_name'],
            'address' => $validated['address'] ?? null,
            'floor' => $validated['floor'] ?? null,
            'area' => $validated['area'] ?? null,
            'rental_price' => $validated['rental_price'] ?? null,
            'contract_term_months' => $validated['contract_term_months'] ?? null,
            'deposit_date' => $validated['deposit_date'] ?? null,
            'first_payment_date' => $validated['first_payment_date'] ?? null,
            'brokerage_fee' => $validated['brokerage_fee'] ?? null,
            'note' => $validated['note'] ?? null,
            'has_vat' => $hasVat,
            'vat_revenue' => $vatRevenue,
            'back_fee' => $backFee,
            'net_revenue' => $netRevenue,
            'final_revenue' => $finalRevenue,
            'status' => 'won',
            'signed_at' => now(),
        ]);

        $oldStatus = $customer->status;

        $customer->update([
            'status' => 'contracted',
        ]);

        $summaryParts = [
            'Chốt hợp đồng',
            $deal->building_name ? "tại {$deal->building_name}" : null,
            $deal->project_code ? "(Mã dự án: {$deal->project_code})" : null,
            $deal->rental_price !== null
                ? 'Giá thuê: ' . number_format((float) $deal->rental_price, 0, ',', '.') . ' VND'
                : null,
            $deal->brokerage_fee !== null
                ? 'Phí môi giới: ' . number_format((float) $deal->brokerage_fee, 0, ',', '.') . ' VND'
                : null,
            $deal->deposit_date ? 'Ngày đặt cọc: ' . $deal->deposit_date->format('d/m/Y') : null,
            $deal->first_payment_date ? 'Thanh toán kỳ đầu: ' . $deal->first_payment_date->format('d/m/Y') : null,
        ];
        $hasVat = (bool) $validated['has_vat'];
        $brokerage = $validated['brokerage_fee'] ?? 0;
        $vatRevenue = $validated['vat_revenue'] ?? null;
        $backFee = $validated['back_fee'] ?? 0;

        
        $content = implode('. ', array_filter($summaryParts));
        if ($content !== '') {
            $content .= '.';
        }

        if ($deal->note) {
            $content .= ' Ghi chú: ' . $deal->note;
        }

        $customer->activities()->create([
            'user_id' => $actor->id,
            'type' => 'deal_closed',
            'content' => $content ?: 'Chốt hợp đồng.',
            'activity_time' => now(),
        ]);

        if ($oldStatus !== 'contracted') {
            $customer->activities()->create([
                'user_id' => $actor->id,
                'type' => 'status_change',
                'content' => "Chuyển trạng thái từ {$oldStatus} sang contracted.",
                'activity_time' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Đã chốt hợp đồng thành công.',
            'data' => $deal->load([
                'customer:id,company_name,contact_name,phone,email',
                'creator:id,name',
                'closer:id,name',
            ]),
        ], 201);
    }
    public function show(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $deal = $customer->deals()
            ->with([
                'creator:id,name',
                'closer:id,name',
                'customer:id,company_name,contact_name,phone,email',
            ])
            ->latest('id')
            ->first();

        if (!$deal) {
            return response()->json([
                'message' => 'Khách hàng chưa có dữ liệu hợp đồng.',
            ], 404);
        }

        return response()->json($deal);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('update', $customer);
        
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'message' => 'Chỉ admin mới được sửa thông tin hợp đồng.',
            ], 403);
        }

        $deal = $customer->deals()->latest('id')->first();

        if (!$deal) {
            return response()->json([
                'message' => 'Khách hàng chưa có dữ liệu hợp đồng.',
            ], 404);
        }

        $validated = $request->validate([
            'project_code' => ['nullable', 'string', 'max:255'],
            'building_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'floor' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'rental_price' => ['nullable', 'numeric', 'min:0'],
            'contract_term_months' => ['nullable', 'integer', 'min:1'],
            'deposit_date' => ['nullable', 'date'],
            'first_payment_date' => ['nullable', 'date'],
            'brokerage_fee' => ['nullable', 'numeric', 'min:0'],
            'closer_user_id' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string'],
            'has_vat' => ['required', 'boolean'],
            'vat_revenue' => ['nullable', 'numeric', 'min:0'],
            'back_fee' => ['nullable', 'numeric', 'min:0'],
        ]);
        $hasVat = (bool) $validated['has_vat'];
        $brokerage = $validated['brokerage_fee'] ?? 0;
        $vatRevenue = $validated['vat_revenue'] ?? null;
        $backFee = $validated['back_fee'] ?? 0;

        $netRevenue = $hasVat
            ? ($vatRevenue ?? 0)
            : $brokerage;

        $finalRevenue = $netRevenue - $backFee;

        $deal->update([
            'project_code' => $validated['project_code'] ?? null,
            'building_name' => $validated['building_name'],
            'address' => $validated['address'] ?? null,
            'floor' => $validated['floor'] ?? null,
            'area' => $validated['area'] ?? null,
            'rental_price' => $validated['rental_price'] ?? null,
            'contract_term_months' => $validated['contract_term_months'] ?? null,
            'deposit_date' => $validated['deposit_date'] ?? null,
            'first_payment_date' => $validated['first_payment_date'] ?? null,
            'brokerage_fee' => $validated['brokerage_fee'] ?? null,
            'closer_user_id' => $validated['closer_user_id'],
            'note' => $validated['note'] ?? null,
            'has_vat' => $hasVat,
            'vat_revenue' => $vatRevenue,
            'back_fee' => $backFee,
            'net_revenue' => $netRevenue,
            'final_revenue' => $finalRevenue,
        ]);

        $customer->activities()->create([
            'user_id' => $request->user()->id,
            'type' => 'deal_updated',
            'content' => 'Admin đã cập nhật thông tin hợp đồng đã chốt.',
            'activity_time' => now(),
        ]);

        return response()->json([
            'message' => 'Đã cập nhật thông tin hợp đồng.',
            'data' => $deal->fresh([
                'customer:id,company_name,contact_name,phone,email',
                'creator:id,name',
                'closer:id,name',
            ]),
        ]);
    }
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));
        $keyword = trim((string) $request->input('keyword', ''));
        $closerUserId = $request->input('closer_user_id');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $recreated = $request->input('recreated'); // 1 | 0 | null

        $query = CustomerDeal::query()
            ->with([
                'customer:id,company_name,contact_name,phone,email,status',
                'creator:id,name',
                'closer:id,name',
                'recreatedCustomer:id,company_name,contact_name',
            ])
            ->when(!$user->isAdmin(), function ($q) use ($user) {
                $q->where('closer_user_id', $user->id);
            })
            ->when($keyword !== '', function ($q) use ($keyword) {
                $q->where(function ($sub) use ($keyword) {
                    $sub->where('project_code', 'like', "%{$keyword}%")
                        ->orWhere('building_name', 'like', "%{$keyword}%")
                        ->orWhere('address', 'like', "%{$keyword}%")
                        ->orWhere('note', 'like', "%{$keyword}%")
                        ->orWhereHas('customer', function ($customerQuery) use ($keyword) {
                            $customerQuery->where('company_name', 'like', "%{$keyword}%")
                                ->orWhere('contact_name', 'like', "%{$keyword}%")
                                ->orWhere('phone', 'like', "%{$keyword}%")
                                ->orWhere('email', 'like', "%{$keyword}%");
                        });
                });
            })
            ->when($user->isAdmin() && filled($closerUserId), function ($q) use ($closerUserId) {
                $q->where('closer_user_id', $closerUserId);
            })
            ->when(filled($dateFrom), function ($q) use ($dateFrom) {
                $q->whereDate('signed_at', '>=', $dateFrom);
            })
            ->when(filled($dateTo), function ($q) use ($dateTo) {
                $q->whereDate('signed_at', '<=', $dateTo);
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
    public function detail(Request $request, CustomerDeal $deal): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin() && (int) $deal->closer_user_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Bạn không có quyền xem hợp đồng này.',
            ], 403);
        }

        return response()->json([
            'data' => $deal->load([
                'customer',
                'creator:id,name',
                'closer:id,name',
                'recreatedCustomer:id,company_name,contact_name,status',
            ]),
        ]);
    }
    public function createNewCustomer(Request $request, CustomerDeal $deal): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            return response()->json([
                'message' => 'Chỉ admin mới được tạo khách hàng mới từ hợp đồng.',
            ], 403);
        }

        if ($deal->recreated_customer_id) {
            return response()->json([
                'message' => 'Hợp đồng này đã tạo khách hàng mới trước đó.',
                'data' => [
                    'recreated_customer_id' => $deal->recreated_customer_id,
                ],
            ], 422);
        }

        $sourceCustomer = $deal->customer;

        $newCustomer = DB::transaction(function () use ($sourceCustomer, $deal, $user) {
            $customer = Customer::create([
                'parent_customer_id' => $sourceCustomer->id,
                'revived_from_type' => 'deal',
                'revived_from_id' => $deal->id,
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

            $deal->update([
                'recreated_customer_id' => $customer->id,
                'recreated_at' => now(),
                'recreated_by' => $user->id,
            ]);

            return $customer;
        });

        return response()->json([
            'message' => 'Đã tạo khách hàng mới từ hợp đồng.',
            'data' => $newCustomer,
        ], 201);
    }
}