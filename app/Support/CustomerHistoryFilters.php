<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CustomerHistoryFilters
{
    public const array ORDER_STATUSES = [
        'Pending', 'Confirmed', 'Processing', 'Ready for Pickup', 'Ready for Delivery',
        'Out for Delivery', 'Delivered', 'Completed', 'Cancelled',
    ];

    public readonly string $search;

    public readonly string $status;

    /** @param list<string> $statuses */
    public function __construct(Request $request, public readonly array $statuses)
    {
        $search = $request->query('q');
        $status = $request->query('status');

        $this->search = is_string($search) ? trim($search) : '';
        $this->status = in_array($status, $statuses, true) ? $status : '';
    }

    public function searchPattern(): string
    {
        return '%'.strtr($this->search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
    }

    public function matchOrder(Builder $query): void
    {
        $pattern = $this->searchPattern();

        $query->where(function (Builder $query) use ($pattern): void {
            $query->whereRaw("LOWER(orders.code) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereHas('items', fn (Builder $items) => $items->whereRaw("LOWER(order_items.name) LIKE LOWER(?) ESCAPE '!'", [$pattern]));
        });
    }

    /** @return array{q?: string, status?: string} */
    public function parameters(): array
    {
        return array_filter(['q' => $this->search, 'status' => $this->status], fn (string $value): bool => $value !== '');
    }
}
