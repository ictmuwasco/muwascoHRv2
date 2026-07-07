<?php
/**
 * Employee Appraisal View
 * Place: backend/app/Views/appraisal/employee_appraisal.php
 */
$pageTitle = 'My Performance Appraisals - HR Management System';
include __DIR__ . '/../components/header_bar.php';
include __DIR__ . '/../components/navbar.php';

// Helper functions
function getActivityDetails($conn, $idsString) {
    if (empty($idsString)) return [];
    $ids = array_filter(array_map('intval', explode(',', $idsString)));
    if (empty($ids)) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT wo.id, wo.objective as name, pc.name as contract_name, d.name as department_name 
            FROM workplan_objectives wo
            LEFT JOIN performance_contracts pc ON wo.performance_contract_id = pc.id
            LEFT JOIN departments d ON pc.department_id = d.id
            WHERE wo.id IN ($placeholders)
            ORDER BY wo.objective";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();
    $activities = [];
    while ($row = $result->fetch_assoc()) {
        $activities[] = $row;
    }
    $stmt->close();
    return $activities;
}

function getActivityNamesForDisplay($conn, $idsString, $limit = 2) {
    if (empty($idsString)) return '—';
    $ids = array_filter(array_map('intval', explode(',', $idsString)));
    if (empty($ids)) return '—';
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT wo.id, wo.objective as name, pc.name as contract_name 
            FROM workplan_objectives wo
            LEFT JOIN performance_contracts pc ON wo.performance_contract_id = pc.id
            WHERE wo.id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();
    $names = [];
    while ($row = $result->fetch_assoc()) {
        $names[] = $row['name'] . ($row['contract_name'] ? " ({$row['contract_name']})" : '');
    }
    $stmt->close();
    $total = count($names);
    if ($total <= $limit) {
        return implode(', ', $names);
    } else {
        $display = implode(', ', array_slice($names, 0, $limit));
        return $display . ', +' . ($total - $limit) . ' more';
    }
}

function getDepartmentHead($department_id, $conn) {
    $deptHeadQuery = $conn->prepare("
        SELECT e.id, e.first_name, e.last_name, e.email, e.employee_type
        FROM employees e
        WHERE e.department_id = ?
        AND e.employee_type IN ('dept_head', 'head_of_department', 'department_head', 'manager', 'head')
        AND e.employee_status = 'active'
        LIMIT 1
    ");
    $deptHeadQuery->bind_param("i", $department_id);
    $deptHeadQuery->execute();
    $deptHeadResult = $deptHeadQuery->get_result();
    
    if ($deptHeadResult->num_rows > 0) {
        return $deptHeadResult->fetch_assoc();
    }
    
    $fallbackQuery = $conn->prepare("
        SELECT e.id, e.first_name, e.last_name, e.email, e.employee_type
        FROM employees e
        WHERE e.department_id = ?
        AND e.employee_status = 'active'
        ORDER BY 
            CASE e.employee_type
                WHEN 'managing_director' THEN 1
                WHEN 'director' THEN 2
                WHEN 'dept_head' THEN 3
                WHEN 'head_of_department' THEN 4
                WHEN 'department_head' THEN 5
                WHEN 'manager' THEN 6
                WHEN 'head' THEN 7
                WHEN 'senior' THEN 8
                ELSE 9
            END,
            e.hire_date ASC
        LIMIT 1
    ");
    $fallbackQuery->bind_param("i", $department_id);
    $fallbackQuery->execute();
    $fallbackResult = $fallbackQuery->get_result();
    
    if ($fallbackResult->num_rows > 0) {
        return $fallbackResult->fetch_assoc();
    }
    
    return null;
}

function getManagingDirector($conn) {
    $mdQuery = $conn->prepare("
        SELECT e.id, e.first_name, e.last_name, e.email, e.employee_type
        FROM employees e
        WHERE e.employee_type IN ('managing_director', 'director', 'md')
        AND e.employee_status = 'active'
        LIMIT 1
    ");
    $mdQuery->execute();
    $mdResult = $mdQuery->get_result();
    
    if ($mdResult->num_rows > 0) {
        return $mdResult->fetch_assoc();
    }
    
    $fallbackQuery = $conn->prepare("
        SELECT e.id, e.first_name, e.last_name, e.email, e.employee_type
        FROM employees e
        WHERE e.employee_status = 'active'
        ORDER BY 
            CASE e.employee_type
                WHEN 'managing_director' THEN 1
                WHEN 'director' THEN 2
                WHEN 'dept_head' THEN 3
                WHEN 'head_of_department' THEN 4
                WHEN 'department_head' THEN 5
                WHEN 'manager' THEN 6
                WHEN 'head' THEN 7
                WHEN 'senior' THEN 8
                ELSE 9
            END,
            e.hire_date ASC
        LIMIT 1
    ");
    $fallbackQuery->execute();
    $fallbackResult = $fallbackQuery->get_result();
    
    if ($fallbackResult->num_rows > 0) {
        return $fallbackResult->fetch_assoc();
    }
    
    return null;
}

function getEscalationRecipient($employee_id, $conn) {
    $employeeQuery = $conn->prepare("
        SELECT e.id, e.employee_type, e.department_id, e.section_id, e.subsection_id,
               d.name as department_name,
               s.name as section_name,
               ss.name as subsection_name
        FROM employees e
        LEFT JOIN departments d ON e.department_id = d.id
        LEFT JOIN sections s ON e.section_id = s.id
        LEFT JOIN subsections ss ON e.subsection_id = ss.id
        WHERE e.id = ?
    ");
    $employeeQuery->bind_param("i", $employee_id);
    $employeeQuery->execute();
    $employeeResult = $employeeQuery->get_result();
    
    if ($employeeResult->num_rows > 0) {
        $employee = $employeeResult->fetch_assoc();
        $employee_type = strtolower($employee['employee_type'] ?? '');
        
        if (strpos($employee_type, 'section') !== false || 
            in_array($employee_type, ['section_head', 'section_head', 'section_manager'])) {
            return [
                'recipient' => getManagingDirector($conn),
                'escalation_level' => 'managing_director',
                'reason' => 'Section Head Escalation'
            ];
        }
        elseif (strpos($employee_type, 'dept') !== false || 
                strpos($employee_type, 'department') !== false ||
                in_array($employee_type, ['dept_head', 'department_head', 'head_of_department'])) {
            return [
                'recipient' => getManagingDirector($conn),
                'escalation_level' => 'managing_director',
                'reason' => 'Department Head Escalation'
            ];
        }
        elseif (strpos($employee_type, 'subsection') !== false || 
                in_array($employee_type, ['subsection_head', 'sub_section_head'])) {
            return [
                'recipient' => getDepartmentHead($employee['department_id'], $conn),
                'escalation_level' => 'department_head',
                'reason' => 'Subsection Head Escalation'
            ];
        }
        else {
            return [
                'recipient' => getDepartmentHead($employee['department_id'], $conn),
                'escalation_level' => 'department_head',
                'reason' => 'Regular Employee Escalation'
            ];
        }
    }
    
    return [
        'recipient' => null,
        'escalation_level' => 'hr',
        'reason' => 'Employee Not Found - Escalated to HR'
    ];
}

$conn = \App\Helpers\Database::getInstance()->getConnection();
$user = [
    'first_name' => isset($_SESSION['user_name']) ? explode(' ', $_SESSION['user_name'])[0] : 'User',
    'last_name' => isset($_SESSION['user_name']) ? (explode(' ', $_SESSION['user_name'])[1] ?? '') : '',
    'role' => $_SESSION['user_role'] ?? 'guest',
    'id' => $_SESSION['user_id'],
    'employee_id' => $_SESSION['employee_id'] ?? null
];
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mt-6">
        <h1 class="text-3xl font-bold text-white mb-2">
            <i class="fas fa-user-check text-primary-400 mr-2"></i>My Performance Appraisals
        </h1>
        <p class="text-gray-400 mb-6">View and provide feedback on your performance appraisals</p>

        <!-- Escalation Hierarchy Notice -->
        <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-6 mb-6">
            <h3 class="text-lg font-semibold text-white mb-3">⚠️ Appraisal Escalation Hierarchy</h3>
            <p class="text-gray-300 mb-2">When an employee indicates they are not satisfied with an appraisal, it will be escalated according to their position:</p>
            <ul class="text-gray-300 space-y-1">
                <li><strong>Regular Employees & Subsection Heads</strong> → <span class="badge badge-warning">Department Head</span></li>
                <li><strong>Section Heads & Department Heads</strong> → <span class="badge badge-danger">Managing Director</span></li>
            </ul>
        </div>

        <?php if (!empty($appraisals)): ?>
            <?php foreach ($appraisals as $appraisal): 
                $employee_scores = $scores_by_appraisal[$appraisal['id']] ?? [];
                $has_scores = !empty($employee_scores);
                
                // Get KPIs assigned to this employee
                $indicatorsQuery = "
                    SELECT pi.*, 
                           d.name as department_name,
                           s.name as section_name,
                           ss.name as subsection_name
                    FROM performance_indicators pi
                    LEFT JOIN departments d ON pi.department_id = d.id
                    LEFT JOIN sections s ON pi.section_id = s.id
                    LEFT JOIN subsections ss ON pi.subsection_id = ss.id
                    WHERE pi.is_active = 1
                    AND FIND_IN_SET(?, pi.assigned_to_employee_ids) > 0
                    ORDER BY pi.name
                ";
                $indicatorsStmt = $conn->prepare($indicatorsQuery);
                $indicatorsStmt->bind_param("i", $currentEmployee['id']);
                $indicatorsStmt->execute();
                $assigned_indicators = $indicatorsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $indicatorsStmt->close();
                
                // Calculate total score and total set score
                $total_score = 0;
                $total_set_score = 0;
                
                // Expand indicators by their linked activities
                $expanded_indicators = [];
                foreach ($assigned_indicators as $indicator) {
                    $activity_details = getActivityDetails($conn, $indicator['activity_ids'] ?? '');
                    if (!empty($activity_details)) {
                        foreach ($activity_details as $activity) {
                            $expanded_indicators[] = [
                                'indicator_id' => $indicator['id'],
                                'indicator_name' => $indicator['name'],
                                'activity_name' => $activity['name'],
                                'contract_name' => $activity['contract_name'] ?? '',
                                'max_score' => $indicator['max_score']
                            ];
                            $total_set_score += $indicator['max_score'];
                        }
                    } else {
                        $expanded_indicators[] = [
                            'indicator_id' => $indicator['id'],
                            'indicator_name' => $indicator['name'],
                            'activity_name' => '— No Activity Linked —',
                            'contract_name' => '',
                            'max_score' => $indicator['max_score']
                        ];
                        $total_set_score += $indicator['max_score'];
                    }
                }
                
                // Calculate total score from actual scores
                foreach ($expanded_indicators as $exp_ind) {
                    if (isset($employee_scores[$exp_ind['indicator_id']])) {
                        $total_score += $employee_scores[$exp_ind['indicator_id']]['score'];
                    }
                }
                
                $score_percentage = $total_set_score > 0 ? ($total_score / $total_set_score) * 100 : 0;
                
                $status_display = ucwords(str_replace('_', ' ', $appraisal['status']));
                
                $escalation_badge = '';
                if (isset($appraisal['escalation_level']) && $appraisal['escalation_level']) {
                    switch ($appraisal['escalation_level']) {
                        case 'managing_director':
                            $escalation_badge = '<span class="badge badge-danger">MD Level</span>';
                            break;
                        case 'department_head':
                            $escalation_badge = '<span class="badge badge-warning">Dept Head</span>';
                            break;
                        case 'hr':
                            $escalation_badge = '<span class="badge badge-secondary">HR Level</span>';
                            break;
                    }
                }
            ?>
                <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-6 mb-6">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <h3 class="text-xl font-semibold text-white"><?php echo htmlspecialchars($appraisal['cycle_name']); ?></h3>
                            <p class="text-gray-400 text-sm mt-1">
                                <strong>Period:</strong> <?php echo date('M d, Y', strtotime($appraisal['start_date'])); ?> - <?php echo date('M d, Y', strtotime($appraisal['end_date'])); ?><br>
                                <strong>Appraiser:</strong> <?php echo htmlspecialchars($appraisal['appraiser_first_name'] . ' ' . $appraisal['appraiser_last_name']); ?>
                            </p>
                        </div>
                        <div>
                            <?php if ($appraisal['status'] === 'under_review'): ?>
                                <span class="badge badge-warning">Under Review <?php echo $escalation_badge; ?></span>
                            <?php else: ?>
                                <span class="badge badge-info"><?php echo $status_display; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($appraisal['status'] === 'under_review' && isset($appraisal['employee_satisfied']) && $appraisal['employee_satisfied'] == 0): ?>
                        <div class="bg-error/20 border border-error rounded-lg p-4 mb-4">
                            <h5 class="text-error font-semibold mb-2">⚠️ Appraisal Under Review</h5>
                            <p class="text-gray-300">Your appraisal has been escalated for review because you indicated you are not satisfied with this assessment.</p>
                            
                            <?php if (isset($appraisal['escalation_level']) && $appraisal['escalation_level']): ?>
                                <div class="bg-info/20 border border-info rounded-lg p-3 mt-3">
                                    <strong>Escalation Level:</strong> <?php echo strtoupper($appraisal['escalation_level']); ?>
                                    <br>
                                    <?php if ($appraisal['escalation_level'] === 'managing_director'): ?>
                                        <small>As a <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $currentEmployee['employee_type']))); ?>, your appraisal has been escalated to the Managing Director level.</small>
                                    <?php elseif ($appraisal['escalation_level'] === 'department_head'): ?>
                                        <small>Your appraisal has been escalated to the Department Head for review.</small>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <p class="mt-3"><strong>Status:</strong> Awaiting review decision</p>
                            <?php if (isset($appraisal['dept_head_decision']) && $appraisal['dept_head_decision']): ?>
                                <div class="bg-warning/20 border border-warning rounded-lg p-3 mt-3">
                                    <strong>Review Decision:</strong> <?php echo htmlspecialchars($appraisal['dept_head_decision']); ?>
                                    <br>
                                    <small>Decided on <?php echo isset($appraisal['dept_head_decision_date']) ? date('M d, Y', strtotime($appraisal['dept_head_decision_date'])) : ''; ?></small>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($has_scores && !empty($expanded_indicators)): ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead>
                                    <tr class="border-b border-white/20">
                                        <th class="py-2 px-3 text-gray-300 text-sm">Activity</th>
                                        <th class="py-2 px-3 text-gray-300 text-sm">Performance Indicator</th>
                                        <th class="py-2 px-3 text-gray-300 text-sm text-center">Set Score</th>
                                        <th class="py-2 px-3 text-gray-300 text-sm text-center">Score</th>
                                        <th class="py-2 px-3 text-gray-300 text-sm">Appraiser Comment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($expanded_indicators as $exp_ind): 
                                        $score_data = $employee_scores[$exp_ind['indicator_id']] ?? null;
                                        $score_class = '';
                                        if ($score_data && $score_data['score'] > 0) {
                                            $score_percent = ($score_data['score'] / $exp_ind['max_score']) * 100;
                                            if ($score_percent < 50) {
                                                $score_class = 'text-error';
                                            } elseif ($score_percent < 75) {
                                                $score_class = 'text-warning';
                                            } else {
                                                $score_class = 'text-success';
                                            }
                                        }
                                    ?>
                                        <tr class="border-b border-white/10">
                                            <td class="py-2 px-3 text-gray-300 text-sm">
                                                <?php echo htmlspecialchars($exp_ind['activity_name']); ?>
                                                <?php if (!empty($exp_ind['contract_name'])): ?>
                                                    <div><small class="text-gray-500">(<?php echo htmlspecialchars($exp_ind['contract_name']); ?>)</small></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-2 px-3 text-gray-300 text-sm"><?php echo htmlspecialchars($exp_ind['indicator_name']); ?></td>
                                            <td class="py-2 px-3 text-gray-300 text-sm text-center"><?php echo htmlspecialchars($exp_ind['max_score']); ?></td>
                                            <td class="py-2 px-3 text-sm text-center">
                                                <?php if ($score_data): ?>
                                                    <span class="font-bold <?php echo $score_class; ?>"><?php echo number_format($score_data['score'], 1); ?></span>
                                                <?php else: ?>
                                                    <span class="text-gray-500">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-2 px-3 text-gray-300 text-sm">
                                                <?php if ($score_data && $score_data['appraiser_comment']): ?>
                                                    <?php echo nl2br(htmlspecialchars($score_data['appraiser_comment'])); ?>
                                                <?php else: ?>
                                                    <span class="text-gray-500">No comment</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t-2 border-white/20 font-bold">
                                        <td colspan="2" class="py-3 px-3 text-right text-white">TOTAL</td>
                                        <td class="py-3 px-3 text-center text-white"><?php echo number_format($total_set_score, 1); ?></td>
                                        <td class="py-3 px-3 text-center text-primary-400"><?php echo number_format($total_score, 1); ?> (<?php echo number_format($score_percentage, 1); ?>%)</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php elseif ($has_scores && empty($expanded_indicators)): ?>
                        <div class="bg-warning/20 border border-warning rounded-lg p-4">
                            <strong class="text-warning">No performance indicators assigned!</strong><br>
                            You have not been assigned any KPIs for this appraisal cycle. Please contact your supervisor or HR.
                        </div>
                    <?php else: ?>
                        <div class="bg-info/20 border border-info rounded-lg p-4">
                            This appraisal is still in progress. Your appraiser has not yet completed the scoring.
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($appraisal['supervisors_comment'])): ?>
                        <div class="mt-4 p-4 bg-white/5 rounded-lg">
                            <h5 class="text-white font-semibold mb-2">Supervisor Comment</h5>
                            <div class="text-gray-300 text-sm"><?php echo nl2br(htmlspecialchars($appraisal['supervisors_comment'])); ?></div>
                            <?php if (!empty($appraisal['supervisors_comment_date'])): ?>
                                <small class="text-gray-500 mt-2 block">Added on <?php echo date('M d, Y H:i', strtotime($appraisal['supervisors_comment_date'])); ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="mt-4 p-4 bg-white/5 rounded-lg">
                        <h5 class="text-white font-semibold mb-3">Your Feedback</h5>
                        
                        <?php if ($appraisal['employee_comment']): ?>
                            <?php if (!is_null($appraisal['employee_satisfied'])): ?>
                                <div class="mb-3">
                                    <strong class="text-gray-300">Satisfaction Status:</strong> 
                                    <?php echo $appraisal['employee_satisfied'] ? 
                                        '<span class="text-success font-bold">Satisfied</span>' : 
                                        '<span class="text-error font-bold">Not Satisfied</span>'; ?>
                                    <?php if ($appraisal['employee_satisfied'] == 0): ?>
                                        <div class="bg-warning/20 border border-warning rounded-lg p-3 mt-2">
                                            <strong>Note:</strong> By selecting "Not Satisfied", your appraisal was escalated according to company hierarchy.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div>
                                <label class="text-gray-300">Your Comment:</label>
                                <div class="text-gray-300 text-sm mt-1"><?php echo nl2br(htmlspecialchars($appraisal['employee_comment'])); ?></div>
                                <small class="text-gray-500 mt-2 block">Added on <?php echo date('M d, Y H:i', strtotime($appraisal['employee_comment_date'])); ?></small>
                            </div>
                            
                        <?php elseif ($appraisal['status'] === 'awaiting_employee' && $has_scores): ?>
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="add_comment">
                                <input type="hidden" name="appraisal_id" value="<?php echo $appraisal['id']; ?>
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                
                                <div class="bg-warning/20 border border-warning rounded-lg p-3 mb-4">
                                    <strong>Important:</strong> If you select "Not Satisfied", your appraisal will be escalated according to company policy.
                                </div>
                                
                                <div class="mb-4">
                                    <label class="text-gray-300 block mb-2">Are you satisfied with this appraisal? <span class="text-error">*</span></label>
                                    <div class="space-y-2">
                                        <div class="flex items-center p-3 border border-white/20 rounded-lg bg-white/5">
                                            <input type="radio" id="satisfied_yes_<?php echo $appraisal['id']; ?>" 
                                                   name="employee_satisfied" value="1" required checked
                                                   class="mr-2">
                                            <label for="satisfied_yes_<?php echo $appraisal['id']; ?>" class="text-success font-semibold cursor-pointer">Satisfied</label>
                                            <small class="text-gray-400 ml-2">- Appraisal will proceed normally</small>
                                        </div>
                                        <div class="flex items-center p-3 border border-white/20 rounded-lg bg-white/5">
                                            <input type="radio" id="satisfied_no_<?php echo $appraisal['id']; ?>" 
                                                   name="employee_satisfied" value="0" required
                                                   class="mr-2">
                                            <label for="satisfied_no_<?php echo $appraisal['id']; ?>" class="text-error font-semibold cursor-pointer">Not Satisfied</label>
                                            <small class="text-gray-400 ml-2">- Will be escalated to Department Head</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="employee_comment_<?php echo $appraisal['id']; ?>" class="text-gray-300 block mb-2">Your Comment: <span class="text-error">*</span></label>
                                    <textarea name="employee_comment" 
                                              id="employee_comment_<?php echo $appraisal['id']; ?>" 
                                              class="w-full px-4 py-2 bg-white/5 border border-white/20 rounded-lg text-white text-sm"
                                              placeholder="Share your thoughts on the appraisal, any achievements you'd like to highlight, areas for development, or specific concerns about the assessment..."
                                              required rows="4"></textarea>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">Submit Feedback</button>
                            </form>
                        <?php else: ?>
                            <div class="bg-info/20 border border-info rounded-lg p-4">
                                You will be able to add your feedback once your appraiser completes the scoring.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="bg-white/10 backdrop-blur-xl border border-white/20 rounded-2xl p-12 text-center">
                <div class="text-6xl mb-4">📋</div>
                <h3 class="text-xl font-semibold text-white mb-2">No Active Appraisals</h3>
                <p class="text-gray-400">You don't have any appraisals in draft or awaiting your input at the moment. Your supervisor will create appraisals during review periods.</p>
                <a href="/dashboard" class="btn btn-primary mt-4 inline-block">Return to Dashboard</a>
            </div>
        <?php endif; ?>
    </div>
</div>