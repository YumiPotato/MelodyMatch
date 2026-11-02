<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
ini_set('display_errors', '0');
mysqli_report(MYSQLI_REPORT_OFF);

function respond($statusCode, $success, $message)
{
    http_response_code($statusCode);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function submittedValue($name)
{
    return isset($_POST[$name]) && is_string($_POST[$name]) ? trim($_POST[$name]) : '';
}

function characterLength($value)
{
    $length = preg_match_all('/./us', $value, $matches);
    return $length === false ? PHP_INT_MAX : $length;
}

session_start();
$role = isset($_SESSION['role']) && is_string($_SESSION['role']) ? $_SESSION['role'] : '';
if (empty($_SESSION['user_id']) || $role !== 'Artist') {
    respond(401, false, 'Sign in with an artist account to update these settings.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, false, 'Account settings must be submitted with POST.');
}

$csrfToken = submittedValue('csrf_token');
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    respond(403, false, 'Your session expired. Reload the page and try again.');
}

require_once __DIR__ . '/database_connection.php';
if (!$dbConnect) {
    error_log('Artist account update database connection failed: ' . mysqli_connect_error());
    respond(500, false, 'Your account could not be updated right now.');
}

if (!mysqli_set_charset($dbConnect, 'utf8mb4')) {
    error_log('Could not set artist account update charset: ' . mysqli_error($dbConnect));
    respond(500, false, 'Your account could not be updated right now.');
}

$userId = (int) $_SESSION['user_id'];
$action = submittedValue('action');

if ($action === 'password') {
    $currentPassword = isset($_POST['current_password']) && is_string($_POST['current_password'])
        ? $_POST['current_password']
        : '';
    $newPassword = isset($_POST['new_password']) && is_string($_POST['new_password'])
        ? $_POST['new_password']
        : '';
    $confirmPassword = isset($_POST['confirm_password']) && is_string($_POST['confirm_password'])
        ? $_POST['confirm_password']
        : '';

    if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
        respond(400, false, 'Your new password must be between 8 and 72 characters.');
    }
    if ($newPassword !== $confirmPassword) {
        respond(400, false, 'The new passwords do not match.');
    }

    $statement = mysqli_prepare($dbConnect, 'SELECT password FROM users WHERE userID = ? AND role = ? LIMIT 1');
    if (!$statement || !mysqli_stmt_bind_param($statement, 'is', $userId, $role) ||
        !mysqli_stmt_execute($statement) || !mysqli_stmt_bind_result($statement, $storedPasswordHash)) {
        error_log('Could not query artist password for update: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
        if ($statement) {
            mysqli_stmt_close($statement);
        }
        respond(500, false, 'Your password could not be updated right now.');
    }

    $passwordFound = mysqli_stmt_fetch($statement);
    mysqli_stmt_close($statement);
    if (!$passwordFound || !password_verify($currentPassword, $storedPasswordHash)) {
        respond(400, false, 'Your current password is incorrect.');
    }

    $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    if ($newPasswordHash === false) {
        respond(500, false, 'Your password could not be updated right now.');
    }
    $statement = mysqli_prepare($dbConnect, 'UPDATE users SET password = ? WHERE userID = ? AND role = ?');
    if (!$statement || !mysqli_stmt_bind_param($statement, 'sis', $newPasswordHash, $userId, $role) ||
        !mysqli_stmt_execute($statement)) {
        error_log('Could not update artist password: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
        if ($statement) {
            mysqli_stmt_close($statement);
        }
        respond(500, false, 'Your password could not be updated right now.');
    }
    mysqli_stmt_close($statement);
    respond(200, true, 'Your password has been updated.');
}

if ($action !== 'profile') {
    respond(400, false, 'Choose a valid account setting to update.');
}

$artistName = submittedValue('artist_name');
$genre = submittedValue('genre');
$language = submittedValue('language');
$biography = submittedValue('biography');
$youtubeUrl = submittedValue('youtube_url');
$tiktokUrl = submittedValue('tiktok_url');

if ($artistName === '' || characterLength($artistName) > 100 ||
    $genre === '' || characterLength($genre) > 255 ||
    $language === '' || characterLength($language) > 50 ||
    characterLength($biography) > 255) {
    respond(400, false, 'Check the profile fields and their character limits.');
}

$allowedGenres = ['Pop', 'Rock', 'Hip-Hop', 'Electronic', 'Classical', 'Jazz', 'Blues', 'Country', 'R&B', 'Folk'];
$selectedGenres = array_values(array_unique(array_filter(array_map('trim', explode(',', $genre)))));
if (count($selectedGenres) === 0 || count(array_diff($selectedGenres, $allowedGenres)) > 0) {
    respond(400, false, 'Choose one or more valid artist genres.');
}
$genre = implode(', ', $selectedGenres);

foreach ([
    'YouTube' => $youtubeUrl,
    'TikTok' => $tiktokUrl
] as $platform => $url) {
    if (characterLength($url) > 255 ||
        ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) ||
            !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)))) {
        respond(400, false, $platform . ' must be a valid HTTP or HTTPS URL of 255 characters or fewer.');
    }
}

$profilePictureUpload = $_FILES['profile_picture'] ?? null;
$profilePictureExtension = '';
if (is_array($profilePictureUpload) && $profilePictureUpload['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($profilePictureUpload['error'] !== UPLOAD_ERR_OK || $profilePictureUpload['size'] > 5 * 1024 * 1024 ||
        !is_uploaded_file($profilePictureUpload['tmp_name'])) {
        respond(400, false, 'Choose a profile picture smaller than 5 MB.');
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($profilePictureUpload['tmp_name']);
    $allowedImages = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp'
    ];
    if (!isset($allowedImages[$mimeType])) {
        respond(400, false, 'Profile pictures must be JPEG, PNG, GIF, or WebP images.');
    }
    $profilePictureExtension = $allowedImages[$mimeType];
}

$oldProfilePicture = '';
$picturePath = null;
$newProfilePicture = '';
if (!mysqli_begin_transaction($dbConnect)) {
    error_log('Could not start artist profile update transaction: ' . mysqli_error($dbConnect));
    respond(500, false, 'Your profile could not be updated right now.');
}

try {
    $statement = mysqli_prepare($dbConnect, 'SELECT profile_picture FROM users WHERE userID = ? AND role = ? LIMIT 1');
    if (!$statement || !mysqli_stmt_bind_param($statement, 'is', $userId, $role) ||
        !mysqli_stmt_execute($statement) || !mysqli_stmt_bind_result($statement, $oldProfilePicture) ||
        !mysqli_stmt_fetch($statement)) {
        error_log('Could not load artist profile picture during update: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
        if ($statement) {
            mysqli_stmt_close($statement);
        }
        throw new RuntimeException('Your profile could not be updated right now.');
    }
    mysqli_stmt_close($statement);

    if ($profilePictureExtension !== '') {
        $uploadDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'profile_pictures';
        if (!is_dir($uploadDirectory) &&
            !mkdir($uploadDirectory, 0775, true) &&
            !is_dir($uploadDirectory)) {
            throw new RuntimeException('The profile picture could not be saved.');
        }
        $fileName = bin2hex(random_bytes(16)) . '.' . $profilePictureExtension;
        $picturePath = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($profilePictureUpload['tmp_name'], $picturePath)) {
            throw new RuntimeException('The profile picture could not be saved.');
        }
        $newProfilePicture = 'uploads/profile_pictures/' . $fileName;
    } else {
        $newProfilePicture = is_string($oldProfilePicture) ? $oldProfilePicture : '';
    }

    $statement = mysqli_prepare(
        $dbConnect,
        'UPDATE artist_profiles
         SET artist_name = ?, genre = ?, language = ?, biography = ?, youtube_url = ?, tiktok_url = ?
         WHERE userID = ?'
    );
    if (!$statement || !mysqli_stmt_bind_param(
        $statement,
        'ssssssi',
        $artistName,
        $genre,
        $language,
        $biography,
        $youtubeUrl,
        $tiktokUrl,
        $userId
    ) || !mysqli_stmt_execute($statement) || mysqli_stmt_affected_rows($statement) < 0) {
        error_log('Could not update artist profile details: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
        if ($statement) {
            mysqli_stmt_close($statement);
        }
        throw new RuntimeException('Your profile could not be updated right now.');
    }
    mysqli_stmt_close($statement);

    $statement = mysqli_prepare($dbConnect, 'UPDATE users SET profile_picture = ? WHERE userID = ? AND role = ?');
    if (!$statement || !mysqli_stmt_bind_param($statement, 'sis', $newProfilePicture, $userId, $role) ||
        !mysqli_stmt_execute($statement)) {
        error_log('Could not update artist account details: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
        if ($statement) {
            mysqli_stmt_close($statement);
        }
        throw new RuntimeException('Your profile could not be updated right now.');
    }
    mysqli_stmt_close($statement);

    if (!mysqli_commit($dbConnect)) {
        error_log('Could not commit artist profile update: ' . mysqli_error($dbConnect));
        throw new RuntimeException('Your profile could not be updated right now.');
    }
} catch (RuntimeException $exception) {
    mysqli_rollback($dbConnect);
    if ($picturePath !== null && is_file($picturePath)) {
        unlink($picturePath);
    }
    respond(500, false, $exception->getMessage());
}

if ($profilePictureExtension !== '' &&
    is_string($oldProfilePicture) &&
    preg_match('~\Auploads/profile_pictures/[a-f0-9]{32}\.(?:jpg|png|gif|webp)\z~', $oldProfilePicture)) {
    $oldPicturePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $oldProfilePicture);
    if (is_file($oldPicturePath)) {
        unlink($oldPicturePath);
    }
}

respond(200, true, 'Your artist profile has been updated.');
