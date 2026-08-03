<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Contracts\UserServiceInterface;

class UserService implements UserServiceInterface
{
    public function __construct(private UserRepositoryInterface $repository)
    {
    }

    public function list(array $filters = [])
    {
        return $this->repository->all($filters);
    }

    public function get(int $id): ?User
    {
        return $this->repository->find($id);
    }

    public function create(array $data): User
    {
        return $this->repository->create($data);
    }

    public function update(User $user, array $data): User
    {
        return $this->repository->update($user, $data);
    }

    public function delete(User $user): void
    {
        $this->repository->delete($user);
    }
}
