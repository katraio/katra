<?php

namespace App\Http\Controllers\Api\V1\Channels;

use App\Conversations\ChannelAccess;
use App\Conversations\ChannelService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ChannelArchiveController extends Controller
{
    public function __invoke(
        Request $request,
        string $channel,
        ChannelAccess $access,
        ChannelService $channels,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->resolveAddressable($user, $channel);
        $channels->archive($resolved, $user);

        return response()->noContent();
    }
}
