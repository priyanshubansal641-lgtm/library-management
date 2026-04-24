<?php
require __DIR__ . '/config.php';

$pdo = new PDO(
    "mysql:host=".DB_HOST.";port=".DB_PORT.";charset=utf8mb4",
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec("CREATE DATABASE IF NOT EXISTS `".DB_NAME."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `".DB_NAME."`");

$pdo->exec("CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    roll_number VARCHAR(50) UNIQUE NOT NULL,
    barcode VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS seats (
    seat_id VARCHAR(10) PRIMARY KEY,
    is_occupied TINYINT(1) DEFAULT 0,
    current_student_id INT DEFAULT NULL
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    seat_id VARCHAR(10) NOT NULL,
    entry_time DATETIME DEFAULT NOW(),
    exit_time DATETIME DEFAULT NULL,
    FOREIGN KEY (student_id) REFERENCES students(id)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS visitors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    mobile VARCHAR(15) NOT NULL,
    email VARCHAR(100) DEFAULT NULL,
    purpose VARCHAR(50) DEFAULT NULL,
    entry_time DATETIME DEFAULT NOW(),
    visit_date VARCHAR(20) DEFAULT NULL,
    visit_time VARCHAR(20) DEFAULT NULL
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS app_options (
    option_key VARCHAR(50) PRIMARY KEY,
    option_value VARCHAR(100) NOT NULL
)");

$pdo->exec("INSERT IGNORE INTO app_options (option_key, option_value) VALUES ('last_reset_date', '2000-01-01')");

$cnt = $pdo->query("SELECT COUNT(*) FROM seats")->fetchColumn();
if($cnt == 0){
    $vals = [];
    $floors = ['G'=>42,'1'=>114,'2'=>142,'3'=>96];
    foreach($floors as $floor=>$total)
        for($i=1;$i<=$total;$i++)
            $vals[] = "('$floor-$i',0,NULL)";
    $pdo->exec("INSERT INTO seats (seat_id,is_occupied,current_student_id) VALUES ".implode(',',$vals));
    echo "394 seats initialized.\n";
}

echo "Setup complete.\n";
