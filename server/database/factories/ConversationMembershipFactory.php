<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConversationMembership> */
class ConversationMembershipFactory extends Factory
{
    protected $model = ConversationMembership::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'channel_role' => null,
            'last_read_sequence' => 0,
            'joined_at' => now(),
            'left_at' => null,
            'removed_at' => null,
            'added_by_user_id' => null,
        ];
    }
}
