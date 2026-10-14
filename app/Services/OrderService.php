<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Events\OrderStatusUpdated;
use App\Events\StaffOrderUpdated;
use App\Models\DiningTable;
use App\Models\Menu;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function create(DiningTable $table, array $data): Order
    {
        return DB::transaction(function () use ($table, $data) {
            $menus = Menu::whereIn('id', collect($data['items'])->pluck('menu_id')->unique())
                ->get()
                ->keyBy('id');

            $lines = [];
            $total = 0;

            foreach ($data['items'] as $index => $item) {
                $menu = $menus->get($item['menu_id']);

                if (! $menu || ! $menu->is_available) {
                    throw ValidationException::withMessages([
                        "items.$index.menu_id" => $menu
                            ? "Menu {$menu->name} sedang tidak tersedia."
                            : 'Menu tidak ditemukan.',
                    ]);
                }

                $spiceLevel = null;

                if ($menu->has_spice_level) {
                    if (! isset($item['spice_level'])) {
                        throw ValidationException::withMessages([
                            "items.$index.spice_level" => "Level pedas untuk {$menu->name} wajib dipilih.",
                        ]);
                    }

                    $spiceLevel = (int) $item['spice_level'];
                }

                $subtotal = $menu->price * $item['quantity'];
                $total += $subtotal;

                $lines[] = [
                    'menu_id' => $menu->id,
                    'menu_name' => $menu->name,
                    'price' => $menu->price,
                    'quantity' => $item['quantity'],
                    'spice_level' => $spiceLevel,
                    'notes' => $item['notes'] ?? null,
                    'subtotal' => $subtotal,
                ];
            }

            $order = Order::create([
                'order_code' => $this->generateOrderCode(),
                'dining_table_id' => $table->id,
                'customer_name' => $data['customer_name'] ?? null,
                'status' => OrderStatus::PendingPayment,
                'total_amount' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            $order->items()->createMany($lines);

            return $order->load('items', 'diningTable');
        });
    }

    private function generateOrderCode(): string
    {
        do {
            $code = 'ORD-'.now()->format('ymd').'-'.Str::upper(Str::random(8));
        } while (Order::where('order_code', $code)->exists());

        return $code;
    }

    public function updateStatus(Order $order, OrderStatus $target, User $actor): Order
    {
        abort_unless(
            $this->roleCanSet($actor->role, $target),
            403,
            'Role Anda tidak berhak mengubah pesanan ke status ini.'
        );

        $order = DB::transaction(function () use ($order, $target) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            abort_unless(
                $locked->status->canTransitionTo($target),
                409,
                "Pesanan berstatus '{$locked->status->label()}' tidak bisa diubah ke '{$target->label()}'."
            );

            $locked->update(['status' => $target]);

            return $locked;
        });

        $order->load('items', 'diningTable', 'payment');

        StaffOrderUpdated::dispatch($order);
        OrderStatusUpdated::dispatch($order->order_code, $target->value);

        return $order;
    }

    private function roleCanSet(UserRole $role, OrderStatus $target): bool
    {
        return match ($target) {
            OrderStatus::Preparing, OrderStatus::Ready => in_array($role, [UserRole::Admin, UserRole::Kitchen], true),
            OrderStatus::Completed => in_array($role, [UserRole::Admin, UserRole::Cashier], true),
            default => false,
        };
    }
}
