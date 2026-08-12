<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Contracts\DepartmentServiceInterface;
use App\Services\DepartmentService;

/**
 * Section Controller - REST API for section management.
 * 
 * Thin controller that handles HTTP request/response only.
 * All business logic is delegated to DepartmentService.
 */
class SectionController extends BaseController
{
    private DepartmentServiceInterface $departmentService;

    public function __construct()
    {
        $this->departmentService = new DepartmentService();
        
        // Set repository dependencies
        $this->departmentService->setDepartmentRepository(new \App\Repositories\DepartmentRepository());
        $this->departmentService->setSectionRepository(new \App\Repositories\SectionRepository());
    }

    /**
     * GET /api/sections - List all sections.
     */
    public function indexAction(): void
    {
        try {
            $this->requirePermission('departments', 'view');
        } catch (\Exception $e) {
            \logger()->warning('Sections permission check failed', ['error' => $e->getMessage()]);
        }

        try {
            // Check if filtering by department_id
            $departmentId = $_GET['department_id'] ?? null;
            
            if ($departmentId) {
                $sections = $this->departmentService->getSections((int)$departmentId);
            } else {
                $sections = $this->departmentService->getAllSections();
            }
            
            // Enrich sections with department names
            $departmentRepo = new \App\Repositories\DepartmentRepository();
            $allDepts = array_column($departmentRepo->findAll(), null, 'id');
            
            foreach ($sections as &$section) {
                $deptId = (int)$section['department_id'];
                $dept = $allDepts[$deptId] ?? null;
                $section['department_name'] = $dept ? $dept['name'] : 'Unknown';
            }
            
            \logger()->info('Sections enriched', ['count' => count($sections), 'sample' => $sections[0] ?? null]);
            
            $this->success($sections);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Section listing error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve sections. Please try again.', 500);
        }
    }

    /**
     * GET /api/sections/{id} - Get a single section with subsections.
     */
    public function showAction(int $id): void
    {
        $this->requirePermission('departments', 'view');

        try {
            $section = $this->departmentService->getSectionById($id);
            if (!$section) {
                $this->notFound('Section not found');
            }

            $this->success($section);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Section retrieval error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to retrieve section. Please try again.', 500);
        }
    }

    /**
     * POST /api/sections - Create a new section.
     */
    public function storeAction(): void
    {
        $this->requirePermission('departments', 'create');

        $data = $this->getJsonBody();

        try {
            $sectionId = $this->departmentService->createSection($data);
            $this->success(['id' => $sectionId], 'Section created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            \logger()->warning('Section validation error', ['error' => $e->getMessage(), 'data' => $data]);
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Section creation error', ['error' => $e->getMessage(), 'data' => $data, 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to create section: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/sections/{id} - Update an existing section.
     */
    public function updateAction(int $id): void
    {
        $this->requirePermission('departments', 'edit');

        $data = $this->getJsonBody();

        try {
            $result = $this->departmentService->updateSection($id, $data);
            $this->success($result, 'Section updated successfully');
        } catch (\InvalidArgumentException $e) {
            \logger()->warning('Section validation error', ['error' => $e->getMessage(), 'id' => $id, 'data' => $data]);
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Section update error', ['error' => $e->getMessage(), 'id' => $id, 'data' => $data, 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to update section: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/sections/{id} - Delete an existing section.
     */
    public function destroyAction(int $id): void
    {
        $this->requirePermission('departments', 'delete');

        try {
            $result = $this->departmentService->deleteSection($id);
            $this->success($result, 'Section deleted successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Section deletion error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to delete section. Please try again.', 500);
        }
    }
}