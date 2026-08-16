<?php

namespace App\Http\Resources;

use App\Enums\ConversationType;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
final class CommunicationSearchResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $conversation = $this->conversation;

        return [
            'type' => 'message',
            'message_id' => $this->public_id,
            'conversation_id' => $conversation->public_id,
            'conversation_type' => $conversation->type->value,
            'conversation_label' => $conversation->type === ConversationType::Channel
                ? $conversation->channel?->name
                : $conversation->directMessage?->participants
                    ->reject(fn (User $participant): bool => $participant->is($user))
                    ->sortBy('name')
                    ->pluck('name')
                    ->implode(', '),
            'body' => $this->currentBody(),
            'author' => [
                'id' => $this->author->public_id,
                'name' => $this->author->name,
            ],
            'sequence' => $this->sequence,
            'thread_root_message_id' => $this->parent?->public_id,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
