<?php

namespace App\Providers;

use App\Contracts\AiChatClientContract;
use App\Contracts\GuestChatStore;
use App\Models\Admin;
use App\Models\ChatConversation;
use App\Models\Faculty;
use App\Models\Student;
use App\Models\VehicleRequest;
use App\Policies\AdminPolicy;
use App\Policies\ChatConversationPolicy;
use App\Policies\FacultyPolicy;
use App\Policies\StudentPolicy;
use App\Policies\VehicleRequestPolicy;
use App\Services\Ai\AiHttpTransport;
use App\Services\Ai\FakeAiChatClient;
use App\Services\Ai\HttpAiChatClient;
use App\Services\Chat\InMemoryGuestChatStore;
use App\Services\Chat\RedisGuestChatStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiHttpTransport::class, fn () => new AiHttpTransport());

        // Final AI contract client (Phase 9C). Drives both the student (9D) and
        // guest (9E) queue flows.
        $this->app->singleton(AiChatClientContract::class, function ($app) {
            return match (config('chat.ai_driver', 'fake')) {
                'fake'  => new FakeAiChatClient(),
                'http'  => new HttpAiChatClient($app->make(AiHttpTransport::class)),
                default => throw new \LogicException(
                    'Unsupported AI_CHAT_DRIVER: ' . config('chat.ai_driver'),
                ),
            };
        });

        $this->app->singleton(GuestChatStore::class, function ($app) {
            return $app->environment('testing')
                ? new InMemoryGuestChatStore()
                : new RedisGuestChatStore();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
        Gate::policy(Admin::class, AdminPolicy::class);
        Gate::policy(ChatConversation::class, ChatConversationPolicy::class);
        Gate::policy(Faculty::class, FacultyPolicy::class);
        Gate::policy(Student::class, StudentPolicy::class);
        Gate::policy(VehicleRequest::class, VehicleRequestPolicy::class);

        RateLimiter::for('guest-chat-submit', function (Request $request) {
            return Limit::perMinutes(
                (int) config('chat.guest_throttle.minutes', 1),
                (int) config('chat.guest_throttle.requests', 10),
            )->by($request->ip());
        });
    }
}
