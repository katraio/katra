<?php

namespace Database\Factories;

use App\Enums\OrganizationKind;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Organization> */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(100, 999999),
            'kind' => OrganizationKind::Client,
            'created_by_user_id' => null,
        ];
    }

    public function operating(): static
    {
        return $this->state(fn (): array => [
            'kind' => OrganizationKind::Operating,
        ]);
    }
}
