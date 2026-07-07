<?php

declare(strict_types=1);

namespace App\Controllers\Appraisal;

use App\Core\Controller;
use App\Services\AuditService;

class CompletedAppraisalController extends Controller
{
    private AuditService $audit;

    public function __construct()
    {
        parent::__construct();
        $this->audit = AuditService::getInstance();
    }

    public function indexAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
            return;
        }

        if (!function_exists('hasPermission')) {
            require_once dirname(__DIR__, 3) . '/auth.php';
        }

        $conn = $this->getDbConnection();
        $this->audit->logPageView();

        $this->view('appraisal/completed_appraisals', [
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }
}