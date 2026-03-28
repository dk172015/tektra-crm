<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeadSource;
use Illuminate\Http\JsonResponse;

class LeadSourceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            LeadSource::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
        );
    }
}