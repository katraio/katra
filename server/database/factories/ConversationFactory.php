<?php

namespace Database\Factories;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'type' => ConversationType::Channel,
            'next_message_sequence' => 1,
            'created_by_user_id' => User::factory(),
            'archived_at' => null,
        ];
    }

    public function directMessage(): static
    {
        return $this->state(fn (): array => ['type' => ConversationType::DirectMessage]);
    }
}
