<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function index(Request $request): View
    {
        return view('supplier.dashboard', ['dashboard' => $this->snapshot($request)]);
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->snapshot($request))
            ->header('Cache-Control', 'private, no-store');
    }

    private function snapshot(Request $request): array
    {
        $values = $request->validate([
            'unit' => ['nullable', 'string', Rule::in(['Stem', 'Bouquet'])],
        ]);

        return $this->dashboard->data($request->user(), $values['unit'] ?? 'Stem');
    }
}