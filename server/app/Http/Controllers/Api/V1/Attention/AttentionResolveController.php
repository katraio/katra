<?php

namespace App\Http\Controllers\Api\V1\Attention;

use App\Attention\AttentionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttentionItemResource;
use App\Models\User;
use Illuminate\Http\Request;

final class AttentionResolveController extends Controller
{
    public function __invoke(Request $request, string $attentionItem, AttentionService $attention): AttentionItemResource
    {
        /** @var User $user */
        $user = $request->user();

        return new AttentionItemResource($attention->resolve($user, $attentionItem));
    }
}
