<?php

namespace App\Http\Resources;

use App\Models\Meeting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Meeting */
final class GuestMeetingLobbyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'starts_at' => $this->starts_at->toISOString(),
            'duration_minutes' => $this->duration_minutes,
            'status' => $this->status->value,
            'organizer' => ['name' => $this->organizer->name],
            'organization' => ['name' => $this->organization->name],
        ];
    }
}
