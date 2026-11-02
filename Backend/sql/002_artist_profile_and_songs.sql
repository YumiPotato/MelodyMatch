ALTER TABLE artist_profiles
    ADD COLUMN instagram_url VARCHAR(255) NOT NULL DEFAULT '' AFTER biography;

CREATE TABLE songs (
    songID INT(11) NOT NULL AUTO_INCREMENT,
    userID INT(11) NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    genre VARCHAR(100) NOT NULL,
    language VARCHAR(50) NOT NULL,
    release_date DATE NOT NULL,
    audio_path VARCHAR(255) NOT NULL,
    cover_path VARCHAR(255) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'published',
    views_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    likes_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    saves_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (songID),
    KEY songs_user_release (userID, status, release_date),
    CONSTRAINT songs_user_fk FOREIGN KEY (userID) REFERENCES users (userID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
