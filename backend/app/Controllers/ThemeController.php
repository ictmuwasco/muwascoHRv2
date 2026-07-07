<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\BaseController;

/**
 * ThemeController
 *
 * Handles theme preference updates.
 * Place: backend/app/Controllers/ThemeController.php
 */
class ThemeController extends BaseController
{
    /**
     * Update user theme preference
     * POST /api/theme
     */
    public function updateAction(): void
    {
        // Only accept POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['error' => 'Method not allowed'], 405);
            return;
        }

        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        $theme = $input['theme'] ?? null;

        // Validate theme
        if (!in_array($theme, ['light', 'dark'], true)) {
            $this->json(['error' => 'Invalid theme. Must be "light" or "dark"'], 400);
            return;
        }

        // Update session
        $_SESSION['theme'] = $theme;

        // Return success response
        $this->json([
            'success' => true,
            'theme' => $theme,
            'message' => 'Theme updated successfully'
        ]);
    }
}