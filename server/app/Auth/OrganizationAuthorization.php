<?php

namespace App\Auth;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Silber\Bouncer\BouncerFacade as Bouncer;

final class OrganizationAuthorization
{
    public function __construct(private readonly OrganizationScope $scope) {}

    public function assign(User $user, Organization $organization, OrganizationRole $role): void
    {
        $this->scope->run($organization->getKey(), function () use ($user, $role): void {
            $user->assign($role->value);
            Bouncer::refresh($user);
        });
    }

    public function allows(User $user, Organization $organization, string $ability): bool
    {
        if (! $user->isGlobalAdministrator() && ! $user->organizationMemberships()
            ->where('organization_id', $organization->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->exists()) {
            return false;
        }

        return $this->scope->run(
            $organization->getKey(),
            fn (): bool => Gate::forUser($user)->allows($ability),
        );
    }

    public function role(User $user, Organization $organization): ?OrganizationRole
    {
        return $this->scope->run(
            $organization->getKey(),
            fn (): ?OrganizationRole => collect(OrganizationRole::cases())
                ->first(fn (OrganizationRole $role): bool => $user->isA($role->value)),
        );
    }
}
