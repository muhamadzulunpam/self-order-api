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
    public static function findActiveByToken(string $token): ?self
    {
        return static::where('qr_token', $token)
            ->where('is_active', true)
            ->first();
    }
    public function findOrderByCode(string $orderCode): ?Order
    {
        return $this->orders()->where('order_code', $orderCode)->first();
    }
}
