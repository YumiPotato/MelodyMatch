CREATE TABLE playlists (
    playlistID INT(11) NOT NULL AUTO_INCREMENT,
    userID INT(11) NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) NOT NULL DEFAULT '',
    mood VARCHAR(100) NOT NULL DEFAULT '',
    genre VARCHAR(100) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (playlistID),
    KEY playlists_user_created (userID, created_at),
    CONSTRAINT playlists_user_fk FOREIGN KEY (userID) REFERENCES users (userID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE playlist_songs (
    playlistID INT(11) NOT NULL,
    songID INT(11) NOT NULL,
    position INT(11) NOT NULL,
    PRIMARY KEY (playlistID, songID),
    UNIQUE KEY playlist_songs_position (playlistID, position),
    KEY playlist_songs_song (songID),
    CONSTRAINT playlist_songs_playlist_fk FOREIGN KEY (playlistID) REFERENCES playlists (playlistID) ON DELETE CASCADE,
    CONSTRAINT playlist_songs_song_fk FOREIGN KEY (songID) REFERENCES songs (songID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
