<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorsSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function setFrontendUrl(string $url): void
    {
        $this->app['config']->set('cors.allowed_origins', [$url]);
    }

    public function test_attacker_origin_is_not_allowed(): void
    {
        $this->app['config']->set('cors.allowed_origins', ['https://galalabot.app']);

        $response = $this->withHeaders(['Origin' => 'https://attacker.example'])
            ->getJson('/api/v1/guest/chat/history');

        $acao = $response->headers->get('Access-Control-Allow-Origin');
        $this->assertNotEquals('https://attacker.example', $acao);
    }

    public function test_official_frontend_origin_is_allowed(): void
    {
        $this->app['config']->set('cors.allowed_origins', ['https://galalabot.app']);

        $response = $this->withHeaders(['Origin' => 'https://galalabot.app'])
            ->getJson('/api/v1/guest/chat/history');

        $acao = $response->headers->get('Access-Control-Allow-Origin');
        $this->assertEquals('https://galalabot.app', $acao);
    }

    public function test_local_origin_is_allowed_in_testing(): void
    {
        // APP_ENV=testing so local origins are included via cors.php logic
        $this->app['config']->set('cors.allowed_origins', [
            'http://127.0.0.1:8088',
            'http://localhost:8088',
        ]);

        $response = $this->withHeaders(['Origin' => 'http://127.0.0.1:8088'])
            ->getJson('/api/v1/guest/chat/history');

        $acao = $response->headers->get('Access-Control-Allow-Origin');
        $this->assertEquals('http://127.0.0.1:8088', $acao);
    }

    public function test_wildcard_is_not_returned(): void
    {
        $this->app['config']->set('cors.allowed_origins', ['https://galalabot.app']);

        $response = $this->withHeaders(['Origin' => 'https://galalabot.app'])
            ->getJson('/api/v1/guest/chat/history');

        $acao = $response->headers->get('Access-Control-Allow-Origin');
        $this->assertNotEquals('*', $acao);
    }

    public function test_cors_config_does_not_contain_wildcard(): void
    {
        // Ensure the production cors config does not have a wildcard anywhere
        $config = config('cors.allowed_origins');
        $this->assertNotContains('*', $config);
    }
}
