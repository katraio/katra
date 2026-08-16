<?php

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OrganizationInvitation> */
class OrganizationInvitationFactory extends Factory
{
    protected $model = OrganizationInvitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'role' => OrganizationRole::InternalMember,
            'token_hash' => hash('sha256', Str::random(64)),
            'invited_by_user_id' => User::factory(),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'accepted_by_user_id' => null,
            'revoked_at' => null,
            'last_sent_at' => null,
            'last_delivery_status' => null,
            'last_delivery_at' => null,
        ];
    }
}
