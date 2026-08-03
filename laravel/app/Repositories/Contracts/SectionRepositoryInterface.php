<?php

namespace App\Repositories\Contracts;

use App\Models\Section;

interface SectionRepositoryInterface
{
    public function all(array $filters = []);

    public function find(int $id): ?Section;

    public function create(array $data): Section;

    public function update(Section $section, array $data): Section;

    public function delete(Section $section): void;
}
