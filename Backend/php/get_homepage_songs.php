<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
mysqli_report(MYSQLI_REPORT_OFF);

function respond($statusCode, $success, $message = '', $songs = null, $csrfToken = null)
{
    http_response_code($statusCode);
    $response = ['success' => $success];
    if ($message !== '') {
        $response['message'] = $message;
    }
    if ($songs !== null) {
        $response['songs'] = $songs;
        $response['csrfToken'] = $csrfToken;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

session_start();
$role = isset($_SESSION['role']) && is_string($_SESSION['role']) ? $_SESSION['role'] : '';
if (empty($_SESSION['user_id']) || !in_array($role, ['Listener', 'Artist'], true)) {
    respond(401, false, 'Sign in to browse released songs.');
}

require_once __DIR__ . '/database_connection.php';
if (!$dbConnect) {
    error_log('Homepage songs database connection failed: ' . mysqli_connect_error());
    respond(500, false, 'Released songs are temporarily unavailable.');
}
if (!mysqli_set_charset($dbConnect, 'utf8mb4')) {
    error_log('Could not set homepage songs database charset: ' . mysqli_error($dbConnect));
    respond(500, false, 'Released songs are temporarily unavailable.');
}

$userId = (int) $_SESSION['user_id'];
$statement = mysqli_prepare(
    $dbConnect,
    "SELECT s.songID, s.title, s.genre, s.language, s.mood, s.release_date, s.audio_path, s.cover_path,
            a.artist_name, u.username, s.saves_count, s.likes_count,
            MAX(current_save.songID), MAX(current_like.songID)
     FROM songs s
     INNER JOIN users u ON u.userID = s.userID
     INNER JOIN artist_profiles a ON a.userID = u.userID
     LEFT JOIN saved_songs current_save ON current_save.songID = s.songID AND current_save.userID = ?
     LEFT JOIN song_likes current_like ON current_like.songID = s.songID AND current_like.userID = ?
     WHERE s.status IN ('published', 'scheduled') AND s.release_date <= CURDATE()
       AND LOWER(u.status) = 'active'
     GROUP BY s.songID, s.title, s.genre, s.language, s.mood, s.release_date, s.audio_path, s.cover_path,
              a.artist_name, u.username, s.saves_count, s.likes_count
     ORDER BY s.release_date DESC, s.songID DESC
     LIMIT 30"
);
if (!$statement || !mysqli_stmt_bind_param($statement, 'ii', $userId, $userId) ||
    !mysqli_stmt_execute($statement) ||
    !mysqli_stmt_bind_result(
        $statement,
        $songId,
        $title,
        $genre,
        $language,
        $mood,
        $releaseDate,
        $audioPath,
        $coverPath,
        $artistName,
        $username,
        $saveCount,
        $likeCount,
        $savedSongId,
        $likedSongId
    )) {
    error_log('Could not query homepage song releases: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
    if ($statement) {
        mysqli_stmt_close($statement);
    }
    respond(500, false, 'Released songs are temporarily unavailable.');
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
        'genre' => $genre,
        'language' => $language,
        'mood' => $mood,
        'releaseDate' => $releaseDate,
        'audioUrl' => '/MelodyMatch/' . $audioPath,
        'coverUrl' => $coverUrl,
        'saves' => (int) $saveCount,
        'saved' => $savedSongId !== null,
        'likes' => (int) $likeCount,
        'liked' => $likedSongId !== null
    ];
}
mysqli_stmt_close($statement);

respond(200, true, '', $songs, $_SESSION['csrf_token'] ?? null);
