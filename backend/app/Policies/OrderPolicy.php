<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * WHO may trigger a transition. WHETHER the transition is legal from the current
 * status is decided by the domain (OrderStatus / OrderStateMachine → 409).
 */
final class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Order $order): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function pay(User $user, Order $order): bool
    {
        return true; // admin + cashier
    }

    public function ship(User $user, Order $order): bool
    {
        return $user->isAdmin();
    }

    public function complete(User $user, Order $order): bool
    {
        return $user->isAdmin();
    }

    public function cancel(User $user, Order $order): bool
    {
        return $user->isAdmin();
    }

    public function downloadInvoice(User $user, Order $order): bool
    {
        return true;
    }
}
