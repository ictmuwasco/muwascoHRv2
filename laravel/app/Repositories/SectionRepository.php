<?php

namespace App\Repositories;

use App\Models\Section;
use App\Repositories\Contracts\SectionRepositoryInterface;

class SectionRepository implements SectionRepositoryInterface
{
    public function all(array $filters = [])
    {
        $query = Section::with(['department', 'head']);

        if (!empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (!empty($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query->get();
    }

    public function find(int $id): ?Section
    {
        return Section::with(['department', 'head', 'employees'])->find($id);
    }

    public function create(array $data): Section
    {
        return Section::create($data);
    }

    public function update(Section $section, array $data): Section
    {
        $section->update($data);
        return $section;
    }

    public function delete(Section $section): void
    {
        $section->delete();
    }
}
