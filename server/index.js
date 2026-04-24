const express = require('express');
const mysql = require('mysql2/promise');
const multer = require('multer');
const path = require('path');
const fs = require('fs');

const app = express();
const upload = multer({ dest: 'uploads/' });

app.use(express.json());
app.use(express.urlencoded({ extended: true }));
app.use(express.static(path.join(__dirname, '../public')));

// CORS
app.use((req, res, next) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  if (req.method === 'OPTIONS') return res.sendStatus(200);
  next();
});

// DB connection
const pool = mysql.createPool({
  host: process.env.DB_HOST,
  port: process.env.DB_PORT || 21797,
  user: process.env.DB_USER,
  password: process.env.DB_PASS,
  database: process.env.DB_NAME || 'defaultdb',
  ssl: { rejectUnauthorized: false },
  waitForConnections: true,
});

app.get('/api.php', handleApi);
app.post('/api.php', handleApi);

async function handleApi(req, res) {
  res.setHeader('Content-Type', 'application/json');
  const body = req.body || {};
  const action = req.query.action || body.action || '';
  let db;
  try {
    db = await pool.getConnection();
  } catch (e) {
    return res.json({ error: 'DB connection failed' });
  }

  try {
    switch (action) {

      case 'get-seats': {
        const [[opt]] = await db.query("SELECT option_value FROM app_options WHERE option_key='last_reset_date'");
        const today = new Date().toISOString().slice(0, 10);
        if (!opt || opt.option_value !== today) {
          await db.query("UPDATE seats SET is_occupied=0, current_student_id=NULL");
          await db.query("UPDATE sessions SET exit_time=NOW() WHERE exit_time IS NULL");
          await db.query("UPDATE app_options SET option_value=? WHERE option_key='last_reset_date'", [today]);
        }
        const [rows] = await db.query("SELECT seat_id, is_occupied, current_student_id FROM seats");
        const map = {};
        rows.forEach(r => { map[r.seat_id] = { occupied: !!r.is_occupied, studentId: r.current_student_id }; });
        return res.json({ event: 'all-seats', data: map });
      }

      case 'verify-student': {
        const barcode = (req.query.barcode || body.barcode || '').trim();
        if (!barcode) return res.json({ error: 'Barcode required' });
        const [[student]] = await db.query("SELECT * FROM students WHERE barcode=? OR roll_number=?", [barcode, barcode]);
        if (!student) return res.json({ error: 'Student not found' });
        const [[session]] = await db.query("SELECT * FROM sessions WHERE student_id=? AND exit_time IS NULL", [student.id]);
        const isInside = !!session;
        if (isInside) student.seat_id = session.seat_id;
        return res.json({ student, isInside });
      }

      case 'book-seat': {
        const { studentId, seatId } = body;
        if (!studentId || !seatId) return res.json({ error: 'Missing data' });
        const [[alreadyIn]] = await db.query("SELECT id FROM sessions WHERE student_id=? AND exit_time IS NULL", [studentId]);
        if (alreadyIn) return res.json({ error: 'Student already has a seat' });
        const [[seat]] = await db.query("SELECT seat_id FROM seats WHERE seat_id=? AND is_occupied=0", [seatId]);
        if (!seat) return res.json({ error: `Seat ${seatId} already taken` });
        await db.query("UPDATE seats SET is_occupied=1, current_student_id=? WHERE seat_id=?", [studentId, seatId]);
        await db.query("INSERT INTO sessions (student_id, seat_id, entry_time) VALUES (?,?,NOW())", [studentId, seatId]);
        return res.json({ success: true });
      }

      case 'student-exit': {
        const { studentId } = body;
        if (!studentId) return res.json({ success: false });
        const [[session]] = await db.query("SELECT * FROM sessions WHERE student_id=? AND exit_time IS NULL", [studentId]);
        if (!session) return res.json({ success: false });
        await db.query("UPDATE sessions SET exit_time=NOW() WHERE id=?", [session.id]);
        await db.query("UPDATE seats SET is_occupied=0, current_student_id=NULL WHERE seat_id=?", [session.seat_id]);
        return res.json({ success: true, seatId: session.seat_id });
      }

      case 'visitor-entry': {
        const { name, mobile, email, purpose, visit_date, visit_time } = body;
        if (!name || !mobile) return res.json({ success: false, msg: 'Name and mobile required' });
        await db.query("INSERT INTO visitors (name,mobile,email,purpose,entry_time,visit_date,visit_time) VALUES (?,?,?,?,NOW(),?,?)",
          [name, mobile, email || null, purpose || null, visit_date || null, visit_time || null]);
        return res.json({ success: true });
      }

      case 'reset-all-seats': {
        await db.query("UPDATE seats SET is_occupied=0, current_student_id=NULL");
        await db.query("UPDATE sessions SET exit_time=NOW() WHERE exit_time IS NULL");
        return res.json({ success: true });
      }

      case 'admin-release-seat': {
        const { seatId } = body;
        if (!seatId) return res.json({ success: false });
        const [[session]] = await db.query("SELECT * FROM sessions WHERE seat_id=? AND exit_time IS NULL", [seatId]);
        if (session) await db.query("UPDATE sessions SET exit_time=NOW() WHERE id=?", [session.id]);
        await db.query("UPDATE seats SET is_occupied=0, current_student_id=NULL WHERE seat_id=?", [seatId]);
        return res.json({ success: true });
      }

      case 'get-student-logs': {
        const filter = req.query.filter || 'today';
        const where = filter === 'today' ? 'WHERE DATE(s.entry_time) = CURDATE()'
          : filter === 'week' ? 'WHERE s.entry_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
          : filter === 'month' ? 'WHERE s.entry_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)' : '';
        const [logs] = await db.query(`SELECT s.id, st.name, st.roll_number, s.seat_id, s.entry_time, s.exit_time,
          DATE_FORMAT(s.entry_time,'%d %b %Y') AS entry_date,
          DATE_FORMAT(s.entry_time,'%h:%i %p') AS entry_time_fmt,
          DATE_FORMAT(s.exit_time,'%h:%i %p') AS exit_time_fmt,
          TIMEDIFF(COALESCE(s.exit_time,NOW()),s.entry_time) AS duration
          FROM sessions s JOIN students st ON s.student_id=st.id ${where}
          ORDER BY s.entry_time DESC LIMIT 500`);
        return res.json(logs);
      }

      case 'get-visitor-logs': {
        const filter = req.query.filter || 'today';
        const where = filter === 'today' ? 'WHERE DATE(entry_time) = CURDATE()'
          : filter === 'week' ? 'WHERE entry_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
          : filter === 'month' ? 'WHERE entry_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)' : '';
        const [logs] = await db.query(`SELECT id,name,mobile,email,purpose,
          DATE_FORMAT(entry_time,'%h:%i %p') AS time,
          DATE_FORMAT(entry_time,'%d %b %Y') AS date
          FROM visitors ${where} ORDER BY entry_time DESC LIMIT 500`);
        return res.json(logs);
      }

      case 'add-student': {
        const { name, roll_number, barcode } = body;
        if (!name || !roll_number) return res.json({ success: false, msg: 'Name and Roll required' });
        const [[exists]] = await db.query("SELECT id FROM students WHERE roll_number=?", [roll_number]);
        if (exists) return res.json({ success: false, msg: `Roll ${roll_number} already exists` });
        await db.query("INSERT INTO students (name,roll_number,barcode) VALUES (?,?,?)", [name, roll_number, barcode || roll_number]);
        return res.json({ success: true, msg: `${name} added successfully` });
      }

      default:
        return res.json({ error: 'Invalid action' });
    }
  } catch (e) {
    return res.json({ error: e.message });
  } finally {
    db.release();
  }
}

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`Server running on port ${PORT}`));
