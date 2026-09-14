-- Runs once, on first MariaDB init (mounted into /docker-entrypoint-initdb.d).
-- Creates the per-service databases and grants the compose DB user
-- (MARIADB_USER, default `tds`) full access to each. One database per service
-- keeps the per-service migration logs from colliding — `tds_frontend` holds
-- every composed extension's tables (they share one PDO and one phinxlog).

CREATE DATABASE IF NOT EXISTS `tds_auth`     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `tds_customer` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `tds_frontend` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- MARIADB_USER is created by the image before these scripts run; grant it the
-- databases. '%' so the app container can connect over the compose net.
GRANT ALL PRIVILEGES ON `tds_auth`.*     TO 'tds'@'%';
GRANT ALL PRIVILEGES ON `tds_customer`.* TO 'tds'@'%';
GRANT ALL PRIVILEGES ON `tds_frontend`.* TO 'tds'@'%';
FLUSH PRIVILEGES;
