<?php

namespace App\Services\Contracts;

use App\Models\Section;

interface SectionServiceInterface
{
    public function list(array $filters = []);

    public function get(int $id): ?Section;

    public function create(array $data): Section;

    public function update(Section $section, array $data): Section;

    public function delete(Section $section): void;
}
