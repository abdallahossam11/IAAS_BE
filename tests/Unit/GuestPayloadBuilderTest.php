<?php

namespace Tests\Unit;

use App\Services\Ai\GuestPayloadBuilder;
use Tests\TestCase;

class GuestPayloadBuilderTest extends TestCase
{
    private string $requestId = 'req-uuid-1234';
    private string $tokenHash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenHash = hash('sha256', 'raw-token-should-never-appear');
    }

    private function history(): array
    {
        return [
            ['request_id' => 'req-1', 'role' => 'user',      'content' => 'Hello',     'created_at' => '2026-01-01T00:00:00+00:00'],
            ['request_id' => 'req-1', 'role' => 'assistant',  'content' => 'Hi there',  'created_at' => '2026-01-01T00:00:01+00:00'],
            ['request_id' => 'req-2', 'role' => 'user',       'content' => 'Follow up', 'created_at' => '2026-01-01T00:00:02+00:00'],
        ];
    }

    public function test_payload_contains_request_id(): void
    {
        $payload = (new GuestPayloadBuilder())->build($this->requestId, $this->tokenHash, $this->history());

        $this->assertSame($this->requestId, $payload['request_id']);
    }

    public function test_payload_contains_guest_session_reference_equal_to_token_hash(): void
    {
        $payload = (new GuestPayloadBuilder())->build($this->requestId, $this->tokenHash, $this->history());

        $this->assertSame($this->tokenHash, $payload['guest_session_reference']);
    }

    public function test_payload_language_is_auto(): void
    {
        $payload = (new GuestPayloadBuilder())->build($this->requestId, $this->tokenHash, $this->history());

        $this->assertSame('auto', $payload['language']);
    }

    public function test_payload_never_contains_raw_token(): void
    {
        $rawToken = 'raw-token-should-never-appear';
        $hash     = hash('sha256', $rawToken);

        $payload = (new GuestPayloadBuilder())->build($this->requestId, $hash, $this->history());

        $this->assertStringNotContainsString($rawToken, json_encode($payload));
    }

    public function test_messages_include_user_and_assistant_roles(): void
    {
        $payload = (new GuestPayloadBuilder())->build($this->requestId, $this->tokenHash, $this->history());

        $roles = array_column($payload['messages'], 'role');

        $this->assertContains('user', $roles);
        $this->assertContains('assistant', $roles);
    }

    public function test_messages_ordered_oldest_to_newest(): void
    {
        $payload = (new GuestPayloadBuilder())->build($this->requestId, $this->tokenHash, $this->history());

        $this->assertSame('user',      $payload['messages'][0]['role']);
        $this->assertSame('assistant', $payload['messages'][1]['role']);
        $this->assertSame('user',      $payload['messages'][2]['role']);
    }

    public function test_message_items_omit_internal_request_id(): void
    {
        $payload = (new GuestPayloadBuilder())->build($this->requestId, $this->tokenHash, $this->history());

        foreach ($payload['messages'] as $msg) {
            $this->assertArrayNotHasKey('request_id', $msg);
        }
    }

    public function test_message_items_omit_created_at(): void
    {
        $payload = (new GuestPayloadBuilder())->build($this->requestId, $this->tokenHash, $this->history());

        foreach ($payload['messages'] as $msg) {
            $this->assertArrayNotHasKey('created_at', $msg);
        }
    }

    public function test_message_items_contain_only_role_and_content(): void
    {
        $payload = (new GuestPayloadBuilder())->build($this->requestId, $this->tokenHash, $this->history());

        foreach ($payload['messages'] as $msg) {
            $this->assertSame(['role', 'content'], array_keys($msg));
        }
    }

    public function test_system_role_entries_excluded(): void
    {
        $history = array_merge($this->history(), [
            ['request_id' => 'sys', 'role' => 'system', 'content' => 'You are helpful.', 'created_at' => '2026-01-01T00:00:03+00:00'],
        ]);

        $payload = (new GuestPayloadBuilder())->build($this->requestId, $this->tokenHash, $history);

        foreach ($payload['messages'] as $msg) {
            $this->assertNotSame('system', $msg['role']);
        }
    }

    public function test_unknown_role_entries_excluded(): void
    {
        $history = array_merge($this->history(), [
            ['request_id' => 'x', 'role' => 'failed', 'content' => 'oops', 'created_at' => '2026-01-01T00:00:04+00:00'],
        ]);

        $payload = (new GuestPayloadBuilder())->build($this->requestId, $this->tokenHash, $history);

        foreach ($payload['messages'] as $msg) {
            $this->assertNotSame('failed', $msg['role']);
        }
    }

    public function test_schema_version_is_1_0(): void
    {
        $payload = (new GuestPayloadBuilder())->build($this->requestId, $this->tokenHash, []);

        $this->assertSame('1.0', $payload['schema_version']);
    }

    public function test_top_level_payload_keys(): void
    {
        $payload = (new GuestPayloadBuilder())->build($this->requestId, $this->tokenHash, []);

        $this->assertArrayHasKey('schema_version',          $payload);
        $this->assertArrayHasKey('request_id',              $payload);
        $this->assertArrayHasKey('guest_session_reference', $payload);
        $this->assertArrayHasKey('language',                $payload);
        $this->assertArrayHasKey('messages',                $payload);
    }

    public function test_empty_history_produces_empty_messages(): void
    {
        $payload = (new GuestPayloadBuilder())->build($this->requestId, $this->tokenHash, []);

        $this->assertSame([], $payload['messages']);
    }
}
