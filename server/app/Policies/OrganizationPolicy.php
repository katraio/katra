<?php

namespace App\Policies;

use App\Auth\OrganizationAccess;
use App\Models\Organization;
use App\Models\User;

final class OrganizationPolicy
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->visibleTo($user)->exists();
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->access->visibleTo($user)->whereKey($organization->getKey())->exists();
    }

    public function manageAny(User $user): bool
    {
        return $user->isGlobalAdministrator();
    }

    public function create(User $user): bool
    {
        return $user->isGlobalAdministrator();
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->isGlobalAdministrator();
    }
}
