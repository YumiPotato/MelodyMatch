<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
mysqli_report(MYSQLI_REPORT_OFF);

function respond($statusCode, $success, $message = '', $songs = null)
{
    http_response_code($statusCode);
    $response = ['success' => $success];
    if ($message !== '') {
        $response['message'] = $message;
    }
    if ($songs !== null) {
        $response['songs'] = $songs;
        $response['csrfToken'] = $_SESSION['csrf_token'] ?? null;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

session_start();
$role = isset($_SESSION['role']) && is_string($_SESSION['role']) ? $_SESSION['role'] : '';
if (empty($_SESSION['user_id']) || !in_array($role, ['Listener', 'Artist'], true)) {
    respond(401, false, 'Sign in to search released songs.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    respond(405, false, 'Search songs using GET.');
}

$query = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
if ($query === '' || strlen($query) > 100) {
    respond(400, false, 'Enter a search term of 100 characters or fewer.');
}
$escapedQuery = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query);
$likeQuery = '%' . $escapedQuery . '%';

require_once __DIR__ . '/database_connection.php';
if (!$dbConnect) {
    error_log('Playlist song search database connection failed: ' . mysqli_connect_error());
    respond(500, false, 'Song search is temporarily unavailable.');
}
if (!mysqli_set_charset($dbConnect, 'utf8mb4')) {
    error_log('Could not set playlist song search database charset: ' . mysqli_error($dbConnect));
    respond(500, false, 'Song search is temporarily unavailable.');
}

$userId = (int) $_SESSION['user_id'];
$statement = mysqli_prepare(
    $dbConnect,
    "SELECT s.songID, s.title, s.audio_path, s.cover_path, a.artist_name, u.username,
            s.saves_count, s.likes_count, viewer_save.songID, viewer_like.songID
     FROM songs s
     INNER JOIN users u ON u.userID = s.userID
     INNER JOIN artist_profiles a ON a.userID = u.userID
     LEFT JOIN saved_songs viewer_save ON viewer_save.songID = s.songID AND viewer_save.userID = ?
     LEFT JOIN song_likes viewer_like ON viewer_like.songID = s.songID AND viewer_like.userID = ?
     WHERE s.status IN ('published', 'scheduled') AND s.release_date <= CURDATE()
       AND LOWER(u.status) = 'active'
       AND (s.title LIKE ? ESCAPE '!' OR a.artist_name LIKE ? ESCAPE '!' OR u.username LIKE ? ESCAPE '!')
     ORDER BY s.release_date DESC, s.songID DESC
     LIMIT 40"
);
if (!$statement || !mysqli_stmt_bind_param(
        $statement,
        'iisss',
        $userId,
        $userId,
        $likeQuery,
        $likeQuery,
        $likeQuery
    ) ||
    !mysqli_stmt_execute($statement) ||
    !mysqli_stmt_bind_result(
        $statement,
        $songId,
        $title,
        $audioPath,
        $coverPath,
        $artistName,
        $username,
        $saves,
        $likes,
        $savedSongId,
        $likedSongId
    )) {
    error_log('Could not query playlist song search: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
    if ($statement) {
        mysqli_stmt_close($statement);
    }
    respond(500, false, 'Song search is temporarily unavailable.');
}

$songs = [];
while (mysqli_stmt_fetch($statement)) {
    if (!is_string($audioPath) ||
        !preg_match('~\Auploads/song_tracks/[a-f0-9]{32}\.(?:mp3|wav|m4a|aac|ogg|flac)\z~', $audioPath)) {
        continue;
    }
    $audioFilePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $audioPath);
    if (!is_file($audioFilePath)) {
        continue;
    }

    $coverUrl = null;
    if (is_string($coverPath) &&
        preg_match('~\Auploads/song_covers/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $coverPath)) {
        $coverFilePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $coverPath);
        if (is_file($coverFilePath)) {
            $coverUrl = '/MelodyMatch/' . $coverPath;
        }
    }

    $songs[] = [
        'id' => (int) $songId,
        'title' => $title,
        'artistName' => $artistName ?: $username,
        'audioUrl' => '/MelodyMatch/' . $audioPath,
        'coverUrl' => $coverUrl,
        'saves' => (int) $saves,
        'saved' => $savedSongId !== null,
        'likes' => (int) $likes,
        'liked' => $likedSongId !== null
    ];
}
mysqli_stmt_close($statement);

respond(200, true, '', $songs);
