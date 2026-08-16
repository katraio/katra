<?php

namespace Database\Factories;

use App\Enums\DirectMessageState;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DirectMessage> */
class DirectMessageFactory extends Factory
{
    protected $model = DirectMessage::class;

    public function configure(): static
    {
        return $this->afterMaking(function (DirectMessage $directMessage): void {
            $participants = collect([
                $directMessage->initiatedBy,
                User::factory()->create(),
            ])->sortBy('id')->values();

            $directMessage->participant_set_hash = md5($participants->pluck('id')->implode(','));
            $directMessage->setRelation('factoryParticipants', $participants);
        })->afterCreating(function (DirectMessage $directMessage): void {
            foreach ($directMessage->getRelation('factoryParticipants') as $participant) {
                OrganizationMembership::factory()->create([
                    'organization_id' => $directMessage->organization_id,
                    'user_id' => $participant->getKey(),
                ]);
                $directMessage->participantRecords()->create(['user_id' => $participant->getKey()]);
                $directMessage->conversation->memberships()->create([
                    'user_id' => $participant->getKey(),
                    'joined_at' => now(),
                    'added_by_user_id' => $directMessage->initiated_by_user_id,
                ]);
            }

            $directMessage->transitions()->create([
                'from_state' => null,
                'to_state' => DirectMessageState::Open,
                'actor_user_id' => $directMessage->initiated_by_user_id,
                'created_at' => now(),
            ]);
        });
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $initiator = User::factory()->create();

        return [
            'organization_id' => Organization::factory()->operating(),
            'conversation_id' => function (array $attributes): int {
                return Conversation::factory()->directMessage()->create([
                    'organization_id' => $attributes['organization_id'],
                    'created_by_user_id' => $attributes['initiated_by_user_id'],
                ])->getKey();
            },
            'initiated_by_user_id' => $initiator->getKey(),
            'internal_owner_user_id' => null,
            'state' => DirectMessageState::Open,
            'completed_at' => null,
            'completed_by_user_id' => null,
            'continuation_requested_at' => null,
            'continuation_requested_by_user_id' => null,
        ];
    }
}
