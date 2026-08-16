<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Auth\OrganizationAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingResource;
use App\Meetings\MeetingService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class MeetingInstantCreateController extends Controller
{
    public function __invoke(
        Request $request,
        string $organization,
        OrganizationAccess $organizations,
        MeetingService $meetings,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $resolvedOrganization = $organizations->visibleTo($user)
            ->where('public_id', $organization)
            ->firstOrFail();
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'participant_ids' => ['sometimes', 'array', 'max:50'],
            'participant_ids.*' => ['required', 'string', 'ulid', 'distinct'],
        ]);
        $participantIds = $validated['participant_ids'] ?? [];
        $participants = User::query()->whereIn('public_id', $participantIds)->get();

        if ($participants->count() !== count($participantIds)) {
            throw ValidationException::withMessages([
                'participant_ids' => ['One or more selected people are unavailable.'],
            ]);
        }

        $meeting = $meetings->create(
            $resolvedOrganization,
            $user,
            $participants,
            $validated['title'],
            CarbonImmutable::now(),
            30,
            null,
            [],
            [],
            true,
        );

        return (new MeetingResource($meeting))->response()->setStatusCode(201);
    }
}
