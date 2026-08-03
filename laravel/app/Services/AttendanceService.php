<?php

namespace App\Services;

use App\Models\Attendance;
use App\Repositories\Contracts\AttendanceRepositoryInterface;
use App\Services\Contracts\AttendanceServiceInterface;

class AttendanceService implements AttendanceServiceInterface
{
    public function __construct(private AttendanceRepositoryInterface $repository)
    {
    }

    public function list(array $filters = [])
    {
        return $this->repository->all($filters);
    }

    public function get(int $id): ?Attendance
    {
        return $this->repository->find($id);
    }

    public function create(array $data): Attendance
    {
        return $this->repository->create($data);
    }

    public function update(Attendance $attendance, array $data): Attendance
    {
        return $this->repository->update($attendance, $data);
    }

    public function delete(Attendance $attendance): void
    {
        $this->repository->delete($attendance);
    }
}
