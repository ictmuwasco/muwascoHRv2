<?php

namespace App\Repositories\Contracts;

use App\Models\LeaveApplication;

interface LeaveRepositoryInterface
{
    public function all(array $filters = []);

    public function find(int $id): ?LeaveApplication;

    public function create(array $data): LeaveApplication;

    public function update(LeaveApplication $leaveApplication, array $data): LeaveApplication;

    public function delete(LeaveApplication $leaveApplication): void;
}
