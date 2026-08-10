<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\HolidayService;

/**
 * Holiday Controller - REST API for holiday management.
 */
class HolidayController extends BaseController
{
    private HolidayService $holidayService;

    public function __construct()
    {
        $this->holidayService = new HolidayService();
    }

    /**
     * GET /api/holidays - List all holidays.
     */
    public function indexAction(): void
    {
        try {
            $holidays = $this->holidayService->getAllHolidays();
            $this->success($holidays);
        } catch (\Exception $e) {
            \logger()->error('Holiday listing error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve holidays. Please try again.', 500);
        }
    }

    /**
     * GET /api/holidays/upcoming - Get upcoming holidays.
     */
    public function upcomingAction(): void
    {
        try {
            $limit = (int)($_GET['limit'] ?? 10);
            $holidays = $this->holidayService->getUpcomingHolidays($limit);
            $this->success($holidays);
        } catch (\Exception $e) {
            \logger()->error('Upcoming holidays error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve upcoming holidays. Please try again.', 500);
        }
    }

    /**
     * GET /api/holidays/{id} - Get a single holiday.
     */
    public function showAction(int $id): void
    {
        try {
            $holiday = $this->holidayService->getHolidayById($id);
            if (!$holiday) {
                $this->notFound('Holiday not found');
            }

            $this->success($holiday);
        } catch (\Exception $e) {
            \logger()->error('Holiday retrieval error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to retrieve holiday. Please try again.', 500);
        }
    }

    /**
     * POST /api/holidays - Create a new holiday.
     */
    public function storeAction(): void
    {
        $this->requirePermission('holidays', 'create');

        $data = $this->getJsonBody();

        try {
            $holidayId = $this->holidayService->createHoliday($data);
            $this->success(['id' => $holidayId], 'Holiday created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Holiday creation error', ['error' => $e->getMessage(), 'data' => $data]);
            $this->error('Failed to create holiday. Please try again.', 500);
        }
    }

    /**
     * PUT /api/holidays/{id} - Update an existing holiday.
     */
    public function updateAction(int $id): void
    {
        $this->requirePermission('holidays', 'edit');

        $data = $this->getJsonBody();

        try {
            $result = $this->holidayService->updateHoliday($id, $data);
            $this->success($result, 'Holiday updated successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Holiday update error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to update holiday. Please try again.', 500);
        }
    }

    /**
     * DELETE /api/holidays/{id} - Delete a holiday.
     */
    public function destroyAction(int $id): void
    {
        $this->requirePermission('holidays', 'delete');

        try {
            $result = $this->holidayService->deleteHoliday($id);
            $this->success($result, 'Holiday deleted successfully');
        } catch (\Exception $e) {
            \logger()->error('Holiday deletion error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to delete holiday. Please try again.', 500);
        }
    }
}