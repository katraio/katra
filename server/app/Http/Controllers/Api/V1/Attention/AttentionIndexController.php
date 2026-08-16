<?php

namespace App\Http\Controllers\Api\V1\Attention;

use App\Attention\AttentionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttentionItemResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AttentionIndexController extends Controller
{
    public function __invoke(Request $request, AttentionService $attention): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return AttentionItemResource::collection($attention->unresolvedFor($user));
    }
}
