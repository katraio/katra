<?php

namespace App\Auth;

use App\Enums\MembershipKind;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class OrganizationAccess
{
    /** @return Builder<Organization> */
    public function visibleTo(User $user): Builder
    {
        if ($user->isGlobalAdministrator()) {
            return Organization::query();
        }

        return Organization::query()->whereHas(
            'memberships',
            fn (Builder $query): Builder => $query
                ->where('user_id', $user->getKey())
                ->where('kind', MembershipKind::Internal->value)
                ->where('status', MembershipStatus::Active->value),
        );
    }
}
