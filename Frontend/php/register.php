<?php
$pageTitle = "MelodyMatch | Register";
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
                <p class="eyebrow">Join the vibe</p>
                <h1>Create your account</h1>
                <p>Start your personalized music journey.</p>
            </div>

            <form class="auth-form">
                <label>
                    Full name
                    <input type="text" placeholder="Your name">
                </label>
                <label>
                    Email
                    <input type="email" placeholder="you@example.com">
                </label>
                <label>
                    Password
                    <input type="password" placeholder="Create a password">
                </label>
                <button type="submit">Register</button>
            </form>

            <div class="auth-footer">
                <p>Already have an account? <a href="login.php">Log in</a></p>
                <a class="ghost-link" href="homepage.php">Back to home</a>
            </div>
        </div>
    </div>
</body>
</html>
