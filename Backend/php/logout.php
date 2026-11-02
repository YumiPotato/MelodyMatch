<?php

session_start();
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $cookieParameters = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $cookieParameters['path'],
        $cookieParameters['domain'],
        $cookieParameters['secure'],
        $cookieParameters['httponly']
    );
}

if (!session_destroy()) {
    error_log('Could not destroy listener session during logout.');
    http_response_code(500);
    exit('Logout is temporarily unavailable.');
}

header('Location: /MelodyMatch/Frontend/html/login.html');
exit;
