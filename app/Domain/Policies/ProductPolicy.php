<?php
/**
 * File: app/Domain/Policies/ProductPolicy.php
 */

declare(strict_types=1);

namespace App\Domain\Policies;

final class ProductPolicy extends BasePolicy
{
    public function viewAny(PolicyContext $ctx): bool
    {
        return $this->isAuthenticated($ctx);
    }

    public function create(PolicyContext $ctx): bool
    {
        return $this->isAdmin($ctx) || $this->isStaff($ctx);
    }

    public function update(PolicyContext $ctx, ?int $productId = null): bool
    {
        return $this->isAdmin($ctx) || $this->isStaff($ctx);
    }

    public function delete(PolicyContext $ctx, ?int $productId = null): bool
    {
        return $this->isAdmin($ctx);
    }

    public function syncWoo(PolicyContext $ctx): bool
    {
        // همگام‌سازی ووکامرس حساس و پرریسک است
        return $this->isAdmin($ctx) || $this->isStaff($ctx);
    }
}
