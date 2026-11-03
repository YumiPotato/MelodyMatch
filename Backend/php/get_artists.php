<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
mysqli_report(MYSQLI_REPORT_OFF);

function respond($statusCode, $success, $message, $artists = null)
{
    http_response_code($statusCode);
    $response = ['success' => $success, 'message' => $message];
    if ($artists !== null) {
        $response['artists'] = $artists;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/database_connection.php';
if (!$dbConnect) {
    error_log('Artist list database connection failed: ' . mysqli_connect_error());
    respond(500, false, 'The artist list is unavailable.');
}
if (!mysqli_set_charset($dbConnect, 'utf8mb4')) {
    error_log('Could not set artist list database charset: ' . mysqli_error($dbConnect));
    respond(500, false, 'The artist list is unavailable.');
}

$result = mysqli_query(
    $dbConnect,
    "SELECT u.userID, u.username, u.profile_picture, a.artist_name, a.genre,
            COUNT(s.songID) AS song_count
     FROM users u
     INNER JOIN artist_profiles a ON a.userID = u.userID
     LEFT JOIN songs s ON s.userID = u.userID
       AND s.status IN ('published', 'scheduled') AND s.release_date <= CURDATE()
     WHERE u.role = 'Artist' AND LOWER(u.status) = 'active'
     GROUP BY u.userID, u.username, u.profile_picture, a.artist_name, a.genre
     ORDER BY song_count DESC, a.artist_name ASC
     LIMIT 30"
);
if (!$result) {
    error_log('Could not load artist list: ' . mysqli_error($dbConnect));
    respond(500, false, 'The artist list is unavailable.');
}

$artists = [];
while ($row = mysqli_fetch_assoc($result)) {
    $profileImage = null;
    if (is_string($row['profile_picture']) &&
        preg_match('~\Auploads/profile_pictures/[a-f0-9]{32}\.(?:jpg|png|gif|webp)\z~', $row['profile_picture'])) {
        $imagePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $row['profile_picture']);
        if (is_file($imagePath)) {
            $profileImage = '/MelodyMatch/' . $row['profile_picture'];
        }
    }
    $artists[] = [
        'id' => (int) $row['userID'],
        'name' => $row['artist_name'] ?: $row['username'],
        'genre' => $row['genre'],
        'songs' => (int) $row['song_count'],
        'profilePicture' => $profileImage
    ];
}
mysqli_free_result($result);
respond(200, true, '', $artists);
