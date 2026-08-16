<?php

namespace Database\Factories;

use App\Enums\ChannelVisibility;
use App\Enums\ConversationType;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Channel> */
class ChannelFactory extends Factory
{
    protected $model = Channel::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'organization_id' => Organization::factory(),
            'conversation_id' => function (array $attributes): int {
                return Conversation::factory()->create([
                    'organization_id' => $attributes['organization_id'],
                    'type' => ConversationType::Channel,
                ])->getKey();
            },
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'visibility' => ChannelVisibility::Public,
        ];
    }

    public function private(): static
    {
        return $this->state(fn (): array => ['visibility' => ChannelVisibility::Private]);
    }

    public function clientTeam(): static
    {
        return $this->state(fn (): array => [
            'name' => 'Team',
            'slug' => 'team',
            'visibility' => ChannelVisibility::ClientTeam,
        ]);
    }
}
