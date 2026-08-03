<?php

namespace App\Repositories;

use App\Models\Attendance;
use App\Repositories\Contracts\AttendanceRepositoryInterface;

class AttendanceRepository implements AttendanceRepositoryInterface
{
    public function all(array $filters = [])
    {
        $query = Attendance::with(['employee']);

        if (!empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (!empty($filters['date'])) {
            $query->where('attendance_date', $filters['date']);
        }

        if (!empty($filters['from_date']) && !empty($filters['to_date'])) {
            $query->whereBetween('attendance_date', [$filters['from_date'], $filters['to_date']]);
        }

        return $query->orderBy('attendance_date', 'desc')->paginate(15);
    }

    public function find(int $id): ?Attendance
    {
        return Attendance::with('employee')->find($id);
    }

    public function create(array $data): Attendance
    {
        return Attendance::create($data);
    }

    public function update(Attendance $attendance, array $data): Attendance
    {
        $attendance->update($data);
        return $attendance;
    }

    public function delete(Attendance $attendance): void
    {
        $attendance->delete();
    }
}
