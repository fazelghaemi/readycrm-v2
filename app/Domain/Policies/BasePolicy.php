<?php
/**
 * File: app/Domain/Policies/BasePolicy.php
 */

declare(strict_types=1);

namespace App\Domain\Policies;

use App\Domain\Enums\UserRole;

abstract class BasePolicy
{
    protected function isAdmin(PolicyContext $ctx): bool
    {
        return UserRole::canManageEverything($ctx->role);
    }

    protected function isStaff(PolicyContext $ctx): bool
    {
        return $ctx->role === UserRole::STAFF;
    }

    protected function isViewer(PolicyContext $ctx): bool
    {
        return $ctx->role === UserRole::VIEWER;
    }

    protected function isAuthenticated(PolicyContext $ctx): bool
    {
        return $ctx->user_id !== null;
    }
}
