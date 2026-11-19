<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Flower;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $type = $request->query('type') ?? '';
        $category = $request->query('category') ?? '';
        $types = [Flower::TYPE_STEM, Flower::TYPE_BOUQUET];
        abort_unless(($type === '' || in_array($type, $types, true)) && is_string($category), 404);

        $active = Flower::query()->where('flowers.status', Flower::STATUS_ACTIVE);
        $categories = (clone $active)->distinct()->orderBy('category')->pluck('category');
        $flowers = Flower::storefront()
            ->when($type !== '', fn ($query) => $query->where('product_type', $type))
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->orderBy('flowers.name')->orderBy('flowers.id')
            ->simplePaginate(12)->appends(compact('type', 'category'));

        return view('client.catalog', compact('flowers', 'types', 'type', 'categories', 'category'));
    }

    public function show(int $flower): View
    {
        return view('client.product', ['flower' => Flower::storefront()->findOrFail($flower)]);
    }
}
