<?php

namespace App\Http\Controllers\Api\V1\Messages;

use App\Conversations\ConversationReadService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConversationReadPositionUpdateController extends Controller
{
    public function __invoke(
        Request $request,
        string $conversation,
        ConversationReadService $readState,
    ): JsonResponse {
        $validated = $request->validate([
            'through_sequence' => ['required', 'integer', 'min:0'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $readState->advance($user, $conversation, $validated['through_sequence']),
        ]);
    }
}
