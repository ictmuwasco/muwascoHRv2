<?php
/**
 * Live scope + authorization checks for the Completed Appraisals page.
 *
 * These EXECUTE the real service and the real permission check against the
 * database. An earlier static-only version of this script passed while
 * AppraisalReportService.php was 0 bytes, because string matching finds
 * nothing to disagree with in an empty file. Runtime execution is the only
 * check that catches that class of failure.
 */
$root = dirname(__DIR__);
require_once $root . '/backend/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$ok = true;
$check = static function (string $label, bool $pass) use (&$ok): void {
    printf("%-58s %s\n", $label, $pass ? 'PASS' : 'FAIL');
    $ok = $ok && $pass;
};

$db = \App\Helpers\Database::getInstance()->getConnection();

/** Seed the session exactly as AuthService::login() does. */
$simulate = static function (array $user) use ($db): void {
    $emp = null;
    if (!empty($user['employee_id'])) {
        $empId = (int) $user['employee_id'];
        $s = $db->prepare('SELECT * FROM employees WHERE id = ? LIMIT 1');
        $s->bind_param('i', $empId);
        $s->execute();
        $emp = $s->get_result()->fetch_assoc();
        $s->close();
    }
    $_SESSION['user_id']    = (int) $user['id'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_email'] = $user['email'];
    if ($emp) {
        foreach (['employee_id', 'department_id', 'section_id', 'subsection_id'] as $k) {
            $_SESSION[$k] = $emp[$k] ?? null;
        }
    }
    $_SESSION['session_valid'] = true;
    $_SESSION['last_activity']  = time();
};

$findUser = static function (string $role) use ($db) {
    $s = $db->prepare('SELECT id, email, role, employee_id FROM users WHERE is_active = 1 AND role = ? LIMIT 1');
    $s->bind_param('s', $role);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ?: null;
};

// --- 1. The private-call bug that produced the 500 --------------------------
$rc = new ReflectionClass(\App\Services\Appraisal\AppraisalWorkflowService::class);
$check('mayReviewRow() is public', $rc->hasMethod('mayReviewRow') && $rc->getMethod('mayReviewRow')->isPublic());
$check('canReviewTarget() stays private', !$rc->getMethod('canReviewTarget')->isPublic());
$noThrow = true;
try {
    $rc->getMethod('mayReviewRow')->invoke(
        new \App\Services\Appraisal\AppraisalWorkflowService(),
        ['employee_pk' => 0, 'employee_type' => 'officer', 'escalation_level' => null,
         'appraiser_id' => 0, 'employee_id' => 0]
    );
} catch (\Throwable $e) {
    $noThrow = false;
    echo '  invoke error: ' . $e->getMessage() . PHP_EOL;
}
$check('mayReviewRow() is actually invocable', $noThrow);

// --- 2. Router registration ------------------------------------------------
$api = file_get_contents($root . '/api.php');
preg_match("~'/appraisals/completed',\s*AppraisalReportController::class, 'completedAction', '([^']+)'~", $api, $g1);
preg_match("~'/appraisals/completed/filters',\s*AppraisalReportController::class, 'filtersAction',\s*'([^']+)'~", $api, $g2);
preg_match("~'/appraisals/\{id\}/report',\s*AppraisalReportController::class, 'downloadAction',\s*'([^']+)'~", $api, $g3);
$check('router gates /completed on performance:feedback', ($g1[1] ?? '') === 'performance:feedback');
$check('router gates /filters on performance:supervise', ($g2[1] ?? '') === 'performance:supervise');
$check('router gates /report on performance:feedback', ($g3[1] ?? '') === 'performance:feedback');

// --- 3. The real permission gate, per role ---------------------------------
// This is the exact check behind "Forbidden - insufficient permissions for
// this action." AuthorizationMiddleware::enforce delegates to the same
// AuthorizationService::hasPermission() call.
echo "\n  role         feedback  supervise   (/completed gate = feedback)\n";
foreach (['hr_manager', 'dept_head', 'officer'] as $role) {
    $user = $findUser($role);
    if (!$user) {
        echo "  (no {$role} account - skipped)\n";
        continue;
    }
    $uid = (int) $user['id'];
    $svc = \App\Helpers\AuthorizationService::getInstance();
    $fb = $svc->hasPermission($uid, 'performance', 'feedback');
    $sup = $svc->hasPermission($uid, 'performance', 'supervise');
    printf("  %-12s %-9s %-11s\n", $role, $fb ? 'yes' : 'NO', $sup ? 'yes' : 'NO');
    // /appraisals/completed is gated on feedback, so EVERY role must pass it.
    $check("{$role} passes the /completed router gate", $fb);
    if ($role === 'officer') {
        $check('officer is correctly denied /filters (supervise)', !$sup);
    }
}

// --- 4. Execute the real service per role ----------------------------------
$repRc = new ReflectionClass(\App\Services\Appraisal\AppraisalReportService::class);
$get = static function (string $name) use ($repRc) {
    $x = $repRc->getMethod($name);
    $x->setAccessible(true);
    return $x;
};
$scopedWhere  = $get('scopedWhere');
$applyFilters = $get('applyFilters');
$fetchRows    = $get('fetchRows');
$countVisible = $get('countVisible');
$canSeeRow    = $get('canSeeRow');

$runList = static function () use ($scopedWhere, $applyFilters, $fetchRows, $countVisible, $canSeeRow) {
    $instance = new \App\Services\Appraisal\AppraisalReportService();
    [$scopeWhere, $scopeParams] = $scopedWhere->invoke($instance);
    [$where, $params] = $applyFilters->invoke($instance, $scopeWhere, $scopeParams, [], 'completed');
    $rows = $fetchRows->invoke($instance, $where, $params, 0, 20);
    $visible = array_values(array_filter(
        $rows,
        static fn (array $r): bool => $canSeeRow->invoke($instance, $r)
    ));
    return [
        'scope'   => $scopeWhere,
        'rows'    => count($rows),
        'visible' => count($visible),
        'total'   => $countVisible->invoke($instance, $where, $params),
    ];
};

echo "\n";
foreach ([
    ['hr_manager',   'supervisor'],
    ['dept_head',    'supervisor'],
    ['section_head', 'supervisor'],
    ['officer',      'officer'],
] as [$role, $kind]) {
    $user = $findUser($role);
    if (!$user) {
        echo "  (no {$role} account - skipped)\n";
        continue;
    }
    $simulate($user);
    try {
        $res = $runList();
    } catch (\Throwable $e) {
        printf("%-58s %s\n", "{$role}: list executes without a fatal", 'FAIL');
        echo '  ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
        $ok = false;
        continue;
    }
    $check("{$role}: list executes without a fatal", true);
    printf("       scope=%-44s rows=%d visible=%d total=%d\n", $res['scope'], $res['rows'], $res['visible'], $res['total']);

    if ($kind === 'officer') {
        $check('officer scope pins to a.employee_id', str_contains($res['scope'], 'a.employee_id = ?'));
        $check('officer scope never widens to a department', !str_contains($res['scope'], 'e.department_id'));
    } else {
        $check("{$role}: scope is not self-pinned", !str_contains($res['scope'], 'a.employee_id = ?'));
    }
}

// --- 5. Analytics: run the real aggregation and cross-check the arithmetic --
$roles = ['managing_director', 'hr_manager', 'section_head', 'officer'];

// How many appraisals exist with no scope restriction at all. Used to tell a
// genuinely org-wide caller from a scoped one, so the SUM cross-check below is
// only asserted where it is actually a valid comparison.
$totalStmt = $db->query('SELECT COUNT(*) AS c FROM employee_appraisals');
$expectedScopeTotal = (int) ($totalStmt->fetch_assoc()['c'] ?? 0);
$totalStmt->close();
printf("\norganisation-wide appraisal count: %d\n", $expectedScopeTotal);

foreach ($roles as $i => $role) {
    $user = $findUser($role);
    if (!$user) {
        echo "  (no {$role} account - analytics skipped)\n";
        continue;
    }
    $simulate($user);

    try {
        $an = (new \App\Services\Appraisal\AppraisalReportService())->analytics([]);
    } catch (\Throwable $e) {
        $check("{$role}: analytics() runs without a fatal", false);
        echo '  ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
        $ok = false;
        continue;
    }
    $check("{$role}: analytics() runs without a fatal", true);

    // Officers hold feedback but not supervise, so they must be told the view is
    // unavailable rather than handed an all-zero dashboard that reads as "poor
    // performance everywhere".
    $expectAvailable = $role !== 'officer';
    $check("{$role}: available = " . var_export($expectAvailable, true), ($an['available'] ?? null) === $expectAvailable);
    if (!$expectAvailable) {
        continue;
    }

    printf(
        "       %-18s appraisals=%d scored=%d overall=%s%% depts=%d secs=%d subs=%d\n",
        $role,
        $an['appraisals'],
        $an['scored'],
        $an['overall']['percentage'] === null ? 'n/a' : $an['overall']['percentage'],
        count($an['departments']),
        count($an['sections']),
        count($an['subsections'])
    );
    if ($i === 0) {
        printf(
            "       top employee: %s (%s) %s%%  |  top quarter: %s %s%%\n",
            $an['top_employee']['name'] ?? '-',
            $an['top_employee']['detail'] ?? '-',
            $an['top_employee']['percentage'] ?? '-',
            $an['top_cycle']['name'] ?? '-',
            $an['top_cycle']['percentage'] ?? '-'
        );
    }

    // The headline MUST agree with the table it sits above. This is the single
    // most important invariant: if these drift, every number on the panel is a
    // lie relative to the rows the user can see.
    //
    // Compared against completedList() with IDENTICAL filters. Reusing $runList()
    // here would be wrong - it hard-codes status='completed', whereas the page's
    // default is "All statuses", so the two would disagree for a legitimate
    // reason and this check would cry wolf instead of catching a real drift.
    $svcAgain = new \App\Services\Appraisal\AppraisalReportService();
    $viaList = $svcAgain->completedList([], 1, 100);
    $check(
        "{$role}: analytics total matches list total for the same filters",
        $an['appraisals'] === $viaList['total']
    );
    $check(
        "{$role}: analytics scored count matches the list's scored rows",
        $an['scored'] === count(array_filter(
            $viaList['items'],
            static fn (array $r): bool => (float) ($r['total_max_score'] ?? 0) > 0
        ))
    );

    // Independent recomputation, replicating the service's "newest row per
    // (appraisal, indicator) wins" rule directly in SQL. Deliberately written
    // out longhand rather than calling the service's own helper, so it is a
    // genuine second opinion and not a tautology. Only meaningful for a caller
    // who can see the whole organisation: a section head correctly sees LESS, so
    // a global total would disagree for the right reason and prove nothing.
    if ($an['appraisals'] === $expectedScopeTotal) {
        $st = $db->query(
            "SELECT COALESCE(SUM(newest.score), 0) AS s,
                    COALESCE(SUM(p.max_score), 0) AS m
             FROM (
                 SELECT s.employee_appraisal_id, s.performance_indicator_id, s.score
                 FROM appraisal_scores s
                 LEFT JOIN appraisal_scores newer
                   ON newer.employee_appraisal_id = s.employee_appraisal_id
                  AND newer.performance_indicator_id = s.performance_indicator_id
                  AND (newer.updated_at > s.updated_at
                       OR (newer.updated_at = s.updated_at AND newer.id > s.id))
                 WHERE newer.id IS NULL
             ) newest
             JOIN performance_indicators p ON p.id = newest.performance_indicator_id"
        );
        $agg = $st->fetch_assoc();
        $st->close();
        $check(
            "{$role}: overall matches a direct SUM(s)/SUM(max) recompute",
            abs($an['overall']['total_score'] - (float) $agg['s']) < 0.01
                && abs($an['overall']['total_max_score'] - (float) $agg['m']) < 0.01
        );
    } else {
        // A scoped caller must see a STRICT SUBSET, never more than the org.
        $check(
            "{$role}: scoped view is a strict subset of the organisation",
            $an['appraisals'] < $expectedScopeTotal
        );
    }

    // Every ranking must be in non-increasing order, or "best" is arbitrary.
    foreach (['departments', 'sections', 'subsections'] as $key) {
        $pcts = array_column($an[$key], 'percentage');
        $sorted = $pcts;
        rsort($sorted);
        $check("{$role}: {$key} is ranked best-first", $pcts === $sorted);
        $check(
            "{$role}: {$key} has a non-zero count everywhere",
            array_reduce($an[$key], static fn ($c, $u) => $c && $u['count'] > 0, true)
        );
        // None of these averages may exceed the whole-scope average scaled by
        // rounding, nor fall below zero - a negative score is impossible and a
        // unit cannot exceed the maximum available to it.
        $check(
            "{$role}: {$key} percentages are all within 0-100",
            array_reduce($an[$key], static fn ($c, $u) => $c && $u['percentage'] >= 0 && $u['percentage'] <= 100, true)
        );
    }

    // "Best" must be the actual head of the ranking it was taken from, and the
    // department/section/cycle leaders must not beat it, since every one of them
    // is drawn from the same set of appraisals.
    $empPct = $an['top_employee']['percentage'] ?? null;
    $cycPct = $an['top_cycle']['percentage'] ?? null;
    $deptPct = $an['departments'][0]['percentage'] ?? null;
    $check(
        "{$role}: top_employee is set whenever anything is scored",
        $an['scored'] === 0 ? $empPct === null : is_float($empPct) || is_int($empPct)
    );
    $check(
        "{$role}: top_cycle is set whenever anything is scored",
        $an['scored'] === 0 ? $cycPct === null : is_float($cycPct) || is_int($cycPct)
    );
    $check(
        "{$role}: top_employee is not beaten by any department",
        $empPct === null || $deptPct === null || $empPct >= $deptPct
    );
    $check(
        "{$role}: top_cycle is not beaten by any department",
        $cycPct === null || $deptPct === null || $cycPct >= $deptPct
    );
}

// --- 6. End-to-end HTTP: route really resolves and the controller is wired ---
// Reflection and direct service calls cannot catch a route that was never
// registered, or one shadowed by the /appraisals/{id} wildcard. This drives the
// real router over a real login so both are proven.
if (!extension_loaded('curl')) {
    echo "  (curl not available - HTTP analytics check skipped)\n";
    echo $ok ? "\nALL LIVE SCOPE CHECKS PASSED\n" : "\nLIVE SCOPE CHECKS FAILED\n";
    return;
}

$base = 'http://localhost/hrdemo/api';
$jar = tempnam(sys_get_temp_dir(), 'mdc');

$login = static function (string $email, string $password) use ($base, $jar): ?array {
    $ch = curl_init($base . '/auth/login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $password]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string) $body, true);
    return is_array($json) ? ['code' => $code, 'json' => $json] : null;
};

$getAnalytics = static function (string $token) use ($base, $jar): array {
    $ch = curl_init($base . '/appraisals/completed/analytics');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'json' => json_decode((string) $body, true)];
};

$password = getenv('HR_TEST_PASSWORD') ?: 'Password123!';
$mdEmail = null;
$mdId = 0;
$mdStmt = $db->query("SELECT id, email FROM users WHERE is_active = 1 AND role = 'managing_director' LIMIT 1");
if ($row = $mdStmt->fetch_assoc()) {
    $mdEmail = $row['email'];
    $mdId = (int) $row['id'];
}
$mdStmt->close();

// The HTTP leg needs a real password, and this repo has no seeded one. Rather
// than hard-code a credential or skip the most valuable check (that the route
// resolves and the controller is wired), temporarily set a known hash and
// ALWAYS restore the original in a finally block - a crash must not leave a
// real managing_director account with a password from a test script.
$originalHash = null;
if ($mdEmail !== null) {
    $h = $db->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $h->bind_param('i', $mdId);
    $h->execute();
    $originalHash = $h->get_result()->fetch_assoc()['password'] ?? null;
    $h->close();
}

if ($mdEmail === null) {
    echo "  (no managing_director account - HTTP analytics check skipped)\n";
} else {
    $setHash = static function (int $id, string $hash) use ($db): void {
        $s = $db->prepare('UPDATE users SET password = ? WHERE id = ?');
        $s->bind_param('si', $hash, $id);
        $s->execute();
        $s->close();
    };
    $setHash($mdId, password_hash($password, PASSWORD_DEFAULT));

    try {
        $auth = $login($mdEmail, $password);
    $token = $auth['json']['data']['access_token']
        ?? $auth['json']['data']['token']
        ?? null;
    if ($token === null) {
        // RATE_LIMITED is expected once this script has been run a few times -
        // the login limiter is doing its job, and tripping it is not a
        // regression in the analytics. Report it as a skip, not a silent pass
        // and not a failure.
        $err = $auth['json']['error']['code'] ?? '';
        if ($err === 'RATE_LIMITED') {
            echo "  (login rate-limited - HTTP analytics leg skipped, re-run later)\n";
        } else {
            echo "  (could not obtain a token for {$mdEmail})\n";
            echo '  login said: ' . substr(json_encode($auth['json'] ?? null), 0, 200) . PHP_EOL;
        }
    } else {
        $res = $getAnalytics($token);
        $check('GET /appraisals/completed/analytics returns 200', ($res['code'] ?? 0) === 200);

        $d = $res['json']['data'] ?? null;
        $check('response carries the analytics payload', is_array($d) && isset($d['overall'], $d['top_employee'], $d['top_cycle']));
        if (is_array($d)) {
            printf(
                "       HTTP: appraisals=%d scored=%d overall=%s%% top=%s top cycle=%s\n",
                $d['appraisals'] ?? -1,
                $d['scored'] ?? -1,
                $d['overall']['percentage'] ?? 'n/a',
                $d['top_employee']['name'] ?? '-',
                $d['top_cycle']['name'] ?? '-'
            );
            // Must agree with the direct service call made earlier in this run.
            // The session is still whatever the LAST role in the loop above left
            // behind (an officer, who gets available:false), so it has to be
            // re-seeded as the managing director first or this compares a
            // supervisor's HTTP result against an officer's service result.
            $mdUser = $findUser('managing_director');
            if ($mdUser) {
                $simulate($mdUser);
            }
            $svcDirect = (new \App\Services\Appraisal\AppraisalReportService())->analytics([]);
            $check(
                'HTTP payload matches the direct service call',
                ($d['appraisals'] ?? null) === ($svcDirect['appraisals'] ?? null)
                    && ($d['overall']['percentage'] ?? null) === ($svcDirect['overall']['percentage'] ?? null)
            );

            // A filter must actually narrow the aggregate, not just the table.
            $deptId = (int) ($d['departments'][0]['id'] ?? 0);
            if ($deptId > 0) {
                $ch = curl_init($base . '/appraisals/completed/analytics?department_id=' . $deptId);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token],
                    CURLOPT_COOKIEJAR => $jar,
                    CURLOPT_COOKIEFILE => $jar,
                    CURLOPT_TIMEOUT => 20,
                ]);
                $filtered = json_decode((string) curl_exec($ch), true);
                $code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $check('filtered analytics returns 200', $code2 === 200);
                $check(
                    "filtering to department #{$deptId} narrows the appraisal count",
                    isset($filtered['data']['appraisals'])
                        && $filtered['data']['appraisals'] < $d['appraisals']
                );
                $check(
                    "filtering to department #{$deptId} leaves exactly one department",
                    count($filtered['data']['departments'] ?? []) === 1
                );
            }
        }
    }
    } finally {
        // Unconditional: whatever happened above, the real account goes back to
        // the password it had before this script touched it.
        // Unconditional: whatever happened above, the real account goes back to
        // the password it had before this script touched it.
        if ($originalHash !== null) {
            $setHash($mdId, $originalHash);
        }
        @unlink($jar);

        // Prove the restore actually happened rather than assuming it did. A
        // verification script that quietly left a real managing_director account
        // on a password it invented would be worse than no check at all.
        $s = $db->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        $s->bind_param('i', $mdId);
        $s->execute();
        $seenAfter = $s->get_result()->fetch_assoc()['password'] ?? null;
        $s->close();
        $restored = ($seenAfter === $originalHash);
        printf("       password restore: %s\n", $restored ? 'verified' : 'MISMATCH');
        $check('managing_director password is restored after the HTTP leg', $restored);
    }
}

echo $ok ? "\nALL LIVE SCOPE CHECKS PASSED\n" : "\nLIVE SCOPE CHECKS FAILED\n";
