<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\HolidayRepository;

/**
 * Holiday Service
 */
class HolidayService
{
    private HolidayRepository $holidayRepository;

    public function __construct()
    {
        $this->holidayRepository = new HolidayRepository();
    }

    public function getAllHolidays(): array
    {
        return $this->holidayRepository->findAll();
    }

    public function getUpcomingHolidays(int $limit = 10): array
    {
        return $this->holidayRepository->getUpcoming($limit);
    }

    public function getHolidayById(int $id): ?array
    {
        return $this->holidayRepository->findById($id);
    }

    public function getHolidaysByMonth(int $year, int $month): array
    {
        return $this->holidayRepository->getByMonth($year, $month);
    }

    public function createHoliday(array $data): int
    {
        $errors = $this->validateHolidayData($data);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        return $this->holidayRepository->create([
            'name' => trim(strip_tags((string)($data['name'] ?? ''))),
            'date' => $data['date'] ?? '',
            'description' => trim(strip_tags((string)($data['description'] ?? ''))),
            'is_recurring' => isset($data['is_recurring']) && $data['is_recurring'] ? 1 : 0,
        ]);
    }

    public function updateHoliday(int $id, array $data): bool
    {
        $errors = $this->validateHolidayData($data, $id);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(implode(', ', $errors));
        }

        $updateData = [];
        if (isset($data['name'])) {
            $updateData['name'] = trim(strip_tags((string)$data['name']));
        }
        if (isset($data['date'])) {
            $updateData['date'] = $data['date'];
        }
        if (isset($data['description'])) {
            $updateData['description'] = trim(strip_tags((string)$data['description']));
        }
        if (isset($data['is_recurring'])) {
            $updateData['is_recurring'] = $data['is_recurring'] ? 1 : 0;
        }

        return $this->holidayRepository->update($id, $updateData);
    }

    public function deleteHoliday(int $id): bool
    {
        return $this->holidayRepository->delete($id);
    }

    private function validateHolidayData(array $data, ?int $excludeId = null): array
    {
        $errors = [];

        if (empty($data['name'])) {
            $errors[] = 'Holiday name is required';
        }

        if (empty($data['date'])) {
            $errors[] = 'Holiday date is required';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$data['date'])) {
            $errors[] = 'Invalid date format. Use YYYY-MM-DD';
        }

        return $errors;
    }
}