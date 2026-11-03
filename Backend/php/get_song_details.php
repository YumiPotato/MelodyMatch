<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
mysqli_report(MYSQLI_REPORT_OFF);

function respond($statusCode, $success, $message = '', $song = null)
{
    http_response_code($statusCode);
    $response = ['success' => $success];
    if ($message !== '') {
        $response['message'] = $message;
    }
    if ($song !== null) {
        $response['song'] = $song;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

session_start();
$role = isset($_SESSION['role']) && is_string($_SESSION['role']) ? $_SESSION['role'] : '';
if (empty($_SESSION['user_id']) || !in_array($role, ['Listener', 'Artist'], true)) {
    respond(401, false, 'Sign in to view song details.');
}

$songIdValue = isset($_GET['song_id']) && is_string($_GET['song_id']) ? $_GET['song_id'] : '';
if (!ctype_digit($songIdValue) || (int) $songIdValue < 1) {
    respond(400, false, 'Choose a valid song.');
}

require_once __DIR__ . '/database_connection.php';
if (!$dbConnect) {
    error_log('Song details database connection failed: ' . mysqli_connect_error());
    respond(500, false, 'Song details are temporarily unavailable.');
}
if (!mysqli_set_charset($dbConnect, 'utf8mb4')) {
    error_log('Could not set song details database charset: ' . mysqli_error($dbConnect));
    respond(500, false, 'Song details are temporarily unavailable.');
}

$songId = (int) $songIdValue;
$statement = mysqli_prepare(
    $dbConnect,
    "SELECT s.title, s.description, s.genre, s.language, s.mood, s.release_date,
            s.audio_path, s.cover_path, s.views_count, s.likes_count, s.saves_count,
            viewer_save.songID, viewer_like.songID,
            u.userID, u.username, u.profile_picture, a.artist_name, a.biography,
            a.youtube_url, a.tiktok_url
     FROM songs s
     INNER JOIN users u ON u.userID = s.userID
     INNER JOIN artist_profiles a ON a.userID = u.userID
     LEFT JOIN saved_songs viewer_save
       ON viewer_save.songID = s.songID AND viewer_save.userID = ?
     LEFT JOIN song_likes viewer_like
       ON viewer_like.songID = s.songID AND viewer_like.userID = ?
     WHERE s.songID = ? AND s.status IN ('published', 'scheduled')
       AND s.release_date <= CURDATE() AND LOWER(u.status) = 'active'
     LIMIT 1"
);
$userId = (int) $_SESSION['user_id'];
if (!$statement || !mysqli_stmt_bind_param($statement, 'iii', $userId, $userId, $songId) ||
    !mysqli_stmt_execute($statement) ||
    !mysqli_stmt_bind_result(
        $statement,
        $title,
        $description,
        $genre,
        $language,
        $mood,
        $releaseDate,
        $audioPath,
        $coverPath,
        $views,
        $likes,
        $saves,
        $savedSongId,
        $likedSongId,
        $artistId,
        $username,
        $artistPicture,
        $artistName,
        $biography,
        $youtubeUrl,
        $tiktokUrl
    )) {
    error_log('Could not query song details: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
    if ($statement) {
        mysqli_stmt_close($statement);
    }
    respond(500, false, 'Song details are temporarily unavailable.');
}
if (!mysqli_stmt_fetch($statement)) {
    mysqli_stmt_close($statement);
    respond(404, false, 'This song could not be found.');
}
mysqli_stmt_close($statement);

$audioUrl = null;
if (is_string($audioPath) &&
    preg_match('~\Auploads/song_tracks/[a-f0-9]{32}\.(?:mp3|wav|m4a|aac|ogg|flac)\z~', $audioPath)) {
    $audioFilePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $audioPath);
    if (is_file($audioFilePath)) {
        $audioUrl = '/MelodyMatch/' . $audioPath;
    }
}
if (!$audioUrl) {
    respond(404, false, 'The audio for this song is not available.');
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

$artistProfilePicture = null;
if (is_string($artistPicture) &&
    preg_match('~\Auploads/profile_pictures/[a-f0-9]{32}\.(?:jpg|png|gif|webp)\z~', $artistPicture)) {
    $picturePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $artistPicture);
    if (is_file($picturePath)) {
        $artistProfilePicture = '/MelodyMatch/' . $artistPicture;
    }
}

respond(200, true, '', [
    'id' => $songId,
    'title' => $title,
    'description' => $description,
    'genre' => $genre,
    'language' => $language,
    'mood' => $mood,
    'releaseDate' => $releaseDate,
    'audioUrl' => $audioUrl,
    'coverUrl' => $coverUrl,
    'views' => (int) $views,
    'likes' => (int) $likes,
    'saves' => (int) $saves,
    'liked' => $likedSongId !== null,
    'saved' => $savedSongId !== null,
    'artistId' => (int) $artistId,
    'artistName' => $artistName ?: $username,
    'artistUsername' => $username,
    'artistPicture' => $artistProfilePicture,
    'artistBiography' => $biography,
    'youtubeUrl' => $youtubeUrl,
    'tiktokUrl' => $tiktokUrl,
    'csrfToken' => $_SESSION['csrf_token'] ?? null
]);
