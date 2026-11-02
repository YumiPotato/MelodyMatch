<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
mysqli_report(MYSQLI_REPORT_OFF);

function respond($statusCode, $success, $message, $liked = null, $likeCount = null)
{
    http_response_code($statusCode);
    $response = ['success' => $success, 'message' => $message];
    if ($liked !== null) {
        $response['liked'] = $liked;
        $response['likes'] = $likeCount;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

session_start();
$role = isset($_SESSION['role']) && is_string($_SESSION['role']) ? $_SESSION['role'] : '';
if (empty($_SESSION['user_id']) || !in_array($role, ['Listener', 'Artist'], true)) {
    respond(401, false, 'Sign in to like songs.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, false, 'Like a song using POST.');
}

$csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
    ? $_POST['csrf_token']
    : '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    respond(403, false, 'Your session expired. Reload the page and try again.');
}

$songIdValue = isset($_POST['song_id']) && is_string($_POST['song_id'])
    ? $_POST['song_id']
    : '';
if (!ctype_digit($songIdValue) || (int) $songIdValue < 1) {
    respond(400, false, 'Choose a valid song.');
}
$songId = (int) $songIdValue;
$userId = (int) $_SESSION['user_id'];

require_once __DIR__ . '/database_connection.php';
if (!$dbConnect) {
    error_log('Like song database connection failed: ' . mysqli_connect_error());
    respond(500, false, 'This song could not be liked right now.');
}
if (!mysqli_set_charset($dbConnect, 'utf8mb4')) {
    error_log('Could not set like song database charset: ' . mysqli_error($dbConnect));
    respond(500, false, 'This song could not be liked right now.');
}
if (!mysqli_begin_transaction($dbConnect)) {
    error_log('Could not begin like song transaction: ' . mysqli_error($dbConnect));
    respond(500, false, 'This song could not be liked right now.');
}

$statement = mysqli_prepare(
    $dbConnect,
    "SELECT s.likes_count FROM songs s
     INNER JOIN users u ON u.userID = s.userID
     WHERE s.songID = ? AND s.status IN ('published', 'scheduled') AND s.release_date <= CURDATE()
       AND LOWER(u.status) = 'active'
     FOR UPDATE"
);
if (!$statement || !mysqli_stmt_bind_param($statement, 'i', $songId) ||
    !mysqli_stmt_execute($statement) || !mysqli_stmt_bind_result($statement, $currentLikeCount)) {
    error_log('Could not verify song for liking: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
    if ($statement) {
        mysqli_stmt_close($statement);
    }
    mysqli_rollback($dbConnect);
    respond(500, false, 'This song could not be liked right now.');
}
$songFound = mysqli_stmt_fetch($statement);
mysqli_stmt_close($statement);
if (!$songFound) {
    mysqli_rollback($dbConnect);
    respond(404, false, 'This released song could not be found.');
}

$statement = mysqli_prepare($dbConnect, 'SELECT songID FROM song_likes WHERE userID = ? AND songID = ? LIMIT 1');
if (!$statement || !mysqli_stmt_bind_param($statement, 'ii', $userId, $songId) ||
    !mysqli_stmt_execute($statement) || !mysqli_stmt_bind_result($statement, $existingSongId)) {
    error_log('Could not check song like state: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
    if ($statement) {
        mysqli_stmt_close($statement);
    }
    mysqli_rollback($dbConnect);
    respond(500, false, 'This song could not be liked right now.');
}
$alreadyLiked = mysqli_stmt_fetch($statement);
mysqli_stmt_close($statement);

if ($alreadyLiked) {
    $statement = mysqli_prepare($dbConnect, 'DELETE FROM song_likes WHERE userID = ? AND songID = ?');
    $liked = false;
} else {
    $statement = mysqli_prepare($dbConnect, 'INSERT INTO song_likes (userID, songID) VALUES (?, ?)');
    $liked = true;
}
if (!$statement || !mysqli_stmt_bind_param($statement, 'ii', $userId, $songId) ||
    !mysqli_stmt_execute($statement)) {
    error_log('Could not update song like state: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
    if ($statement) {
        mysqli_stmt_close($statement);
    }
    mysqli_rollback($dbConnect);
    respond(500, false, 'This song could not be liked right now.');
}
mysqli_stmt_close($statement);

$newLikeCount = max(0, (int) $currentLikeCount + ($liked ? 1 : -1));
$statement = mysqli_prepare($dbConnect, 'UPDATE songs SET likes_count = ? WHERE songID = ?');
if (!$statement || !mysqli_stmt_bind_param($statement, 'ii', $newLikeCount, $songId) ||
    !mysqli_stmt_execute($statement)) {
    error_log('Could not update song like count: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
    if ($statement) {
        mysqli_stmt_close($statement);
    }
    mysqli_rollback($dbConnect);
    respond(500, false, 'This song could not be liked right now.');
}
mysqli_stmt_close($statement);

if (!mysqli_commit($dbConnect)) {
    error_log('Could not commit song like state: ' . mysqli_error($dbConnect));
    mysqli_rollback($dbConnect);
    respond(500, false, 'This song could not be liked right now.');
}

respond(200, true, $liked ? 'Song added to your liked songs.' : 'Song removed from your liked songs.', $liked, $newLikeCount);
