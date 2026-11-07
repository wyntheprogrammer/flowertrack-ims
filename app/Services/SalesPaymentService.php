<?php

namespace App\Services;

use App\Models\FinanceOperation;
use App\Models\Flower;
use App\Models\FlowerPrice;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentRefund;
use App\Models\SalesRecord;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesPaymentService
{
    public const METHODS = ['Cash', 'GCash', 'Bank Transfer'];

    /** Share strict HTTP/service boundaries without accepting client-owned totals or states. */
    public static function rules(string $kind): array
    {
        $rules = [
            'request_key' => ['required', 'uuid'],
            'version' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
        $amount = ['required', 'string', 'max:16', 'regex:/\A\d{1,10}(?:\.\d{1,2})?\z/'];
        $reason = ['required', 'string', 'max:500'];
        $rules += match ($kind) {
            'price' => ['amount' => $amount],
            'record' => [
                'order_id' => ['required', 'integer', 'min:1'],
                'method' => ['required', Rule::in(self::METHODS)],
                'amount' => $amount,
                'reference' => ['nullable', 'string', 'max:100', 'required_unless:method,Cash'],
                'remarks' => ['nullable', 'string', 'max:500'],
            ],
            'confirm' => ['reason' => $reason],
            'refund' => ['amount' => $amount, 'reason' => $reason],
            default => throw new \InvalidArgumentException('Unknown finance operation.'),
        };
        foreach (['id', 'code', 'status', 'payment_status', 'paid', 'paidCents', 'amount_cents',
            'price_cents', 'total', 'total_cents', 'subtotal_cents', 'fee_cents', 'confirmed_by',
            'confirmed_at', 'actor_id', 'stocked_out', 'remaining_quantity', 'created_at'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        return $rules;
    }

    /** Load actual prices, commercial snapshots, receipts and append-only refunds. */
    public function data(User $actor): array
    {
        $this->authorize($actor);
        return DB::transaction(function () use ($actor) {
            $prices = FlowerPrice::all()->keyBy('flower_id');
            $orders = Order::with(['items', 'payment'])->orderByDesc('id')->get();
            $ordersById = $orders->keyBy('id');
            $payments = OrderPayment::orderByDesc('id')->get();
            $paymentsById = $payments->keyBy('id');
            $refunds = PaymentRefund::orderBy('id')->get();
            $sums = [];
            foreach ($refunds as $refund) {
                $id = (int) $refund->payment_id;
                $sums[$id] = ($sums[$id] ?? 0) + (int) $refund->amount_cents;
            }
            $orderRows = $orders->map(fn (Order $order) => [
                'dbId' => (int) $order->id, 'id' => $order->code, 'client' => $order->client_name,
                'status' => $order->status, 'method' => $order->method,
                'items' => $order->items->map(fn ($item) => $this->item($item))->values()->all(),
                'deliveryFee' => (int) $order->fee_cents,
                'version' => $this->orderVersion($order),
            ])->all();

            return [
                'flowers' => Flower::orderBy('id')->get()->map(function (Flower $flower) use ($prices) {
                    $price = $prices->get($flower->id);
                    return [
                        'dbId' => (int) $flower->id, 'id' => $flower->code, 'name' => $flower->name,
                        'category' => $flower->category, 'itemType' => $flower->product_type,
                        'baseUnit' => $flower->product_type === 'Bouquet' ? 'Bouquet' : 'Stem',
                        'status' => $flower->status, 'canEdit' => $flower->status === 'Active',
                        'price' => $price ? (int) $price->price_cents : null,
                        'updated' => $this->time($price?->updated_at),
                        'version' => $this->priceVersion($flower, $price),
                    ];
                })->all(),
                'orders' => $orderRows,
                'sales' => SalesRecord::orderByDesc('id')->get()->map(function (SalesRecord $sale) use ($ordersById) {
                    return [
                        'dbId' => (int) $sale->id, 'id' => $sale->code,
                        'orderId' => $ordersById->get($sale->order_id)?->code,
                        'client' => $sale->client_name, 'date' => $this->time($sale->completed_at),
                        'gross' => (int) $sale->total_cents,
                        'items' => collect($sale->items)->map(fn ($item) => [
                            'flowerId' => $item['flowerId'], 'name' => $item['flowerName'],
                            'itemType' => $item['itemType'], 'baseUnit' => $item['baseUnit'],
                            'quantity' => (int) $item['quantity'], 'unitPrice' => (int) $item['unitPriceCents'],
                        ])->all(),
                    ];
                })->all(),
                'payments' => $payments->map(function (OrderPayment $payment) use ($ordersById, $sums) {
                    $order = $ordersById->get($payment->order_id);
                    return [
                        'dbId' => (int) $payment->id, 'id' => $payment->code,
                        'orderId' => $order?->code, 'client' => $order?->client_name,
                        'method' => $payment->method, 'reference' => $payment->reference ?? '',
                        'amount' => (int) $payment->amount_cents, 'status' => $payment->status,
                        'date' => $this->time($payment->created_at), 'confirmedAt' => $this->time($payment->confirmed_at),
                        'version' => $this->paymentVersion($payment, $order, $sums[$payment->id] ?? 0),
                    ];
                })->all(),
                'refunds' => $refunds->map(function (PaymentRefund $refund) use ($paymentsById, $ordersById) {
                    $payment = $paymentsById->get($refund->payment_id);
                    return [
                        'dbId' => (int) $refund->id, 'id' => $refund->code,
                        'paymentId' => $payment?->code,
                        'orderId' => $ordersById->get($payment?->order_id)?->code,
                        'amount' => (int) $refund->amount_cents,
                        'date' => $this->time($refund->created_at), 'reason' => $refund->reason,
                    ];
                })->all(),
                'history' => FinanceOperation::whereNotNull('order_id')->orderBy('id')->get()
                    ->map(fn (FinanceOperation $operation) => [
                        'orderId' => $ordersById->get($operation->order_id)?->code,
                        'note' => $operation->metadata['note'] ?? $operation->kind,
                        'actor' => $operation->metadata['actor_name'] ?? 'Recorded user',
                        'date' => $this->time($operation->created_at),
                    ])->all(),
                'permissions' => ['managePrices' => true, 'recordPayment' => true, 'confirm' => true,
                    'refund' => $actor->role === User::ROLE_ADMIN],
            ];
        });
    }

    /** Price changes affect new order lines only; existing snapshots remain unchanged. */
    public function price(Flower $flower, array $input, User $actor): array
    {
        $values = $this->validate('price', $input);
        $amount = $this->positive($values['amount']);
        $payload = ['flower_id' => (int) $flower->id, 'amount' => $amount];
        return $this->write('price', $values, $payload, $actor,
            function () use ($flower) {
                $locked = Flower::query()->lockForUpdate()->findOrFail($flower->id);
                $price = FlowerPrice::where('flower_id', $locked->id)->lockForUpdate()->first();
                return ['flower' => $locked, 'price' => $price];
            },
            function (array $context, FinanceOperation $operation) use ($values, $amount) {
                $flower = $context['flower']; $price = $context['price'];
                $this->assertVersion($this->priceVersion($flower, $price), $values['version']);
                if ($flower->status !== 'Active') $this->invalid('flower', 'Only active catalog items can be priced.');
                $before = $price ? (int) $price->price_cents : null;
                $price ??= new FlowerPrice(['flower_id' => $flower->id]);
                $price->price_cents = $amount;
                $price->save();
                $operation->result_id = $price->id;
                return ['note' => $flower->code.' price changed from '.($before === null ? 'not set' : Money::format($before)).' to '.Money::format($amount).'.'];
            });
    }

    /** Record exactly one full Pending payment; never issue stock or create a sale. */
    public function recordPayment(array $input, User $actor): array
    {
        $values = $this->validate('record', $input);
        $amount = $this->positive($values['amount']);
        $reference = trim($values['reference'] ?? '');
        if ($values['method'] !== 'Cash' && $reference === '') $this->invalid('reference', 'Enter the actual payment reference.');
        $remarks = trim($values['remarks'] ?? '');
        $payload = ['order_id' => (int) $values['order_id'], 'method' => $values['method'],
            'amount' => $amount, 'reference' => Str::lower($reference), 'remarks' => $remarks];
        $referenceKey = $values['method'] === 'Cash' ? null
            : hash('sha256', json_encode([$values['method'], Str::lower($reference)], JSON_THROW_ON_ERROR));

        return $this->write('record', $values, $payload, $actor,
            function () use ($values) {
                $order = Order::query()->lockForUpdate()->findOrFail($values['order_id']);
                $order->load('items');
                $payment = OrderPayment::where('order_id', $order->id)->lockForUpdate()->first();
                $order->setRelation('payment', $payment);
                return ['order' => $order, 'payment' => $payment];
            },
            function (array $context, FinanceOperation $operation) use ($values, $amount, $reference, $remarks) {
                $order = $context['order'];
                $this->assertVersion($this->orderVersion($order), $values['version']);
                if ($order->status === 'Cancelled') $this->invalid('order', 'Cancelled orders cannot receive a new payment.');
                if ($context['payment'] !== null) $this->invalid('order', 'This order already has a payment record. Additional payments are not allowed.');
                if ((int) $order->total_cents !== $amount) $this->invalid('amount', 'Payment must equal the full order total. Partial and excess payments are not allowed.');
                if ($values['method'] !== 'Cash' && OrderPayment::query()
                    ->where('method', $values['method'])
                    ->whereRaw('LOWER(TRIM(reference)) = ?', [Str::lower($reference)])
                    ->lockForUpdate()->first() !== null) {
                    $this->invalid('reference', 'This payment reference is already recorded for the selected method.');
                }
                $payment = OrderPayment::create([
                    'code' => 'TMP-'.Str::ulid(), 'order_id' => $order->id,
                    'method' => $values['method'], 'amount_cents' => $amount, 'status' => 'Pending',
                    'reference' => $reference === '' ? null : $reference,
                    'remarks' => $remarks === '' ? null : $remarks,
                ]);
                $payment->code = 'PAY-'.str_pad((string) $payment->id, 3, '0', STR_PAD_LEFT);
                $payment->save();
                $operation->payment_id = $payment->id;
                $operation->result_id = $payment->id;
                return ['note' => $payment->code.': '.Money::format($amount).' recorded as Pending via '.$payment->method.'.'];
            }, $referenceKey);
    }

    /** Confirm only after verifying the actual receipt/transfer/cash collection. */
    public function confirm(OrderPayment $payment, array $input, User $actor): array
    {
        $values = $this->validate('confirm', $input);
        $reason = $this->reason($values['reason']);
        $payload = ['payment_id' => (int) $payment->id, 'reason' => $reason];
        return $this->write('confirm', $values, $payload, $actor,
            fn () => $this->lockPayment($payment),
            function (array $context, FinanceOperation $operation) use ($values, $reason, $actor) {
                $payment = $context['payment']; $order = $context['order'];
                $sum = $this->refundTotal($payment, true);
                $this->assertVersion($this->paymentVersion($payment, $order, $sum), $values['version']);
                if ($order->status === 'Cancelled' || $payment->status !== 'Pending' || $sum !== 0) {
                    $this->invalid('payment', 'Only an eligible Pending payment can be confirmed.');
                }
                if ((int) $payment->amount_cents !== (int) $order->total_cents) {
                    $this->invalid('amount', 'The receipt must match the complete order total.');
                }
                $payment->status = 'Confirmed';
                $payment->confirmed_by = $actor->id;
                $payment->confirmed_at = now();
                $payment->save();
                $operation->result_id = $payment->id;
                return ['note' => $payment->code.' confirmed. '.$reason];
            });
    }

    /** Record an Admin-approved refund; preserve original receipt and all sale/stock history. */
    public function refund(OrderPayment $payment, array $input, User $actor): array
    {
        $this->authorize($actor);
        abort_unless($actor->role === User::ROLE_ADMIN, 403);
        $values = $this->validate('refund', $input);
        $amount = $this->positive($values['amount']);
        $reason = $this->reason($values['reason']);
        $payload = ['payment_id' => (int) $payment->id, 'amount' => $amount, 'reason' => $reason];
        return $this->write('refund', $values, $payload, $actor,
            fn () => $this->lockPayment($payment),
            function (array $context, FinanceOperation $operation) use ($values, $amount, $reason, $actor) {
                $payment = $context['payment']; $order = $context['order'];
                $sum = $this->refundTotal($payment, true);
                $this->assertVersion($this->paymentVersion($payment, $order, $sum), $values['version']);
                if (!in_array($payment->status, ['Confirmed', 'Refunded'], true) || !$payment->confirmed_at) {
                    $this->invalid('payment', 'Only a previously confirmed payment can be refunded.');
                }
                if ($amount > (int) $payment->amount_cents - $sum) {
                    $this->invalid('amount', 'Refund exceeds the remaining refundable amount.');
                }
                $refund = PaymentRefund::create([
                    'code' => 'TMP-'.Str::ulid(), 'payment_id' => $payment->id,
                    'amount_cents' => $amount, 'reason' => $reason,
                    'actor_id' => $actor->id, 'actor_name' => $actor->name,
                ]);
                $refund->code = 'REF-'.str_pad((string) $refund->id, 3, '0', STR_PAD_LEFT);
                $refund->save();
                // Any refund is visible to existing Order and Client financial status DTOs.
                $payment->status = 'Refunded';
                $payment->save();
                $operation->result_id = $refund->id;
                return ['note' => $refund->code.': '.Money::format($amount).' refunded against '.$payment->code.'. '.$reason];
            });
    }

    /** Serialize by Order or Flower before testing UUID replay and performing any write. */
    private function write(string $kind, array $input, array $payload, User $actor,
        callable $lock, callable $work, ?string $referenceKey = null): array
    {
        $this->authorize($actor);
        $hash = hash('sha256', json_encode([
            'kind' => $kind, 'actor_id' => (int) $actor->id, 'payload' => $payload,
        ], JSON_THROW_ON_ERROR));
        try {
            DB::transaction(function () use ($kind, $input, $actor, $hash, $lock, $work, $referenceKey) {
                $context = $lock();
                $previous = FinanceOperation::where('request_key', $input['request_key'])->lockForUpdate()->first();
                if ($previous) {
                    if (!hash_equals($previous->request_hash, $hash)) $this->invalid('request_key', 'This request key was already used with different details.');
                    return;
                }
                $operation = FinanceOperation::create([
                    'request_key' => $input['request_key'], 'request_hash' => $hash,
                    'reference_key' => $referenceKey, 'kind' => $kind, 'actor_id' => $actor->id,
                    'order_id' => isset($context['order']) ? $context['order']->id : null,
                    'payment_id' => isset($context['payment']) ? $context['payment']->id : null,
                    'flower_id' => isset($context['flower']) ? $context['flower']->id : null,
                ]);
                $operation->metadata = ['actor_name' => $actor->name, ...$work($context, $operation)];
                $operation->save();
            }, 3);
        } catch (QueryException $exception) {
            if (!in_array((int) ($exception->errorInfo[1] ?? 0), [1062, 19], true)) throw $exception;
            $previous = FinanceOperation::where('request_key', $input['request_key'])->first();
            if ($previous) {
                if (!hash_equals($previous->request_hash, $hash)) $this->invalid('request_key', 'This request key was already used with different details.');
            } elseif ($referenceKey !== null && FinanceOperation::where('reference_key', $referenceKey)->exists()) {
                $this->invalid('reference', 'This payment reference is already recorded for the selected method.');
            } else {
                throw $exception;
            }
        }
        return $this->data($actor);
    }

    private function lockPayment(OrderPayment $payment): array
    {
        $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
        $locked = OrderPayment::query()->lockForUpdate()->findOrFail($payment->id);
        abort_unless((int) $locked->order_id === (int) $order->id, 409);
        return ['order' => $order, 'payment' => $locked];
    }

    private function refundTotal(OrderPayment $payment, bool $lock = false): int
    {
        $query = PaymentRefund::where('payment_id', $payment->id)->orderBy('id');
        if ($lock) $query->lockForUpdate();
        return (int) $query->get()->sum('amount_cents');
    }

    private function priceVersion(Flower $flower, ?FlowerPrice $price): string
    {
        return $this->version(['flower', (int) $flower->id, $flower->status,
            $price ? (int) $price->id : null, $price ? (int) $price->price_cents : null,
            $price?->updated_at?->format('Y-m-d H:i:s')]);
    }

    private function orderVersion(Order $order): string
    {
        $order->loadMissing(['items', 'payment']);
        return $this->version(['order', (int) $order->id, $order->status, (int) $order->total_cents,
            $order->updated_at?->format('Y-m-d H:i:s'), $order->payment?->id,
            $order->items->sortBy('flower_id')->map(fn ($item) => $this->item($item))->values()->all()]);
    }

    private function paymentVersion(OrderPayment $payment, ?Order $order, int $refunded): string
    {
        return $this->version(['payment', (int) $payment->id, (int) $payment->order_id,
            $payment->status, (int) $payment->amount_cents, $payment->method, $payment->reference, $payment->remarks,
            $payment->confirmed_by === null ? null : (int) $payment->confirmed_by,
            $payment->confirmed_at?->format('Y-m-d H:i:s'),
            $payment->updated_at?->format('Y-m-d H:i:s'), $refunded,
            $order?->status, $order ? (int) $order->total_cents : null]);
    }

    private function version(array $values): string
    {
        return hash_hmac('sha256', json_encode($values, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function assertVersion(string $current, string $expected): void
    {
        if (!hash_equals($current, $expected)) $this->invalid('version', 'Financial details changed. Refresh and reopen this action.');
    }

    private function item($item): array
    {
        return ['flowerId' => $item->flower_code, 'name' => $item->name,
            'itemType' => $item->product_type, 'baseUnit' => $item->base_unit,
            'quantity' => (int) $item->quantity, 'unitPrice' => (int) $item->unit_price_cents];
    }

    private function time($date): ?string
    {
        return $date?->copy()->setTimezone('Asia/Manila')->toIso8601String();
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && !$actor->must_change_password
            && in_array($actor->role, [User::ROLE_ADMIN, User::ROLE_SUPPLIER], true), 403);
    }

    private function validate(string $kind, array $input): array
    {
        if (isset($input['request_key']) && is_string($input['request_key'])) {
            $input['request_key'] = Str::lower($input['request_key']);
        }
        return Validator::make($input, self::rules($kind))->validate();
    }

    private function positive(string $decimal): int
    {
        try { $amount = Money::parse($decimal); }
        catch (\InvalidArgumentException $exception) { $this->invalid('amount', 'Enter a valid exact monetary amount with at most two decimal places.'); }
        if ($amount <= 0) $this->invalid('amount', 'Amount must be greater than zero.');
        return $amount;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') $this->invalid('reason', 'Enter the verification or refund reason.');
        return $reason;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}