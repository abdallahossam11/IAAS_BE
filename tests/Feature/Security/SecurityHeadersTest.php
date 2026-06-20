<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_has_x_frame_options_deny(): void
    {
        $response = $this->get('/admin/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_admin_login_has_csp_frame_ancestors_none(): void
    {
        $response = $this->get('/admin/login');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_admin_login_has_x_content_type_options_nosniff(): void
    {
        $response = $this->get('/admin/login');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_admin_login_has_referrer_policy(): void
    {
        $response = $this->get('/admin/login');

        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_admin_login_has_permissions_policy(): void
    {
        $response = $this->get('/admin/login');

        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_api_response_has_security_headers(): void
    {
        $response = $this->getJson('/api/v1/guest/chat/history');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_hsts_absent_in_local_http_environment(): void
    {
        // APP_ENV=local and request is not secure — HSTS must not be sent
        $response = $this->get('/admin/login');

        $this->assertFalse(
            $response->headers->has('Strict-Transport-Security'),
            'HSTS must not be present for non-production or non-HTTPS requests'
        );
    }

    public function test_hsts_present_in_production_https_request(): void
    {
        // Test the middleware directly to avoid Filament route complexity
        $this->app['config']->set('app.env', 'production');

        $request = Request::create('/admin/login', 'GET', [], [], [], ['HTTPS' => 'on']);
        $middleware = new SecurityHeaders;

        $response = $middleware->handle($request, fn ($req) => response('ok'));

        $this->assertEquals(
            'max-age=31536000; includeSubDomains',
            $response->headers->get('Strict-Transport-Security'),
            'HSTS must be present on production+HTTPS responses'
        );
    }
}
