<?php

namespace App\Http\Controllers\Api\V1\Guest;

use App\Contracts\GuestChatStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guest\SendGuestMessageRequest;
use App\Jobs\ProcessGuestAiChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GuestChatController extends Controller
{
    private const TOKEN_PATTERN = '/^[A-Za-z0-9]{64}$/';

    public function __construct(private readonly GuestChatStore $store) {}

    // ── send ──────────────────────────────────────────────────────────────────

    public function send(SendGuestMessageRequest $request): JsonResponse
    {
        $rawToken = $request->header('X-Guest-Token');
        $isNew    = false;

        if ($rawToken === null || $rawToken === '') {
            $rawToken = Str::random(64);
            $isNew    = true;
        }

        $tokenHash = hash('sha256', $rawToken);
        $requestId = (string) Str::uuid();

        if (! $this->store->acquirePending($tokenHash, $requestId)) {
            return response()->json([
                'success' => false,
                'message' => 'A response is already being processed.',
            ], 409);
        }

        try {
            $this->store->appendUserMessage($tokenHash, $requestId, $request->validated('message'));
            $this->store->createRequest($requestId, $tokenHash);

            ProcessGuestAiChat::dispatch($requestId, $tokenHash)
                ->onConnection(config('chat.ai_connection'))
                ->onQueue(config('chat.ai_queue'));
        } catch (\Throwable $e) {
            $this->store->rollbackSubmission($tokenHash, $requestId);
            throw $e;
        }

        $data = ['request_id' => $requestId, 'status' => 'queued'];

        if ($isNew) {
            $data['guest_token'] = $rawToken;
        }

        return response()->json(['success' => true, 'data' => $data], 202);
    }

    // ── status ────────────────────────────────────────────────────────────────

    public function status(Request $request, string $requestId): JsonResponse
    {
        $unauthorized = $this->requireValidToken($request);

        if ($unauthorized !== null) {
            return $unauthorized;
        }

        if (! Str::isUuid($requestId)) {
            return response()->json([
                'success' => false,
                'message' => 'Request not found.',
            ], 404);
        }

        $rawToken  = $request->header('X-Guest-Token');
        $tokenHash = hash('sha256', $rawToken);

        $req = $this->store->getRequest($requestId);

        if ($req === null || ! hash_equals($req['token_hash'], $tokenHash)) {
            return response()->json([
                'success' => false,
                'message' => 'Request not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'request_id' => $req['request_id'],
                'status'     => $req['status'],
                'content'    => ($req['content'] !== '') ? $req['content'] : null,
                'error_code' => ($req['error_code'] !== '') ? $req['error_code'] : null,
            ],
        ]);
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function history(Request $request): JsonResponse
    {
        $unauthorized = $this->requireValidToken($request);

        if ($unauthorized !== null) {
            return $unauthorized;
        }

        $rawToken  = $request->header('X-Guest-Token');
        $tokenHash = hash('sha256', $rawToken);

        $internalHistory = $this->store->getHistory($tokenHash);

        $this->store->refreshHistoryTtl($tokenHash);

        $publicHistory = array_filter(
            $internalHistory,
            fn (array $entry) => in_array($entry['role'] ?? '', ['user', 'assistant'], true),
        );

        $messages = array_values(array_map(fn (array $entry) => [
            'role'       => $entry['role'],
            'content'    => $entry['content'],
            'created_at' => $entry['created_at'],
        ], $publicHistory));

        return response()->json([
            'success' => true,
            'data'    => ['messages' => $messages],
        ]);
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function requireValidToken(Request $request): ?JsonResponse
    {
        $rawToken = $request->header('X-Guest-Token');

        if ($rawToken === null || $rawToken === '') {
            return response()->json([
                'success' => false,
                'message' => 'Guest token required.',
            ], 401);
        }

        if (! preg_match(self::TOKEN_PATTERN, $rawToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Guest token required.',
            ], 401);
        }

        return null;
    }
}
