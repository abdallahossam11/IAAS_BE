<?php

namespace App\Providers;

use App\Models\Admin;
use App\Models\Faculty;
use App\Models\Student;
use App\Models\VehicleRequest;
use App\Policies\AdminPolicy;
use App\Policies\FacultyPolicy;
use App\Policies\StudentPolicy;
use App\Policies\VehicleRequestPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
    }
}
