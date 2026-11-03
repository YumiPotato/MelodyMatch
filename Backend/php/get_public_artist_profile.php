<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
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
$viewerId = isset($_SESSION['user_id']) &&
    in_array($_SESSION['role'] ?? '', ['Listener', 'Artist'], true)
    ? (int) $_SESSION['user_id']
    : 0;

$artistIdValue = isset($_GET['artist_id']) && is_string($_GET['artist_id'])
    ? $_GET['artist_id']
    : '';
if (!ctype_digit($artistIdValue) || (int) $artistIdValue < 1) {
    respond(400, false, 'A valid artist profile is required.');
}

require_once __DIR__ . '/database_connection.php';
if (!$dbConnect) {
    error_log('Public artist profile database connection failed: ' . mysqli_connect_error());
    respond(500, false, 'This artist profile is temporarily unavailable.');
}
if (!mysqli_set_charset($dbConnect, 'utf8mb4')) {
    error_log('Could not set public artist profile database charset: ' . mysqli_error($dbConnect));
    respond(500, false, 'This artist profile is temporarily unavailable.');
}

$artistId = (int) $artistIdValue;
$statement = mysqli_prepare(
    $dbConnect,
    'SELECT u.username, u.profile_picture, a.artist_name, a.genre, a.language,
            a.biography, a.youtube_url, a.tiktok_url
     FROM users u
     INNER JOIN artist_profiles a ON a.userID = u.userID
     WHERE u.userID = ? AND u.role = ? AND LOWER(u.status) = ? LIMIT 1'
);
$role = 'Artist';
$active = 'active';
if (!$statement || !mysqli_stmt_bind_param($statement, 'iss', $artistId, $role, $active) ||
    !mysqli_stmt_execute($statement) ||
    !mysqli_stmt_bind_result(
        $statement,
        $username,
        $storedProfilePicture,
        $artistName,
        $genre,
        $language,
        $biography,
        $youtubeUrl,
        $tiktokUrl
    )) {
    error_log('Could not query public artist profile: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
    if ($statement) {
        mysqli_stmt_close($statement);
    }
    respond(500, false, 'This artist profile is temporarily unavailable.');
}

if (!mysqli_stmt_fetch($statement)) {
    mysqli_stmt_close($statement);
    respond(404, false, 'This artist profile could not be found.');
}
mysqli_stmt_close($statement);

$profileImage = null;
if (is_string($storedProfilePicture) &&
    preg_match('~\Auploads/profile_pictures/[a-f0-9]{32}\.(?:jpg|png|gif|webp)\z~', $storedProfilePicture)) {
    $imagePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $storedProfilePicture);
    if (is_file($imagePath)) {
        $profileImage = '/MelodyMatch/' . $storedProfilePicture;
    }
}

$songs = [];
$songStatement = mysqli_prepare(
    $dbConnect,
    "SELECT s.songID, s.title, s.description, s.genre, s.language, s.mood, s.release_date,
            s.audio_path, s.cover_path, s.views_count, s.likes_count, s.saves_count,
            viewer_save.songID, viewer_like.songID
     FROM songs s
     LEFT JOIN saved_songs viewer_save
       ON viewer_save.songID = s.songID AND viewer_save.userID = ?
     LEFT JOIN song_likes viewer_like
       ON viewer_like.songID = s.songID AND viewer_like.userID = ?
     WHERE s.userID = ? AND s.status IN ('published', 'scheduled') AND s.release_date <= CURDATE()
     ORDER BY s.release_date DESC, s.songID DESC"
);
if (!$songStatement || !mysqli_stmt_bind_param($songStatement, 'iii', $viewerId, $viewerId, $artistId) ||
    !mysqli_stmt_execute($songStatement) ||
    !mysqli_stmt_bind_result(
        $songStatement,
        $songId,
        $songTitle,
        $songDescription,
        $songGenre,
        $songLanguage,
        $songMood,
        $songReleaseDate,
        $songAudioPath,
        $songCoverPath,
        $songViews,
        $songLikes,
        $songSaves,
        $savedSongId,
        $likedSongId
    )) {
    error_log('Could not query public artist releases: ' . ($songStatement ? mysqli_stmt_error($songStatement) : mysqli_error($dbConnect)));
    if ($songStatement) {
        mysqli_stmt_close($songStatement);
    }
    respond(500, false, 'This artist profile is temporarily unavailable.');
}

while (mysqli_stmt_fetch($songStatement)) {
    $audioUrl = null;
    if (is_string($songAudioPath) &&
        preg_match('~\Auploads/song_tracks/[a-f0-9]{32}\.(?:mp3|wav|m4a|aac|ogg|flac)\z~', $songAudioPath)) {
        $audioFilePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $songAudioPath);
        if (is_file($audioFilePath)) {
            $audioUrl = '/MelodyMatch/' . $songAudioPath;
        }
    }

    $coverUrl = null;
    if (is_string($songCoverPath) &&
        preg_match('~\Auploads/song_covers/[a-f0-9]{32}\.(?:jpg|png|webp)\z~', $songCoverPath)) {
        $coverFilePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $songCoverPath);
        if (is_file($coverFilePath)) {
            $coverUrl = '/MelodyMatch/' . $songCoverPath;
        }
    }

    $songs[] = [
        'id' => (int) $songId,
        'title' => $songTitle,
        'description' => $songDescription,
        'genre' => $songGenre,
        'language' => $songLanguage,
        'mood' => $songMood,
        'releaseDate' => $songReleaseDate,
        'audioUrl' => $audioUrl,
        'coverUrl' => $coverUrl,
        'views' => (int) $songViews,
        'likes' => (int) $songLikes,
        'saves' => (int) $songSaves,
        'saved' => $savedSongId !== null,
        'liked' => $likedSongId !== null
    ];
}
mysqli_stmt_close($songStatement);

respond(200, true, '', [
    'artistName' => $artistName,
    'username' => $username,
    'profilePicture' => $profileImage,
    'genre' => $genre,
    'language' => $language,
    'biography' => $biography,
    'youtubeUrl' => $youtubeUrl,
    'tiktokUrl' => $tiktokUrl,
    'csrfToken' => $viewerId > 0 ? ($_SESSION['csrf_token'] ?? null) : null,
    'songs' => $songs
]);
