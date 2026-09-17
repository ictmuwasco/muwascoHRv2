        // Copy the latest contract's start + end dates onto the `employees`
        // table so the public `/profile` and `/employees/{id}` endpoints
        // expose a single source of truth for the active contract.
        $this->employeeService->updateEmployeeContractDates(
            employeeId: $userId,
            contractStartDate: !empty($latestContract['start_date']) ? $latestContract['start_date'] : null,
            contractEndDate: !empty($latestContract['end_date']) ? $latestContract['end_date'] : null,
        );

        // Count the latest contract among ALL contracts for this user and
        // backfill the `total_contracts` field on the `employees` table if it
        // has drifted (e.g. older contracts that were created before the
        // `total_contracts` column existed).
        $this->employeeService->detectAndSyncContractCountForUser($userId);