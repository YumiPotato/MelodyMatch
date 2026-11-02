ALTER TABLE artist_profiles
    DROP COLUMN instagram_url;

ALTER TABLE users
    DROP COLUMN country;

ALTER TABLE songs
    ADD COLUMN mood VARCHAR(100) NOT NULL DEFAULT '' AFTER language;
