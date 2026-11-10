<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function index(): View
    {
        return view('supplier.reports');
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->reports->data($request->user()), 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}