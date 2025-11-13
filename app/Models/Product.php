<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'name',
        'category',
        'price_number',
        'price_label',
        'stock',
        'images',
        'sku',
        'description',
        'specifications',
    ];

    protected $casts = [
        'specifications' => 'array',
        'images' => 'array',
        'price_number' => 'decimal:2',
    ];
}
