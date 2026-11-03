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
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

session_start();
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Artist') {
    respond(401, false, 'Sign in with an artist account to view your songs.');
}

require_once __DIR__ . '/database_connection.php';
if (!$dbConnect) {
    error_log('Artist songs database connection failed: ' . mysqli_connect_error());
    respond(500, false, 'Your songs are temporarily unavailable.');
}
if (!mysqli_set_charset($dbConnect, 'utf8mb4')) {
    error_log('Could not set artist songs database charset: ' . mysqli_error($dbConnect));
    respond(500, false, 'Your songs are temporarily unavailable.');
}

$userId = (int) $_SESSION['user_id'];
$statement = mysqli_prepare(
    $dbConnect,
    'SELECT songID, title, genre, language, mood, release_date, status, views_count,
            likes_count, saves_count, audio_path, cover_path
     FROM songs WHERE userID = ? ORDER BY release_date DESC, songID DESC'
);
if (!$statement || !mysqli_stmt_bind_param($statement, 'i', $userId) ||
    !mysqli_stmt_execute($statement) ||
    !mysqli_stmt_bind_result(
        $statement,
        $songId,
        $title,
        $genre,
        $language,
        $mood,
        $releaseDate,
        $status,
        $views,
        $likes,
        $saves,
        $audioPath,
        $coverPath
    )) {
    error_log('Could not query artist songs: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
    if ($statement) {
        mysqli_stmt_close($statement);
    }
    respond(500, false, 'Your songs are temporarily unavailable.');
}

$songs = [];
while (mysqli_stmt_fetch($statement)) {
    $audioUrl = null;
    if (is_string($audioPath) &&
        preg_match('~\Auploads/song_tracks/[a-f0-9]{32}\.(?:mp3|wav|m4a|aac|ogg|flac)\z~', $audioPath)) {
        $audioFilePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $audioPath);
        if (is_file($audioFilePath)) {
            $audioUrl = '/MelodyMatch/' . $audioPath;
        }
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
        'genre' => $genre,
        'language' => $language,
        'mood' => $mood,
        'releaseDate' => $releaseDate,
        'status' => $status,
        'views' => (int) $views,
        'likes' => (int) $likes,
        'saves' => (int) $saves,
        'audioUrl' => $audioUrl,
        'coverUrl' => $coverUrl
    ];
}
mysqli_stmt_close($statement);

respond(200, true, '', $songs);
