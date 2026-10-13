<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class Menu extends Model
{
    /** @use HasFactory<\Database\Factories\MenuFactory> */
    use HasFactory;
    protected $fillable = [
        'category_id', 'name', 'description', 'price',
        'image', 'has_spice_level', 'is_available',
    ];

    protected $casts = [
        'price' => 'integer',
        'has_spice_level' => 'boolean',
        'is_available' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
