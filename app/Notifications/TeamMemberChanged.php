<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Roles;

class TeamMemberChanged extends AdminNotification
{
    /**
     * @param  'added'|'role-changed'|'deactivated'|'removed'  $action
     */
    public function __construct(
        private readonly string $memberName,
        private readonly string $action,
        private readonly string $role,
        private readonly string $actorName,
        private readonly ?int $storeId = null,
    ) {}

    /**
     * @param  'added'|'role-changed'|'deactivated'|'removed'  $action
     */
    public static function for(User $member, string $action, User $actor): self
    {
        return new self($member->name, $action, $member->role, $actor->name, $member->vendor_id);
    }

    public static function section(): string
    {
        return 'team';
    }

    public function title(): string
    {
        return match ($this->action) {
            'added' => "{$this->memberName} was added to the team",
            'role-changed' => "{$this->memberName} is now a ".strtolower(Roles::label($this->role)),
            'deactivated' => "{$this->memberName} was deactivated",
            default => "{$this->memberName} was removed from the team",
        };
    }

    public function body(): string
    {
        return "By {$this->actorName}.";
    }

    public function url(): string
    {
        return '/admin/team';
    }

    public function tone(): string
    {
        return $this->action === 'added' ? 'success' : 'neutral';
    }

    /**
     * A change to a store's own logins is that store's news.
     *
     * Without this the notification carried no store, and `Notifier` leaves
     * vendors out of an audience it cannot place — so the store whose team had
     * just changed was the one group never told about it. Marketplace staff
     * have no store, so their changes stay staff-only.
     */
    public function vendorId(): ?int
    {
        return $this->storeId;
    }
}
