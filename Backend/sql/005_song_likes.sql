CREATE TABLE song_likes (
    userID INT(11) NOT NULL,
    songID INT(11) NOT NULL,
    liked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (userID, songID),
    KEY song_likes_song (songID),
    CONSTRAINT song_likes_user_fk FOREIGN KEY (userID) REFERENCES users (userID) ON DELETE CASCADE,
    CONSTRAINT song_likes_song_fk FOREIGN KEY (songID) REFERENCES songs (songID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
