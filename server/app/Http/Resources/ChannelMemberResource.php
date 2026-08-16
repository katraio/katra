<?php

namespace App\Http\Resources;

use App\Models\ConversationMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ConversationMembership */
final class ChannelMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user->public_id,
            'first_name' => $this->user->first_name,
            'last_name' => $this->user->last_name,
            'name' => $this->user->name,
            'role' => $this->channel_role?->value,
            'joined_at' => $this->joined_at->toISOString(),
            'is_current_user' => $request->user()?->is($this->user) ?? false,
        ];
    }
}
