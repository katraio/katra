<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Enums\MeetingOutcomeKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingOutcomeResource;
use App\Meetings\MeetingAccess;
use App\Meetings\MeetingOutcomeService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MeetingOutcomeStoreController extends Controller
{
    public function __invoke(
        Request $request,
        string $meeting,
        MeetingAccess $access,
        MeetingOutcomeService $outcomes,
    ): MeetingOutcomeResource {
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->findVisible($user, $meeting);
        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::enum(MeetingOutcomeKind::class)],
            'body' => ['required', 'string', 'max:2000'],
            'assignee_user_id' => ['nullable', 'string', 'ulid'],
        ]);
        $kind = MeetingOutcomeKind::from($validated['kind']);
        $assignee = isset($validated['assignee_user_id'])
            ? User::query()->where('public_id', $validated['assignee_user_id'])->first()
            : null;

        return new MeetingOutcomeResource($outcomes->create(
            $resolved,
            $user,
            $kind,
            $validated['body'],
            $assignee,
        ));
    }
}
