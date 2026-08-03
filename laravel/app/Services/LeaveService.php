<?php

namespace App\Services;

use App\Models\LeaveApplication;
use App\Repositories\Contracts\LeaveRepositoryInterface;
use App\Services\Contracts\LeaveServiceInterface;

class LeaveService implements LeaveServiceInterface
{
    public function __construct(private LeaveRepositoryInterface $repository)
    {
    }

    public function list(array $filters = [])
    {
        return $this->repository->all($filters);
    }

    public function get(int $id): ?LeaveApplication
    {
        return $this->repository->find($id);
    }

    public function create(array $data): LeaveApplication
    {
        return $this->repository->create($data);
    }

    public function update(LeaveApplication $leaveApplication, array $data): LeaveApplication
    {
        return $this->repository->update($leaveApplication, $data);
    }

    public function delete(LeaveApplication $leaveApplication): void
    {
        $this->repository->delete($leaveApplication);
    }
}
