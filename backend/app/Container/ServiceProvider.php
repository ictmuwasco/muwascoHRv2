<?php

declare(strict_types=1);

namespace App\Container;

use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\DepartmentRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Repositories\Contracts\OfficeRepositoryInterface;
use App\Repositories\Contracts\PushSubscriptionRepositoryInterface;
use App\Repositories\UserRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\SectionRepository;
use App\Repositories\OfficeRepository;
use App\Repositories\PushSubscriptionRepository;
use App\Services\Contracts\AuthServiceInterface;
use App\Services\Contracts\EmployeeServiceInterface;
use App\Services\AuthService;
use App\Services\EmployeeService;
use App\Helpers\Database;
use App\Helpers\Hash;
use App\Helpers\Session;
use App\Database\DatabaseConnection;

class ServiceProvider
{
    private Container $container;

    public function __construct()
    {
        $this->container = Container::getInstance();
    }

    public function register(): void
    {
        $this->registerHelpers();
        $this->registerRepositories();
        $this->registerServices();
    }

    private function registerHelpers(): void
    {
        $this->container->singleton(Database::class, function () {
            return Database::getInstance();
        });

        $this->container->singleton(DatabaseConnection::class, function () {
            return DatabaseConnection::getInstance();
        });

        $this->container->singleton(Hash::class, function () {
            return Hash::getInstance();
        });

        $this->container->singleton(Session::class, function () {
            return Session::getInstance();
        });
    }

    private function registerRepositories(): void
    {
        $this->container->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->container->bind(EmployeeRepositoryInterface::class, EmployeeRepository::class);
        $this->container->bind(DepartmentRepositoryInterface::class, DepartmentRepository::class);
        $this->container->bind(SectionRepositoryInterface::class, SectionRepository::class);
        $this->container->bind(OfficeRepositoryInterface::class, OfficeRepository::class);
        $this->container->bind(PushSubscriptionRepositoryInterface::class, PushSubscriptionRepository::class);
    }

    private function registerServices(): void
    {
        $this->container->bind(AuthServiceInterface::class, AuthService::class);

        $this->container->bind(EmployeeServiceInterface::class, EmployeeService::class);

        $this->container->singleton(EmployeeService::class, function (Container $c) {
            $service = new EmployeeService();
            $service->setEmployeeRepository($c->get(EmployeeRepositoryInterface::class));
            $service->setDepartmentRepository($c->get(DepartmentRepositoryInterface::class));
            $service->setSectionRepository($c->get(SectionRepositoryInterface::class));
            $service->setOfficeRepository($c->get(OfficeRepositoryInterface::class));
            $service->setUserRepository($c->get(UserRepositoryInterface::class));
            return $service;
        });
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}
