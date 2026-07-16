-- Schema fixes for live servers (2026-02-09)
-- Safe to run on existing databases.

-- Result checker purchases columns (idempotent)
SET @rcp_table_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'result_checker_purchases'
);

SET @rcp_agent_id_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'result_checker_purchases'
    AND COLUMN_NAME = 'agent_id'
);
SET @sql = IF(@rcp_table_exists = 1 AND @rcp_agent_id_exists = 0,
  'ALTER TABLE result_checker_purchases ADD COLUMN agent_id INT NULL',
  'SELECT \"result_checker_purchases.agent_id already exists or table missing\"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @rcp_admin_price_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'result_checker_purchases'
    AND COLUMN_NAME = 'admin_price'
);
SET @sql = IF(@rcp_table_exists = 1 AND @rcp_admin_price_exists = 0,
  'ALTER TABLE result_checker_purchases ADD COLUMN admin_price DECIMAL(10,2) NULL',
  'SELECT \"result_checker_purchases.admin_price already exists or table missing\"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @rcp_profit_amount_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'result_checker_purchases'
    AND COLUMN_NAME = 'profit_amount'
);
SET @sql = IF(@rcp_table_exists = 1 AND @rcp_profit_amount_exists = 0,
  'ALTER TABLE result_checker_purchases ADD COLUMN profit_amount DECIMAL(10,2) NULL',
  'SELECT \"result_checker_purchases.profit_amount already exists or table missing\"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @rcp_sms_phone_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'result_checker_purchases'
    AND COLUMN_NAME = 'sms_phone'
);
SET @sql = IF(@rcp_table_exists = 1 AND @rcp_sms_phone_exists = 0,
  'ALTER TABLE result_checker_purchases ADD COLUMN sms_phone VARCHAR(20) NULL',
  'SELECT \"result_checker_purchases.sms_phone already exists or table missing\"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @rcp_notification_email_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'result_checker_purchases'
    AND COLUMN_NAME = 'notification_email'
);
SET @sql = IF(@rcp_table_exists = 1 AND @rcp_notification_email_exists = 0,
  'ALTER TABLE result_checker_purchases ADD COLUMN notification_email VARCHAR(255) NULL',
  'SELECT \"result_checker_purchases.notification_email already exists or table missing\"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Agent result checker pricing table
CREATE TABLE IF NOT EXISTS agent_result_checker_pricing (
  agent_id INT NOT NULL,
  card_type ENUM('BECE','WASSCE') NOT NULL,
  custom_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (agent_id, card_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SMS notifications schema updates (idempotent)
SET @sms_table_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sms_notifications'
);

SET @sms_purpose_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sms_notifications'
    AND COLUMN_NAME = 'purpose'
);
SET @sql = IF(@sms_table_exists = 1 AND @sms_purpose_exists = 1,
  'ALTER TABLE sms_notifications MODIFY purpose VARCHAR(50) NOT NULL',
  'SELECT \"sms_notifications.purpose missing or table missing\"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sms_message_id_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sms_notifications'
    AND COLUMN_NAME = 'message_id'
);
SET @sql = IF(@sms_table_exists = 1 AND @sms_message_id_exists = 0,
  'ALTER TABLE sms_notifications ADD COLUMN message_id VARCHAR(100) NULL',
  'SELECT \"sms_notifications.message_id already exists or table missing\"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sms_cost_exists = (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sms_notifications'
    AND COLUMN_NAME = 'cost'
);
SET @sql = IF(@sms_table_exists = 1 AND @sms_cost_exists = 0,
  'ALTER TABLE sms_notifications ADD COLUMN cost DECIMAL(10,2) NULL',
  'SELECT \"sms_notifications.cost already exists or table missing\"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
