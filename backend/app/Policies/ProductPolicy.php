<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/**
 * Admin: full access. Cashier: read-only.
 */
final class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Product $product): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->isAdmin();
    }

    public function adjustStock(User $user, Product $product): bool
    {
        return $user->isAdmin();
    }

    public function viewStockMovements(User $user, Product $product): bool
    {
        return $user->isAdmin();
    }
}
