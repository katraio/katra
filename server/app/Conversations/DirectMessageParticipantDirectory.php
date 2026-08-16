<?php

namespace App\Conversations;

use App\Auth\OrganizationAuthorization;
use App\Enums\MembershipKind;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationAbility;
use App\Enums\OrganizationKind;
use App\Enums\SystemRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class DirectMessageParticipantDirectory
{
    public function __construct(
        private readonly DirectMessageAccess $access,
        private readonly OrganizationAuthorization $authorization,
    ) {}

    /** @return Collection<int, array{user: User, kind: MembershipKind}> */
    public function candidates(
        User $actor,
        Organization $organization,
        string $query = '',
        int $limit = 20,
    ): Collection {
        if (
            ! $this->access->isOperatingInternal($actor)
            || ! $this->authorization->allows(
                $actor,
                $organization,
                OrganizationAbility::CreateDirectMessages->value,
            )
        ) {
            throw new AuthorizationException;
        }

        $normalized = mb_strtolower(trim($query));

        return User::query()
            ->with(['organizationMemberships.organization', 'roles'])
            ->whereKeyNot($actor->getKey())
            ->where(function (Builder $eligible) use ($organization): void {
                $eligible->where(
                    fn (Builder $internal): Builder => $this->authorizedInternal($internal, $organization),
                );

                if ($organization->kind === OrganizationKind::Client) {
                    $eligible->orWhereHas(
                        'organizationMemberships',
                        fn (Builder $membership): Builder => $membership
                            ->where('organization_id', $organization->getKey())
                            ->where('kind', MembershipKind::Client->value)
                            ->where('status', MembershipStatus::Active->value),
                    );
                }
            })
            ->when($normalized !== '', function (Builder $users) use ($normalized): void {
                $users->whereRaw(
                    "position(? in lower(first_name || ' ' || last_name)) > 0",
                    [$normalized],
                );
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(fn (User $user): array => [
                'user' => $user,
                'kind' => $this->isOperatingInternal($user)
                    ? MembershipKind::Internal
                    : MembershipKind::Client,
            ]);
    }

    private function authorizedInternal(Builder $users, Organization $organization): Builder
    {
        return $users->where(function (Builder $internal) use ($organization): void {
            $internal
                ->whereHas(
                    'roles',
                    fn (Builder $role): Builder => $role->where('name', SystemRole::GlobalAdministrator->value),
                )
                ->orWhere(function (Builder $member) use ($organization): void {
                    $member
                        ->whereHas(
                            'organizationMemberships',
                            fn (Builder $membership): Builder => $membership
                                ->where('kind', MembershipKind::Internal->value)
                                ->where('status', MembershipStatus::Active->value)
                                ->whereHas(
                                    'organization',
                                    fn (Builder $operating): Builder => $operating
                                        ->where('kind', OrganizationKind::Operating->value),
                                ),
                        )
                        ->whereHas(
                            'organizationMemberships',
                            fn (Builder $membership): Builder => $membership
                                ->where('organization_id', $organization->getKey())
                                ->where('kind', MembershipKind::Internal->value)
                                ->where('status', MembershipStatus::Active->value),
                        );
                });
        });
    }

    private function isOperatingInternal(User $user): bool
    {
        return $user->roles->contains('name', SystemRole::GlobalAdministrator->value)
            || $user->organizationMemberships->contains(
                fn ($membership): bool => $membership->kind === MembershipKind::Internal
                    && $membership->status === MembershipStatus::Active
                    && $membership->organization->kind === OrganizationKind::Operating,
            );
    }
}
