<?php
$pageTitle = "MelodyMatch | Login";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="/MelodyMatch/Frontend/css/auth.css">
</head>
<body>
    <div class="auth-shell">
        <div class="auth-card">
            <div class="auth-header">
                <p class="eyebrow">Welcome back</p>
                <h1>Log in to MelodyMatch</h1>
                <p>Pick up where your sound left off.</p>
            </div>

            <form class="auth-form">
                <label>
                    Email
                    <input type="email" placeholder="you@example.com">
                </label>
                <label>
                    Password
                    <input type="password" placeholder="Enter your password">
                </label>
                <button type="submit">Login</button>
            </form>

            <div class="auth-footer">
                <p>New here? <a href="register.php">Create an account</a></p>
                <a class="ghost-link" href="homepage.php">Back to home</a>
            </div>
        </div>
    </div>
</body>
</html>
