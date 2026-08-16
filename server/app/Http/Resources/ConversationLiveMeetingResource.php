<?php

namespace App\Http\Resources;

use App\Models\Meeting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Meeting */
final class ConversationLiveMeetingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'status' => $this->status->value,
            'started_at' => $this->started_at?->toISOString(),
            'organizer' => [
                'id' => $this->organizer->public_id,
                'name' => $this->organizer->name,
            ],
        ];
    }
}
