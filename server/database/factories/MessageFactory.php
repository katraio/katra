<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'sequence' => fake()->unique()->numberBetween(1, PHP_INT_MAX),
            'author_user_id' => User::factory(),
            'idempotency_key' => (string) Str::ulid(),
            'parent_message_id' => null,
            'body' => fake()->sentence(),
        ];
    }
}
