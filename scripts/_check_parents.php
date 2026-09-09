<?php
// Temp script: extract WorkplanController private helpers verbatim into WorkplanService.
$src = 'backend/app/Controllers/HR/WorkplanController.php';
$c = file_get_contents($src);
$c = str_replace("\r\n", "\n", $c);

$helpers = ['isBroadWorkplan','defaultView','availableViews','viewScope','departmentWhere',
 'cascadeSections','cascadeSubsections','validateCascadeAssignment','selectRows','workplanScope',
 'rowExists','objectiveExists','objectiveLabel','actorName','decodeJsonField','deriveLevel',
 'unitLabel','assignableEmployees','objectiveWithinScope','validateOfficerPlacement',
 'validateParentLinkage'];

// Method start positions: "    (public|private|protected) function NAME("
preg_match_all('/\n    (?:public|private|protected) function (\w+)\(/', $c, $m, PREG_OFFSET_CAPTURE);
$starts = []; // name => [docStart, bodyEnd]
for ($i = 0; $i < count($m[1]); $i++) {
    $name = $m[1][$i][0];
    $sigStart = $m[0][$i][1]; // at the "\n"
    // find next method start or end-of-class
    $next = $i + 1 < count($m[0]) ? $m[0][$i + 1][1] : strrpos($c, "\n}");
    // extend end to include trailing blank line(s) before next method
    $starts[$name] = [$sigStart, $next];
}

$extracted = [];
$removeRanges = [];
foreach ($helpers as $h) {
    if (!isset($starts[$h])) { echo "MISSING HELPER: $h\n"; exit(1); }
    [$s, $e] = $starts[$h];
    // widen start backwards over the immediately-preceding docblock (if any)
    $docStart = $s;
    $pre = substr($c, 0, $s + 1);
    $close = strrpos($pre, '*/');
    if ($close !== false && trim(substr($pre, $close + 2)) === '') {
        $open = strrpos(substr($pre, 0, $close), '/**');
        if ($open !== false && strpos(substr($pre, $open, $close - $open), 'function') === false) {
            $nl = strrpos(substr($pre, 0, $open), "\n");
            $docStart = $nl !== false ? $nl : max(0, $open - 1);
        }
    }
    $body = substr($c, $docStart, $e - $docStart);
    $body = rtrim($body, "\n");
    // make public
    $body = preg_replace('/\n    private function /', "\n    public function ", $body);
    $extracted[$h] = $body;
    $removeRanges[] = [$docStart, $e];
}

// Remove ranges from controller (descending order). Clamp overlapping
// neighbours first: an earlier helper's range must not swallow the next
// helper's docblock region (they are adjacent in the source).
usort($removeRanges, fn($a, $b) => $a[0] <=> $b[0]);
for ($i = 0; $i < count($removeRanges) - 1; $i++) {
    if ($removeRanges[$i][1] > $removeRanges[$i + 1][0]) {
        $removeRanges[$i][1] = $removeRanges[$i + 1][0];
    }
}
usort($removeRanges, fn($a, $b) => $b[0] <=> $a[0]);
foreach ($removeRanges as [$s, $e]) {
    $c = substr($c, 0, $s) . substr($c, $e);
}
// collapse any triple+ blank lines left behind
$c = preg_replace("/\n{3,}/", "\n\n", $c);

// Rewrite call sites in controller: $this->helper( -> $this->workplans->helper(
foreach ($helpers as $h) {
    $c = preg_replace('/\$this->' . $h . '\(/', '$this->workplans->' . $h . '(', $c);
}

// Add use import + property + constructor init
$c = str_replace(
    "use App\\Helpers\\OrgScope;",
    "use App\\Helpers\\OrgScope;\nuse App\\Services\\Workplan\\WorkplanService;",
    $c
);
$c = str_replace(
    "    private \\mysqli \$db;\n\n    public function __construct()\n    {\n        \$this->db = Database::getInstance()->getConnection();\n    }",
    "    private \\mysqli \$db;\n\n    /** Domain/data service owning workplan query, scope and validation logic. */\n    private WorkplanService \$workplans;\n\n    public function __construct()\n    {\n        \$this->db = Database::getInstance()->getConnection();\n        \$this->workplans = new WorkplanService(\$this->db);\n    }",
    $c, $cnt1);
if ($cnt1 !== 1) { echo "CONSTRUCTOR REPLACEMENT FAILED ($cnt1)\n"; exit(1); }

file_put_contents($src, $c);

// Build service file
$svc = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Services\\Workplan;\n\nuse App\\Helpers\\OrgScope;\n\n/**\n * Workplan Service\n *\n * Owns workplan domain logic: role-based view scoping, cascade lineage,\n * objective scope/parent validation, and shared workplan queries.\n * Extracted verbatim from WorkplanController (Phase 3, behavior-preserving).\n */\nclass WorkplanService\n{\n    public function __construct(private \\mysqli \$db)\n    {\n    }\n\n";
$svc .= implode("\n\n", array_map(fn($b) => ltrim($b, "\n"), $extracted));
$svc .= "\n}\n";
file_put_contents('backend/app/Services/Workplan/WorkplanService.php', $svc);

echo 'Extracted ' . count($extracted) . " helpers.\n";
echo 'Controller now ' . (substr_count($c, "\n") + 1) . " lines.\n";
