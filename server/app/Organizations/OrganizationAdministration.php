<?php

namespace App\Organizations;

use App\Enums\ChannelVisibility;
use App\Enums\ConversationType;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationAdministrationEventKind;
use App\Enums\OrganizationKind;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OrganizationAdministration
{
    /** @return EloquentCollection<int, Organization> */
    public function organizations(User $actor): EloquentCollection
    {
        $this->ensureGlobalAdministrator($actor);

        return Organization::query()
            ->withCount([
                'memberships as member_count' => fn ($query) => $query
                    ->where('status', MembershipStatus::Active->value),
            ])
            ->orderBy('name')
            ->get();
    }

    public function createClient(User $actor, string $name, string $requestId): Organization
    {
        $this->ensureGlobalAdministrator($actor);
        $slug = Str::slug($name);

        if ($slug === '') {
            throw ValidationException::withMessages([
                'name' => ['Provide an Organization name that can form a stable identifier.'],
            ]);
        }

        if (Organization::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'name' => ['An Organization with this name already exists.'],
            ]);
        }

        try {
            return DB::transaction(function () use ($actor, $name, $requestId, $slug): Organization {
                $organization = Organization::query()->create([
                    'name' => $name,
                    'slug' => $slug,
                    'kind' => OrganizationKind::Client,
                    'created_by_user_id' => $actor->getKey(),
                ]);

                $conversation = Conversation::query()->create([
                    'organization_id' => $organization->getKey(),
                    'type' => ConversationType::Channel,
                    'created_by_user_id' => $actor->getKey(),
                ]);

                $conversation->channel()->create([
                    'organization_id' => $organization->getKey(),
                    'name' => 'Team',
                    'slug' => 'team',
                    'visibility' => ChannelVisibility::ClientTeam,
                ]);

                $organization->administrationEvents()->create([
                    'request_id' => $requestId,
                    'actor_user_id' => $actor->getKey(),
                    'kind' => OrganizationAdministrationEventKind::Created,
                    'previous_name' => null,
                    'current_name' => $name,
                ]);

                return $organization->loadCount([
                    'memberships as member_count' => fn ($query) => $query
                        ->where('status', MembershipStatus::Active->value),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => ['An Organization with this name already exists.'],
            ]);
        }
    }

    public function rename(
        User $actor,
        Organization $organization,
        string $name,
        string $requestId,
    ): Organization {
        $this->ensureGlobalAdministrator($actor);

        return DB::transaction(function () use ($actor, $organization, $name, $requestId): Organization {
            $locked = Organization::query()->lockForUpdate()->findOrFail($organization->getKey());

            if ($locked->name !== $name) {
                $previousName = $locked->name;
                $locked->forceFill(['name' => $name])->save();
                $locked->administrationEvents()->create([
                    'request_id' => $requestId,
                    'actor_user_id' => $actor->getKey(),
                    'kind' => OrganizationAdministrationEventKind::Renamed,
                    'previous_name' => $previousName,
                    'current_name' => $name,
                ]);
            }

            return $locked->loadCount([
                'memberships as member_count' => fn ($query) => $query
                    ->where('status', MembershipStatus::Active->value),
            ]);
        });
    }

    private function ensureGlobalAdministrator(User $actor): void
    {
        if (! $actor->isGlobalAdministrator()) {
            throw new AuthorizationException;
        }
    }
}
