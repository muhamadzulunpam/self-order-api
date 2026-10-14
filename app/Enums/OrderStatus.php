<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Menunggu pembayaran',
            self::Paid => 'Pesanan diterima',
            self::Preparing => 'Sedang dimasak',
            self::Ready => 'Siap disajikan',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::Paid => self::Preparing,
            self::Preparing => self::Ready,
            self::Ready => self::Completed,
            default => null,
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return $this->next() === $target;
    }
}
