<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Contracts\DepartmentServiceInterface;
use App\Services\DepartmentService;

/**
 * Subsection Controller - REST API for subsection management.
 * 
 * Thin controller that handles HTTP request/response only.
 * All business logic is delegated to DepartmentService.
 */
class SubsectionController extends BaseController
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
     * GET /api/subsections - List all subsections.
     */
    public function indexAction(): void
    {
        try {
            $this->requirePermission('departments', 'view');
        } catch (\Exception $e) {
            \logger()->warning('Subsections permission check failed', ['error' => $e->getMessage()]);
        }

        try {
            $subsections = $this->departmentService->getAllSubsections();
            
            // Enrich subsections with section names
            $sectionRepo = new \App\Repositories\SectionRepository();
            $allSections = array_column($sectionRepo->findAll(), null, 'id');
            
            foreach ($subsections as &$subsection) {
                $sectionId = (int)$subsection['section_id'];
                $section = $allSections[$sectionId] ?? null;
                $subsection['section_name'] = $section ? $section['name'] : 'Unknown';
            }
            
            // Log for debugging
            \logger()->info('Subsections enriched', ['count' => count($subsections), 'sample' => $subsections[0] ?? null]);
            
            $this->success($subsections);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Subsection listing error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve subsections. Please try again.', 500);
        }
    }

    /**
     * GET /api/subsections/{id} - Get a single subsection.
     */
    public function showAction(int $id): void
    {
        $this->requirePermission('departments', 'view');

        try {
            $subsection = $this->departmentService->getSubsectionById($id);
            if (!$subsection) {
                $this->notFound('Subsection not found');
            }

            $this->success($subsection);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Subsection retrieval error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to retrieve subsection. Please try again.', 500);
        }
    }

    /**
     * POST /api/subsections - Create a new subsection.
     */
    public function storeAction(): void
    {
        $this->requirePermission('departments', 'create');

        $data = $this->getJsonBody();

        try {
            $subsectionId = $this->departmentService->createSubsection($data);
            $this->success(['id' => $subsectionId], 'Subsection created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            \logger()->warning('Subsection validation error', ['error' => $e->getMessage(), 'data' => $data]);
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Subsection creation error', ['error' => $e->getMessage(), 'data' => $data, 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to create subsection: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/subsections/{id} - Update an existing subsection.
     */
    public function updateAction(int $id): void
    {
        $this->requirePermission('departments', 'edit');

        $data = $this->getJsonBody();

        try {
            $result = $this->departmentService->updateSubsection($id, $data);
            $this->success($result, 'Subsection updated successfully');
        } catch (\InvalidArgumentException $e) {
            \logger()->warning('Subsection validation error', ['error' => $e->getMessage(), 'id' => $id, 'data' => $data]);
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Subsection update error', ['error' => $e->getMessage(), 'id' => $id, 'data' => $data, 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to update subsection: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/subsections/{id} - Delete an existing subsection.
     */
    public function destroyAction(int $id): void
    {
        $this->requirePermission('departments', 'delete');

        try {
            $result = $this->departmentService->deleteSubsection($id);
            $this->success($result, 'Subsection deleted successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Subsection deletion error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to delete subsection. Please try again.', 500);
        }
    }
}