<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Auth\OrganizationAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingCandidateResource;
use App\Meetings\MeetingParticipantDirectory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class MeetingCandidateIndexController extends Controller
{
    public function __invoke(
        Request $request,
        string $organization,
        OrganizationAccess $organizations,
        MeetingParticipantDirectory $directory,
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

        return MeetingCandidateResource::collection($directory->candidates(
            $user,
            $resolvedOrganization,
            (string) ($validated['query'] ?? ''),
            (int) ($validated['limit'] ?? 50),
        ));
    }
}
