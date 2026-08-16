<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeetingResource;
use App\Meetings\MeetingAccess;
use App\Meetings\MeetingGuestLinkService;
use App\Models\User;
use Illuminate\Http\Request;

final class MeetingGuestLinkController extends Controller
{
    public function revoke(Request $request, string $meeting, MeetingAccess $access, MeetingGuestLinkService $links): MeetingResource
    {
        /** @var User $user */
        $user = $request->user();

        return new MeetingResource($links->revoke($access->findVisible($user, $meeting), $user));
    }

    public function regenerate(Request $request, string $meeting, MeetingAccess $access, MeetingGuestLinkService $links): MeetingResource
    {
        /** @var User $user */
        $user = $request->user();

        return new MeetingResource($links->regenerate($access->findVisible($user, $meeting), $user));
    }
}
