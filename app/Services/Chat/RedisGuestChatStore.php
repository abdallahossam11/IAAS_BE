<?php

namespace App\Services\Chat;

use App\Contracts\GuestChatStore;
use Illuminate\Support\Facades\Redis;

class RedisGuestChatStore implements GuestChatStore
{
    private int $historyTtl;
    private int $pendingTtl;

    public function __construct(?int $historyTtl = null, ?int $pendingTtl = null)
    {
        $this->historyTtl = $historyTtl ?? (int) config('chat.guest_session_ttl', 86400);
        $this->pendingTtl = $pendingTtl ?? (int) config('chat.guest_pending_ttl', 600);
    }

    private function messagesKey(string $tokenHash): string
    {
        return "guest_chat:{$tokenHash}:messages";
    }

    private function pendingKey(string $tokenHash): string
    {
        return "guest_chat:{$tokenHash}:pending";
    }

    private function requestKey(string $requestId): string
    {
        return "guest_ai_request:{$requestId}";
    }

    // ── appendUserMessage ────────────────────────────────────────────────────

    public function appendUserMessage(string $tokenHash, string $requestId, string $content): void
    {
        $key   = $this->messagesKey($tokenHash);
        $entry = json_encode([
            'request_id' => $requestId,
            'role'        => 'user',
            'content'     => $content,
            'created_at'  => now()->toIso8601String(),
        ]);

        Redis::connection()->pipeline(function ($pipe) use ($key, $entry) {
            $pipe->rpush($key, $entry);
            $pipe->expire($key, $this->historyTtl);
        });
    }

    // ── getHistory ───────────────────────────────────────────────────────────

    public function getHistory(string $tokenHash): array
    {
        $key  = $this->messagesKey($tokenHash);
        $rows = Redis::connection()->lrange($key, 0, -1);

        return array_map(fn (string $row) => json_decode($row, true), $rows);
    }

    // ── createRequest ────────────────────────────────────────────────────────

    public function createRequest(string $requestId, string $tokenHash): void
    {
        $key  = $this->requestKey($requestId);
        $now  = now()->toIso8601String();
        $data = [
            'request_id'    => $requestId,
            'token_hash'    => $tokenHash,
            'status'        => 'queued',
            'content'       => '',
            'error_code'    => '',
            'error_message' => '',
            'created_at'    => $now,
            'updated_at'    => $now,
        ];

        Redis::connection()->pipeline(function ($pipe) use ($key, $data) {
            $pipe->hmset($key, $data);
            $pipe->expire($key, $this->historyTtl);
        });
    }

    // ── getRequest ───────────────────────────────────────────────────────────

    public function getRequest(string $requestId): ?array
    {
        $data = Redis::connection()->hgetall($this->requestKey($requestId));

        return (is_array($data) && ! empty($data)) ? $data : null;
    }

    // ── acquirePending ───────────────────────────────────────────────────────

    public function acquirePending(string $tokenHash, string $requestId): bool
    {
        $result = Redis::connection()->set(
            $this->pendingKey($tokenHash),
            $requestId,
            'EX',
            $this->pendingTtl,
            'NX',
        );

        return $result !== null && $result !== false;
    }

    // ── refreshPendingIfOwned ────────────────────────────────────────────────

    public function refreshPendingIfOwned(string $tokenHash, string $requestId): bool
    {
        $lua = <<<'LUA'
local val = redis.call('GET', KEYS[1])
if val == ARGV[1] then
    redis.call('EXPIRE', KEYS[1], ARGV[2])
    return 1
end
return 0
LUA;

        $result = Redis::connection()->eval(
            $lua,
            1,
            $this->pendingKey($tokenHash),
            $requestId,
            (string) $this->pendingTtl,
        );

        return (int) $result === 1;
    }

    // ── clearPending ─────────────────────────────────────────────────────────

    public function clearPending(string $tokenHash, string $requestId): bool
    {
        $lua = <<<'LUA'
local val = redis.call('GET', KEYS[1])
if val == ARGV[1] then
    redis.call('DEL', KEYS[1])
    return 1
end
return 0
LUA;

        $result = Redis::connection()->eval(
            $lua,
            1,
            $this->pendingKey($tokenHash),
            $requestId,
        );

        return (int) $result === 1;
    }

    // ── markProcessing ───────────────────────────────────────────────────────

    public function markProcessing(string $requestId, string $tokenHash): bool
    {
        $lua = <<<'LUA'
local req    = KEYS[1]
local pend   = KEYS[2]
local rId    = ARGV[1]
local tHash  = ARGV[2]
local ttl    = tonumber(ARGV[3])
local nowStr = ARGV[4]

if redis.call('EXISTS', req) == 0 then return 0 end
if redis.call('HGET', req, 'token_hash') ~= tHash then return 0 end
if redis.call('HGET', req, 'status') ~= 'queued' then return 0 end
if redis.call('GET', pend) ~= rId then return 0 end

redis.call('HSET', req, 'status', 'processing', 'updated_at', nowStr)
redis.call('EXPIRE', req, ttl)
return 1
LUA;

        $result = Redis::connection()->eval(
            $lua,
            2,
            $this->requestKey($requestId),
            $this->pendingKey($tokenHash),
            $requestId,
            $tokenHash,
            (string) $this->historyTtl,
            now()->toIso8601String(),
        );

        return (int) $result === 1;
    }

    // ── completeRequest ──────────────────────────────────────────────────────

    public function completeRequest(string $requestId, string $tokenHash, string $content): bool
    {
        $lua = <<<'LUA'
local req     = KEYS[1]
local msgs    = KEYS[2]
local pend    = KEYS[3]
local rId     = ARGV[1]
local tHash   = ARGV[2]
local content = ARGV[3]
local ttl     = tonumber(ARGV[4])
local nowStr  = ARGV[5]
local entry   = ARGV[6]

if redis.call('EXISTS', req) == 0 then return 0 end
if redis.call('HGET', req, 'token_hash') ~= tHash then return 0 end
if redis.call('HGET', req, 'status') ~= 'processing' then return 0 end
if redis.call('GET', pend) ~= rId then return 0 end

redis.call('RPUSH', msgs, entry)
redis.call('EXPIRE', msgs, ttl)
redis.call('HSET', req, 'status', 'completed', 'content', content, 'error_code', '', 'error_message', '', 'updated_at', nowStr)
redis.call('EXPIRE', req, ttl)
redis.call('DEL', pend)
return 1
LUA;

        $assistantEntry = json_encode([
            'request_id' => $requestId,
            'role'        => 'assistant',
            'content'     => $content,
            'created_at'  => now()->toIso8601String(),
        ]);

        $result = Redis::connection()->eval(
            $lua,
            3,
            $this->requestKey($requestId),
            $this->messagesKey($tokenHash),
            $this->pendingKey($tokenHash),
            $requestId,
            $tokenHash,
            $content,
            (string) $this->historyTtl,
            now()->toIso8601String(),
            $assistantEntry,
        );

        return (int) $result === 1;
    }

    // ── failRequest ──────────────────────────────────────────────────────────

    public function failRequest(
        string $requestId,
        string $tokenHash,
        string $errorCode,
        string $errorMessage,
    ): bool {
        $lua = <<<'LUA'
local req     = KEYS[1]
local pend    = KEYS[2]
local rId     = ARGV[1]
local tHash   = ARGV[2]
local errCode = ARGV[3]
local errMsg  = ARGV[4]
local ttl     = tonumber(ARGV[5])
local nowStr  = ARGV[6]

if redis.call('EXISTS', req) == 0 then return 0 end
if redis.call('HGET', req, 'token_hash') ~= tHash then return 0 end
local status = redis.call('HGET', req, 'status')
if status ~= 'queued' and status ~= 'processing' then return 0 end

redis.call('HSET', req, 'status', 'failed', 'error_code', errCode, 'error_message', errMsg, 'updated_at', nowStr)
redis.call('EXPIRE', req, ttl)
if redis.call('GET', pend) == rId then
    redis.call('DEL', pend)
end
return 1
LUA;

        $result = Redis::connection()->eval(
            $lua,
            2,
            $this->requestKey($requestId),
            $this->pendingKey($tokenHash),
            $requestId,
            $tokenHash,
            $errorCode,
            $errorMessage,
            (string) $this->historyTtl,
            now()->toIso8601String(),
        );

        return (int) $result === 1;
    }

    // ── rollbackSubmission ───────────────────────────────────────────────────

    public function rollbackSubmission(string $tokenHash, string $requestId): void
    {
        $lua = <<<'LUA'
local req   = KEYS[1]
local msgs  = KEYS[2]
local pend  = KEYS[3]
local rId   = ARGV[1]
local tHash = ARGV[2]
local ttl   = tonumber(ARGV[3])

-- verify ownership before touching request
if redis.call('EXISTS', req) == 1 then
    if redis.call('HGET', req, 'token_hash') == tHash then
        redis.call('DEL', req)
    end
end

-- find and remove only the matching user entry
local all = redis.call('LRANGE', msgs, 0, -1)
for i, v in ipairs(all) do
    local decoded = cjson.decode(v)
    if decoded and decoded.role == 'user' and decoded.request_id == rId then
        redis.call('LREM', msgs, 1, v)
        break
    end
end

-- refresh TTL only when history non-empty
local remaining = redis.call('LLEN', msgs)
if remaining > 0 then
    redis.call('EXPIRE', msgs, ttl)
end

-- clear pending only if still owned
if redis.call('GET', pend) == rId then
    redis.call('DEL', pend)
end

return 1
LUA;

        Redis::connection()->eval(
            $lua,
            3,
            $this->requestKey($requestId),
            $this->messagesKey($tokenHash),
            $this->pendingKey($tokenHash),
            $requestId,
            $tokenHash,
            (string) $this->historyTtl,
        );
    }

    // ── refreshHistoryTtl ────────────────────────────────────────────────────

    public function refreshHistoryTtl(string $tokenHash): void
    {
        Redis::connection()->expire($this->messagesKey($tokenHash), $this->historyTtl);
    }
}
