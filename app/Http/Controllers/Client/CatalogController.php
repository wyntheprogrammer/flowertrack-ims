<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Flower;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function home(): View
    {
        $featuredFlowers = Flower::query()
            ->fromSub(Flower::storefront(), 'storefront_flowers')
            ->orderByRaw('CASE WHEN price_cents > 0 AND on_hand > reserved THEN 0 ELSE 1 END')
            ->orderBy('name')->orderBy('id')
            ->limit(4)->get();

        return view('client.dashboard', compact('featuredFlowers'));
    }

    public function __invoke(Request $request): View
    {
        $type = $request->query('type') ?? '';
        $category = $request->query('category') ?? '';
        $search = $request->query('q') ?? '';
        $types = [Flower::TYPE_STEM, Flower::TYPE_BOUQUET];
        abort_unless(($type === '' || in_array($type, $types, true)) && is_string($category) && is_string($search), 404);

        $search = trim($search);
        $searchPattern = '%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        $active = Flower::query()->where('flowers.status', Flower::STATUS_ACTIVE);
        $categories = (clone $active)->distinct()->orderBy('category')->pluck('category');
        $flowers = Flower::storefront()
            ->when($type !== '', fn ($query) => $query->where('product_type', $type))
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->when($search !== '', fn (Builder $query): Builder => $query->whereRaw("LOWER(flowers.name) LIKE LOWER(?) ESCAPE '!'", [$searchPattern]))
            ->orderBy('flowers.name')->orderBy('flowers.id')
            ->simplePaginate(12)->appends(['type' => $type, 'category' => $category, 'q' => $search]);

        return view('client.catalog', compact('flowers', 'types', 'type', 'categories', 'category', 'search'));
    }

    public function show(int $flower): View
    {
        return view('client.product', ['flower' => Flower::storefront()->findOrFail($flower)]);
    }
}
