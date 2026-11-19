-- Run once in phpMyAdmin against the existing sarisari_db database.
-- Does not modify existing tables or data.
CREATE TABLE IF NOT EXISTS dsa_adjustment_stack (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 movement_id BIGINT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 quantity_change DECIMAL(12,3) NOT NULL,
 undone_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_user_stack (user_id, undone_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
