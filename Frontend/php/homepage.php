<?php
$pageTitle = "MelodyMatch | Music Discovery";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="/MelodyMatch/Frontend/css/homepage.css">
</head>
<body>
    <div class="page-shell">
        <aside class="sidebar">
            <div class="brand-block">
                <div class="brand-mark">MM</div>
                <div>
                    <h2>MelodyMatch</h2>
                    <p>Gen-Z sound space</p>
                </div>
            </div>
            <nav>
                <a href="#discover">✦ Discover</a>
                <a href="#playlists">♪ Playlists</a>
                <a href="#artists">☾ Artists</a>
                <a href="#library">♡ Library</a>
            </nav>
            <div class="auth-actions">
                <a class="nav-btn" href="login.php">Login</a>
                <a class="nav-btn alt" href="register.php">Register</a>
            </div>
        </aside>

        <main class="main-content">
            <header class="topbar">
                <div class="search-box">
                    <span>🔎</span>
                    <input type="text" placeholder="Search songs, artists, playlists...">
                </div>
                <div class="profile-pill">MV</div>
            </header>

            <section class="hero-card" id="discover">
                <div>
                    <p class="eyebrow">New mood</p>
                    <h1>Feel the vibe with soft pink playlists and chill discoveries.</h1>
                    <p>Browse featured playlists, explore artists and find your next favorite sound.</p>
                </div>
                <a class="button primary" href="#playlists">Explore playlists</a>
            </section>

            <section class="section">
                <div class="section-title">
                    <h3>Featured Playlists</h3>
                    <a href="#">See all</a>
                </div>
                <div class="card-grid" id="playlists">
                    <article class="music-card">
                        <div class="cover pink"></div>
                        <h4>Midnight Chill</h4>
                        <p>Lo-fi, jazz and calm evenings</p>
                    </article>
                    <article class="music-card">
                        <div class="cover purple"></div>
                        <h4>Focus Flow</h4>
                        <p>Soft beats for studying and working</p>
                    </article>
                    <article class="music-card">
                        <div class="cover rose"></div>
                        <h4>Feel Good Hits</h4>
                        <p>Bright pop and uplifting tracks</p>
                    </article>
                </div>
            </section>

            <section class="section">
                <div class="section-title">
                    <h3>Popular Artists</h3>
                    <a href="#">View more</a>
                </div>
                <div class="artist-list" id="artists">
                    <div class="artist-item">
                        <div class="avatar">A</div>
                        <div>
                            <strong>Ava Lane</strong>
                            <p>Indie pop • 120K listeners</p>
                        </div>
                    </div>
                    <div class="artist-item">
                        <div class="avatar">N</div>
                        <div>
                            <strong>Noah Reed</strong>
                            <p>Alternative • 98K listeners</p>
                        </div>
                    </div>
                    <div class="artist-item">
                        <div class="avatar">M</div>
                        <div>
                            <strong>Mia Sol</strong>
                            <p>R&B • 87K listeners</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="section">
                <div class="section-title">
                    <h3>Your Library</h3>
                    <a href="#">Manage</a>
                </div>
                <div class="library-list" id="library">
                    <div class="library-item">
                        <span>♡</span>
                        <div>
                            <strong>Liked Songs</strong>
                            <p>243 tracks</p>
                        </div>
                    </div>
                    <div class="library-item">
                        <span>♪</span>
                        <div>
                            <strong>Recently Played</strong>
                            <p>14 recent listens</p>
                        </div>
                    </div>
                    <div class="library-item">
                        <span>✦</span>
                        <div>
                            <strong>Saved Playlists</strong>
                            <p>6 curated lists</p>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
