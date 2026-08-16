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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MeetingCreateController extends Controller
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
            'starts_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['required', 'integer', Rule::in([15, 30, 45, 60])],
            'desired_outcome' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'participant_ids' => ['sometimes', 'array', 'max:50'],
            'participant_ids.*' => ['required', 'string', 'ulid', 'distinct'],
            'guest_emails' => ['sometimes', 'array', 'max:25'],
            'guest_emails.*' => ['required', 'email:rfc', 'max:254', 'distinct:ignore_case'],
            'agenda_items' => ['sometimes', 'array', 'max:20'],
            'agenda_items.*.title' => ['required', 'string', 'max:240'],
            'agenda_items.*.owner_user_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'agenda_items.*.duration_minutes' => [
                'required',
                'integer',
                Rule::in([5, 10, 15, 20, 30]),
            ],
        ]);

        $participantIds = $validated['participant_ids'] ?? [];
        $guestEmails = $validated['guest_emails'] ?? [];

        if ($participantIds === [] && $guestEmails === []) {
            throw ValidationException::withMessages([
                'participant_ids' => ['Invite at least one Katra user or external guest.'],
            ]);
        }

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
            CarbonImmutable::parse($validated['starts_at']),
            $validated['duration_minutes'],
            $validated['desired_outcome'] ?? null,
            $guestEmails,
            $validated['agenda_items'] ?? [],
        );

        return (new MeetingResource($meeting))->response()->setStatusCode(201);
    }
}
