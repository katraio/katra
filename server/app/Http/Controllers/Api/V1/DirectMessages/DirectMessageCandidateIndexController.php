<?php

namespace App\Http\Controllers\Api\V1\DirectMessages;

use App\Auth\OrganizationAccess;
use App\Conversations\DirectMessageParticipantDirectory;
use App\Http\Controllers\Controller;
use App\Http\Resources\DirectMessageCandidateResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class DirectMessageCandidateIndexController extends Controller
{
    public function __invoke(
        Request $request,
        string $organization,
        OrganizationAccess $organizations,
        DirectMessageParticipantDirectory $directory,
    ): AnonymousResourceCollection {
        $validated = $request->validate([
            'query' => ['sometimes', 'nullable', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $resolvedOrganization = $organizations->visibleTo($user)
            ->where('public_id', $organization)
            ->firstOrFail();

        return DirectMessageCandidateResource::collection($directory->candidates(
            $user,
            $resolvedOrganization,
            (string) ($validated['query'] ?? ''),
            (int) ($validated['limit'] ?? 20),
        ));
    }
}
