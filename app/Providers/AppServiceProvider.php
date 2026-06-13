<?php

namespace App\Providers;

use App\Contracts\GuestAiChatClientContract;
use App\Contracts\GuestChatStore;
use App\Contracts\StudentAiChatClientContract;
use App\Models\Admin;
use App\Models\Faculty;
use App\Models\Student;
use App\Models\VehicleRequest;
use App\Policies\AdminPolicy;
use App\Policies\FacultyPolicy;
use App\Policies\StudentPolicy;
use App\Policies\VehicleRequestPolicy;
use App\Services\Ai\AiHttpErrorMapper;
use App\Services\Ai\AiHttpResponseValidator;
use App\Services\Ai\AiHttpTransport;
use App\Services\Ai\AiOutboundPayloadValidator;
use App\Services\Ai\FakeGuestAiChatClient;
use App\Services\Ai\FakeStudentAiChatClient;
use App\Services\Ai\HttpGuestAiChatClient;
use App\Services\Ai\HttpStudentAiChatClient;
use App\Services\Chat\InMemoryGuestChatStore;
use App\Services\Chat\RedisGuestChatStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiHttpErrorMapper::class, fn () => new AiHttpErrorMapper());

        $this->app->singleton(
            AiHttpResponseValidator::class,
            fn ($app) => new AiHttpResponseValidator($app->make(AiHttpErrorMapper::class)),
        );

        $this->app->singleton(AiOutboundPayloadValidator::class, fn () => new AiOutboundPayloadValidator());

        $this->app->singleton(AiHttpTransport::class, fn () => new AiHttpTransport());

        $this->app->singleton(StudentAiChatClientContract::class, function ($app) {
            return match (config('chat.ai_driver', 'fake')) {
                'fake'  => new FakeStudentAiChatClient(),
                'http'  => new HttpStudentAiChatClient(
                    $app->make(AiHttpTransport::class),
                    $app->make(AiOutboundPayloadValidator::class),
                    $app->make(AiHttpResponseValidator::class),
                ),
                default => throw new \LogicException(
                    'Unsupported AI_CHAT_DRIVER: ' . config('chat.ai_driver'),
                ),
            };
        });

        $this->app->singleton(GuestAiChatClientContract::class, function ($app) {
            return match (config('chat.ai_driver', 'fake')) {
                'fake'  => new FakeGuestAiChatClient(),
                'http'  => new HttpGuestAiChatClient(
                    $app->make(AiHttpTransport::class),
                    $app->make(AiOutboundPayloadValidator::class),
                    $app->make(AiHttpResponseValidator::class),
                ),
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
        Gate::policy(Admin::class, AdminPolicy::class);
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
