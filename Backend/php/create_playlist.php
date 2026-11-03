<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
mysqli_report(MYSQLI_REPORT_OFF);

function respond($statusCode, $success, $message, $playlistId = null)
{
    http_response_code($statusCode);
    $response = ['success' => $success, 'message' => $message];
    if ($playlistId !== null) {
        $response['playlistId'] = $playlistId;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

session_start();
$role = isset($_SESSION['role']) && is_string($_SESSION['role']) ? $_SESSION['role'] : '';
if (empty($_SESSION['user_id']) || !in_array($role, ['Listener', 'Artist'], true)) {
    respond(401, false, 'Sign in to create a playlist.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, false, 'Create a playlist using POST.');
}

$csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
    ? $_POST['csrf_token']
    : '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    respond(403, false, 'Your session expired. Reload the page and try again.');
}

$name = isset($_POST['name']) && is_string($_POST['name']) ? trim($_POST['name']) : '';
$description = isset($_POST['description']) && is_string($_POST['description'])
    ? trim($_POST['description'])
    : '';
$mood = isset($_POST['mood']) && is_string($_POST['mood']) ? trim($_POST['mood']) : '';
$genre = isset($_POST['genre']) && is_string($_POST['genre']) ? trim($_POST['genre']) : '';
$songIdsValue = isset($_POST['song_ids']) && is_string($_POST['song_ids'])
    ? $_POST['song_ids']
    : '';
$songIds = json_decode($songIdsValue, true);

if ($name === '' || strlen($name) > 100) {
    respond(400, false, 'Enter a playlist name of 100 characters or fewer.');
}
if (strlen($description) > 500 || strlen($mood) > 100 || strlen($genre) > 100) {
    respond(400, false, 'Playlist description, mood, or genre exceeds its character limit.');
}
if (!is_array($songIds) || count($songIds) < 1 || count($songIds) > 100) {
    respond(400, false, 'Choose between 1 and 100 songs for your playlist.');
}

$validatedSongIds = [];
foreach ($songIds as $songId) {
    if (!is_int($songId) || $songId < 1 || isset($validatedSongIds[$songId])) {
        respond(400, false, 'The playlist contains an invalid or repeated song.');
    }
    $validatedSongIds[$songId] = true;
}
$validatedSongIds = array_keys($validatedSongIds);

require_once __DIR__ . '/database_connection.php';
if (!$dbConnect) {
    error_log('Create playlist database connection failed: ' . mysqli_connect_error());
    respond(500, false, 'Your playlist could not be created right now.');
}
if (!mysqli_set_charset($dbConnect, 'utf8mb4')) {
    error_log('Could not set create playlist database charset: ' . mysqli_error($dbConnect));
    respond(500, false, 'Your playlist could not be created right now.');
}
if (!mysqli_begin_transaction($dbConnect)) {
    error_log('Could not begin playlist creation transaction: ' . mysqli_error($dbConnect));
    respond(500, false, 'Your playlist could not be created right now.');
}

$placeholders = implode(',', array_fill(0, count($validatedSongIds), '?'));
$types = str_repeat('i', count($validatedSongIds));
$statement = mysqli_prepare(
    $dbConnect,
    "SELECT COUNT(*) FROM songs s
     INNER JOIN users u ON u.userID = s.userID
     WHERE s.songID IN ($placeholders)
       AND s.status IN ('published', 'scheduled') AND s.release_date <= CURDATE()
       AND LOWER(u.status) = 'active'"
);
if (!$statement || !mysqli_stmt_bind_param($statement, $types, ...$validatedSongIds) ||
    !mysqli_stmt_execute($statement) || !mysqli_stmt_bind_result($statement, $availableSongCount) ||
    !mysqli_stmt_fetch($statement)) {
    error_log('Could not validate playlist songs: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
    if ($statement) {
        mysqli_stmt_close($statement);
    }
    mysqli_rollback($dbConnect);
    respond(500, false, 'Your playlist could not be created right now.');
}
mysqli_stmt_close($statement);
if ((int) $availableSongCount !== count($validatedSongIds)) {
    mysqli_rollback($dbConnect);
    respond(400, false, 'One or more selected songs are no longer available.');
}

$userId = (int) $_SESSION['user_id'];
$statement = mysqli_prepare(
    $dbConnect,
    'INSERT INTO playlists (userID, name, description, mood, genre) VALUES (?, ?, ?, ?, ?)'
);
if (!$statement || !mysqli_stmt_bind_param($statement, 'issss', $userId, $name, $description, $mood, $genre) ||
    !mysqli_stmt_execute($statement)) {
    error_log('Could not insert playlist: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
    if ($statement) {
        mysqli_stmt_close($statement);
    }
    mysqli_rollback($dbConnect);
    respond(500, false, 'Your playlist could not be created right now.');
}
$playlistId = mysqli_insert_id($dbConnect);
mysqli_stmt_close($statement);

$statement = mysqli_prepare($dbConnect, 'INSERT INTO playlist_songs (playlistID, songID, position) VALUES (?, ?, ?)');
if (!$statement) {
    error_log('Could not prepare playlist songs insert: ' . mysqli_error($dbConnect));
    mysqli_rollback($dbConnect);
    respond(500, false, 'Your playlist could not be created right now.');
}
foreach ($validatedSongIds as $position => $songId) {
    $oneBasedPosition = $position + 1;
    if (!mysqli_stmt_bind_param($statement, 'iii', $playlistId, $songId, $oneBasedPosition) ||
        !mysqli_stmt_execute($statement)) {
        error_log('Could not insert song into playlist: ' . mysqli_stmt_error($statement));
        mysqli_stmt_close($statement);
        mysqli_rollback($dbConnect);
        respond(500, false, 'Your playlist could not be created right now.');
    }
}
mysqli_stmt_close($statement);
if (!mysqli_commit($dbConnect)) {
    error_log('Could not commit playlist creation: ' . mysqli_error($dbConnect));
    mysqli_rollback($dbConnect);
    respond(500, false, 'Your playlist could not be created right now.');
}

respond(201, true, 'Your playlist was created.', $playlistId);
