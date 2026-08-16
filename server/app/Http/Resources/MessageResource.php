<?php

namespace App\Http\Resources;

use App\Models\Message;
use App\Models\MessageAttentionTarget;
use App\Models\MessageMention;
use App\Models\MessageReaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
final class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $reactions = $this->relationLoaded('reactions') ? $this->reactions : $this->reactions()->get();

        return [
            'id' => $this->public_id,
            'sequence' => $this->sequence,
            'body' => $this->currentBody(),
            'author' => [
                'id' => $this->author->public_id,
                'first_name' => $this->author->first_name,
                'last_name' => $this->author->last_name,
                'name' => $this->author->name,
            ],
            'parent_message_id' => $this->parent?->public_id,
            'mention_user_ids' => $this->mentions
                ->map(fn (MessageMention $mention): string => $mention->mentionedUser->public_id)
                ->sort()
                ->values(),
            'mentions' => $this->mentions
                ->map(fn (MessageMention $mention): array => [
                    'id' => $mention->mentionedUser->public_id,
                    'first_name' => $mention->mentionedUser->first_name,
                    'last_name' => $mention->mentionedUser->last_name,
                    'name' => $mention->mentionedUser->name,
                ])
                ->sortBy('name')
                ->values(),
            'attention_user_ids' => $this->attentionTargets
                ->map(fn (MessageAttentionTarget $target): string => $target->targetedUser->public_id)
                ->sort()
                ->values(),
            'attention_targets' => $this->attentionTargets
                ->map(fn (MessageAttentionTarget $target): array => [
                    'id' => $target->targetedUser->public_id,
                    'first_name' => $target->targetedUser->first_name,
                    'last_name' => $target->targetedUser->last_name,
                    'name' => $target->targetedUser->name,
                ])
                ->sortBy('name')
                ->values(),
            'reactions' => $reactions
                ->groupBy('kind')
                ->map(fn ($group, string $kind): array => [
                    'kind' => $kind,
                    'count' => $group->count(),
                    'reacted_by_current_user' => $group->contains(
                        fn (MessageReaction $reaction): bool => $reaction->user_id === $request->user()?->getKey(),
                    ),
                ])
                ->sortBy('kind')
                ->values(),
            'edited_at' => $this->latestRevision?->operation === 'edit'
                ? $this->latestRevision->created_at->toISOString()
                : null,
            'deleted_at' => $this->latestRevision?->operation === 'delete'
                ? $this->latestRevision->created_at->toISOString()
                : null,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
