<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingOutcomeResource;
use App\Meetings\MeetingAccess;
use App\Meetings\MeetingOutcomeService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class MeetingOutcomeIndexController extends Controller
{
    public function __invoke(
        Request $request,
        string $meeting,
        MeetingAccess $access,
        MeetingOutcomeService $outcomes,
    ): AnonymousResourceCollection {
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->findVisible($user, $meeting);

        return MeetingOutcomeResource::collection($outcomes->list($resolved));
    }
}
