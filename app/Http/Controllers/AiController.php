<?php

namespace App\Http\Controllers;

use App\AI\Services\WudiAiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AiController extends Controller
{
    public function chat(Request $request, WudiAiService $ai)
    {
        $request->validate([
            'message' => 'required|string|max:1200',
            'conversation_id' => 'nullable|integer',
            'request_id' => 'nullable|string|max:80',
        ]);

        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($ai->respond(
            $user,
            $request->string('message')->toString(),
            $request->integer('conversation_id') ?: null,
            $request->string('request_id')->toString() ?: null,
        ));
    }

    public function history(Request $request, WudiAiService $ai)
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($ai->history($user));
    }

    public function cancel(Request $request)
    {
        $request->validate(['request_id' => 'required|string|max:80']);

        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $requestId = $request->string('request_id')->toString();
        Cache::put("ai:cancel:{$user->id}:{$requestId}", true, now()->addMinutes(5));

        return response()->json(['status' => 'cancelled']);
    }
}
