<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
mysqli_report(MYSQLI_REPORT_OFF);

function respond($statusCode, $success, $message = '', $playlists = null, $csrfToken = null)
{
    http_response_code($statusCode);
    $response = ['success' => $success];
    if ($message !== '') {
        $response['message'] = $message;
    }
    if ($playlists !== null) {
        $response['playlists'] = $playlists;
        $response['csrfToken'] = $csrfToken;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

session_start();
$role = isset($_SESSION['role']) && is_string($_SESSION['role']) ? $_SESSION['role'] : '';
if (empty($_SESSION['user_id']) || !in_array($role, ['Listener', 'Artist'], true)) {
    respond(401, false, 'Sign in to view your playlists.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    respond(405, false, 'View playlists using GET.');
}

$playlistIdValue = $_GET['playlist_id'] ?? '';
if (!is_string($playlistIdValue)) {
    respond(400, false, 'Choose a valid playlist.');
}
$playlistId = null;
if ($playlistIdValue !== '') {
    if (!ctype_digit($playlistIdValue) || (int) $playlistIdValue < 1) {
        respond(400, false, 'Choose a valid playlist.');
    }
    $playlistId = (int) $playlistIdValue;
}

require_once __DIR__ . '/database_connection.php';
if (!$dbConnect) {
    error_log('Get playlists database connection failed: ' . mysqli_connect_error());
    respond(500, false, 'Your playlists are temporarily unavailable.');
}
if (!mysqli_set_charset($dbConnect, 'utf8mb4')) {
    error_log('Could not set get playlists database charset: ' . mysqli_error($dbConnect));
    respond(500, false, 'Your playlists are temporarily unavailable.');
}

$userId = (int) $_SESSION['user_id'];
if ($playlistId === null) {
    $statement = mysqli_prepare(
        $dbConnect,
        'SELECT p.playlistID, p.name, p.description, p.created_at, COUNT(ps.songID)
         FROM playlists p
         LEFT JOIN playlist_songs ps ON ps.playlistID = p.playlistID
         WHERE p.userID = ?
         GROUP BY p.playlistID, p.name, p.description, p.created_at
         ORDER BY p.created_at DESC, p.playlistID DESC'
    );
    if (!$statement || !mysqli_stmt_bind_param($statement, 'i', $userId) ||
        !mysqli_stmt_execute($statement) ||
        !mysqli_stmt_bind_result($statement, $storedPlaylistId, $name, $description, $createdAt, $songCount)) {
        error_log('Could not query user playlist list: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
        if ($statement) {
            mysqli_stmt_close($statement);
        }
        respond(500, false, 'Your playlists are temporarily unavailable.');
    }

    $playlists = [];
    while (mysqli_stmt_fetch($statement)) {
        $playlists[] = [
            'id' => (int) $storedPlaylistId,
            'name' => $name,
            'description' => $description,
            'createdAt' => $createdAt,
            'songCount' => (int) $songCount
        ];
    }
    mysqli_stmt_close($statement);
    respond(200, true, '', $playlists, $_SESSION['csrf_token'] ?? null);
}

$sql = "SELECT p.playlistID, p.name, p.description, p.created_at,
               s.songID, s.title, s.audio_path, s.cover_path,
               COALESCE(a.artist_name, u.username), u.username,
               s.likes_count, s.saves_count, viewer_like.songID, viewer_save.songID
        FROM playlists p
        LEFT JOIN playlist_songs ps ON ps.playlistID = p.playlistID
        LEFT JOIN songs s ON s.songID = ps.songID
          AND s.status IN ('published', 'scheduled') AND s.release_date <= CURDATE()
        LEFT JOIN users u ON u.userID = s.userID AND LOWER(u.status) = 'active'
        LEFT JOIN artist_profiles a ON a.userID = u.userID
        LEFT JOIN song_likes viewer_like ON viewer_like.songID = s.songID AND viewer_like.userID = ?
        LEFT JOIN saved_songs viewer_save ON viewer_save.songID = s.songID AND viewer_save.userID = ?
        WHERE p.userID = ? AND p.playlistID = ?
        ORDER BY p.created_at DESC, p.playlistID DESC, ps.position ASC";

$statement = mysqli_prepare($dbConnect, $sql);
if (!$statement || !mysqli_stmt_bind_param(
        $statement,
        'iiii',
        $userId,
        $userId,
        $userId,
        $playlistId
    ) ||
    !mysqli_stmt_execute($statement) ||
    !mysqli_stmt_bind_result(
        $statement,
        $storedPlaylistId,
        $name,
        $description,
        $createdAt,
        $songId,
        $title,
        $audioPath,
        $coverPath,
        $artistName,
        $username,
        $likes,
        $saves,
        $likedSongId,
        $savedSongId
    )) {
    error_log('Could not query user playlists: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
    if ($statement) {
        mysqli_stmt_close($statement);
    }
    respond(500, false, 'Your playlists are temporarily unavailable.');
}

$playlistsById = [];
while (mysqli_stmt_fetch($statement)) {
    $storedPlaylistId = (int) $storedPlaylistId;
    if (!isset($playlistsById[$storedPlaylistId])) {
        $playlistsById[$storedPlaylistId] = [
            'id' => $storedPlaylistId,
            'name' => $name,
            'description' => $description,
            'createdAt' => $createdAt,
            'songs' => []
        ];
    }
    if ($songId === null || !$title || !$username) {
        continue;
    }

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

    $playlistsById[$storedPlaylistId]['songs'][] = [
        'id' => (int) $songId,
        'title' => $title,
        'artistName' => $artistName ?: $username,
        'audioUrl' => $audioUrl,
        'coverUrl' => $coverUrl,
        'likes' => (int) $likes,
        'saves' => (int) $saves,
        'liked' => $likedSongId !== null,
        'saved' => $savedSongId !== null
    ];
}
mysqli_stmt_close($statement);

if ($playlistId !== null && !isset($playlistsById[$playlistId])) {
    respond(404, false, 'This playlist could not be found.');
}

$playlist = array_values($playlistsById)[0];
$playlist['songCount'] = count($playlist['songs']);
respond(200, true, '', [$playlist], $_SESSION['csrf_token'] ?? null);
