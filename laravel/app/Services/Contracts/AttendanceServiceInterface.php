<?php

namespace App\Services\Contracts;

use App\Models\Attendance;

interface AttendanceServiceInterface
{
    public function list(array $filters = []);

    public function get(int $id): ?Attendance;

    public function create(array $data): Attendance;

    public function update(Attendance $attendance, array $data): Attendance;

    public function delete(Attendance $attendance): void;
}
