<?php

namespace Tests\Unit;

use App\Exceptions\AiClientException;
use App\Services\Ai\AiHttpTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for AiHttpTransport.
 *
 * All HTTP calls are mocked via Http::fake() — no real network requests occur.
 * Real timeout enforcement is an integration-test item and cannot be verified here.
 */
class AiHttpTransportTest extends TestCase
{
    private const STUDENT_URL  = 'https://student-ai.test/v1/chat/student/respond';
    private const STUDENT_TOKEN = 'test-student-bearer-token';
    private const REQUEST_ID   = 'aabbccdd-0011-2233-4455-667788990000';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function transport(): AiHttpTransport
    {
        return new AiHttpTransport();
    }

    private function validPayload(): array
    {
        return [
            'schema_version'  => '1.0',
            'request_id'      => self::REQUEST_ID,
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

    private function successHttpResponse(): array
    {
        return [
            self::STUDENT_URL => Http::response([
                'schema_version' => '1.0',
                'request_id'     => self::REQUEST_ID,
                'status'         => 'completed',
                'content'        => 'Test AI response',
            ], 200),
        ];
    }

    private function assertThrowsConfigError(callable $fn, string $context = ''): void
    {
        try {
            $fn();
            $this->fail('Expected AiClientException[AI_CONFIGURATION_ERROR] was not thrown.' . ($context ? " ($context)" : ''));
        } catch (AiClientException $e) {
            $this->assertSame('AI_CONFIGURATION_ERROR', $e->errorCode, $context);
        }
    }

    // ── Full URL is used exactly (no path appended) ───────────────────────────

    public function test_configured_full_url_used_directly_without_path_appended(): void
    {
        Http::fake($this->successHttpResponse());

        $this->transport()->send(self::STUDENT_URL, self::STUDENT_TOKEN, $this->validPayload());

        Http::assertSent(fn (Request $req) => $req->url() === self::STUDENT_URL);
    }

    // ── Authorization header carries the Bearer token ─────────────────────────

    public function test_bearer_token_sent_in_authorization_header(): void
    {
        Http::fake($this->successHttpResponse());

        $this->transport()->send(self::STUDENT_URL, self::STUDENT_TOKEN, $this->validPayload());

        Http::assertSent(fn (Request $req) =>
            $req->hasHeader('Authorization') &&
            $req->header('Authorization')[0] === 'Bearer ' . self::STUDENT_TOKEN
        );
    }

    // ── X-Request-ID equals body request_id ──────────────────────────────────

    public function test_x_request_id_header_equals_body_request_id(): void
    {
        Http::fake($this->successHttpResponse());

        $this->transport()->send(self::STUDENT_URL, self::STUDENT_TOKEN, $this->validPayload());

        Http::assertSent(fn (Request $req) =>
            $req->hasHeader('X-Request-ID') &&
            $req->header('X-Request-ID')[0] === self::REQUEST_ID
        );
    }

    // ── Content-Type includes charset ─────────────────────────────────────────

    public function test_content_type_header_includes_charset_utf8(): void
    {
        Http::fake($this->successHttpResponse());

        $this->transport()->send(self::STUDENT_URL, self::STUDENT_TOKEN, $this->validPayload());

        Http::assertSent(fn (Request $req) =>
            str_contains(
                implode('', $req->header('Content-Type')),
                'application/json; charset=utf-8',
            )
        );
    }

    // ── Accept: application/json ──────────────────────────────────────────────

    public function test_accept_application_json_sent(): void
    {
        Http::fake($this->successHttpResponse());

        $this->transport()->send(self::STUDENT_URL, self::STUDENT_TOKEN, $this->validPayload());

        Http::assertSent(fn (Request $req) =>
            str_contains(
                implode('', $req->header('Accept')),
                'application/json',
            )
        );
    }

    // ── Timeouts are read from config (integration-test territory for enforcement) ──

    public function test_connect_and_response_timeout_config_defaults_are_correct(): void
    {
        // Verify the config keys the transport reads. Actual enforcement is
        // a real-network integration-test item — Http::fake() cannot reproduce cURL timeouts.
        $this->assertSame(10, (int) config('chat.ai_connect_timeout', 10));
        $this->assertSame(420, (int) config('chat.ai_response_timeout', 420));
    }

    // ── Redirect responses are returned without following ─────────────────────

    public function test_redirect_response_is_returned_without_following(): void
    {
        // withoutRedirecting() causes the 301 to be returned directly (not followed).
        Http::fake([
            self::STUDENT_URL => Http::response('', 301, ['Location' => 'https://other.example.com']),
        ]);

        $response = $this->transport()->send(self::STUDENT_URL, self::STUDENT_TOKEN, $this->validPayload());

        $this->assertSame(301, $response->status());
        Http::assertSentCount(1); // only one request — redirect NOT followed
    }

    // ── Configuration guard — URL ─────────────────────────────────────────────

    public function test_missing_url_throws_configuration_error(): void
    {
        $this->assertThrowsConfigError(
            fn () => $this->transport()->send('', self::STUDENT_TOKEN, $this->validPayload()),
            'empty URL',
        );
        Http::assertNothingSent();
    }

    public function test_blank_url_after_trim_throws_configuration_error(): void
    {
        $this->assertThrowsConfigError(
            fn () => $this->transport()->send('   ', self::STUDENT_TOKEN, $this->validPayload()),
            'whitespace-only URL',
        );
        Http::assertNothingSent();
    }

    public function test_malformed_url_throws_configuration_error(): void
    {
        $this->assertThrowsConfigError(
            fn () => $this->transport()->send('not a url at all', self::STUDENT_TOKEN, $this->validPayload()),
            'malformed URL',
        );
        Http::assertNothingSent();
    }

    public function test_url_without_host_throws_configuration_error(): void
    {
        $this->assertThrowsConfigError(
            fn () => $this->transport()->send('https://', self::STUDENT_TOKEN, $this->validPayload()),
            'URL without host',
        );
        Http::assertNothingSent();
    }

    public function test_url_with_userinfo_throws_configuration_error(): void
    {
        $this->assertThrowsConfigError(
            fn () => $this->transport()->send(
                'https://user:pass@ai.example.com/v1/chat',
                self::STUDENT_TOKEN,
                $this->validPayload(),
            ),
            'URL with userinfo',
        );
        Http::assertNothingSent();
    }

    public function test_url_with_fragment_throws_configuration_error(): void
    {
        $this->assertThrowsConfigError(
            fn () => $this->transport()->send(
                'https://ai.example.com/v1/chat#section',
                self::STUDENT_TOKEN,
                $this->validPayload(),
            ),
            'URL with fragment',
        );
        Http::assertNothingSent();
    }

    public function test_url_with_query_string_throws_configuration_error(): void
    {
        $this->assertThrowsConfigError(
            fn () => $this->transport()->send(
                'https://ai.example.com/v1/chat?debug=1',
                self::STUDENT_TOKEN,
                $this->validPayload(),
            ),
            'URL with query string',
        );
        Http::assertNothingSent();
    }

    public function test_unsupported_scheme_throws_configuration_error(): void
    {
        $this->assertThrowsConfigError(
            fn () => $this->transport()->send(
                'ftp://ai.example.com/v1/chat',
                self::STUDENT_TOKEN,
                $this->validPayload(),
            ),
            'ftp scheme',
        );
        Http::assertNothingSent();
    }

    public function test_non_https_url_rejected_outside_local_testing(): void
    {
        // Simulate production environment where http:// must be rejected
        $this->app->instance('env', 'production');

        try {
            $this->assertThrowsConfigError(
                fn () => $this->transport()->send(
                    'http://ai.example.com/v1/chat',
                    self::STUDENT_TOKEN,
                    $this->validPayload(),
                ),
                'http:// in production',
            );
            Http::assertNothingSent();
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_http_url_is_allowed_in_testing_environment(): void
    {
        // Explicitly bind 'testing' (symmetric with the production test which binds 'production')
        $this->app->instance('env', 'testing');

        Http::fake([
            'http://ai.test/v1/chat' => Http::response([
                'schema_version' => '1.0',
                'request_id'     => self::REQUEST_ID,
                'status'         => 'completed',
                'content'        => 'ok',
            ], 200),
        ]);

        $response = $this->transport()->send(
            'http://ai.test/v1/chat',
            self::STUDENT_TOKEN,
            $this->validPayload(),
        );

        $this->assertSame(200, $response->status());
    }

    // ── Configuration guard — token ───────────────────────────────────────────

    public function test_missing_token_throws_configuration_error(): void
    {
        $this->assertThrowsConfigError(
            fn () => $this->transport()->send(self::STUDENT_URL, '', $this->validPayload()),
            'empty token',
        );
        Http::assertNothingSent();
    }

    public function test_blank_token_after_trim_throws_configuration_error(): void
    {
        $this->assertThrowsConfigError(
            fn () => $this->transport()->send(self::STUDENT_URL, '   ', $this->validPayload()),
            'whitespace-only token',
        );
        Http::assertNothingSent();
    }

    // ── Guard messages must not leak sensitive values ─────────────────────────

    public function test_config_error_message_does_not_contain_url_or_token(): void
    {
        $secretUrl   = 'not a url at all';
        $secretToken = 'super-secret-bearer-token';

        try {
            $this->transport()->send($secretUrl, $secretToken, $this->validPayload());
            $this->fail('Expected AiClientException was not thrown.');
        } catch (AiClientException $e) {
            $this->assertStringNotContainsString($secretUrl,   $e->getMessage());
            $this->assertStringNotContainsString($secretToken, $e->getMessage());
        }
    }

    // ── Transport failures ────────────────────────────────────────────────────

    public function test_connection_failure_maps_to_ai_connection_error(): void
    {
        Http::fake([self::STUDENT_URL => Http::failedConnection()]);

        try {
            $this->transport()->send(self::STUDENT_URL, self::STUDENT_TOKEN, $this->validPayload());
            $this->fail('Expected AiClientException was not thrown.');
        } catch (AiClientException $e) {
            $this->assertContains($e->errorCode, ['AI_CONNECTION_ERROR', 'TIMEOUT']);
        }
    }

    // ── Response is returned for downstream validation ────────────────────────

    public function test_successful_request_returns_response_object(): void
    {
        Http::fake($this->successHttpResponse());

        $response = $this->transport()->send(self::STUDENT_URL, self::STUDENT_TOKEN, $this->validPayload());

        $this->assertSame(200, $response->status());
        Http::assertSentCount(1);
    }

    // ── URL and token are normalised (trimmed) before use ─────────────────────

    public function test_url_with_surrounding_whitespace_is_normalised_before_sending(): void
    {
        Http::fake($this->successHttpResponse());

        $this->transport()->send('  ' . self::STUDENT_URL . '  ', self::STUDENT_TOKEN, $this->validPayload());

        Http::assertSent(fn (Request $req) => $req->url() === self::STUDENT_URL);
    }

    public function test_token_with_surrounding_whitespace_is_normalised_in_authorization_header(): void
    {
        Http::fake($this->successHttpResponse());

        $this->transport()->send(self::STUDENT_URL, '  ' . self::STUDENT_TOKEN . '  ', $this->validPayload());

        Http::assertSent(fn (Request $req) =>
            $req->header('Authorization')[0] === 'Bearer ' . self::STUDENT_TOKEN
        );
    }
}
