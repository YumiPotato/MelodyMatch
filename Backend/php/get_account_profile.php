<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
mysqli_report(MYSQLI_REPORT_OFF);

function respond($statusCode, $success, $message = '', $profile = null)
{
    http_response_code($statusCode);
    $response = ['success' => $success];
    if ($message !== '') {
        $response['message'] = $message;
    }
    if ($profile !== null) {
        $response['profile'] = $profile;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

session_start();
$role = isset($_SESSION['role']) && is_string($_SESSION['role']) ? $_SESSION['role'] : '';
if (empty($_SESSION['user_id']) || !in_array($role, ['Listener', 'Artist'], true)) {
    respond(401, false, 'Sign in to view your account profile.');
}

require_once __DIR__ . '/database_connection.php';
if (!$dbConnect) {
    error_log('Account profile API database connection failed: ' . mysqli_connect_error());
    respond(500, false, 'Your profile is temporarily unavailable.');
}

if (!mysqli_set_charset($dbConnect, 'utf8mb4')) {
    error_log('Could not set account profile API database charset: ' . mysqli_error($dbConnect));
    respond(500, false, 'Your profile is temporarily unavailable.');
}

$statement = mysqli_prepare(
    $dbConnect,
    'SELECT userID, username, email, profile_picture, created_at, status
     FROM users WHERE userID = ? AND role = ? LIMIT 1'
);
$userId = (int) $_SESSION['user_id'];
if (!$statement || !mysqli_stmt_bind_param($statement, 'is', $userId, $role) ||
    !mysqli_stmt_execute($statement) ||
    !mysqli_stmt_bind_result(
        $statement,
        $storedUserId,
        $username,
        $email,
        $storedProfilePicture,
        $createdAt,
        $status
    )) {
    error_log('Could not query account profile: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
    if ($statement) {
        mysqli_stmt_close($statement);
    }
    respond(500, false, 'Your profile is temporarily unavailable.');
}

$userFound = mysqli_stmt_fetch($statement);
mysqli_stmt_close($statement);
if (!$userFound) {
    $_SESSION = [];
    session_destroy();
    respond(401, false, 'Your account could not be found. Please sign in again.');
}

$profileImage = null;
if (is_string($storedProfilePicture) &&
    preg_match('~\Auploads/profile_pictures/[a-f0-9]{32}\.(?:jpg|png|gif|webp)\z~', $storedProfilePicture)) {
    $imagePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $storedProfilePicture);
    if (is_file($imagePath)) {
        $profileImage = '/MelodyMatch/' . $storedProfilePicture;
    }
}

$artistName = null;
$artistGenre = null;
$artistLanguage = null;
$artistBiography = null;
$youtubeUrl = null;
$tiktokUrl = null;
$musicActivity = null;
if ($role === 'Artist') {
    $artistStatement = mysqli_prepare(
        $dbConnect,
        'SELECT artist_name, genre, language, biography, youtube_url, tiktok_url
         FROM artist_profiles WHERE userID = ? LIMIT 1'
    );
    if (!$artistStatement || !mysqli_stmt_bind_param($artistStatement, 'i', $userId) ||
        !mysqli_stmt_execute($artistStatement) ||
        !mysqli_stmt_bind_result(
            $artistStatement,
            $artistName,
            $artistGenre,
            $artistLanguage,
            $artistBiography,
            $youtubeUrl,
            $tiktokUrl
        )) {
        error_log('Could not query artist profile: ' . ($artistStatement ? mysqli_stmt_error($artistStatement) : mysqli_error($dbConnect)));
        if ($artistStatement) {
            mysqli_stmt_close($artistStatement);
        }
        respond(500, false, 'Your profile is temporarily unavailable.');
    }

    if (!mysqli_stmt_fetch($artistStatement)) {
        mysqli_stmt_close($artistStatement);
        respond(404, false, 'The artist profile could not be found.');
    }
    mysqli_stmt_close($artistStatement);

    $activityStatement = mysqli_prepare(
        $dbConnect,
        "SELECT COUNT(*), COALESCE(SUM(views_count), 0), COALESCE(SUM(likes_count), 0),
                COALESCE(SUM(saves_count), 0)
         FROM songs WHERE userID = ?"
    );
    if (!$activityStatement || !mysqli_stmt_bind_param($activityStatement, 'i', $userId) ||
        !mysqli_stmt_execute($activityStatement) ||
        !mysqli_stmt_bind_result(
            $activityStatement,
            $songCount,
            $viewCount,
            $likeCount,
            $saveCount
        ) ||
        !mysqli_stmt_fetch($activityStatement)) {
        error_log('Could not query artist music activity: ' . ($activityStatement ? mysqli_stmt_error($activityStatement) : mysqli_error($dbConnect)));
        if ($activityStatement) {
            mysqli_stmt_close($activityStatement);
        }
        respond(500, false, 'Your profile is temporarily unavailable.');
    }
    mysqli_stmt_close($activityStatement);
    $musicActivity = [
        'songs' => (int) $songCount,
        'views' => (int) $viewCount,
        'likes' => (int) $likeCount,
        'saves' => (int) $saveCount
    ];
}

$profile = [
    'id' => (int) $storedUserId,
    'role' => $role,
    'username' => $username,
    'email' => $email,
    'profilePicture' => $profileImage,
    'joined' => $createdAt,
    'status' => ucfirst(strtolower($status)),
    'artistName' => $artistName,
    'genre' => $artistGenre,
    'language' => $artistLanguage,
    'biography' => $artistBiography,
    'youtubeUrl' => $youtubeUrl,
    'tiktokUrl' => $tiktokUrl,
    'musicActivity' => $musicActivity
];
$profile['csrfToken'] = $_SESSION['csrf_token'] ?? null;

respond(200, true, '', $profile);
