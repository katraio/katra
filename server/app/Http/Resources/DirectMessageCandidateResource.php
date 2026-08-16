<?php

namespace App\Http\Resources;

use App\Enums\MembershipKind;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin array{user: User, kind: MembershipKind} */
final class DirectMessageCandidateResource extends JsonResource
{
    /** @return array<string, string> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['user']->public_id,
            'first_name' => $this->resource['user']->first_name,
            'last_name' => $this->resource['user']->last_name,
            'name' => $this->resource['user']->name,
            'kind' => $this->resource['kind']->value,
        ];
    }
}
