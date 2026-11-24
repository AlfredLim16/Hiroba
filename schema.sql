CREATE DATABASE IF NOT EXISTS hiroba
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE hiroba;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS login_logs;
DROP TABLE IF EXISTS reports;
DROP TABLE IF EXISTS order_status_history;
DROP TABLE IF EXISTS order_deliverables;
DROP TABLE IF EXISTS order_messages;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS service_images;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS seller_profiles;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_status (is_active, locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seller_profiles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  student_id VARCHAR(30) NOT NULL,
  id_card_path VARCHAR(255) NOT NULL,
  bio TEXT NOT NULL,
  photo_path VARCHAR(255) NOT NULL,
  portfolio_url VARCHAR(255) NULL,
  contact_handle VARCHAR(100) NULL,
  status ENUM('pending', 'approved', 'rejected', 'suspended') NOT NULL DEFAULT 'pending',
  admin_feedback VARCHAR(255) NULL,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_seller_user (user_id),
  UNIQUE KEY uq_seller_student_id (student_id),
  KEY idx_seller_status (status),
  CONSTRAINT fk_seller_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(50) NOT NULL,
  slug VARCHAR(50) NOT NULL,
  description VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name),
  UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE services (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  seller_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  title VARCHAR(80) NOT NULL,
  slug VARCHAR(90) NOT NULL,
  description TEXT NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  delivery_days TINYINT UNSIGNED NOT NULL DEFAULT 3,
  requirements TEXT NULL,
  status ENUM('active', 'hidden') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_services_slug (slug),
  KEY idx_services_plaza (status, created_at),
  KEY idx_services_category (category_id, status),
  KEY idx_services_seller (seller_id),
  CONSTRAINT fk_services_seller FOREIGN KEY (seller_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_services_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT,
  CONSTRAINT chk_services_price CHECK (price >= 1.00 AND price <= 50000.00),
  CONSTRAINT chk_services_delivery_days CHECK (delivery_days >= 1 AND delivery_days <= 30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_images (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id INT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_service_images_service (service_id, is_primary),
  CONSTRAINT fk_service_images_service FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_number VARCHAR(32) NOT NULL,
  service_id INT UNSIGNED NOT NULL,
  buyer_id INT UNSIGNED NOT NULL,
  seller_id INT UNSIGNED NOT NULL,
  last_changed_by INT UNSIGNED NULL,
  title_at_order VARCHAR(80) NOT NULL,
  price_at_order DECIMAL(10,2) NOT NULL,
  delivery_days_at_order TINYINT UNSIGNED NOT NULL,
  status ENUM('pending', 'in_progress', 'submitted', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
  revision_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  cancelled_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_order_number (order_number),
  KEY idx_orders_buyer (buyer_id, created_at),
  KEY idx_orders_seller (seller_id, created_at),
  KEY idx_orders_service (service_id),
  KEY idx_orders_status (status),
  CONSTRAINT fk_orders_service FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE RESTRICT,
  CONSTRAINT fk_orders_buyer FOREIGN KEY (buyer_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_orders_seller FOREIGN KEY (seller_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT fk_orders_last_changed_by FOREIGN KEY (last_changed_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_orders_price CHECK (price_at_order >= 1.00),
  CONSTRAINT chk_orders_not_self_buy CHECK (buyer_id != seller_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT UNSIGNED NOT NULL,
  sender_id INT UNSIGNED NOT NULL,
  message_type ENUM('initial_brief', 'revision_note', 'general') NOT NULL DEFAULT 'general',
  content TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order_messages_order (order_id, created_at),
  CONSTRAINT fk_order_messages_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_messages_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_deliverables (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT UNSIGNED NOT NULL,
  seller_id INT UNSIGNED NOT NULL,
  version TINYINT UNSIGNED NOT NULL DEFAULT 1,
  deliverable_url VARCHAR(500) NOT NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_deliverable_order_version (order_id, version),
  KEY idx_order_deliverables_order (order_id),
  CONSTRAINT fk_order_deliverables_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_order_deliverables_seller FOREIGN KEY (seller_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_status_history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id INT UNSIGNED NOT NULL,
  changed_by INT UNSIGNED NOT NULL,
  from_status VARCHAR(20) NOT NULL DEFAULT '',
  to_status VARCHAR(20) NOT NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_history_order (order_id, created_at),
  CONSTRAINT fk_history_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_history_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_id INT UNSIGNED NOT NULL,
  service_id INT UNSIGNED NOT NULL,
  reason ENUM('academic_dishonesty', 'prohibited_item', 'scam_spam', 'inappropriate_content', 'other') NOT NULL,
  details VARCHAR(500) NULL,
  status ENUM('pending', 'reviewed', 'dismissed', 'action_taken') NOT NULL DEFAULT 'pending',
  admin_notes VARCHAR(255) NULL,
  resolved_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reports_status (status, created_at),
  KEY idx_reports_service (service_id),
  CONSTRAINT fk_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_reports_service FOREIGN KEY (service_id) REFERENCES services (id) ON DELETE CASCADE,
  CONSTRAINT fk_reports_admin FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  email_attempted VARCHAR(190) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent VARCHAR(255) NULL,
  status ENUM('success', 'failed', 'locked') NOT NULL,
  failure_reason VARCHAR(100) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_logs_email (email_attempted, created_at),
  KEY idx_login_logs_user (user_id),
  KEY idx_login_logs_status (status, created_at),
  CONSTRAINT fk_login_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id INT UNSIGNED NULL,
  action VARCHAR(50) NOT NULL,
  entity_type VARCHAR(50) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  details VARCHAR(500) NULL,
  ip_address VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_logs_actor (actor_id, created_at),
  KEY idx_audit_logs_entity (entity_type, entity_id),
  KEY idx_audit_logs_action (action, created_at),
  CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW vw_plaza_services AS
SELECT 
  s.id AS service_id,
  s.title,
  s.slug,
  s.description,
  s.price,
  s.delivery_days,
  c.id AS category_id,
  c.name AS category_name,
  c.slug AS category_slug,
  u.id AS seller_id,
  u.name AS seller_name,
  sp.photo_path AS seller_photo,
  sp.student_id,
  img.image_path AS primary_image_path,
  s.created_at
FROM services s
JOIN categories c ON s.category_id = c.id
JOIN users u ON s.seller_id = u.id
JOIN seller_profiles sp ON u.id = sp.user_id
LEFT JOIN service_images img ON s.id = img.service_id AND img.is_primary = 1
WHERE s.status = 'active' AND sp.status = 'approved';

CREATE OR REPLACE VIEW vw_order_details AS
SELECT 
  o.id AS order_id,
  o.order_number,
  o.title_at_order,
  o.price_at_order,
  o.delivery_days_at_order,
  o.status,
  o.revision_count,
  o.created_at,
  o.updated_at,
  buyer.id AS buyer_id,
  buyer.name AS buyer_name,
  buyer.email AS buyer_email,
  seller.id AS seller_id,
  seller.name AS seller_name,
  seller.email AS seller_email,
  sp.contact_handle AS seller_contact
FROM orders o
JOIN users buyer ON o.buyer_id = buyer.id
JOIN users seller ON o.seller_id = seller.id
LEFT JOIN seller_profiles sp ON seller.id = sp.user_id;

CREATE OR REPLACE VIEW vw_admin_pending_sellers AS
SELECT 
  sp.id AS profile_id,
  u.id AS user_id,
  u.name,
  u.email,
  sp.student_id,
  sp.id_card_path,
  sp.bio,
  sp.portfolio_url,
  sp.created_at AS applied_at
FROM seller_profiles sp
JOIN users u ON sp.user_id = u.id
WHERE sp.status = 'pending';

DROP TRIGGER IF EXISTS trg_orders_audit_status_change;
DELIMITER $$
CREATE TRIGGER trg_orders_audit_status_change
AFTER UPDATE ON orders
FOR EACH ROW
BEGIN
  IF OLD.status != NEW.status THEN
    INSERT INTO order_status_history (
      order_id,
      changed_by,
      from_status,
      to_status,
      reason,
      created_at
    ) VALUES (
      NEW.id,
      COALESCE(NEW.last_changed_by, NEW.seller_id),
      OLD.status,
      NEW.status,
      COALESCE(NEW.cancelled_reason, 'Status updated via order workflow'),
      NOW()
    );
  END IF;
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_auto_increment_revision_count;
DELIMITER $$
CREATE TRIGGER trg_auto_increment_revision_count
AFTER INSERT ON order_messages
FOR EACH ROW
BEGIN
  IF NEW.message_type = 'revision_note' THEN
    UPDATE orders 
    SET revision_count = revision_count + 1 
    WHERE id = NEW.order_id;
  END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS sp_place_order;
DELIMITER $$
CREATE PROCEDURE sp_place_order(
  IN p_service_id INT UNSIGNED,
  IN p_buyer_id INT UNSIGNED,
  IN p_initial_brief TEXT,
  OUT p_new_order_id INT UNSIGNED,
  OUT p_order_number VARCHAR(32)
)
BEGIN
  DECLARE v_seller_id INT UNSIGNED;
  DECLARE v_title VARCHAR(80);
  DECLARE v_price DECIMAL(10,2);
  DECLARE v_delivery_days TINYINT UNSIGNED;
  DECLARE v_generated_num VARCHAR(32);

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;

  SELECT seller_id, title, price, delivery_days
    INTO v_seller_id, v_title, v_price, v_delivery_days
    FROM services
   WHERE id = p_service_id AND status = 'active'
   FOR UPDATE;

  IF v_seller_id IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Service not found or is currently inactive.';
  END IF;

  IF v_seller_id = p_buyer_id THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Sellers cannot purchase their own services.';
  END IF;

  SET v_generated_num = CONCAT('HRB-', YEAR(NOW()), '-', LPAD(FLOOR(RAND() * 9000) + 1000, 4, '0'));

  INSERT INTO orders (
    order_number, service_id, buyer_id, seller_id, last_changed_by,
    title_at_order, price_at_order, delivery_days_at_order,
    status, revision_count, created_at
  ) VALUES (
    v_generated_num, p_service_id, p_buyer_id, v_seller_id, p_buyer_id,
    v_title, v_price, v_delivery_days,
    'pending', 0, NOW()
  );

  SET p_new_order_id = LAST_INSERT_ID();
  SET p_order_number = v_generated_num;

  IF p_initial_brief IS NOT NULL AND CHAR_LENGTH(TRIM(p_initial_brief)) > 0 THEN
    INSERT INTO order_messages (
      order_id, sender_id, message_type, content, created_at
    ) VALUES (
      p_new_order_id, p_buyer_id, 'initial_brief', p_initial_brief, NOW()
    );
  END IF;

  COMMIT;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS sp_submit_order_deliverable;
DELIMITER $$
CREATE PROCEDURE sp_submit_order_deliverable(
  IN p_order_id INT UNSIGNED,
  IN p_seller_id INT UNSIGNED,
  IN p_deliverable_url VARCHAR(500),
  IN p_notes TEXT
)
BEGIN
  DECLARE v_current_status VARCHAR(20);
  DECLARE v_authorized_seller INT UNSIGNED;
  DECLARE v_next_version TINYINT UNSIGNED;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;

  SELECT status, seller_id 
    INTO v_current_status, v_authorized_seller
    FROM orders 
   WHERE id = p_order_id 
   FOR UPDATE;

  IF v_authorized_seller IS NULL OR v_authorized_seller != p_seller_id THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Unauthorized: User is not the seller of this order.';
  END IF;

  IF v_current_status != 'in_progress' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Deliverables can only be submitted for in-progress orders.';
  END IF;

  SELECT COALESCE(MAX(version), 0) + 1
    INTO v_next_version
    FROM order_deliverables
   WHERE order_id = p_order_id;

  INSERT INTO order_deliverables (
    order_id, seller_id, version, deliverable_url, notes, created_at
  ) VALUES (
    p_order_id, p_seller_id, v_next_version, p_deliverable_url, p_notes, NOW()
  );

  UPDATE orders 
     SET status = 'submitted', last_changed_by = p_seller_id, updated_at = NOW() 
   WHERE id = p_order_id;

  COMMIT;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS sp_record_login_attempt;
DELIMITER $$
CREATE PROCEDURE sp_record_login_attempt(
  IN p_email VARCHAR(190),
  IN p_ip_address VARCHAR(45),
  IN p_user_agent VARCHAR(255),
  IN p_status VARCHAR(20),
  IN p_failure_reason VARCHAR(100)
)
BEGIN
  DECLARE v_user_id INT UNSIGNED;
  DECLARE v_current_attempts TINYINT UNSIGNED;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  START TRANSACTION;

  SELECT id, failed_attempts 
    INTO v_user_id, v_current_attempts
    FROM users 
   WHERE email = p_email 
   FOR UPDATE;

  INSERT INTO login_logs (
    user_id, email_attempted, ip_address, user_agent, status, failure_reason, created_at
  ) VALUES (
    v_user_id, p_email, p_ip_address, p_user_agent, p_status, p_failure_reason, NOW()
  );

  IF v_user_id IS NOT NULL THEN
    IF p_status = 'failed' THEN
      SET v_current_attempts = v_current_attempts + 1;
      IF v_current_attempts >= 5 THEN
        UPDATE users 
           SET failed_attempts = v_current_attempts,
               locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
         WHERE id = v_user_id;
      ELSE
        UPDATE users 
           SET failed_attempts = v_current_attempts
         WHERE id = v_user_id;
      END IF;
    ELSEIF p_status = 'success' THEN
      UPDATE users 
         SET failed_attempts = 0,
             locked_until = NULL
       WHERE id = v_user_id;
    END IF;
  END IF;

  COMMIT;
END$$
DELIMITER ;
