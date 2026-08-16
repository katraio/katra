<?php

namespace App\Http\Resources;

use App\Enums\AttentionKind;
use App\Enums\ConversationType;
use App\Models\AttentionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin AttentionItem */
final class AttentionItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isMessageAttention = $this->kind === AttentionKind::MessageAttentionRequest;
        $isMeetingAction = $this->kind === AttentionKind::MeetingAction;
        $isChannel = $this->conversation?->type === ConversationType::Channel;
        $channelName = $this->conversation?->channel?->name;
        $sourceLabel = $isChannel && $channelName !== null ? "#{$channelName}" : 'a Direct Message';
        $meeting = $this->meetingOutcome?->meeting;

        return [
            'id' => $this->public_id,
            'kind' => $this->kind->value,
            'priority' => $this->priority->value,
            'state' => $this->state->value,
            'title' => $isMeetingAction
                ? "{$this->actor->name} assigned you an action from {$meeting?->title}"
                : ($isMessageAttention
                    ? "{$this->actor->name} requested your attention in {$sourceLabel}"
                    : "{$this->actor->name} requested to continue a Direct Message"),
            'reason' => $isMeetingAction
                ? 'Complete this follow-up in the normal Inbox; the source meeting will reflect the same completion state.'
                : ($isMessageAttention
                    ? 'Review the linked message and mark this item complete when the requested attention is no longer needed.'
                    : 'Review the completed conversation and reopen it when your team is ready to continue.'),
            'context' => $isMeetingAction
                ? Str::limit($this->meetingOutcome?->body ?? '', 280)
                : ($isMessageAttention
                    ? Str::limit($this->message?->body ?? '', 280)
                    : 'A client participant asked your team to continue this completed conversation.'),
            'organization' => [
                'id' => $this->organization->public_id,
                'name' => $this->organization->name,
            ],
            'actor' => [
                'id' => $this->actor->public_id,
                'name' => $this->actor->name,
            ],
            'destination' => [
                'type' => $isMeetingAction ? 'meeting' : $this->conversation?->type->value,
                'conversation_id' => $this->conversation?->public_id,
                'meeting_id' => $meeting?->public_id,
                'message_id' => $this->message?->public_id,
                'thread_root_message_id' => $this->message?->parent?->public_id,
                'message_sequence' => $this->message?->sequence,
            ],
            'viewed_at' => $this->viewed_at?->toISOString(),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
