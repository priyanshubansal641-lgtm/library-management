<?php
require __DIR__ . '/config.php';

$pdo = new PDO(
    "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec("UPDATE sessions SET exit_time=NOW() WHERE exit_time IS NULL");
$pdo->exec("UPDATE seats SET is_occupied=0, current_student_id=NULL");
$today = date('Y-m-d');
$pdo->exec("UPDATE app_options SET option_value='$today' WHERE option_key='last_reset_date'");

echo "All seats reset successfully!";
