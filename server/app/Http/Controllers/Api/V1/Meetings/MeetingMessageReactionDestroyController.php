<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingMessageResource;
use App\Meetings\MeetingAccess;
use App\Meetings\MeetingMessageReactionService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MeetingMessageReactionDestroyController extends Controller
{
    public function __invoke(
        Request $request,
        string $meeting,
        string $message,
        MeetingAccess $access,
        MeetingMessageReactionService $reactions,
    ): MeetingMessageResource {
        $validated = $request->validate([
            'kind' => ['required', 'string', Rule::in(MeetingMessageReactionService::SUPPORTED_KINDS)],
        ]);
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->findVisible($user, $meeting);

        return new MeetingMessageResource($reactions->remove($resolved, $user, $message, $validated['kind']));
    }
}
