<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code',
    'name',
    'product_type',
    'category',
    'minimum_stock',
    'description',
    'image_path',
    'status',
])]
class Flower extends Model
{
    use HasFactory;

    public const TYPE_STEM = 'Stem';
    public const TYPE_BOUQUET = 'Bouquet';
    public const STATUS_ACTIVE = 'Active';
    public const STATUS_INACTIVE = 'Inactive';

    protected function casts(): array
    {
        return ['minimum_stock' => 'integer'];
    }
}
