<?php

namespace App\Repositories;

use App\Models\LeaveApplication;
use App\Repositories\Contracts\LeaveRepositoryInterface;

class LeaveRepository implements LeaveRepositoryInterface
{
    public function all(array $filters = [])
    {
        $query = LeaveApplication::with(['employee', 'leaveType', 'appliedBy']);

        if (!empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['leave_type_id'])) {
            $query->where('leave_type_id', $filters['leave_type_id']);
        }

        return $query->orderBy('applied_at', 'desc')->paginate(15);
    }

    public function find(int $id): ?LeaveApplication
    {
        return LeaveApplication::with(['employee', 'leaveType', 'appliedBy', 'approver'])->find($id);
    }

    public function create(array $data): LeaveApplication
    {
        return LeaveApplication::create($data);
    }

    public function update(LeaveApplication $leaveApplication, array $data): LeaveApplication
    {
        $leaveApplication->update($data);
        return $leaveApplication;
    }

    public function delete(LeaveApplication $leaveApplication): void
    {
        $leaveApplication->delete();
    }
}
