<?php
/**
 * File: app/Domain/Policies/SalePolicy.php
 */

declare(strict_types=1);

namespace App\Domain\Policies;

final class SalePolicy extends BasePolicy
{
    public function viewAny(PolicyContext $ctx): bool
    {
        return $this->isAuthenticated($ctx);
    }

    public function create(PolicyContext $ctx): bool
    {
        return $this->isAdmin($ctx) || $this->isStaff($ctx);
    }

    public function update(PolicyContext $ctx, ?int $saleId = null): bool
    {
        return $this->isAdmin($ctx) || $this->isStaff($ctx);
    }

    public function delete(PolicyContext $ctx, ?int $saleId = null): bool
    {
        return $this->isAdmin($ctx);
    }

    public function refund(PolicyContext $ctx, ?int $saleId = null): bool
    {
        // ریفاند بهتره فقط admin
        return $this->isAdmin($ctx);
    }

    public function syncWoo(PolicyContext $ctx): bool
    {
        return $this->isAdmin($ctx) || $this->isStaff($ctx);
    }

    public function runAi(PolicyContext $ctx): bool
    {
        return $this->isAdmin($ctx) || $this->isStaff($ctx);
    }
}
