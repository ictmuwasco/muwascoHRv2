<?php

namespace App\Services\Contracts;

use App\Models\LeaveApplication;

interface LeaveServiceInterface
{
    public function list(array $filters = []);

    public function get(int $id): ?LeaveApplication;

    public function create(array $data): LeaveApplication;

    public function update(LeaveApplication $leaveApplication, array $data): LeaveApplication;

    public function delete(LeaveApplication $leaveApplication): void;
}
