<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
mysqli_report(MYSQLI_REPORT_OFF);

function respond($statusCode, $success, $message, $songId = null)
{
    http_response_code($statusCode);
    $response = ['success' => $success, 'message' => $message];
    if ($songId !== null) {
        $response['songId'] = $songId;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, false, 'Submit a song release using POST.');
}

session_start();
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Artist') {
    respond(401, false, 'Sign in with an artist account to release music.');
}

$csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
    ? $_POST['csrf_token']
    : '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    respond(403, false, 'Your session expired. Refresh the page and try again.');
}

$title = isset($_POST['title']) && is_string($_POST['title']) ? trim($_POST['title']) : '';
$description = isset($_POST['description']) && is_string($_POST['description'])
    ? trim($_POST['description'])
    : '';
$genre = isset($_POST['genre']) && is_string($_POST['genre']) ? trim($_POST['genre']) : '';
$language = isset($_POST['language']) && is_string($_POST['language']) ? trim($_POST['language']) : '';
$mood = isset($_POST['mood']) && is_string($_POST['mood']) ? trim($_POST['mood']) : '';
$releaseDate = isset($_POST['release_date']) && is_string($_POST['release_date'])
    ? trim($_POST['release_date'])
    : '';

if ($title === '' || strlen($title) > 150) {
    respond(400, false, 'Enter a song title of 150 characters or fewer.');
}
if (strlen($description) > 2000) {
    respond(400, false, 'Keep the song description to 2,000 characters or fewer.');
}
if ($genre === '' || strlen($genre) > 100 || $language === '' || strlen($language) > 50) {
    respond(400, false, 'Choose a genre and language for your release.');
}
if (preg_match('/[\x00-\x1F\x7F]/', $mood) || strlen($mood) > 100) {
    respond(400, false, 'Keep the song mood to 100 characters or fewer.');
}
$releaseDateValue = DateTime::createFromFormat('!Y-m-d', $releaseDate);
if (!$releaseDateValue || $releaseDateValue->format('Y-m-d') !== $releaseDate) {
    respond(400, false, 'Choose a valid release date.');
}

$audioFile = isset($_FILES['audio_file']) ? $_FILES['audio_file'] : null;
if (!$audioFile || $audioFile['error'] !== UPLOAD_ERR_OK ||
    $audioFile['size'] < 1 || $audioFile['size'] > 30 * 1024 * 1024 ||
    !is_uploaded_file($audioFile['tmp_name'])) {
    respond(400, false, 'Choose an audio file smaller than 30 MB.');
}

$audioMimeTypes = [
    'audio/mpeg' => 'mp3',
    'audio/mp3' => 'mp3',
    'audio/wav' => 'wav',
    'audio/x-wav' => 'wav',
    'audio/vnd.wave' => 'wav',
    'audio/mp4' => 'm4a',
    'audio/x-m4a' => 'm4a',
    'audio/aac' => 'aac',
    'audio/ogg' => 'ogg',
    'application/ogg' => 'ogg',
    'audio/flac' => 'flac',
    'audio/x-flac' => 'flac'
];
$fileInfo = new finfo(FILEINFO_MIME_TYPE);
$audioMimeType = $fileInfo->file($audioFile['tmp_name']);
if (!isset($audioMimeTypes[$audioMimeType])) {
    respond(400, false, 'Use an MP3, WAV, M4A, AAC, OGG, or FLAC audio file.');
}

$coverFile = isset($_FILES['cover_art']) ? $_FILES['cover_art'] : null;
$coverExtension = '';
if ($coverFile && $coverFile['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($coverFile['error'] !== UPLOAD_ERR_OK || $coverFile['size'] > 5 * 1024 * 1024 ||
        !is_uploaded_file($coverFile['tmp_name'])) {
        respond(400, false, 'Choose cover art smaller than 5 MB.');
    }

    $imageInfo = @getimagesize($coverFile['tmp_name']);
    $extensionsByImageType = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp'
    ];
    if (!$imageInfo || !isset($extensionsByImageType[$imageInfo[2]])) {
        respond(400, false, 'Cover art must be a JPEG, PNG, or WebP image.');
    }
    $coverExtension = $extensionsByImageType[$imageInfo[2]];
}

require_once __DIR__ . '/database_connection.php';
if (!$dbConnect) {
    error_log('Song release database connection failed: ' . mysqli_connect_error());
    respond(500, false, 'Your song could not be released right now.');
}
if (!mysqli_set_charset($dbConnect, 'utf8mb4')) {
    error_log('Could not set song release database charset: ' . mysqli_error($dbConnect));
    respond(500, false, 'Your song could not be released right now.');
}

$userId = (int) $_SESSION['user_id'];
$artistCheck = mysqli_prepare($dbConnect, 'SELECT userID FROM artist_profiles WHERE userID = ? LIMIT 1');
if (!$artistCheck || !mysqli_stmt_bind_param($artistCheck, 'i', $userId) ||
    !mysqli_stmt_execute($artistCheck)) {
    error_log('Could not validate artist account for release: ' . ($artistCheck ? mysqli_stmt_error($artistCheck) : mysqli_error($dbConnect)));
    if ($artistCheck) {
        mysqli_stmt_close($artistCheck);
    }
    respond(500, false, 'Your song could not be released right now.');
}
mysqli_stmt_store_result($artistCheck);
$isArtist = mysqli_stmt_num_rows($artistCheck) === 1;
mysqli_stmt_close($artistCheck);
if (!$isArtist) {
    respond(403, false, 'An active artist profile is required to release music.');
}

$uploadRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads';
$audioDirectory = $uploadRoot . DIRECTORY_SEPARATOR . 'song_tracks';
$coverDirectory = $uploadRoot . DIRECTORY_SEPARATOR . 'song_covers';
foreach ([$audioDirectory, $coverDirectory] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        error_log('Could not create song upload directory: ' . $directory);
        respond(500, false, 'Your song could not be released right now.');
    }
}

$audioFileName = bin2hex(random_bytes(16)) . '.' . $audioMimeTypes[$audioMimeType];
$audioPath = $audioDirectory . DIRECTORY_SEPARATOR . $audioFileName;
$audioRelativePath = 'uploads/song_tracks/' . $audioFileName;
$coverPath = '';
$coverRelativePath = '';
if (!move_uploaded_file($audioFile['tmp_name'], $audioPath)) {
    error_log('Could not store uploaded artist audio.');
    respond(500, false, 'Your song could not be released right now.');
}

if ($coverExtension !== '') {
    $coverFileName = bin2hex(random_bytes(16)) . '.' . $coverExtension;
    $coverPath = $coverDirectory . DIRECTORY_SEPARATOR . $coverFileName;
    if (!move_uploaded_file($coverFile['tmp_name'], $coverPath)) {
        unlink($audioPath);
        error_log('Could not store uploaded artist cover art.');
        respond(500, false, 'Your song could not be released right now.');
    }
    $coverRelativePath = 'uploads/song_covers/' . $coverFileName;
}

$status = $releaseDate > date('Y-m-d') ? 'scheduled' : 'published';
$statement = mysqli_prepare(
    $dbConnect,
    'INSERT INTO songs
     (userID, title, description, genre, language, mood, release_date, audio_path, cover_path, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
if (!$statement || !mysqli_stmt_bind_param(
    $statement,
    'isssssssss',
    $userId,
    $title,
    $description,
    $genre,
    $language,
    $mood,
    $releaseDate,
    $audioRelativePath,
    $coverRelativePath,
    $status
) || !mysqli_stmt_execute($statement)) {
    error_log('Could not save artist song release: ' . ($statement ? mysqli_stmt_error($statement) : mysqli_error($dbConnect)));
    if ($statement) {
        mysqli_stmt_close($statement);
    }
    unlink($audioPath);
    if ($coverPath !== '') {
        unlink($coverPath);
    }
    respond(500, false, 'Your song could not be released right now.');
}

$songId = mysqli_insert_id($dbConnect);
mysqli_stmt_close($statement);
respond(201, true, $status === 'scheduled'
    ? 'Your song is scheduled for release.'
    : 'Your song has been released.', $songId);
