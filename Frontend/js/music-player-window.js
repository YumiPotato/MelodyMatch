(function () {
    const state = window.MelodyMatchPlayerState;
    const audio = document.getElementById('audioPlayer');
    if (!state || document.body.dataset.musicPlayerInitialized === 'true') return;
    document.body.dataset.musicPlayerInitialized = 'true';

    function currentSong() {
        return state.songs[state.currentIndex] || null;
    }

    function setStatus(message) {
        document.getElementById('playerStatus').textContent = message;
    }

    function updateReactionButtons() {
        const song = currentSong();
        const likeButton = document.getElementById('likeSong');
        const saveButton = document.getElementById('saveSong');
        likeButton.setAttribute('aria-pressed', String(Boolean(song && song.liked)));
        likeButton.textContent = song ? `${song.liked ? '♥ Liked' : '♡ Like'} · ${song.likes}` : '♡ Like';
        saveButton.setAttribute('aria-pressed', String(Boolean(song && song.saved)));
        saveButton.textContent = song ? `${song.saved ? '♥ Saved' : '♡ Save'} · ${song.saves}` : '♡ Save';
    }

    function playAt(index, addToHistory) {
        if (!state.songs.length) return;
        const nextIndex = (index + state.songs.length) % state.songs.length;
        const song = state.songs[nextIndex];
        const previousSong = currentSong();
        if (addToHistory && previousSong && previousSong.id !== song.id) {
            state.history.push(previousSong.id);
        }
        state.currentIndex = nextIndex;
        state.currentSongId = song.id;
        document.getElementById('playerTitle').textContent = song.title;
        document.getElementById('playerArtist').textContent = song.artistName;
        const cover = document.getElementById('playerCover');
        if (song.coverUrl) {
            cover.src = song.coverUrl;
            cover.alt = `${song.title} cover`;
            cover.hidden = false;
        } else {
            cover.removeAttribute('src');
            cover.alt = '';
            cover.hidden = true;
        }
        if (audio.src !== song.audioUrl) audio.src = song.audioUrl;
        updateReactionButtons();
        audio.play().catch(error => {
            console.error('Could not play selected song.', error);
            setStatus('Playback could not start. Press Play in the audio controls.');
        });
    }

    function nextSong() {
        if (state.songs.length < 2) {
            setStatus('There are no other released songs in the queue.');
            return;
        }
        if (state.shuffle) {
            const offset = 1 + Math.floor(Math.random() * (state.songs.length - 1));
            playAt(state.currentIndex + offset, true);
        } else if (state.currentIndex + 1 < state.songs.length) {
            playAt(state.currentIndex + 1, true);
        } else if (state.repeat) {
            playAt(state.currentIndex, false);
        } else {
            audio.pause();
            audio.currentTime = 0;
            setStatus('End of the release queue.');
        }
    }

    async function toggleReaction(endpoint, button, reaction) {
        const song = currentSong();
        if (!song || !state.csrfToken) {
            setStatus('Sign in again to like or save songs.');
            return;
        }
        button.disabled = true;
        const accountId = state.accountId;
        const profile = await window.MelodyMatchMusicPlayer.syncAccount();
        if (!profile || String(profile.id) !== String(accountId)) {
            setStatus('Playback stopped because the signed-in account changed.');
            button.disabled = false;
            return;
        }
        try {
            const response = await fetch(`${state.backendBase}${endpoint}`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams({
                    song_id: String(song.id),
                    csrf_token: state.csrfToken
                })
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'This song could not be updated.');
            }
            song[reaction] = result[reaction];
            song[reaction === 'liked' ? 'likes' : 'saves'] = result[reaction === 'liked' ? 'likes' : 'saves'];
            updateReactionButtons();
            setStatus(result.message);
        } catch (error) {
            console.error('Could not update song reaction.', error);
            setStatus(error.message);
        } finally {
            button.disabled = false;
        }
    }

    window.playMelodyMatchSong = songId => {
        const index = state.songs.findIndex(song => song.id === songId);
        if (index < 0) {
            console.error('The selected song is missing from the music queue.');
            return;
        }
        playAt(index, true);
    };

    window.updateMelodyMatchPlayerSong = (songId, updates) => {
        const song = state.songs.find(item => String(item.id) === String(songId));
        if (!song) return;
        Object.assign(song, updates);
        if (String(state.currentSongId) === String(songId)) {
            updateReactionButtons();
        }
    };

    document.getElementById('previousSong').addEventListener('click', () => {
        const previousId = state.history.pop();
        const previousIndex = state.songs.findIndex(song => song.id === previousId);
        if (previousIndex >= 0) playAt(previousIndex, false);
        else playAt(state.currentIndex - 1, false);
    });
    document.getElementById('nextSong').addEventListener('click', nextSong);
    document.getElementById('toggleShuffle').addEventListener('click', event => {
        state.shuffle = !state.shuffle;
        event.currentTarget.setAttribute('aria-pressed', String(state.shuffle));
        event.currentTarget.textContent = `Shuffle ${state.shuffle ? 'on' : 'off'}`;
    });
    document.getElementById('toggleRepeat').addEventListener('click', event => {
        state.repeat = !state.repeat;
        event.currentTarget.setAttribute('aria-pressed', String(state.repeat));
        event.currentTarget.textContent = `Repeat ${state.repeat ? 'on' : 'off'}`;
    });
    document.getElementById('likeSong').addEventListener('click', event => {
        toggleReaction('like_song.php', event.currentTarget, 'liked');
    });
    document.getElementById('saveSong').addEventListener('click', event => {
        toggleReaction('save_song.php', event.currentTarget, 'saved');
    });
    document.getElementById('closePlayer').addEventListener('click', () => {
        window.MelodyMatchMusicPlayer.stop('');
    });
    audio.addEventListener('ended', () => {
        if (state.repeat) playAt(state.currentIndex, false);
        else nextSong();
    });

    updateReactionButtons();
}());
