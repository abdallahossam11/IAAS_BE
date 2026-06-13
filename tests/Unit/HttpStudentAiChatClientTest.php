<?php

namespace Tests\Unit;

use App\Exceptions\AiClientException;
use App\Services\Ai\AiHttpErrorMapper;
use App\Services\Ai\AiHttpResponseValidator;
use App\Services\Ai\AiHttpTransport;
use App\Services\Ai\AiOutboundPayloadValidator;
use App\Services\Ai\HttpStudentAiChatClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for HttpStudentAiChatClient.
 *
 * All HTTP calls are mocked — no real network requests occur.
 */
class HttpStudentAiChatClientTest extends TestCase
{
    private const STUDENT_URL   = 'https://student-ai.test/v1/chat/student/respond';
    private const STUDENT_TOKEN = 'student-bearer-token';
    private const GUEST_URL     = 'https://guest-ai.test/v1/chat/guest/respond';
    private const GUEST_TOKEN   = 'guest-bearer-token';
    private const REQUEST_ID    = 'aabbccdd-0011-2233-4455-667788990000';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        config([
            'chat.student_ai.url'   => self::STUDENT_URL,
            'chat.student_ai.token' => self::STUDENT_TOKEN,
            'chat.guest_ai.url'     => self::GUEST_URL,
            'chat.guest_ai.token'   => self::GUEST_TOKEN,
        ]);
    }

    private function makeClient(): HttpStudentAiChatClient
    {
        return new HttpStudentAiChatClient(
            new AiHttpTransport(),
            new AiOutboundPayloadValidator(),
            new AiHttpResponseValidator(new AiHttpErrorMapper()),
        );
    }

    private function validPayload(string $requestId = self::REQUEST_ID): array
    {
        return [
            'schema_version'  => '1.0',
            'request_id'      => $requestId,
            'conversation_id' => '11112222-3333-4444-5555-666677778888',
            'user_reference'  => 'student:STU-001',
            'language'        => 'auto',
            'messages'        => [['role' => 'user', 'content' => 'Hello']],
            'student_context' => [
                'student_id'        => 'STU-001',
                'full_name'         => 'Ahmed Ali',
                'email'             => 'ahmed@example.com',
                'faculty_id'        => 1,
                'faculty_name'      => 'Engineering',
                'gpa'               => 3.75,
                'credits_completed' => 90,
                'credits_required'  => 130,
            ],
        ];
    }

    private function successBody(string $requestId = self::REQUEST_ID): array
    {
        return [
            'schema_version' => '1.0',
            'request_id'     => $requestId,
            'status'         => 'completed',
            'content'        => 'Student AI answer.',
        ];
    }

    // ── Uses student URL from config (full URL, no path appended) ─────────────

    public function test_uses_student_url_from_config(): void
    {
        Http::fake([self::STUDENT_URL => Http::response($this->successBody(), 200)]);

        $this->makeClient()->send($this->validPayload());

        Http::assertSent(fn (Request $req) => $req->url() === self::STUDENT_URL);
    }

    public function test_does_not_use_guest_url(): void
    {
        Http::fake([self::STUDENT_URL => Http::response($this->successBody(), 200)]);

        $this->makeClient()->send($this->validPayload());

        Http::assertNotSent(fn (Request $req) => $req->url() === self::GUEST_URL);
    }

    // ── Uses student Bearer token (not guest token) ───────────────────────────

    public function test_student_bearer_token_is_sent(): void
    {
        Http::fake([self::STUDENT_URL => Http::response($this->successBody(), 200)]);

        $this->makeClient()->send($this->validPayload());

        Http::assertSent(fn (Request $req) =>
            $req->header('Authorization')[0] === 'Bearer ' . self::STUDENT_TOKEN
        );
    }

    public function test_guest_bearer_token_is_not_used(): void
    {
        Http::fake([self::STUDENT_URL => Http::response($this->successBody(), 200)]);

        $this->makeClient()->send($this->validPayload());

        Http::assertSent(fn (Request $req) =>
            $req->header('Authorization')[0] !== 'Bearer ' . self::GUEST_TOKEN
        );
    }

    // ── X-Request-ID == body request_id ──────────────────────────────────────

    public function test_x_request_id_equals_body_request_id(): void
    {
        Http::fake([self::STUDENT_URL => Http::response($this->successBody(), 200)]);

        $this->makeClient()->send($this->validPayload());

        Http::assertSent(fn (Request $req) =>
            $req->header('X-Request-ID')[0] === self::REQUEST_ID
        );
    }

    // ── Content-Type with charset ─────────────────────────────────────────────

    public function test_content_type_includes_charset(): void
    {
        Http::fake([self::STUDENT_URL => Http::response($this->successBody(), 200)]);

        $this->makeClient()->send($this->validPayload());

        Http::assertSent(fn (Request $req) =>
            str_contains(
                implode('', $req->header('Content-Type')),
                'application/json; charset=utf-8',
            )
        );
    }

    // ── Invalid payload → no HTTP request sent ────────────────────────────────

    public function test_invalid_outbound_payload_sends_no_http_request(): void
    {
        $payload = $this->validPayload();
        unset($payload['schema_version']); // trigger outbound validation failure

        try {
            $this->makeClient()->send($payload);
        } catch (AiClientException $e) {
            $this->assertSame('AI_CONFIGURATION_ERROR', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    // ── Valid response returned as ['content' => ...] ─────────────────────────

    public function test_valid_200_response_returns_content(): void
    {
        Http::fake([self::STUDENT_URL => Http::response($this->successBody(), 200)]);

        $result = $this->makeClient()->send($this->validPayload());

        $this->assertSame(['content' => 'Student AI answer.'], $result);
    }

    // ── Error responses mapped to correct codes ───────────────────────────────

    public function test_401_response_throws_ai_unauthorized(): void
    {
        Http::fake([self::STUDENT_URL => Http::response([
            'schema_version' => '1.0',
            'request_id'     => self::REQUEST_ID,
            'error'          => ['code' => 'UNAUTHORIZED', 'message' => 'Auth failed.', 'retryable' => false],
        ], 401)]);

        try {
            $this->makeClient()->send($this->validPayload());
            $this->fail('Expected AiClientException');
        } catch (AiClientException $e) {
            $this->assertSame('AI_UNAUTHORIZED', $e->errorCode);
        }
    }

    public function test_504_response_throws_timeout(): void
    {
        Http::fake([self::STUDENT_URL => Http::response([
            'schema_version' => '1.0',
            'request_id'     => self::REQUEST_ID,
            'error'          => ['code' => 'AI_TIMEOUT', 'message' => 'Processing timed out.', 'retryable' => true],
        ], 504)]);

        try {
            $this->makeClient()->send($this->validPayload());
            $this->fail('Expected AiClientException');
        } catch (AiClientException $e) {
            $this->assertSame('TIMEOUT', $e->errorCode);
        }
    }

    // ── 3xx not followed, treated as INVALID_AI_RESPONSE ─────────────────────

    public function test_redirect_response_treated_as_invalid(): void
    {
        Http::fake([self::STUDENT_URL => Http::response('', 301, ['Location' => 'https://other.example.com'])]);

        try {
            $this->makeClient()->send($this->validPayload());
            $this->fail('Expected AiClientException');
        } catch (AiClientException $e) {
            $this->assertSame('INVALID_AI_RESPONSE', $e->errorCode);
        }
    }

    // ── Connection failure propagated safely ──────────────────────────────────

    public function test_connection_failure_throws_ai_client_exception(): void
    {
        Http::fake([self::STUDENT_URL => Http::failedConnection()]);

        try {
            $this->makeClient()->send($this->validPayload());
            $this->fail('Expected AiClientException');
        } catch (AiClientException $e) {
            $this->assertContains($e->errorCode, ['AI_CONNECTION_ERROR', 'TIMEOUT']);
        }
    }

    // ── Driver regression: fake client still resolves when driver=fake ────────

    public function test_fake_driver_still_resolves_with_fake_client(): void
    {
        config(['chat.ai_driver' => 'fake']);

        $client = app(\App\Contracts\StudentAiChatClientContract::class);

        $this->assertInstanceOf(\App\Services\Ai\FakeStudentAiChatClient::class, $client);
    }

    // ── Driver: http resolves the real HTTP client ─────────────────────────────

    public function test_http_driver_resolves_http_student_client(): void
    {
        config(['chat.ai_driver' => 'http']);

        // Force re-resolution of the singleton (rebind to reset cached instance)
        $this->app->forgetInstance(\App\Contracts\StudentAiChatClientContract::class);

        $client = app(\App\Contracts\StudentAiChatClientContract::class);

        $this->assertInstanceOf(HttpStudentAiChatClient::class, $client);
    }

    // ── Unsupported driver throws LogicException ──────────────────────────────

    public function test_unsupported_driver_throws_logic_exception(): void
    {
        config(['chat.ai_driver' => 'unsupported_value']);
        $this->app->forgetInstance(\App\Contracts\StudentAiChatClientContract::class);

        $this->expectException(\LogicException::class);

        app(\App\Contracts\StudentAiChatClientContract::class);
    }
}
