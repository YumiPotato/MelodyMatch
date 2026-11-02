<?php

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/database_connection.php';

if (!$dbConnect) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The artist list is unavailable.']);
    exit;
}

if (!mysqli_set_charset($dbConnect, 'utf8mb4')) {
    error_log('Could not set the database connection charset: ' . mysqli_error($dbConnect));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The artist list is unavailable.']);
    exit;
}

$result = mysqli_query(
    $dbConnect,
    "SELECT DISTINCT artist_name
     FROM artist_profiles
     WHERE artist_name IS NOT NULL AND TRIM(artist_name) <> ''
     ORDER BY artist_name"
);

if (!$result) {
    error_log('Could not load artist names: ' . mysqli_error($dbConnect));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The artist list is unavailable.']);
    exit;
}

$artists = [];
while ($row = mysqli_fetch_assoc($result)) {
    $artists[] = $row['artist_name'];
}

echo json_encode(['success' => true, 'artists' => $artists], JSON_UNESCAPED_UNICODE);
