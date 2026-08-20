<?php
header('Content-Type: text/plain');
error_reporting(E_ALL);

$mysqli = new mysqli('127.0.0.1', 'root', 'root', 'muwasco');

$r = $mysqli->query("SHOW COLUMNS FROM user_page_permissions");
echo "user_page_permissions columns:\n";
while ($row = $r->fetch_assoc()) {
    echo "  - " . $row['Field'] . " (" . $row['Type'] . ")\n";
}

$r = $mysqli->query("SHOW KEYS FROM user_page_permissions");
echo "\nKeys on user_page_permissions:\n";
while ($row = $r->fetch_assoc()) {
    echo "  - " . $row['Key_name'] . " (col: " . $row['Column_name'] . ")\n";
}

$r = $mysqli->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_page_permissions' AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
echo "\nFKs on user_page_permissions:\n";
while ($row = $r->fetch_assoc()) {
    echo "  - " . $row['CONSTRAINT_NAME'] . "\n";
}

$r = $mysqli->query("SELECT COUNT(*) as cnt FROM user_page_permissions");
$row = $r->fetch_assoc();
echo "\nuser_page_permissions count: " . $row['cnt'] . "\n";

$mysqli->close();
