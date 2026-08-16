<?php

namespace App\Http\Resources;

use App\Models\MeetingOutcome;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MeetingOutcome */
final class MeetingOutcomeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $author = $this->author ?? $this->guestAuthor;

        return [
            'id' => $this->public_id,
            'sequence' => $this->sequence,
            'kind' => $this->kind->value,
            'body' => $this->body,
            'author' => [
                'id' => $author?->public_id,
                'name' => $author?->name ?? $author?->display_name,
            ],
            'assignee' => $this->assignee === null ? null : [
                'id' => $this->assignee->public_id,
                'name' => $this->assignee->name,
            ],
            'completed_at' => $this->attentionItem?->resolved_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
