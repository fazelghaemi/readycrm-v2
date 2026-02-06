<?php
/**
 * File: app/Domain/Policies/PolicyContext.php
 *
 * یک context خیلی ساده برای سیاست‌ها (کاربر/نقش)
 */

declare(strict_types=1);

namespace App\Domain\Policies;

final class PolicyContext
{
    public ?int $user_id;
    public string $role;

    /** @var array<string,mixed> */
    public array $meta;

    /**
     * @param array<string,mixed> $meta
     */
    public function __construct(?int $user_id, string $role, array $meta = [])
    {
        $this->user_id = $user_id;
        $this->role = $role;
        $this->meta = $meta;
    }
}
