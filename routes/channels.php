<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
Broadcast::channel('staff.orders', function (User $user) {
    return in_array($user->role, [UserRole::Admin, UserRole::Cashier, UserRole::Kitchen], true);
});
