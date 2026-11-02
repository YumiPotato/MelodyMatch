(function () {
    if (window.MelodyMatchPlayerState) return;

    const backendBase = new URL('/MelodyMatch/Backend/php/', window.location.href).href;
    const dock = document.createElement('aside');
    dock.className = 'music-player-dock';
    dock.id = 'musicPlayerDock';
    dock.hidden = true;
    dock.setAttribute('aria-label', 'MelodyMatch music player');
    dock.innerHTML = `
        <img class="music-player-cover" id="playerCover" alt="" hidden>
        <div class="music-player-track">
            <strong id="playerTitle">Nothing playing</strong>
            <span id="playerArtist"></span>
        </div>
        <audio id="audioPlayer" controls preload="metadata"></audio>
        <div class="music-player-controls" aria-label="Playback controls">
            <button id="previousSong" type="button" aria-label="Previous song">|◀</button>
            <button id="toggleShuffle" type="button" aria-pressed="false">Shuffle off</button>
            <button id="toggleRepeat" type="button" aria-pressed="false">Repeat off</button>
            <button id="nextSong" type="button" aria-label="Next song">▶|</button>
            <button id="likeSong" type="button" aria-pressed="false">♡ Like</button>
            <button id="saveSong" type="button" aria-pressed="false">♡ Save</button>
        </div>
        <button class="music-player-close" id="closePlayer" type="button" aria-label="Close music player">×</button>
        <p class="music-player-status" id="playerStatus" role="status" aria-live="polite"></p>`;
    document.body.append(dock);

    const stylesheet = document.createElement('link');
    stylesheet.id = 'music-player-stylesheet';
    stylesheet.rel = 'stylesheet';
    stylesheet.href = new URL('/MelodyMatch/Frontend/css/music-player.css', window.location.href).href;
    document.head.append(stylesheet);

    window.MelodyMatchPlayerState = {
        songs: [],
        currentIndex: -1,
        currentSongId: null,
        csrfToken: '',
        backendBase,
        accountId: null,
        shuffle: false,
        repeat: false,
        history: []
    };

    let controllerLoaded = false;
    let controllerLoading = false;
    let pendingSongId = null;

    function loadController() {
        if (controllerLoaded || controllerLoading) {
            if (controllerLoaded && pendingSongId !== null) {
                window.playMelodyMatchSong(pendingSongId);
                pendingSongId = null;
            }
            return;
        }
        controllerLoading = true;
        const script = document.createElement('script');
        script.src = new URL('/MelodyMatch/Frontend/js/music-player-window.js', window.location.href).href;
        script.onload = () => {
            controllerLoaded = true;
            controllerLoading = false;
            if (pendingSongId !== null) {
                window.playMelodyMatchSong(pendingSongId);
                pendingSongId = null;
            }
        };
        script.onerror = () => {
            controllerLoading = false;
            console.error('Could not load music player controls.');
            document.getElementById('playerStatus').textContent =
                'The music player could not be loaded. Please refresh the page.';
        };
        document.body.append(script);
    }

    async function syncAccount() {
        try {
            const response = await fetch(`${backendBase}get_account_profile.php`, {
                cache: 'no-store',
                headers: { Accept: 'application/json' }
            });
            if (response.status === 401) {
                stopPlayback('Sign in to listen to music.');
                return null;
            }
            const result = await response.json();
            if (!response.ok || !result.success || !result.profile) {
                throw new Error(result.message || 'Could not verify the signed-in account.');
            }
            if (!setAccount(result.profile)) return null;
            return result.profile;
        } catch (error) {
            console.error('Could not verify music player account.', error);
            return null;
        }
    }

    function setAccount(profile) {
        const state = window.MelodyMatchPlayerState;
        if (state.accountId !== null && String(state.accountId) !== String(profile.id)) {
            stopPlayback('Playback stopped because the signed-in account changed.');
            const homepage = profile.role === 'Artist'
                ? '/MelodyMatch/Frontend/html/artist/artist_homepage.html'
                : '/MelodyMatch/Frontend/html/listener/listener_homepage.html';
            window.location.replace(homepage);
            return false;
        }
        state.accountId = profile.id;
        return true;
    }

    function stopPlayback(message) {
        const audio = document.getElementById('audioPlayer');
        audio.pause();
        audio.removeAttribute('src');
        audio.load();
        dock.hidden = true;
        const state = window.MelodyMatchPlayerState;
        state.songs = [];
        state.currentIndex = -1;
        state.currentSongId = null;
        state.csrfToken = '';
        state.accountId = null;
        state.history = [];
        document.body.classList.remove('music-player-active');
        document.getElementById('playerTitle').textContent = 'Nothing playing';
        document.getElementById('playerArtist').textContent = '';
        document.getElementById('playerCover').hidden = true;
        document.getElementById('playerStatus').textContent = message || '';
    }

    window.MelodyMatchMusicPlayer = {
        syncAccount,
        setAccount,
        stop: stopPlayback
    };

    window.openMelodyMatchPlayer = async function (songs, songId, csrfToken) {
        const account = await syncAccount();
        if (!account) {
            document.getElementById('playerStatus').textContent = 'Sign in again to play music.';
            return;
        }
        const playerSongs = songs.map(song => Object.assign({}, song, {
            audioUrl: new URL(song.audioUrl, window.location.href).href,
            coverUrl: song.coverUrl ? new URL(song.coverUrl, window.location.href).href : null
        }));
        const index = playerSongs.findIndex(song => song.id === songId);
        if (index < 0) {
            console.error('Could not find the selected song in the homepage queue.');
            return;
        }

        const state = window.MelodyMatchPlayerState;
        state.songs = playerSongs;
        state.csrfToken = csrfToken || account.csrfToken || '';
        state.accountId = account.id;
        dock.hidden = false;
        document.body.classList.add('music-player-active');
        pendingSongId = songId;
        loadController();
    };

    window.addEventListener('pagehide', () => stopPlayback(''));
    document.addEventListener('click', event => {
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (link && /\/Backend\/php\/logout\.php(?:[?#]|$)/i.test(link.href)) {
            stopPlayback('You have signed out.');
        }
    }, true);

    loadController();
    syncAccount();
    window.setInterval(syncAccount, 10000);
}());
