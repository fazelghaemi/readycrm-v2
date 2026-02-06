<?php
/**
 * File: app/Domain/Policies/SettingsPolicy.php
 */

declare(strict_types=1);

namespace App\Domain\Policies;

final class SettingsPolicy extends BasePolicy
{
    public function view(PolicyContext $ctx): bool
    {
        // تنظیمات حساس: حداقل staff و admin
        return $this->isAdmin($ctx) || $this->isStaff($ctx);
    }

    public function update(PolicyContext $ctx): bool
    {
        // تغییر تنظیمات اصلی (Woo keys, GapGPT keys) فقط admin
        return $this->isAdmin($ctx);
    }
}
