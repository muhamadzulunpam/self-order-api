<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;


class DiningTable extends Model
{
    /** @use HasFactory<\Database\Factories\DiningTableFactory> */
    use HasFactory;
    protected $fillable = ['number', 'qr_token', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function (DiningTable $table) {
            $table->qr_token ??= Str::random(40);
        });
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function orderUrl(): string
    {
        return rtrim(config('app.frontend_url'), '/').'/order/'.$this->qr_token;
    }

    public function regenerateToken(): void
    {
        $this->update(['qr_token' => Str::random(40)]);
    }
}
