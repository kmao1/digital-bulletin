<?php
declare(strict_types=1);

require_once __DIR__ . '/announcement-audience.php';

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['action'] ?? '') === 'notification_config') {
    header('Content-Type: application/json');
    $publicKey = trim((string) getenv('FIREBASE_WEB_PUSH_PUBLIC_KEY'));
    echo json_encode(['enabled' => $publicKey !== '', 'vapidPublicKey' => $publicKey]);
    exit;
}

$requestHost = strtolower((string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
$isLocalRequest = in_array($requestHost, ['localhost', '127.0.0.1', '::1'], true);
$defaultDbHost = $isLocalRequest ? '127.0.0.1' : '';
$defaultDbName = $isLocalRequest ? 'dsb' : '';
$defaultDbUser = $isLocalRequest ? 'root' : '';

$dbHost = trim((string) (getenv('DB_HOST') ?: $defaultDbHost));
$dbName = trim((string) (getenv('DB_NAME') ?: $defaultDbName));
$dbUser = trim((string) (getenv('DB_USER') ?: $defaultDbUser));
$dbPassword = getenv('DB_PASSWORD');
$dbPassword = $dbPassword === false ? '' : $dbPassword;

if (!$isLocalRequest && ($dbHost === '' || $dbName === '' || $dbUser === '' || $dbPassword === '')) {
    http_response_code(503);
    exit('Database settings are missing. Configure DB_HOST, DB_NAME, DB_USER, and DB_PASSWORD in the hosting panel.');
}

function redirectToLogin(string $message, bool $showLogin = false): never
{
    $hash = $showLogin ? '#login-form' : '';
    header('Location: student-login.html?message=' . rawurlencode($message) . $hash);
    exit;
}

function redirectToOfficerLogin(string $message): never
{
    header('Location: officer-login.html?message=' . rawurlencode($message));
    exit;
}

$connection = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);

if ($connection->connect_errno) {
    http_response_code(500);
    exit('Database connection failed. Check the MySQL settings in conn.php.');
}

$connection->set_charset('utf8mb4');

$connection->query(
    'CREATE TABLE IF NOT EXISTS admin (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$initialAdminEmail = trim((string) getenv('INITIAL_ADMIN_EMAIL'));
$initialAdminPassword = (string) getenv('INITIAL_ADMIN_PASSWORD');
if ($initialAdminEmail !== '' && $initialAdminPassword !== '') {
    $adminCheck = $connection->prepare('SELECT id FROM admin WHERE email = ? LIMIT 1');
    $adminCheck->bind_param('s', $initialAdminEmail);
    $adminCheck->execute();
    $adminExists = $adminCheck->get_result()->num_rows > 0;
    $adminCheck->close();

    if (!$adminExists) {
        $initialAdminName = trim((string) getenv('INITIAL_ADMIN_NAME')) ?: 'Administrator';
        $initialAdminHash = password_hash($initialAdminPassword, PASSWORD_DEFAULT);
        $adminInsert = $connection->prepare(
            'INSERT INTO admin (full_name, email, password) VALUES (?, ?, ?)'
        );
        $adminInsert->bind_param('sss', $initialAdminName, $initialAdminEmail, $initialAdminHash);
        $adminInsert->execute();
        $adminInsert->close();
    }
}

$sectionColumn = $connection->query("SHOW COLUMNS FROM student LIKE 'section'");
if ($sectionColumn && $sectionColumn->num_rows === 0) {
    $connection->query(
        "ALTER TABLE student ADD COLUMN section VARCHAR(60) NOT NULL DEFAULT '' AFTER year_section"
    );
}

$connection->query(
    'CREATE TABLE IF NOT EXISTS officer (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$connection->query(
    'CREATE TABLE IF NOT EXISTS officer_login_records (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        officer_id INT UNSIGNED NOT NULL,
        officer_name VARCHAR(120) NOT NULL,
        officer_email VARCHAR(190) NOT NULL,
        login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (officer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$connection->query(
    'CREATE TABLE IF NOT EXISTS direct_messages (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        student_id INT UNSIGNED NOT NULL,
        subject VARCHAR(180) NOT NULL,
        message TEXT NOT NULL,
        sent_by VARCHAR(190) NOT NULL,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        INDEX (student_id),
        INDEX (sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$connection->query(
    'CREATE TABLE IF NOT EXISTS student_device_tokens (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        student_id INT UNSIGNED NOT NULL,
        token VARCHAR(4096) NOT NULL,
        token_hash CHAR(64) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$connection->query(
    'CREATE TABLE IF NOT EXISTS student_device_tokens (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        student_id INT UNSIGNED NOT NULL,
        token VARCHAR(4096) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$connection->query(
    'CREATE TABLE IF NOT EXISTS hallway_bulletin_config (
        id TINYINT UNSIGNED PRIMARY KEY,
        config_json LONGTEXT NOT NULL,
        updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$defaultHallwayConfig = json_encode([
    'announcements' => [
        [
            'id' => 'welcome-1', 'category' => 'URGENT', 'title' => 'MIDTERM EXAMINATION SCHEDULE & CLEARANCE',
            'description' => 'Official examination schedules are available. Secure your examination permit from the ACCESS Office before exam week.',
            'location' => 'ACCESS Office, IS Department', 'deadline' => 'OCT 12, 5:00 PM', 'posted' => 'LIVE NOW',
            'bgImage' => 'main bg.jpg', 'icon' => 'fa-graduation-cap', 'badgeIcon' => 'fa-triangle-exclamation'
        ],
        [
            'id' => 'welcome-2', 'category' => 'EVENTS', 'title' => 'FILM FESTIVAL 2026',
            'description' => 'Review the official schedule for Film Festival 2026 and join us for a week of student films.',
            'location' => 'IS Department', 'deadline' => 'FRI, 6:00 PM', 'posted' => 'TODAY',
            'bgImage' => 'main bg.jpg', 'icon' => 'fa-film', 'badgeIcon' => 'fa-masks-theater'
        ],
        [
            'id' => 'welcome-3', 'category' => 'ACADEMIC', 'title' => 'DEPARTMENT SHIRT: SECOND BATCH',
            'description' => 'The second ordering batch for ACT and BSIS department shirts opens Monday. Visit the ACCESS Office for details.',
            'location' => 'ACCESS Office, IS Building', 'deadline' => 'OCT 5, 5:00 PM', 'posted' => 'TODAY',
            'bgImage' => 'main bg.jpg', 'icon' => 'fa-shirt', 'badgeIcon' => 'fa-graduation-cap'
        ]
    ]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$seedStatement = $connection->prepare(
    'INSERT IGNORE INTO hallway_bulletin_config (id, config_json) VALUES (1, ?)'
);
$seedStatement->bind_param('s', $defaultHallwayConfig);
$seedStatement->execute();
$seedStatement->close();

$connection->query(
    'CREATE TABLE IF NOT EXISTS bulletin_announcements (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(180) NOT NULL,
        category VARCHAR(24) NOT NULL,
        audience_type VARCHAR(16) NOT NULL DEFAULT \'all\',
        audience_value VARCHAR(120) NOT NULL DEFAULT \'\',
        audience_filters TEXT NULL,
        message TEXT NOT NULL,
        created_by VARCHAR(190) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (created_at),
        INDEX (audience_type, audience_value)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

// Additive migration: preserve all existing announcements and legacy audiences.
$audienceFiltersColumn = $connection->query("SHOW COLUMNS FROM bulletin_announcements LIKE 'audience_filters'");
if ($audienceFiltersColumn && $audienceFiltersColumn->num_rows === 0) {
    $connection->query('ALTER TABLE bulletin_announcements ADD COLUMN audience_filters TEXT NULL AFTER audience_value');
}

$initialOfficerEmail = trim((string) getenv('INITIAL_OFFICER_EMAIL'));
$initialOfficerPassword = (string) getenv('INITIAL_OFFICER_PASSWORD');
if ($initialOfficerEmail !== '' && $initialOfficerPassword !== '') {
    $officerCheck = $connection->prepare('SELECT id FROM officer WHERE email = ? LIMIT 1');
    $officerCheck->bind_param('s', $initialOfficerEmail);
    $officerCheck->execute();
    $officerExists = $officerCheck->get_result()->num_rows > 0;
    $officerCheck->close();

    if (!$officerExists) {
        $initialOfficerName = trim((string) getenv('INITIAL_OFFICER_NAME')) ?: 'Department Officer';
        $initialOfficerHash = password_hash($initialOfficerPassword, PASSWORD_DEFAULT);
        $officerInsert = $connection->prepare(
            'INSERT INTO officer (full_name, email, password) VALUES (?, ?, ?)'
        );
        $officerInsert->bind_param('sss', $initialOfficerName, $initialOfficerEmail, $initialOfficerHash);
        $officerInsert->execute();
        $officerInsert->close();
    }
}

function firebaseServiceAccount(): ?array
{
    $serviceAccountPath = (string) getenv('FIREBASE_SERVICE_ACCOUNT_PATH');
    if ($serviceAccountPath === '' || !is_file($serviceAccountPath)) {
        return null;
    }

    $serviceAccount = json_decode((string) file_get_contents($serviceAccountPath), true);
    if (!is_array($serviceAccount) || empty($serviceAccount['client_email']) ||
        empty($serviceAccount['private_key']) || empty($serviceAccount['project_id'])) {
        return null;
    }
    return $serviceAccount;
}

function firebaseAccessToken(): ?string
{
    if (!extension_loaded('curl') || !extension_loaded('openssl')) {
        return null;
    }
    $serviceAccount = firebaseServiceAccount();
    if (!$serviceAccount) return null;
    $base64Url = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    $issuedAt = time();
    $jwtHeader = $base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $jwtClaims = $base64Url(json_encode([
        'iss' => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.database https://www.googleapis.com/auth/firebase.messaging https://www.googleapis.com/auth/userinfo.email',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $issuedAt,
        'exp' => $issuedAt + 3600
    ]));
    $unsignedJwt = $jwtHeader . '.' . $jwtClaims;
    if (!openssl_sign($unsignedJwt, $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256)) {
        return null;
    }

    $tokenRequest = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($tokenRequest, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsignedJwt . '.' . $base64Url($signature)
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8
    ]);
    $tokenResponse = curl_exec($tokenRequest);
    $tokenStatus = (int) curl_getinfo($tokenRequest, CURLINFO_RESPONSE_CODE);
    curl_close($tokenRequest);
    $tokenData = is_string($tokenResponse) ? json_decode($tokenResponse, true) : null;
    if ($tokenStatus !== 200 || empty($tokenData['access_token'])) {
        return null;
    }
    return $tokenData['access_token'];
}

function firebaseRealtimeSignal(string $signalType): bool
{
    $databaseUrl = rtrim((string) getenv('FIREBASE_DATABASE_URL'), '/');
    $accessToken = firebaseAccessToken();
    if ($databaseUrl === '' || !$accessToken) return false;

    $signal = [
        'version' => bin2hex(random_bytes(12)),
        'updatedAt' => gmdate(DATE_ATOM)
    ];
    $signalUrl = $databaseUrl . '/notificationSignals/' . rawurlencode($signalType) . '.json';
    $signalRequest = curl_init($signalUrl);
    curl_setopt_array($signalRequest, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => json_encode($signal),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8
    ]);
    curl_exec($signalRequest);
    $signalStatus = (int) curl_getinfo($signalRequest, CURLINFO_RESPONSE_CODE);
    curl_close($signalRequest);
    return $signalStatus >= 200 && $signalStatus < 300;
}

function firebaseSendPush(string $deviceToken, string $title, string $body, array $data = []): array
{
    $serviceAccount = firebaseServiceAccount();
    $accessToken = firebaseAccessToken();
    if (!$serviceAccount || !$accessToken || !extension_loaded('curl')) {
        return ['sent' => false, 'invalidToken' => false];
    }

    $messageData = [];
    foreach ($data as $key => $value) {
        $messageData[(string) $key] = (string) $value;
    }
    $messageData['title'] = $title;
    $messageData['body'] = $body;
    $messageData['icon'] = 'bcc.jpg';
    $message = [
        'message' => [
            'token' => $deviceToken,
            'data' => $messageData,
            'webpush' => ['headers' => ['Urgency' => 'high']]
        ]
    ];
    $request = curl_init(
        'https://fcm.googleapis.com/v1/projects/' . rawurlencode($serviceAccount['project_id']) . '/messages:send'
    );
    curl_setopt_array($request, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($message),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8
    ]);
    $responseBody = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    curl_close($request);
    $responseData = is_string($responseBody) ? json_decode($responseBody, true) : null;
    $errorCode = $responseData['error']['details'][0]['errorCode'] ?? '';
    return [
        'sent' => $status >= 200 && $status < 300,
        'invalidToken' => $status === 404 || $errorCode === 'UNREGISTERED'
    ];
}

function firebaseSendStudentPush(int $studentId, string $title, string $body, array $data = []): int
{
    $statement = $GLOBALS['connection']->prepare(
        'SELECT token FROM student_device_tokens WHERE student_id = ?'
    );
    $statement->bind_param('i', $studentId);
    $statement->execute();
    $result = $statement->get_result();
    $sentCount = 0;
    $deleteToken = $GLOBALS['connection']->prepare(
        'DELETE FROM student_device_tokens WHERE token_hash = ?'
    );
    while ($device = $result->fetch_assoc()) {
        $response = firebaseSendPush($device['token'], $title, $body, $data);
        if ($response['sent']) $sentCount++;
        if ($response['invalidToken']) {
            $tokenHash = hash('sha256', $device['token']);
            $deleteToken->bind_param('s', $tokenHash);
            $deleteToken->execute();
        }
    }
    $deleteToken->close();
    $statement->close();
    return $sentCount;
}

function firebaseSendAudiencePush(
    string $audienceType,
    string $audienceValue,
    string $title,
    string $body,
    array $data = [],
    array $audienceFilters = []
): int {
    $result = $GLOBALS['connection']->query(
        'SELECT DISTINCT student.id, student.course, student.year_section, student.section
         FROM student INNER JOIN student_device_tokens AS tokens ON tokens.student_id = student.id'
    );
    $audience = [
        'audience_type' => $audienceType,
        'audience_value' => $audienceValue,
        'audience_filters' => $audienceFilters
    ];
    $sentCount = 0;
    while ($student = $result->fetch_assoc()) {
        if (announcementMatchesStudent($audience, $student)) {
            $sentCount += firebaseSendStudentPush((int) $student['id'], $title, $body, $data);
        }
    }
    return $sentCount;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'student_session') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['student_id'])) {
        http_response_code(401);
        echo json_encode(['authenticated' => false]);
        exit;
    }

    $statement = $connection->prepare('SELECT full_name, email FROM student WHERE id = ? LIMIT 1');
    $statement->bind_param('i', $_SESSION['student_id']);
    $statement->execute();
    $student = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!$student) {
        http_response_code(401);
        echo json_encode(['authenticated' => false]);
        exit;
    }

    echo json_encode([
        'authenticated' => true,
        'name' => $student['full_name'],
        'email' => $student['email']
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'officer_session') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['officer_id'])) {
        http_response_code(401);
        echo json_encode(['authenticated' => false]);
        exit;
    }

    $statement = $connection->prepare(
        'SELECT full_name, email FROM officer WHERE id = ? LIMIT 1'
    );
    $officerId = (int) $_SESSION['officer_id'];
    $statement->bind_param('i', $officerId);
    $statement->execute();
    $officer = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!$officer) {
        http_response_code(401);
        echo json_encode(['authenticated' => false]);
        exit;
    }

    echo json_encode([
        'authenticated' => true,
        'name' => $officer['full_name'],
        'email' => $officer['email']
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'admin_session') {
    header('Content-Type: application/json');
    if (!isset($_SESSION['admin_authenticated'], $_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['authenticated' => false]);
        exit;
    }

    $adminId = (int) $_SESSION['admin_id'];
    $statement = $connection->prepare('SELECT full_name, email FROM admin WHERE id = ? LIMIT 1');
    $statement->bind_param('i', $adminId);
    $statement->execute();
    $admin = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!$admin) {
        session_unset();
        session_destroy();
        http_response_code(401);
        echo json_encode(['authenticated' => false]);
        exit;
    }

    echo json_encode(['authenticated' => true, 'name' => $admin['full_name'], 'email' => $admin['email']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'admin_students') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['admin_authenticated'])) {
        http_response_code(401);
        echo json_encode(['authenticated' => false]);
        exit;
    }

    $students = [];
    $result = $connection->query(
        'SELECT id, full_name, email, student_id, course, year_section, section
         FROM student ORDER BY full_name ASC'
    );

    while ($student = $result->fetch_assoc()) {
        $students[] = $student;
    }

    echo json_encode(['authenticated' => true, 'students' => $students]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'student_messages') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['student_id'])) {
        http_response_code(401);
        echo json_encode(['authenticated' => false]);
        exit;
    }

    $studentId = (int) $_SESSION['student_id'];
    $statement = $connection->prepare(
        'SELECT id, subject, message, sent_by, sent_at, is_read
         FROM direct_messages WHERE student_id = ? ORDER BY sent_at DESC'
    );
    $statement->bind_param('i', $studentId);
    $statement->execute();
    $result = $statement->get_result();
    $messages = [];

    while ($message = $result->fetch_assoc()) {
        $messages[] = $message;
    }

    $statement->close();
    echo json_encode(['authenticated' => true, 'messages' => $messages]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'hallway_bulletin') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $result = $connection->query(
        'SELECT config_json, updated_at FROM hallway_bulletin_config WHERE id = 1 LIMIT 1'
    );
    $config = $result ? $result->fetch_assoc() : null;
    echo json_encode([
        'config' => $config ? json_decode($config['config_json'], true) : null,
        'updatedAt' => $config['updated_at'] ?? null
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'bulletin_announcements') {
    header('Content-Type: application/json');
    $isStudent = isset($_SESSION['student_id']);
    $isOfficer = isset($_SESSION['officer_id']);
    $isAdmin = isset($_SESSION['admin_authenticated']);

    if (!$isStudent && !$isOfficer && !$isAdmin) {
        http_response_code(401);
        echo json_encode(['error' => 'Sign in to view announcements.']);
        exit;
    }

    $result = $connection->query(
        'SELECT id, title, category, audience_type, audience_value, audience_filters, message, created_by, created_at
         FROM bulletin_announcements ORDER BY created_at DESC, id DESC'
    );
    $announcements = [];
    $student = null;

    if ($isStudent) {
        $studentId = (int) $_SESSION['student_id'];
        $studentStatement = $connection->prepare(
            'SELECT id, course, year_section, section FROM student WHERE id = ? LIMIT 1'
        );
        $studentStatement->bind_param('i', $studentId);
        $studentStatement->execute();
        $student = $studentStatement->get_result()->fetch_assoc();
        $studentStatement->close();
    }

    while ($announcement = $result->fetch_assoc()) {
        if ($isStudent && (!$student || !announcementMatchesStudent($announcement, $student))) continue;
        $announcement['source'] = 'portal';
        $announcements[] = $announcement;
    }

    // Read public hallway notices directly: edits/removals cannot drift from the portal.
    // Officers keep their existing editable, numeric-ID portal feed.
    if (($isStudent && $student) || $isAdmin) {
        $boardResult = $connection->query('SELECT config_json, updated_at FROM hallway_bulletin_config WHERE id = 1 LIMIT 1');
        $board = $boardResult->fetch_assoc();
        $boardConfig = $board ? json_decode($board['config_json'], true) : [];
        foreach (($boardConfig['announcements'] ?? []) as $slide) {
            $announcements[] = [
                'id' => 'hallway:' . $slide['id'], 'source' => 'hallway',
                'title' => $slide['title'], 'category' => $slide['category'],
                'audience_type' => 'all', 'audience_value' => '', 'audience_filters' => null,
                'message' => $slide['description'], 'created_by' => 'Hallway display',
                'created_at' => $board['updated_at'],
                'location' => $slide['location'] ?? '', 'deadline' => $slide['deadline'] ?? ''
            ];
        }
        usort($announcements, static function (array $left, array $right): int {
            $dateOrder = strcmp($right['created_at'], $left['created_at']);
            return $dateOrder ?: strnatcmp((string) $right['id'], (string) $left['id']);
        });
    }
    header('Cache-Control: no-store');
    echo json_encode(['announcements' => $announcements]);
    exit;
}

if ($action === 'logout') {
    $logoutPage = isset($_SESSION['officer_id'])
        ? 'officer-login.html'
        : (isset($_SESSION['admin_authenticated']) ? 'admin-login.html' : 'student-login.html');
    session_unset();
    session_destroy();
    header('Location: ' . $logoutPage);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectToLogin('Invalid request.');
}

if ($action === 'register_student_device' || $action === 'remove_student_device') {
    header('Content-Type: application/json');
    if (empty($_SESSION['student_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Student sign-in required.']);
        exit;
    }

    $request = json_decode(file_get_contents('php://input'), true);
    $deviceToken = trim((string) ($request['token'] ?? ''));
    if (strlen($deviceToken) < 40 || strlen($deviceToken) > 4096 || preg_match('/[\r\n]/', $deviceToken)) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid notification device token.']);
        exit;
    }

    $studentId = (int) $_SESSION['student_id'];
    $tokenHash = hash('sha256', $deviceToken);
    if ($action === 'register_student_device') {
        $statement = $connection->prepare(
            'INSERT INTO student_device_tokens (student_id, token, token_hash) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE student_id = VALUES(student_id), token = VALUES(token), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->bind_param('iss', $studentId, $deviceToken, $tokenHash);
    } else {
        $statement = $connection->prepare(
            'DELETE FROM student_device_tokens WHERE student_id = ? AND token_hash = ?'
        );
        $statement->bind_param('is', $studentId, $tokenHash);
    }

    if (!$statement->execute()) {
        http_response_code(500);
        echo json_encode(['error' => 'Notification settings could not be saved.']);
        $statement->close();
        exit;
    }
    $statement->close();
    echo json_encode(['saved' => true]);
    exit;
}

if ($action === 'publish_announcement' || $action === 'update_announcement') {
    header('Content-Type: application/json');
    $isAdmin = isset($_SESSION['admin_authenticated']);
    $isOfficer = isset($_SESSION['officer_id']);

    if (!$isAdmin && !$isOfficer) {
        http_response_code(401);
        echo json_encode(['error' => 'Administrator or officer sign-in required.']);
        exit;
    }

    $request = json_decode(file_get_contents('php://input'), true);
    $title = trim((string) ($request['title'] ?? ''));
    $category = strtoupper(trim((string) ($request['category'] ?? '')));
    $message = trim((string) ($request['message'] ?? ''));
    $allowedCategories = ['GENERAL', 'URGENT', 'ACADEMIC', 'EVENTS', 'FACILITIES', 'ATHLETICS'];

    if (!is_array($request) || $title === '' || $message === '' || mb_strlen($title) > 180 || mb_strlen($message) > 5000 ||
        !in_array($category, $allowedCategories, true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Complete the title, message, category, and audience fields.']);
        exit;
    }
    try {
        $audience = normalizeAnnouncementAudience($request);
    } catch (InvalidArgumentException $error) {
        http_response_code(422);
        echo json_encode(['error' => $error->getMessage()]);
        exit;
    }
    $audienceType = $audience['audienceType'];
    $audienceValue = $audience['audienceValue'];
    $audienceFilters = $audience['filters'];
    $audienceFiltersJson = $audienceFilters ? json_encode($audienceFilters, JSON_UNESCAPED_UNICODE) : null;
    if ($audienceType === 'student' || $audienceType === 'group') {
        $students = $connection->query('SELECT id, course, year_section, section FROM student');
        $hasRecipient = false;
        while ($recipient = $students->fetch_assoc()) {
            if (announcementMatchesStudent([
                'audience_type' => $audienceType, 'audience_value' => $audienceValue,
                'audience_filters' => $audienceFilters
            ], $recipient)) {
                $hasRecipient = true;
                break;
            }
        }
        if (!$hasRecipient) {
            http_response_code(422);
            echo json_encode(['error' => 'No registered students match this audience. Choose another recipient or filter.']);
            exit;
        }
    }

    $createdBy = $isAdmin ? ($_SESSION['admin_email'] ?? 'Administrator') : ($_SESSION['officer_email'] ?? 'Department officer');
    $announcementId = filter_var($request['id'] ?? '', FILTER_VALIDATE_INT);
    if ($action === 'update_announcement' && $announcementId) {
        if ($isAdmin) {
            $statement = $connection->prepare(
                'UPDATE bulletin_announcements SET title = ?, category = ?, audience_type = ?, audience_value = ?, audience_filters = ?, message = ? WHERE id = ?'
            );
            $statement->bind_param('ssssssi', $title, $category, $audienceType, $audienceValue, $audienceFiltersJson, $message, $announcementId);
        } else {
            $statement = $connection->prepare(
                'UPDATE bulletin_announcements SET title = ?, category = ?, audience_type = ?, audience_value = ?, audience_filters = ?, message = ? WHERE id = ? AND created_by = ?'
            );
            $statement->bind_param('ssssssis', $title, $category, $audienceType, $audienceValue, $audienceFiltersJson, $message, $announcementId, $createdBy);
        }
    } elseif ($action === 'publish_announcement') {
        $statement = $connection->prepare(
            'INSERT INTO bulletin_announcements (title, category, audience_type, audience_value, audience_filters, message, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->bind_param('sssssss', $title, $category, $audienceType, $audienceValue, $audienceFiltersJson, $message, $createdBy);
    } else {
        http_response_code(422);
        echo json_encode(['error' => 'Choose an announcement to update.']);
        exit;
    }

    if (!$statement->execute()) {
        http_response_code(500);
        echo json_encode(['error' => 'The announcement could not be published.']);
        $statement->close();
        exit;
    }

    if ($action === 'update_announcement' && $statement->affected_rows !== 1) {
        if ($isAdmin) {
            $checkStatement = $connection->prepare('SELECT id FROM bulletin_announcements WHERE id = ? LIMIT 1');
            $checkStatement->bind_param('i', $announcementId);
        } else {
            $checkStatement = $connection->prepare(
                'SELECT id FROM bulletin_announcements WHERE id = ? AND created_by = ? LIMIT 1'
            );
            $checkStatement->bind_param('is', $announcementId, $createdBy);
        }
        $checkStatement->execute();
        $announcementExists = $checkStatement->get_result()->num_rows === 1;
        $checkStatement->close();
        if (!$announcementExists) {
            http_response_code(404);
            echo json_encode(['error' => 'The announcement could not be found or edited.']);
            $statement->close();
            exit;
        }
    }
    $publishedId = $action === 'publish_announcement' ? $statement->insert_id : $announcementId;
    // Release the session lock so live clients can read the saved notice during delivery.
    session_write_close();
    $realtimeUpdated = firebaseRealtimeSignal('announcements');
    $pushSent = firebaseSendAudiencePush(
        $audienceType,
        $audienceValue,
        $title,
        'A campus notice is available. Open the portal to read it.',
        ['type' => 'announcement', 'url' => 'student-dashboard.html', 'announcementId' => (string) $publishedId],
        $audienceFilters
    );
    echo json_encode([
        'published' => true,
        'id' => $publishedId,
        'realtime' => $realtimeUpdated,
        'pushSent' => $pushSent
    ]);
    $statement->close();
    exit;
}

if ($action === 'delete_announcement') {
    header('Content-Type: application/json');
    $isAdmin = isset($_SESSION['admin_authenticated']);
    $isOfficer = isset($_SESSION['officer_id']);
    $request = json_decode(file_get_contents('php://input'), true);
    $announcementId = filter_var($request['id'] ?? '', FILTER_VALIDATE_INT);

    if ((!$isAdmin && !$isOfficer) || !$announcementId) {
        http_response_code($isAdmin || $isOfficer ? 422 : 401);
        echo json_encode(['error' => 'Unable to remove this announcement.']);
        exit;
    }

    $createdBy = $isAdmin ? ($_SESSION['admin_email'] ?? 'Administrator') : ($_SESSION['officer_email'] ?? 'Department officer');
    if ($isAdmin) {
        $statement = $connection->prepare('DELETE FROM bulletin_announcements WHERE id = ?');
        $statement->bind_param('i', $announcementId);
    } else {
        $statement = $connection->prepare('DELETE FROM bulletin_announcements WHERE id = ? AND created_by = ?');
        $statement->bind_param('is', $announcementId, $createdBy);
    }
    $statement->execute();
    $removed = $statement->affected_rows === 1;
    $statement->close();

    if (!$removed) {
        http_response_code(404);
        echo json_encode(['error' => 'The announcement could not be found or removed.']);
        exit;
    }
    $realtimeUpdated = firebaseRealtimeSignal('announcements');
    echo json_encode(['deleted' => true, 'realtime' => $realtimeUpdated]);
    exit;
}

if ($action === 'admin_save_hallway_bulletin') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['admin_authenticated'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Administrator sign-in required.']);
        exit;
    }

    $request = json_decode(file_get_contents('php://input'), true);
    $announcements = $request['announcements'] ?? null;
    $allowedCategories = ['URGENT', 'ACADEMIC', 'ATHLETICS', 'EVENTS'];

    if (!is_array($announcements) || count($announcements) > 20) {
        http_response_code(422);
        echo json_encode(['error' => 'Provide up to 20 hallway announcements.']);
        exit;
    }

    $cleanAnnouncements = [];
    foreach ($announcements as $announcement) {
        if (!is_array($announcement)) {
            http_response_code(422);
            echo json_encode(['error' => 'An announcement has invalid data.']);
            exit;
        }

        $title = trim((string) ($announcement['title'] ?? ''));
        $description = trim((string) ($announcement['description'] ?? ''));
        $category = strtoupper(trim((string) ($announcement['category'] ?? '')));

        if ($title === '' || $description === '' || !in_array($category, $allowedCategories, true) ||
            mb_strlen($title) > 180 || mb_strlen($description) > 2000) {
            http_response_code(422);
            echo json_encode(['error' => 'Each slide needs a valid category, title, and description.']);
            exit;
        }

        $icon = (string) ($announcement['icon'] ?? 'fa-bullhorn');
        $badgeIcon = (string) ($announcement['badgeIcon'] ?? 'fa-bullhorn');
        $bgImage = trim((string) ($announcement['bgImage'] ?? ''));
        if (!preg_match('/^[a-z0-9-]{1,48}$/i', $icon) ||
            !preg_match('/^[a-z0-9-]{1,48}$/i', $badgeIcon) ||
            ($bgImage !== '' && !preg_match('/^(https?:\/\/|\/|[a-z0-9_.-])[^\r\n]*$/i', $bgImage))) {
            http_response_code(422);
            echo json_encode(['error' => 'Use a valid image path and icon name.']);
            exit;
        }

        $cleanAnnouncements[] = [
            'id' => (string) ($announcement['id'] ?? uniqid('slide-', true)),
            'category' => $category,
            'title' => $title,
            'description' => $description,
            'location' => trim((string) ($announcement['location'] ?? '')),
            'deadline' => trim((string) ($announcement['deadline'] ?? '')),
            'posted' => trim((string) ($announcement['posted'] ?? 'JUST POSTED')),
            'bgImage' => $bgImage,
            'icon' => $icon,
            'badgeIcon' => $badgeIcon
        ];
    }

    $configJson = json_encode(['announcements' => $cleanAnnouncements], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $statement = $connection->prepare(
        'INSERT INTO hallway_bulletin_config (id, config_json) VALUES (1, ?)
         ON DUPLICATE KEY UPDATE config_json = VALUES(config_json)'
    );
    $statement->bind_param('s', $configJson);

    if (!$statement->execute()) {
        http_response_code(500);
        echo json_encode(['error' => 'The hallway bulletin could not be saved.']);
        $statement->close();
        exit;
    }

    $statement->close();
    session_write_close();
    $hallwayRealtime = firebaseRealtimeSignal('hallway');
    $announcementRealtime = firebaseRealtimeSignal('announcements');
    echo json_encode([
        'saved' => true, 'announcements' => $cleanAnnouncements,
        'realtime' => $hallwayRealtime && $announcementRealtime
    ]);
    exit;
}

if ($action === 'admin_delete_student') {
    if (!isset($_SESSION['admin_authenticated'])) {
        header('Location: admin-login.html?message=' . rawurlencode('Please sign in as administrator.'));
        exit;
    }

    $studentId = filter_var($_POST['student_id'] ?? '', FILTER_VALIDATE_INT);

    if (!$studentId) {
        header('Location: admin-dashboard.html?message=' . rawurlencode('Invalid student selected.'));
        exit;
    }

    $connection->begin_transaction();
    $messageStatement = $connection->prepare('DELETE FROM direct_messages WHERE student_id = ?');
    $messageStatement->bind_param('i', $studentId);
    $messagesDeleted = $messageStatement->execute();
    $messageStatement->close();

    $deviceStatement = $connection->prepare('DELETE FROM student_device_tokens WHERE student_id = ?');
    $deviceStatement->bind_param('i', $studentId);
    $devicesDeleted = $deviceStatement->execute();
    $deviceStatement->close();

    $studentStatement = $connection->prepare('DELETE FROM student WHERE id = ?');
    $studentStatement->bind_param('i', $studentId);
    $studentDeleted = $studentStatement->execute() && $studentStatement->affected_rows === 1;
    $studentStatement->close();

    if ($messagesDeleted && $devicesDeleted && $studentDeleted) {
        $connection->commit();
        header('Location: admin-dashboard.html?message=' . rawurlencode('Student removed successfully.'));
        exit;
    }

    $connection->rollback();
    header('Location: admin-dashboard.html?message=' . rawurlencode('The student could not be removed.'));
    exit;
}

if ($action === 'admin_send_message') {
    if (!isset($_SESSION['admin_authenticated'])) {
        header('Location: admin-login.html?message=' . rawurlencode('Please sign in as administrator.'));
        exit;
    }

    $studentId = filter_var($_POST['student_id'] ?? '', FILTER_VALIDATE_INT);
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!$studentId || $subject === '' || $message === '') {
        header('Location: admin-dashboard.html?message=' . rawurlencode('Choose a student and complete the message.'));
        exit;
    }

    $statement = $connection->prepare(
        'INSERT INTO direct_messages (student_id, subject, message, sent_by)
         SELECT id, ?, ?, ? FROM student WHERE id = ?'
    );
    $sentBy = $_SESSION['admin_email'] ?? 'Administrator';
    $statement->bind_param('sssi', $subject, $message, $sentBy, $studentId);

    if (!$statement->execute() || $statement->affected_rows !== 1) {
        $statement->close();
        header('Location: admin-dashboard.html?message=' . rawurlencode('The message could not be sent.'));
        exit;
    }

    $statement->close();
    firebaseRealtimeSignal('messages');
    firebaseSendStudentPush(
        $studentId,
        'New private message',
        'You have a new message from the administration.',
        ['type' => 'message', 'url' => 'student-dashboard.html', 'view' => 'messages-view']
    );
    header('Location: admin-dashboard.html?message=' . rawurlencode('Message sent successfully.'));
    exit;
}

if ($action === 'register') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $studentId = trim($_POST['student_id'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $yearSection = trim($_POST['year_section'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $studentId === '' ||
        $course === '' || $yearSection === '' || $section === '' || strlen($password) < 6) {
        redirectToLogin('Please complete all fields and use a password with at least 6 characters.');
    }

    if ($password !== $confirmPassword) {
        redirectToLogin('Passwords do not match.');
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $statement = $connection->prepare(
           'INSERT INTO student (full_name, email, student_id, course, year_section, section, password)
            VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
        $statement->bind_param('sssssss', $fullName, $email, $studentId, $course, $yearSection, $section, $hashedPassword);

    if (!$statement->execute()) {
        if ($connection->errno === 1062) {
            redirectToLogin('That email or student ID is already registered.');
        }

        http_response_code(500);
        exit('Registration failed.');
    }

    $statement->close();

    redirectToLogin('Account created successfully. Please sign in.', true);
}

if ($action === 'login') {
    $studentId = trim($_POST['student_id'] ?? '');
    $password = $_POST['password'] ?? '';

    $statement = $connection->prepare('SELECT id, full_name, password FROM student WHERE student_id = ? LIMIT 1');
    $statement->bind_param('s', $studentId);
    $statement->execute();
    $student = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!$student || !password_verify($password, $student['password'])) {
        redirectToLogin('Invalid student ID or password.', true);
    }

    session_regenerate_id(true);
    unset($_SESSION['officer_id'], $_SESSION['officer_name'], $_SESSION['officer_email'], $_SESSION['admin_authenticated'], $_SESSION['admin_email']);
    $_SESSION['student_id'] = (int) $student['id'];
    $_SESSION['student_name'] = $student['full_name'];
    header('Location: student-dashboard.html');
    exit;
}

if ($action === 'officer_login') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($fullName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        redirectToOfficerLogin('Please enter the officer name, email, and password.');
    }

    $statement = $connection->prepare(
        'SELECT id, full_name, email, password FROM officer WHERE email = ? LIMIT 1'
    );
    $statement->bind_param('s', $email);
    $statement->execute();
    $officer = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!$officer || !password_verify($password, $officer['password'])) {
        redirectToOfficerLogin('Invalid officer email or password.');
    }

    $nameStatement = $connection->prepare(
        'UPDATE officer SET full_name = ? WHERE id = ?'
    );
    $officerId = (int) $officer['id'];
    $nameStatement->bind_param('si', $fullName, $officerId);

    if (!$nameStatement->execute()) {
        $nameStatement->close();
        redirectToOfficerLogin('The officer name could not be saved.');
    }

    $nameStatement->close();

    $recordStatement = $connection->prepare(
        'INSERT INTO officer_login_records (officer_id, officer_name, officer_email)
         VALUES (?, ?, ?)'
    );
    $recordStatement->bind_param('iss', $officerId, $fullName, $officer['email']);

    if (!$recordStatement->execute()) {
        $recordStatement->close();
        redirectToOfficerLogin('The officer login could not be recorded.');
    }

    $recordStatement->close();

    session_regenerate_id(true);
    unset($_SESSION['student_id'], $_SESSION['student_name'], $_SESSION['admin_authenticated'], $_SESSION['admin_email']);
    $_SESSION['officer_id'] = $officerId;
    $_SESSION['officer_name'] = $fullName;
    $_SESSION['officer_email'] = $officer['email'];
    header('Location: officer-dashboard.html');
    exit;
}

if ($action === 'admin_login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $statement = $connection->prepare(
        'SELECT id, full_name, email, password FROM admin WHERE email = ? LIMIT 1'
    );
    $statement->bind_param('s', $email);
    $statement->execute();
    $admin = $statement->get_result()->fetch_assoc();
    $statement->close();

    if (!$admin || !password_verify($password, $admin['password'])) {
        header('Location: admin-login.html?message=' . rawurlencode('Invalid administrator credentials.'));
        exit;
    }

    session_regenerate_id(true);
    unset($_SESSION['student_id'], $_SESSION['student_name'], $_SESSION['officer_id'], $_SESSION['officer_name'], $_SESSION['officer_email']);
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_name'] = $admin['full_name'];
    $_SESSION['admin_email'] = $admin['email'];
    header('Location: admin-dashboard.html');
    exit;
}

redirectToLogin('Unknown request.');
?>