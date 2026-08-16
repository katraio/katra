<?php

namespace App\Http\Resources;

use App\Models\MeetingGuestSession;
use App\Models\MeetingMessage;
use App\Models\MeetingMessageReaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MeetingMessage */
final class MeetingMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $reactions = $this->relationLoaded('reactions') ? $this->reactions : $this->reactions()->get();
        $guestSession = $request->user('meeting-guest');
        $author = $this->author ?? $this->guestAuthor;

        return [
            'id' => $this->public_id,
            'sequence' => $this->sequence,
            'body' => $this->body,
            'author' => [
                'id' => $author?->public_id,
                'name' => $author?->name ?? $author?->display_name,
            ],
            'reactions' => $reactions
                ->groupBy('kind')
                ->map(fn ($group, string $kind): array => [
                    'kind' => $kind,
                    'count' => $group->count(),
                    'reacted_by_current_user' => $group->contains(function (MeetingMessageReaction $reaction) use ($request, $guestSession): bool {
                        if ($guestSession instanceof MeetingGuestSession) {
                            return $reaction->meeting_participant_id === $guestSession->meeting_participant_id;
                        }

                        return $reaction->user_id === $request->user()?->getKey();
                    }),
                ])
                ->sortBy('kind')
                ->values(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
