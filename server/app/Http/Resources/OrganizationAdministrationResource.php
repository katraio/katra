<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OrganizationAdministrationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'kind' => $this->kind->value,
            'member_count' => (int) $this->member_count,
            'created_at' => $this->created_at?->toISOString(),
            'actions' => [
                'update' => true,
            ],
        ];
    }
}
