SET sql_mode = '';
SET NAMES utf8mb4;

CREATE DATABASE acc_wp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'acc_wp'@'%' IDENTIFIED BY 'OldPa$$w0rd''x';
GRANT ALL ON acc_wp.* TO 'acc_wp'@'%';

CREATE DATABASE acc_shop CHARACTER SET utf8 COLLATE utf8_general_ci;
CREATE USER 'acc_shop'@'%' IDENTIFIED BY 'shopPass#1';
GRANT ALL ON acc_shop.* TO 'acc_shop'@'%';

CREATE DATABASE acc_app CHARACTER SET utf8mb4;
CREATE USER 'acc_app'@'%' IDENTIFIED BY 'appPass';
GRANT ALL ON acc_app.* TO 'acc_app'@'%';

USE acc_wp;
CREATE TABLE wp_options (
  option_id BIGINT AUTO_INCREMENT PRIMARY KEY,
  option_name VARCHAR(191) NOT NULL,
  option_value LONGTEXT NOT NULL,
  bin_value VARBINARY(32) NULL
) ENGINE=InnoDB;
INSERT INTO wp_options (option_name, option_value, bin_value) VALUES
  ('siteurl', 'https://example.pl', NULL),
  ('blogname', 'Zażółć gęślą jaźń 🙂', X'00FF10AB'),
  ('tricky', 'quote '' and backslash \\ and `acc_wp`.wp_options text', NULL);
CREATE TABLE wp_posts (
  ID BIGINT AUTO_INCREMENT PRIMARY KEY,
  post_title TEXT,
  post_date DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  post_content LONGTEXT,
  `rank` INT DEFAULT 0
) ENGINE=MyISAM;
INSERT INTO wp_posts (post_title, post_date, post_content, `rank`) VALUES
  ('Hello', NOW(), 'Line1\nLine2', 1),
  ('Zero date', '0000-00-00 00:00:00', 'x', 2);
CREATE TABLE wp_log (id INT AUTO_INCREMENT PRIMARY KEY, msg VARCHAR(100));
CREATE VIEW wp_view AS SELECT ID, post_title FROM wp_posts WHERE `rank` > 0;
DELIMITER ;;
CREATE TRIGGER wp_posts_ai AFTER INSERT ON wp_posts FOR EACH ROW BEGIN INSERT INTO wp_log (msg) VALUES (CONCAT('new post ', NEW.ID)); END;;
CREATE DEFINER='acc_wp'@'%' PROCEDURE wp_count() BEGIN SELECT COUNT(*) FROM wp_posts; END;;
CREATE DEFINER='acc_wp'@'%' FUNCTION wp_double(x INT) RETURNS INT DETERMINISTIC BEGIN RETURN x * 2; END;;
DELIMITER ;
INSERT INTO wp_posts (post_title, post_date, post_content) VALUES ('Trigger test', NOW(), 'y');

USE acc_shop;
CREATE TABLE ps_product (id_product INT AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(64), price DECIMAL(20,6)) ENGINE=InnoDB;
INSERT INTO ps_product (reference, price) VALUES ('REF-1', 10.5), ('REF-2', 99.99), ('REF-3', 0.1);
CREATE TABLE ps_customer (id_customer INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255), note TEXT) ENGINE=InnoDB;
INSERT INTO ps_customer (email, note) VALUES ('a@b.pl', 'ąęśćżźńół'), ('c@d.pl', NULL);
-- procedura nalezaca do root -> uzytkownik acc_shop nie moze jej zrzucic (test fallbacku bez --routines)
DELIMITER ;;
CREATE PROCEDURE shop_root_proc() BEGIN SELECT 1; END;;
DELIMITER ;

USE acc_app;
CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100), created_at TIMESTAMP NULL) ENGINE=InnoDB;
INSERT INTO users (name, created_at) VALUES ('Ala', NOW()), ('Ola', NULL), ('Ula', NOW());
CREATE TABLE sessions (id VARCHAR(64) PRIMARY KEY, payload BLOB) ENGINE=InnoDB;
INSERT INTO sessions VALUES ('s1', X'0001020304');
