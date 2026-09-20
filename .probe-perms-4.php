<?php
require 'backend/bootstrap.php';
$conn = db();

$checks = [
    'role_permissions'   => "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'muwasco' AND table_name = 'role_permissions' ORDER BY ordinal_position",
    'user_permissions'   => "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'muwasco' AND table_name = 'user_permissions' ORDER BY ordinal_position",
    'permissions'        => "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'muwasco' AND table_name = 'permissions' ORDER BY ordinal_position",
    'user_groups'        => "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'muwasco' AND table_name = 'user_groups' ORDER BY ordinal_position",
    'role_groups'        => "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'muwasco' AND table_name = 'role_groups' ORDER BY ordinal_position",
    'roles'             => "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'muwasco' AND table_name = 'roles' ORDER BY ordinal_position",
];

foreach ($checks as $name => $sql) {
    echo "=== $name ===\n";
    $cols = db()->fetchAll($sql);
    if ($cols === []) {
        echo "  (no columns returned)\n";
    } else {
        foreach ($cols as $c) echo "  {$c['column_name']} ({$c['data_type']})\n";
    }
    echo "\n";
}

echo "=== Duncan karenju row from users ===\n";
$row = db()->fetchAll("SELECT * FROM users WHERE id = 78");
if ($row === []) {
    echo "  not found\n";
} else {
    print_r($row[0]);
}
echo "\n";
