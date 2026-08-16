<?php

namespace App\Http\Controllers\Api\V1\DirectMessages;

use App\Conversations\DirectMessageAccess;
use App\Conversations\DirectMessageService;
use App\Http\Controllers\Controller;
use App\Http\Resources\DirectMessageResource;
use App\Models\User;
use Illuminate\Http\Request;

final class DirectMessageCompleteController extends Controller
{
    public function __invoke(
        Request $request,
        string $directMessage,
        DirectMessageAccess $access,
        DirectMessageService $directMessages,
    ): DirectMessageResource {
        /** @var User $user */
        $user = $request->user();
        $resolved = $access->resolveVisible($user, $directMessage);

        return new DirectMessageResource(
            $directMessages->complete($resolved, $user)->load([
                'initiatedBy',
                'internalOwner',
                'completedBy',
                'continuationRequestedBy',
            ]),
        );
    }
}
