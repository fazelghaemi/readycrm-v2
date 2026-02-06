<?php
/**
 * File: app/Domain/Policies/CustomerPolicy.php
 */

declare(strict_types=1);

namespace App\Domain\Policies;

final class CustomerPolicy extends BasePolicy
{
    public function viewAny(PolicyContext $ctx): bool
    {
        return $this->isAuthenticated($ctx);
    }

    public function view(PolicyContext $ctx, ?int $customerId = null): bool
    {
        return $this->isAuthenticated($ctx);
    }

    public function create(PolicyContext $ctx): bool
    {
        return $this->isAdmin($ctx) || $this->isStaff($ctx);
    }

    public function update(PolicyContext $ctx, ?int $customerId = null): bool
    {
        return $this->isAdmin($ctx) || $this->isStaff($ctx);
    }

    public function delete(PolicyContext $ctx, ?int $customerId = null): bool
    {
        // حذف مشتری بهتره فقط admin باشد (برای جلوگیری از دیتالاس)
        return $this->isAdmin($ctx);
    }

    public function runAi(PolicyContext $ctx): bool
    {
        // اجرای AI معمولاً هزینه دارد؛ محدودش می‌کنیم
        return $this->isAdmin($ctx) || $this->isStaff($ctx);
    }
}
