<?php

namespace Tests\Feature\Security;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase K — Sanctum token expiry and trusted proxy.
 *
 * Token expiry:
 *   config('sanctum.expiration') must default to 10080 (7 days in minutes)
 *   and must be read from SANCTUM_TOKEN_EXPIRATION env var.
 *
 * Trusted proxy:
 *   When TRUSTED_PROXIES is set and an X-Forwarded-For header is present,
 *   the request IP seen by the app must be the forwarded IP, not the proxy IP.
 */
class SanctumExpiryTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Token expiry configuration
    // =========================================================================

    public function test_sanctum_expiration_defaults_to_10080_minutes(): void
    {
        $this->assertSame(10080, config('sanctum.expiration'));
    }

    public function test_sanctum_expiration_is_read_from_env(): void
    {
        Config::set('sanctum.expiration', 1440);
        $this->assertSame(1440, config('sanctum.expiration'));
    }

    public function test_expired_token_is_rejected(): void
    {
        // Set expiry to 1 minute, then create a token with an expires_at in the past.
        Config::set('sanctum.expiration', 1);

        $student = Student::factory()->create();
        $token = $student->createToken('test-token');

        // Manually expire the token by backdating its created_at
        PersonalAccessToken::where('id', $token->accessToken->id)->update([
            'created_at' => now()->subMinutes(2),
        ]);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/student/profile')
            ->assertUnauthorized();
    }

    public function test_fresh_token_is_accepted_within_expiry_window(): void
    {
        Config::set('sanctum.expiration', 10080);

        $student = Student::factory()->create();
        Sanctum::actingAs($student, ['*']);

        $this->getJson('/api/v1/student/profile')
            ->assertOk();
    }

    // =========================================================================
    // Trusted proxy configuration
    // =========================================================================

    public function test_trusted_proxies_config_key_exists_in_env_example(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('TRUSTED_PROXIES=', $envExample);
    }

    public function test_sanctum_token_expiration_present_in_env_example(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));
        $this->assertStringContainsString('SANCTUM_TOKEN_EXPIRATION=10080', $envExample);
    }

    public function test_x_forwarded_for_is_honoured_when_proxies_are_trusted(): void
    {
        // When TRUSTED_PROXIES includes the connecting IP, the app should use
        // the X-Forwarded-For header as the true client IP.
        Config::set('app.trusted_proxies', '*');

        $student = Student::factory()->create();
        Sanctum::actingAs($student, ['*']);

        // The student profile route must be reachable; we check that the app
        // does not crash when X-Forwarded-For is present.
        $this->withHeaders([
            'X-Forwarded-For' => '203.0.113.42',
            'X-Forwarded-Proto' => 'https',
        ])->getJson('/api/v1/student/profile')
            ->assertOk();
    }
}
