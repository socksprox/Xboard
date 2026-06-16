<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Models\UserOffense;
use App\Services\OffenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class OffenseController extends Controller
{
    public function __construct(
        private readonly OffenseService $offenseService
    ) {
    }

    public function report(Request $request): JsonResponse
    {
        $node = $request->attributes->get('node_info');

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|min:1',
            'type' => 'required|string|in:torrent',
            'detail' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
            ], 422);
        }

        try {
            $result = $this->offenseService->report(
                $node,
                (int) $request->input('user_id'),
                (string) $request->input('type'),
                (array) $request->input('detail', [])
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['data' => $result]);
    }
}
