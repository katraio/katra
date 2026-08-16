<?php

namespace App\Http\Controllers\Api\V1\Meetings;

use App\Http\Controllers\Controller;
use App\Meetings\MeetingAccess;
use App\Meetings\MeetingMediaService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MeetingMediaCredentialController extends Controller
{
    public function __invoke(
        Request $request,
        string $meeting,
        MeetingAccess $access,
        MeetingMediaService $media,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->findVisible($user, $meeting);

        return response()
            ->json(['data' => $media->credentialForUser($resolved, $user)])
            ->header('Cache-Control', 'private, no-store')
            ->header('Pragma', 'no-cache');
    }
}
