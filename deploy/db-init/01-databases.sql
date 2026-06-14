-- Runs once, on first MariaDB init (mounted into /docker-entrypoint-initdb.d).
-- Creates the four per-service databases and grants the compose DB user
-- (MARIADB_USER, default `tds`) full access to each. One database per service
-- keeps the per-service `phinx_migration` logs from colliding.

CREATE DATABASE IF NOT EXISTS `tds_auth`              CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `tds_contact_ratelimit` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `tds_content`           CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `tds_customer`          CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- MARIADB_USER is created by the image before these scripts run; grant it the
-- four databases. '%' so the app container can connect over the compose net.
GRANT ALL PRIVILEGES ON `tds_auth`.*              TO 'tds'@'%';
GRANT ALL PRIVILEGES ON `tds_contact_ratelimit`.* TO 'tds'@'%';
GRANT ALL PRIVILEGES ON `tds_content`.*           TO 'tds'@'%';
GRANT ALL PRIVILEGES ON `tds_customer`.*          TO 'tds'@'%';
FLUSH PRIVILEGES;
