<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";port=".DB_PORT.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch(Exception $e) {
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

$_jsonBody = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $_POST['action'] ?? $_jsonBody['action'] ?? '';

switch($action) {

    case 'get-seats':
        $lastReset = $pdo->query("SELECT option_value FROM app_options WHERE option_key='last_reset_date'")->fetchColumn();
        $today = date('Y-m-d');
        $resetHour = (int)date('H');
        if($lastReset !== $today && $resetHour >= 0) {
            $pdo->exec("UPDATE seats SET is_occupied=0, current_student_id=NULL");
            $pdo->exec("UPDATE sessions SET exit_time=NOW() WHERE exit_time IS NULL");
            $pdo->exec("UPDATE app_options SET option_value='$today' WHERE option_key='last_reset_date'");
        }
        $rows = $pdo->query("SELECT seat_id, is_occupied, current_student_id FROM seats")->fetchAll();
        $map = [];
        foreach($rows as $r) {
            $map[$r['seat_id']] = ['occupied' => (bool)$r['is_occupied'], 'studentId' => $r['current_student_id']];
        }
        echo json_encode(['event' => 'all-seats', 'data' => $map]);
        break;

    case 'verify-student':
        $barcode = trim($_jsonBody['barcode'] ?? $_POST['barcode'] ?? $_GET['barcode'] ?? '');
        if(!$barcode) { echo json_encode(['error' => 'Barcode required']); break; }
        $st = $pdo->prepare("SELECT * FROM students WHERE barcode=? OR roll_number=?");
        $st->execute([$barcode, $barcode]);
        $student = $st->fetch();
        if(!$student) { echo json_encode(['error' => 'Student not found']); break; }
        $sess = $pdo->prepare("SELECT * FROM sessions WHERE student_id=? AND exit_time IS NULL");
        $sess->execute([$student['id']]);
        $session = $sess->fetch();
        $isInside = (bool)$session;
        if($isInside) $student['seat_id'] = $session['seat_id'];
        echo json_encode(['student' => $student, 'isInside' => $isInside]);
        break;

    case 'book-seat':
        $studentId = $_jsonBody['studentId'] ?? $_POST['studentId'] ?? '';
        $seatId = $_jsonBody['seatId'] ?? $_POST['seatId'] ?? '';
        if(!$studentId || !$seatId) { echo json_encode(['error' => 'Missing data']); break; }
        $alreadyIn = $pdo->prepare("SELECT id FROM sessions WHERE student_id=? AND exit_time IS NULL");
        $alreadyIn->execute([$studentId]);
        if($alreadyIn->fetch()) { echo json_encode(['error' => 'Student already has a seat']); break; }
        $check = $pdo->prepare("SELECT seat_id FROM seats WHERE seat_id=? AND is_occupied=0");
        $check->execute([$seatId]);
        if(!$check->fetch()) { echo json_encode(['error' => "Seat $seatId already taken"]); break; }
        $pdo->prepare("UPDATE seats SET is_occupied=1, current_student_id=? WHERE seat_id=?")->execute([$studentId, $seatId]);
        $pdo->prepare("INSERT INTO sessions (student_id, seat_id, entry_time) VALUES (?,?,NOW())")->execute([$studentId, $seatId]);
        echo json_encode(['success' => true]);
        break;

    case 'student-exit':
        $studentId = $_jsonBody['studentId'] ?? $_POST['studentId'] ?? '';
        if(!$studentId) { echo json_encode(['success' => false]); break; }
        $sess = $pdo->prepare("SELECT * FROM sessions WHERE student_id=? AND exit_time IS NULL");
        $sess->execute([$studentId]);
        $session = $sess->fetch();
        if(!$session) { echo json_encode(['success' => false]); break; }
        $pdo->prepare("UPDATE sessions SET exit_time=NOW() WHERE id=?")->execute([$session['id']]);
        $pdo->prepare("UPDATE seats SET is_occupied=0, current_student_id=NULL WHERE seat_id=?")->execute([$session['seat_id']]);
        echo json_encode(['success' => true, 'seatId' => $session['seat_id']]);
        break;

    case 'visitor-entry':
        $data = $_jsonBody ?: $_POST;
        $name = trim($data['name'] ?? '');
        $mobile = trim($data['mobile'] ?? '');
        if(!$name || !$mobile) { echo json_encode(['success' => false, 'msg' => 'Name and mobile required']); break; }
        $now = new DateTime();
        $pdo->prepare("INSERT INTO visitors (name,mobile,email,purpose,entry_time,visit_date,visit_time) VALUES (?,?,?,?,NOW(),?,?)")
            ->execute([$name, $mobile, $data['email']??null, $data['purpose']??null, $data['visit_date']??null, $data['visit_time']??null]);
        echo json_encode(['success' => true]);
        break;

    case 'reset-all-seats':
        $pdo->exec("UPDATE seats SET is_occupied=0, current_student_id=NULL");
        $pdo->exec("UPDATE sessions SET exit_time=NOW() WHERE exit_time IS NULL");
        echo json_encode(['success' => true]);
        break;

    case 'admin-release-seat':
        $data = $_jsonBody ?: $_POST;
        $seatId = $data['seatId'] ?? '';
        if(!$seatId) { echo json_encode(['success' => false]); break; }
        $sess = $pdo->prepare("SELECT * FROM sessions WHERE seat_id=? AND exit_time IS NULL");
        $sess->execute([$seatId]);
        $session = $sess->fetch();
        if($session) $pdo->prepare("UPDATE sessions SET exit_time=NOW() WHERE id=?")->execute([$session['id']]);
        $pdo->prepare("UPDATE seats SET is_occupied=0, current_student_id=NULL WHERE seat_id=?")->execute([$seatId]);
        echo json_encode(['success' => true]);
        break;

    case 'get-student-logs':
        $filter = $_GET['filter'] ?? 'today';
        $where = match($filter) {
            'today' => 'WHERE DATE(s.entry_time) = CURDATE()',
            'week'  => 'WHERE s.entry_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            'month' => 'WHERE s.entry_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
            default => ''
        };
        $logs = $pdo->query("SELECT s.id, st.name, st.roll_number, s.seat_id, s.entry_time, s.exit_time,
            DATE_FORMAT(s.entry_time,'%d %b %Y') AS entry_date,
            DATE_FORMAT(s.entry_time,'%h:%i %p') AS entry_time_fmt,
            DATE_FORMAT(s.exit_time,'%h:%i %p') AS exit_time_fmt,
            TIMEDIFF(COALESCE(s.exit_time,NOW()),s.entry_time) AS duration
            FROM sessions s JOIN students st ON s.student_id=st.id $where
            ORDER BY s.entry_time DESC LIMIT 500")->fetchAll();
        echo json_encode($logs);
        break;

    case 'get-visitor-logs':
        $filter = $_GET['filter'] ?? 'today';
        $where = match($filter) {
            'today' => 'WHERE DATE(entry_time) = CURDATE()',
            'week'  => 'WHERE entry_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
            'month' => 'WHERE entry_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
            default => ''
        };
        $logs = $pdo->query("SELECT id,name,mobile,email,purpose,
            DATE_FORMAT(entry_time,'%h:%i %p') AS time,
            DATE_FORMAT(entry_time,'%d %b %Y') AS date
            FROM visitors $where ORDER BY entry_time DESC LIMIT 500")->fetchAll();
        echo json_encode($logs);
        break;

    case 'add-student':
        $data = $_jsonBody ?: $_POST;
        $name = trim($data['name'] ?? '');
        $roll = trim($data['roll_number'] ?? '');
        $barcode = trim($data['barcode'] ?? $roll);
        if(!$name || !$roll) { echo json_encode(['success'=>false,'msg'=>'Name and Roll required']); break; }
        $exists = $pdo->prepare("SELECT id FROM students WHERE roll_number=?");
        $exists->execute([$roll]);
        if($exists->fetch()) { echo json_encode(['success'=>false,'msg'=>"Roll $roll already exists"]); break; }
        $pdo->prepare("INSERT INTO students (name,roll_number,barcode) VALUES (?,?,?)")->execute([$name,$roll,$barcode]);
        echo json_encode(['success'=>true,'msg'=>"$name added successfully"]);
        break;

    case 'import-csv':
        if(!isset($_FILES['csv'])) { echo json_encode(['success'=>false,'msg'=>'No file']); break; }
        $handle = fopen($_FILES['csv']['tmp_name'], 'r');
        $stmt = $pdo->prepare("INSERT IGNORE INTO students (name,roll_number,barcode) VALUES (?,?,?)");
        $count = 0;
        while(($row = fgetcsv($handle)) !== false) {
            if(count($row) < 3) continue;
            if(!is_numeric(trim($row[0]))) continue;
            $roll = trim($row[1]); $name = trim($row[2]);
            if(empty($roll)||empty($name)) continue;
            $stmt->execute([$name,$roll,$roll]);
            $count++;
        }
        fclose($handle);
        echo json_encode(['success'=>true,'msg'=>"Imported $count students"]);
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
}
