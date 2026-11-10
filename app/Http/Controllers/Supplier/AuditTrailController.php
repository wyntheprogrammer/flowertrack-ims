<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuditTrailController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeAdmin($request);

        return view('supplier.audit-trail');
    }

    /**
     * Search, filter, sort, and paginate actual audit records.
     */
    public function data(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'module' => ['nullable', 'string', 'max:80'],
            'action' => ['nullable', 'string', 'max:100'],
            'actor' => ['nullable', 'integer', 'min:1'],
            'result' => ['nullable', Rule::in(['Success', 'Failed'])],
            'sort' => ['nullable', Rule::in([
                'created_at',
                'actor_name',
                'module',
                'action',
                'record_code',
                'result',
            ])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'export' => ['nullable', 'boolean'],
        ]);

        $query = AuditEvent::query();
        $total = AuditEvent::query()->count();

        foreach (['module', 'action', 'result'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['actor'])) {
            $query->where('actor_id', $filters['actor']);
        }

        // Convert Philippine calendar dates to UTC query boundaries.
        if (! empty($filters['from'])) {
            $query->where(
                'created_at',
                '>=',
                CarbonImmutable::parse(
                    $filters['from'],
                    'Asia/Manila'
                )->startOfDay()->utc()
            );
        }

        if (! empty($filters['to'])) {
            $query->where(
                'created_at',
                '<',
                CarbonImmutable::parse(
                    $filters['to'],
                    'Asia/Manila'
                )->startOfDay()->addDay()->utc()
            );
        }

        if (! empty($filters['search'])) {
            $keyword = '%'.$filters['search'].'%';

            $query->where(function ($search) use ($keyword) {
                foreach ([
                    'actor_name',
                    'actor_role',
                    'module',
                    'action',
                    'record_code',
                    'result',
                    'description',
                    'ip_address',
                ] as $column) {
                    $search->orWhere($column, 'like', $keyword);
                }
            });
        }

        $filtered = (clone $query)->count();

        $query
            ->orderBy(
                $filters['sort'] ?? 'created_at',
                $filters['direction'] ?? 'desc'
            )
            ->orderBy('id', $filters['direction'] ?? 'desc');

        if ($request->boolean('export')) {
            if ($filtered > 5000) {
                throw ValidationException::withMessages([
                    'export' => 'Limit the export to 5,000 records using the filters.',
                ]);
            }

            $events = $query->limit(5000)->get();
        } else {
            $events = $query
                ->forPage(
                    (int) ($filters['page'] ?? 1),
                    (int) ($filters['per_page'] ?? 5)
                )
                ->get();
        }

        $actors = AuditEvent::query()
            ->whereNotNull('actor_id')
            ->select('actor_id')
            ->selectRaw('MAX(actor_name) AS actor_name')
            ->groupBy('actor_id')
            ->orderBy('actor_name')
            ->get()
            ->map(fn ($actor) => [
                'id' => $actor->actor_id,
                'name' => $actor->actor_name,
            ]);

        return response()->json([
            'data' => $events->map(fn (AuditEvent $event) => [
                'id' => 'AUD-'.str_pad(
                    (string) $event->id,
                    3,
                    '0',
                    STR_PAD_LEFT
                ),
                'date' => CarbonImmutable::parse($event->created_at)
                    ->setTimezone('Asia/Manila')
                    ->toIso8601String(),
                'user' => $event->actor_name,
                'userId' => $event->actor_id
                    ? 'USR-'.str_pad(
                        (string) $event->actor_id,
                        3,
                        '0',
                        STR_PAD_LEFT
                    )
                    : '—',
                'role' => $event->actor_role ?? '—',
                'module' => $event->module,
                'action' => $event->action,
                'record' => $event->record_code
                    ?: ($event->record_id
                        ? class_basename($event->record_type ?? 'Record')
                            .' #'.$event->record_id
                        : '—'),
                'result' => $event->result,
                'description' => $event->description,
                'ip' => $event->ip_address ?? '—',
                'before' => (object) $this->safeValues($event->before_values),
                'after' => (object) $this->safeValues($event->after_values),
            ])->values(),
            'total' => $total,
            'filtered' => $filtered,
            'generatedAt' => now('Asia/Manila')->toIso8601String(),
            'options' => [
                'modules' => AuditEvent::query()
                    ->distinct()->orderBy('module')->pluck('module'),
                'actions' => AuditEvent::query()
                    ->distinct()->orderBy('action')->pluck('action'),
                'actors' => $actors,
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Expose only approved scalar values, never credentials or file paths.
     */
    private function safeValues(mixed $values): array
    {
        $allowed = [
            'id', 'code', 'name', 'email', 'role', 'status',
            'product_type', 'category', 'description',
            'method', 'receiving_unit', 'reference',
            'reason', 'remarks', 'address', 'phone',
            'recipient_name', 'received_date', 'harvest_date',
            'expiry_date', 'scheduled_at', 'completed_at',
            'dispatched_at', 'delivered_at',
            'flower_code', 'client_code', 'client_name', 'base_unit',
            'user_id', 'flower_id', 'client_id', 'order_id',
            'payment_id', 'batch_id', 'assigned_to',
            'minimum_stock', 'quantity', 'received_quantity',
            'initial_quantity', 'remaining_quantity',
            'conversion_factor', 'price_cents', 'amount_cents',
            'subtotal_cents', 'fee_cents', 'total_cents',
            'bunch_factor', 'bundle_factor', 'box_factor',
            'is_active', 'must_change_password', 'stocked_out',
            'spoiled', 'credential_changed', 'image_changed',
            'proof_recorded',
        ];

        $safe = [];

        foreach ((array) $values as $key => $value) {
            if (
                in_array($key, $allowed, true)
                && (is_scalar($value) || $value === null)
            ) {
                $safe[$key] = is_string($value)
                    ? mb_substr($value, 0, 1000)
                    : $value;
            }
        }

        return $safe;
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user
                && $user->role === User::ROLE_ADMIN
                && $user->is_active
                && ! $user->must_change_password,
            403
        );
    }
}