CREATE TABLE saved_songs (
    userID INT(11) NOT NULL,
    songID INT(11) NOT NULL,
    saved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (userID, songID),
    KEY saved_songs_song (songID),
    CONSTRAINT saved_songs_user_fk FOREIGN KEY (userID) REFERENCES users (userID) ON DELETE CASCADE,
    CONSTRAINT saved_songs_song_fk FOREIGN KEY (songID) REFERENCES songs (songID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
