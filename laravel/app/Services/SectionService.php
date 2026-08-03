<?php

namespace App\Services;

use App\Models\Section;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Services\Contracts\SectionServiceInterface;

class SectionService implements SectionServiceInterface
{
    public function __construct(private SectionRepositoryInterface $repository)
    {
    }

    public function list(array $filters = [])
    {
        return $this->repository->all($filters);
    }

    public function get(int $id): ?Section
    {
        return $this->repository->find($id);
    }

    public function create(array $data): Section
    {
        return $this->repository->create($data);
    }

    public function update(Section $section, array $data): Section
    {
        return $this->repository->update($section, $data);
    }

    public function delete(Section $section): void
    {
        $this->repository->delete($section);
    }
}
