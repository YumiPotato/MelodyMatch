<?php

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
mysqli_report(MYSQLI_REPORT_OFF);
require_once __DIR__ . '/database_connection.php';

function respond($statusCode, $success, $message)
{
    http_response_code($statusCode);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function submittedValue($name)
{
    $value = isset($_POST[$name]) && is_string($_POST[$name]) ? trim($_POST[$name]) : '';
    return $value;
}

function characterLength($value)
{
    $length = preg_match_all('/./us', $value, $matches);
    return $length === false ? PHP_INT_MAX : $length;
}

function executeStatement($dbConnect, $sql, $types, ...$parameters)
{
    $statement = mysqli_prepare($dbConnect, $sql);
    if (!$statement) {
        error_log('Could not prepare registration query: ' . mysqli_error($dbConnect));
        throw new RuntimeException('Unable to complete registration.', 500);
    }

    $boundParameters = array_values($parameters);
    $bindArguments = [$types];
    foreach (array_keys($boundParameters) as $index) {
        $bindArguments[] = &$boundParameters[$index];
    }

    if (!call_user_func_array([$statement, 'bind_param'], $bindArguments) ||
        !mysqli_stmt_execute($statement)) {
        error_log('Could not save registration data: ' . mysqli_stmt_error($statement));
        mysqli_stmt_close($statement);
        throw new RuntimeException('Unable to complete registration.', 500);
    }

    mysqli_stmt_close($statement);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, false, 'Registration must be submitted with POST.');
}

if (!$dbConnect) {
    error_log('Registration database connection failed: ' . mysqli_connect_error());
    respond(500, false, 'Registration is temporarily unavailable.');
}

if (!mysqli_set_charset($dbConnect, 'utf8mb4')) {
    error_log('Could not set the registration database charset: ' . mysqli_error($dbConnect));
    respond(500, false, 'Registration is temporarily unavailable.');
}

$accountType = strtolower(submittedValue('role'));
$username = submittedValue('username');
$email = submittedValue('email');
$password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
$confirmPassword = isset($_POST['confirm_password']) && is_string($_POST['confirm_password'])
    ? $_POST['confirm_password']
    : '';
$artistName = submittedValue('artist_name');
$biography = submittedValue('biography');
$youtubeUrl = submittedValue('youtube_url');
$tiktokUrl = submittedValue('tiktok_url');

if (!in_array($accountType, ['listener', 'artist'], true)) {
    respond(400, false, 'Choose a valid account type.');
}
$role = ucfirst($accountType);

if ($username === '' || characterLength($username) > 100) {
    respond(400, false, 'Enter a username or name of 100 characters or fewer.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || characterLength($email) > 100) {
    respond(400, false, 'Enter a valid email address of 100 characters or fewer.');
}

if (strlen($password) < 8 || strlen($password) > 72) {
    respond(400, false, 'Your password must be between 8 and 72 characters.');
}

if ($password !== $confirmPassword) {
    respond(400, false, 'The passwords do not match.');
}

if ($accountType === 'artist') {
    $language = submittedValue('language');
    $genre = submittedValue('genre');
    $allowedGenres = [
        'Pop', 'Rock', 'Hip-Hop', 'Electronic', 'Classical', 'Jazz', 'Blues', 'Country', 'R&B', 'Folk'
    ];
    $selectedGenres = array_values(array_unique(array_filter(array_map('trim', explode(',', $genre)))));
    $invalidGenres = array_diff($selectedGenres, $allowedGenres);

    if ($artistName === '' || characterLength($artistName) > 100) {
        respond(400, false, 'Enter an artist name of 100 characters or fewer.');
    }

    if (count($selectedGenres) === 0 || count($invalidGenres) > 0 ||
        characterLength($genre) > 255) {
        respond(400, false, 'Choose one or more valid artist genres.');
    }

    if ($language === '' || characterLength($language) > 50) {
        respond(400, false, 'Choose one or more languages (up to 50 characters total).');
    }

    if (characterLength($biography) > 255 ||
        characterLength($youtubeUrl) > 255 || characterLength($tiktokUrl) > 255) {
        respond(400, false, 'Your biography and social links must be 255 characters or fewer.');
    }

    foreach (['YouTube' => $youtubeUrl, 'TikTok' => $tiktokUrl] as $label => $url) {
        if ($url !== '') {
            $parts = parse_url($url);
            if (!filter_var($url, FILTER_VALIDATE_URL) ||
                !isset($parts['scheme']) ||
                !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
                respond(400, false, 'Enter a valid ' . $label . ' URL using http or https.');
            }
        }
    }

    $genre = implode(', ', $selectedGenres);
}

$profilePictureUpload = isset($_FILES['profile_picture']) ? $_FILES['profile_picture'] : null;
$profilePictureExtension = '';
if ($profilePictureUpload && $profilePictureUpload['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($profilePictureUpload['error'] !== UPLOAD_ERR_OK ||
        $profilePictureUpload['size'] > 5 * 1024 * 1024 ||
        !is_uploaded_file($profilePictureUpload['tmp_name'])) {
        respond(400, false, 'Choose a valid profile picture smaller than 5 MB.');
    }

    $imageInfo = @getimagesize($profilePictureUpload['tmp_name']);
    $extensionsByType = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp'
    ];
    if (!$imageInfo || !isset($extensionsByType[$imageInfo[2]])) {
        respond(400, false, 'Profile pictures must be JPEG, PNG, GIF, or WebP images.');
    }

    $profilePictureExtension = $extensionsByType[$imageInfo[2]];
}

$transactionStarted = false;
$uploadedFilePath = '';

try {
    $duplicateCheck = mysqli_prepare(
        $dbConnect,
        'SELECT userID FROM users WHERE username = ? OR email = ? LIMIT 1'
    );
    if (!$duplicateCheck) {
        error_log('Could not check for duplicate accounts: ' . mysqli_error($dbConnect));
        throw new RuntimeException('Unable to complete registration.', 500);
    }
    if (!mysqli_stmt_bind_param($duplicateCheck, 'ss', $username, $email) ||
        !mysqli_stmt_execute($duplicateCheck)) {
        error_log('Could not check for duplicate accounts: ' . mysqli_stmt_error($duplicateCheck));
        mysqli_stmt_close($duplicateCheck);
        throw new RuntimeException('Unable to complete registration.', 500);
    }
    mysqli_stmt_store_result($duplicateCheck);
    $accountAlreadyExists = mysqli_stmt_num_rows($duplicateCheck) > 0;
    mysqli_stmt_close($duplicateCheck);

    if ($accountAlreadyExists) {
        throw new RuntimeException('That username or email address is already registered.', 409);
    }

    if (!mysqli_begin_transaction($dbConnect)) {
        error_log('Could not start registration transaction: ' . mysqli_error($dbConnect));
        throw new RuntimeException('Unable to complete registration.', 500);
    }
    $transactionStarted = true;

    $profilePicture = '';

    if ($profilePictureExtension !== '') {
        $uploadDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'profile_pictures';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true) && !is_dir($uploadDirectory)) {
            throw new RuntimeException('The profile picture could not be saved.', 500);
        }

        $fileName = bin2hex(random_bytes(16)) . '.' . $profilePictureExtension;
        $uploadedFilePath = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($profilePictureUpload['tmp_name'], $uploadedFilePath)) {
            throw new RuntimeException('The profile picture could not be saved.', 500);
        }

        $profilePicture = 'uploads/profile_pictures/' . $fileName;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        throw new RuntimeException('Unable to complete registration.', 500);
    }

    executeStatement(
        $dbConnect,
        'INSERT INTO users (username, email, password, role, profile_picture, created_at, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        'sssssss',
        $username,
        $email,
        $passwordHash,
        $role,
        $profilePicture,
        date('Y-m-d'),
        'active'
    );
    $userId = mysqli_insert_id($dbConnect);
    if ($userId < 1) {
        error_log('The database did not generate a user ID.');
        throw new RuntimeException('Unable to complete registration.', 500);
    }

    if ($accountType === 'artist') {
        executeStatement(
            $dbConnect,
            'INSERT INTO artist_profiles
             (userID, artist_name, genre, language, biography, youtube_url, tiktok_url)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            'issssss',
            $userId,
            $artistName,
            $genre,
            $language,
            $biography,
            $youtubeUrl,
            $tiktokUrl
        );
    }

    if (!mysqli_commit($dbConnect)) {
        error_log('Could not commit registration transaction: ' . mysqli_error($dbConnect));
        throw new RuntimeException('Unable to complete registration.', 500);
    }
    $transactionStarted = false;
} catch (RuntimeException $exception) {
    if ($transactionStarted) {
        mysqli_rollback($dbConnect);
    }
    if ($uploadedFilePath !== '' && is_file($uploadedFilePath)) {
        unlink($uploadedFilePath);
    }
    $statusCode = $exception->getCode();
    respond($statusCode >= 400 && $statusCode <= 599 ? $statusCode : 500, false, $exception->getMessage());
}

respond(201, true, 'Your account has been created. You can now log in.');
