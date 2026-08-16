<?php

namespace Database\Seeders;

use App\Enums\OrganizationAbility;
use App\Enums\OrganizationRole;
use Illuminate\Database\Seeder;
use Silber\Bouncer\BouncerFacade as Bouncer;

final class AuthorizationSeeder extends Seeder
{
    public function run(): void
    {
        Bouncer::scope()->remove();

        $roles = collect(OrganizationRole::cases())->mapWithKeys(
            fn (OrganizationRole $role): array => [
                $role->value => Bouncer::role()->firstOrCreate(['name' => $role->value]),
            ],
        );

        $abilities = collect(OrganizationAbility::cases())->mapWithKeys(
            fn (OrganizationAbility $ability): array => [
                $ability->value => Bouncer::ability()->firstOrCreate(['name' => $ability->value]),
            ],
        );

        Bouncer::allow($roles[OrganizationRole::Administrator->value])->to($abilities->values());
        Bouncer::allow($roles[OrganizationRole::InternalMember->value])->to(
            $abilities->only([
                OrganizationAbility::View->value,
                OrganizationAbility::CreateInternalChannels->value,
                OrganizationAbility::CreateDirectMessages->value,
                OrganizationAbility::CreateMeetings->value,
            ])->values(),
        );
        Bouncer::allow($roles[OrganizationRole::ClientAdministrator->value])->to(
            $abilities->only([
                OrganizationAbility::View->value,
                OrganizationAbility::InviteClientMembers->value,
            ])->values(),
        );
        Bouncer::allow($roles[OrganizationRole::ClientMember->value])->to(
            $abilities->only([OrganizationAbility::View->value])->values(),
        );
    }
}
