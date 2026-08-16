<?php

namespace App\Http\Controllers\Api\V1\Search;

use App\Conversations\AuthorizedCommunicationSearch;
use App\Http\Controllers\Controller;
use App\Http\Resources\CommunicationSearchResultResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

final class CommunicationSearchController extends Controller
{
    public function __invoke(
        Request $request,
        AuthorizedCommunicationSearch $search,
    ): AnonymousResourceCollection|JsonResponse {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'current_conversation_id' => ['sometimes', 'string', 'ulid'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:25'],
        ]);
        /** @var User $user */
        $user = $request->user();

        try {
            $results = $search->search(
                $user,
                trim($validated['q']),
                $validated['current_conversation_id'] ?? null,
                $validated['limit'] ?? 20,
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Communication search is temporarily unavailable.',
            ], 503);
        }

        return CommunicationSearchResultResource::collection($results);
    }
}
