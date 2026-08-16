<?php

namespace Database\Factories;

use App\Enums\MembershipKind;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrganizationMembership> */
class OrganizationMembershipFactory extends Factory
{
    protected $model = OrganizationMembership::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'kind' => MembershipKind::Internal,
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
            'suspended_at' => null,
            'created_by_user_id' => null,
        ];
    }

    public function client(): static
    {
        return $this->state(fn (): array => [
            'kind' => MembershipKind::Client,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => MembershipStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }
}
