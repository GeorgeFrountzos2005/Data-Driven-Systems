<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1) BUFFER & CLEAN OUTPUT
ob_start();

// 2) FORCE JSON MIME TYPE
header('Content-Type: application/json');

// 3) BOOTSTRAP
require 'db.php';           // $conn
require 'jwt_handler.php';  // createJWT(), validateJWT()

// 4) ROUTING SETUP with PATH_INFO fallback
$method = $_SERVER['REQUEST_METHOD'];
$path   = $_SERVER['PATH_INFO'] ?? '';
if (empty($path)) {
    $script = $_SERVER['SCRIPT_NAME'];
    $uri    = $_SERVER['REQUEST_URI'];
    $path   = parse_url(substr($uri, strlen($script)), PHP_URL_PATH);
}
$request = explode('/', trim($path, '/'));

// ─────────────────────────────────────────────────────────────────────────────
// GET /users
if ($method === 'GET' && ($request[0] ?? '') === 'users') {
    $result = $conn->query(
      "SELECT user_id, full_name, email, phone, national_id, prs_id, role_id, created_at FROM users"
    );
    echo json_encode($result->fetch_all(MYSQLI_ASSOC));
    ob_end_flush();
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST /users → register a new user
if ($method === 'POST' && ($request[0] ?? '') === 'users') {
  // debug log if you like:
  // file_put_contents(__DIR__.'/api-debug.log', file_get_contents('php://input')."\n", FILE_APPEND);

  $data = json_decode(file_get_contents('php://input'), true);

  $full_name   = trim($data['full_name']   ?? '');
  $email       = trim($data['email']       ?? '');
  $password    = trim($data['password']    ?? '');
  $phone       = isset($data['phone'])     ? trim($data['phone']) : null;
  $national_id = trim($data['national_id'] ?? '');
  $prs_id      = trim($data['prs_id']      ?? '');
  $role_id     = (int) ($data['role_id']   ?? 0);

  $pw_hash = password_hash($password, PASSWORD_DEFAULT);

  $stmt = $conn->prepare("
    INSERT INTO users
      (full_name, email, password_hash, phone, national_id, prs_id, role_id)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  ");
  $stmt->bind_param(
    'ssssssi',
    $full_name,
    $email,
    $pw_hash,
    $phone,
    $national_id,
    $prs_id,
    $role_id
  );

  try {
    $stmt->execute();
    echo json_encode(['success' => true]);
  } catch (mysqli_sql_exception $ex) {
    http_response_code(400);
    echo json_encode([
      'success' => false,
      'error'   => $ex->getMessage()
    ]);
  }

  ob_end_flush();
  exit;
}


// ─────────────────────────────────────────────────────────────────────────────
// POST /login → authenticate
if ($method === 'POST' && ($request[0] ?? '') === 'login') {
  $data = json_decode(file_get_contents('php://input'), true);
  $stmt = $conn->prepare("
    SELECT user_id, password_hash
    FROM users
    WHERE email = ?
  ");
  $stmt->bind_param('s', $data['email']);
  $stmt->execute();
  $stmt->bind_result($user_id, $password_hash);
  $stmt->fetch();

  if (password_verify($data['password'], $password_hash)) {
      echo json_encode(['token' => createJWT($user_id)]);
  } else {
      http_response_code(401);
      echo json_encode(['error' => 'Invalid credentials']);
  }

  ob_end_flush();
  exit;
}


// ─────────────────────────────────────────────────────────────────────────────
// POST /upload  → file upload (protected)
if ($method === 'POST' && ($request[0] ?? '') === 'upload') {
  authenticate();  // from jwt_handler.php
  if (isset($_FILES['file'])) {
      $file_name = basename($_FILES['file']['name']);
      move_uploaded_file($_FILES['file']['tmp_name'], "uploads/" . $file_name);
      echo json_encode(["status" => "success", "file_path" => "uploads/$file_name"]);
  } else {
      http_response_code(400);
      echo json_encode(["error" => "No file provided"]);
  }
  ob_end_flush();
  exit;
}

// GET /vaccination_records
if ($method === 'GET' && ($request[0] ?? '') === 'vaccination_records') {
    $result = $conn->query("SELECT * FROM vaccination_records");
    echo json_encode($result->fetch_all(MYSQLI_ASSOC));
    exit;
}

// POST /vaccination_records
if ($method === 'POST' && ($request[0] ?? '') === 'vaccination_records' && !isset($request[1])) {
    $data = json_decode(file_get_contents('php://input'), true);
    $stmt = $conn->prepare(
      "INSERT INTO vaccination_records
        (user_id, vaccine_name, date_administered, dose_number, provider, lot_number, expiration_date)
      VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
      'ississs',
      $data['user_id'],
      $data['vaccine_name'],
      $data['date_administered'],
      $data['dose_number'],
      $data['provider'],
      $data['lot_number'],
      $data['expiration_date']
    );
    if ($stmt->execute()) echo json_encode(['success'=>true]);
    else { http_response_code(400); echo json_encode(['error'=>$stmt->error]); }
    exit;
}

// POST /vaccination_records/fhir
if (
    $method === 'POST'
    && ($request[0] ?? '') === 'vaccination_records'
    && ($request[1] ?? '') === 'fhir'
) {
    // Decode bundle
    $bundle = json_decode(file_get_contents('php://input'), true);
    if (empty($bundle['entry']) || !is_array($bundle['entry'])) {
        echo json_encode(['imported'=>0,'error'=>'No entries in FHIR bundle']);
        exit;
    }
    // Find Patient and map to user_id
    $user_id = null;
    foreach ($bundle['entry'] as $e) {
        $r = $e['resource'];
        if (($r['resourceType'] ?? '') === 'Patient') {
            $nhs = $r['identifier'][0]['value'] ?? '';
            if ($nhs) {
                $stmtU = $conn->prepare("SELECT user_id FROM users WHERE national_id = ? LIMIT 1");
                $stmtU->bind_param('s',$nhs);
                $stmtU->execute();
                $stmtU->bind_result($tmp);
                if ($stmtU->fetch()) $user_id = $tmp;
                $stmtU->close();
            }
            break;
        }
    }
    // Create user if not exists
    if (!$user_id) {
        $stmtNew = $conn->prepare(
          "INSERT INTO users
            (full_name, email, password_hash, phone, national_id, prs_id, role_id)
          VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $hash = password_hash('changeme',PASSWORD_DEFAULT);
        $fullname = 'FHIR Import';
        $email = 'import@fhir.local';
        $phone = null;
        $prs   = 'PRS-'.time();
        $role  = 3;
        $stmtNew->bind_param('ssssssi',$fullname,$email,$hash,$phone,$nhs,$prs,$role);
        $stmtNew->execute();
        $user_id = $stmtNew->insert_id;
        $stmtNew->close();
    }
    // Insert Immunizations
    $count=0;
    foreach ($bundle['entry'] as $e) {
        $r=$e['resource'];
        if (($r['resourceType'] ?? '')!=='Immunization') continue;
        $name = $r['vaccineCode']['coding'][0]['display'] ?? '';
        $date = $r['occurrenceDateTime'] ?? '';
        $dose = $r['protocolApplied'][0]['doseNumberPositiveInt'] ?? ($r['doseNumber'] ?? null);
        $prov = $r['performer'][0]['actor']['display'] ?? '';
        $lot  = $r['lotNumber'] ?? '';
        $exp  = $r['expirationDate'] ?? null;
        $s = $conn->prepare(
          "INSERT INTO vaccination_records
            (user_id,vaccine_name,date_administered,dose_number,provider,lot_number,expiration_date)
          VALUES(?,?,?,?,?,?,?)"
        );
        $s->bind_param('ississs',$user_id,$name,$date,$dose,$prov,$lot,$exp);
        if ($s->execute()) $count++;
        $s->close();
    }
    echo json_encode(['imported'=>$count]);
    exit;
}

// Default 404
http_response_code(404);
echo json_encode(['error'=>'Endpoint not found']);
ob_end_flush();
