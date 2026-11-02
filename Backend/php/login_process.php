<?php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Login must be submitted with POST.');
}

$email = isset($_POST['email']) && is_string($_POST['email']) ? trim($_POST['email']) : '';
$loginPassword = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $loginPassword === '') {
    header('Location: /MelodyMatch/Frontend/html/login.html?error=invalid');
    exit;
}

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/database_connection.php';
if (!$dbConnect) {
    error_log('Login database connection failed: ' . mysqli_connect_error());
    header('Location: /MelodyMatch/Frontend/html/login.html?error=unavailable');
    exit;
}

if (!mysqli_set_charset($dbConnect, 'utf8mb4')) {
    error_log('Could not set login database charset: ' . mysqli_error($dbConnect));
    header('Location: /MelodyMatch/Frontend/html/login.html?error=unavailable');
    exit;
}

$statement = mysqli_prepare(
    $dbConnect,
    'SELECT userID, password, role, status FROM users WHERE email = ? LIMIT 1'
);
if (!$statement || !mysqli_stmt_bind_param($statement, 's', $email) ||
    !mysqli_stmt_execute($statement)) {
    error_log('Could not execute login lookup: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
    if ($statement) {
        mysqli_stmt_close($statement);
    }
    header('Location: /MelodyMatch/Frontend/html/login.html?error=unavailable');
    exit;
}

$result = mysqli_stmt_get_result($statement);
$account = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($statement);
if (!$account) {
    error_log('Login rejected: no account matched the submitted email.');
    header('Location: /MelodyMatch/Frontend/html/login.html?error=invalid');
    exit;
}

if (!password_verify($loginPassword, $account['password'])) {
    error_log('Login rejected: password verification failed for the matched account.');
    header('Location: /MelodyMatch/Frontend/html/login.html?error=invalid');
    exit;
}

if (strtolower((string) $account['status']) !== 'active') {
    header('Location: /MelodyMatch/Frontend/html/login.html?error=inactive');
    exit;
}

if (!in_array(strtolower((string) $account['role']), ['listener', 'artist'], true)) {
    header('Location: /MelodyMatch/Frontend/html/login.html?error=unsupported');
    exit;
}

$role = ucfirst(strtolower((string) $account['role']));
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
    'path' => '/'
]);
session_start();
if (!session_regenerate_id(true)) {
    error_log('Could not rotate session ID during login.');
    header('Location: /MelodyMatch/Frontend/html/login.html?error=unavailable');
    exit;
}

$_SESSION['user_id'] = (int) $account['userID'];
$_SESSION['role'] = $role;
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
if ($role === 'Artist') {
    header('Location: /MelodyMatch/Frontend/html/artist/artist_homepage.html');
} else {
    header('Location: /MelodyMatch/Frontend/html/listener/listener_homepage.html');
}
exit;
