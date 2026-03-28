<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Property::query()->orderBy('building_name');

        if ($request->filled('district')) {
            $query->where('district', $request->string('district'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json($query->paginate((int) $request->get('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Chỉ admin được tạo mặt bằng.');

        $validated = $request->validate([
            'building_name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'integer', 'min:0'],
            'price_per_m2' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:available,reserved,rented'],
        ]);

        $property = Property::create($validated);

        return response()->json($property, 201);
    }
}