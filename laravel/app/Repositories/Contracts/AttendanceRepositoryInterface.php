<?php

namespace App\Repositories\Contracts;

use App\Models\Attendance;

interface AttendanceRepositoryInterface
{
    public function all(array $filters = []);

    public function find(int $id): ?Attendance;

    public function create(array $data): Attendance;

    public function update(Attendance $attendance, array $data): Attendance;

    public function delete(Attendance $attendance): void;
}
