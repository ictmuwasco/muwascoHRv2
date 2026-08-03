<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(\App\Repositories\Contracts\EmployeeRepositoryInterface::class, \App\Repositories\EmployeeRepository::class);
        $this->app->bind(\App\Repositories\Contracts\DepartmentRepositoryInterface::class, \App\Repositories\DepartmentRepository::class);
        $this->app->bind(\App\Repositories\Contracts\SectionRepositoryInterface::class, \App\Repositories\SectionRepository::class);
        $this->app->bind(\App\Repositories\Contracts\AttendanceRepositoryInterface::class, \App\Repositories\AttendanceRepository::class);
        $this->app->bind(\App\Repositories\Contracts\LeaveRepositoryInterface::class, \App\Repositories\LeaveRepository::class);
        $this->app->bind(\App\Repositories\Contracts\UserRepositoryInterface::class, \App\Repositories\UserRepository::class);

        $this->app->bind(\App\Services\Contracts\EmployeeServiceInterface::class, \App\Services\EmployeeService::class);
        $this->app->bind(\App\Services\Contracts\DepartmentServiceInterface::class, \App\Services\DepartmentService::class);
        $this->app->bind(\App\Services\Contracts\SectionServiceInterface::class, \App\Services\SectionService::class);
        $this->app->bind(\App\Services\Contracts\AttendanceServiceInterface::class, \App\Services\AttendanceService::class);
        $this->app->bind(\App\Services\Contracts\LeaveServiceInterface::class, \App\Services\LeaveService::class);
        $this->app->bind(\App\Services\Contracts\UserServiceInterface::class, \App\Services\UserService::class);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}