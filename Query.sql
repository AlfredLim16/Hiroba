USE hiroba;

-- 1. AUTHENTICATION AND SECURITY QUERIES (src/auth.php, public/login.php, public/register.php)

-- Query 1.1: Verify if email already exists during registration
SELECT id 
FROM users 
WHERE email = :email 
LIMIT 1;

-- Query 1.2: Register a new student user account
INSERT INTO users (name, email, password_hash, is_admin, is_active, failed_attempts, locked_until)
VALUES (:name, :email, :password_hash, 0, 1, 0, NULL);

-- Query 1.3: Fetch user credentials and security state for authentication
SELECT id, name, email, password_hash, is_admin, is_active, failed_attempts, locked_until
FROM users 
WHERE email = :email 
LIMIT 1;

-- Query 1.4: Check whether an account is currently locked out by brute-force protection
SELECT id, locked_until, TIMESTAMPDIFF(SECOND, NOW(), locked_until) AS seconds_remaining
FROM users
WHERE id = :user_id 
  AND locked_until > NOW();

-- Query 1.5: Increment failed login attempt counter and trigger 15-minute lockout at 5 attempts
UPDATE users 
SET failed_attempts = failed_attempts + 1,
    locked_until = IF(failed_attempts + 1 >= 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NULL)
WHERE id = :user_id;

-- Query 1.6: Reset failed attempt counters upon successful login
UPDATE users 
SET failed_attempts = 0,
    locked_until = NULL
WHERE id = :user_id;

-- Query 1.7: Record an authentication event into the security audit ledger
INSERT INTO login_logs (user_id, email_attempted, ip_address, user_agent, status, failure_reason)
VALUES (:user_id, :email_attempted, :ip_address, :user_agent, :status, :failure_reason);

-- Query 1.8: Execute atomic authentication tracking via Stored Procedure
CALL sp_record_login_attempt(:email, :ip_address, :user_agent, :status, :failure_reason);


-- 2. PUBLIC PLAZA AND SEARCH QUERIES (public/index.php)

-- Query 2.1: Fetch all skill categories with active listing counts for Plaza filter pills
SELECT 
  c.id,
  c.name,
  c.slug,
  COUNT(s.id) AS active_services_count
FROM categories c
LEFT JOIN services s ON c.id = s.category_id AND s.status = 'active'
GROUP BY c.id, c.name, c.slug
ORDER BY c.name ASC;

-- Query 2.2: Fetch active services for Plaza browsing using pre-compiled View (Optimized)
SELECT 
  service_id,
  title,
  slug,
  description,
  price,
  delivery_days,
  category_id,
  category_name,
  category_slug,
  seller_id,
  seller_name,
  seller_photo,
  student_id,
  primary_image_path,
  created_at
FROM vw_plaza_services
WHERE (:category_id = 0 OR category_id = :category_id)
  AND (:search_keyword = '' OR title LIKE CONCAT('%', :search_keyword, '%'))
ORDER BY created_at DESC
LIMIT :limit OFFSET :offset;

-- Query 2.3: Underlying 5-table JOIN query executed by vw_plaza_services (For Panel Defense)
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
WHERE s.status = 'active' 
  AND sp.status = 'approved'
  AND (:category_id = 0 OR s.category_id = :category_id)
  AND (:keyword = '' OR s.title LIKE CONCAT('%', :keyword, '%'))
ORDER BY s.created_at DESC
LIMIT :limit OFFSET :offset;

-- Query 2.4: Count total matching services for Plaza pagination calculation
SELECT COUNT(*) AS total_services
FROM services s
JOIN seller_profiles sp ON s.seller_id = sp.user_id
WHERE s.status = 'active'
  AND sp.status = 'approved'
  AND (:category_id = 0 OR s.category_id = :category_id)
  AND (:keyword = '' OR s.title LIKE CONCAT('%', :keyword, '%'));


-- 3. INDIVIDUAL SERVICE DETAILS AND SELLER PROFILE (public/service.php)

-- Query 3.1: Fetch single service full scope, category info, and vetted seller bio
SELECT 
  s.id AS service_id,
  s.seller_id,
  s.category_id,
  s.title,
  s.slug,
  s.description,
  s.price,
  s.delivery_days,
  s.requirements,
  s.status AS service_status,
  s.created_at AS service_created_at,
  c.name AS category_name,
  c.slug AS category_slug,
  u.name AS seller_name,
  u.email AS seller_email,
  sp.student_id,
  sp.bio AS seller_bio,
  sp.photo_path AS seller_photo,
  sp.portfolio_url,
  sp.verified_at AS seller_verified_at
FROM services s
JOIN categories c ON s.category_id = c.id
JOIN users u ON s.seller_id = u.id
JOIN seller_profiles sp ON u.id = sp.user_id
WHERE s.id = :service_id 
  AND s.status = 'active'
LIMIT 1;

-- Query 3.2: Fetch all showcase gallery images for a service listing
SELECT id, image_path, is_primary, created_at
FROM service_images
WHERE service_id = :service_id
ORDER BY is_primary DESC, id ASC;

-- Query 3.3: Calculate seller total completed order count and credibility metrics
SELECT COUNT(*) AS total_completed_orders
FROM orders
WHERE seller_id = :seller_id 
  AND status = 'completed';

-- Query 3.4: Fetch other active services published by the same student seller
SELECT 
  s.id,
  s.title,
  s.slug,
  s.price,
  s.delivery_days,
  img.image_path AS primary_image_path
FROM services s
LEFT JOIN service_images img ON s.id = img.service_id AND img.is_primary = 1
WHERE s.seller_id = :seller_id
  AND s.id != :current_service_id
  AND s.status = 'active'
ORDER BY s.created_at DESC
LIMIT 4;


-- 4. SERVICE CREATION AND MANAGEMENT (public/service-new.php, public/service-edit.php, public/service-hide.php)

-- Query 4.1: Verify user possesses an approved seller profile before opening service creation
SELECT id, status, student_id
FROM seller_profiles
WHERE user_id = :user_id 
  AND status = 'approved'
LIMIT 1;

-- Query 4.2: Insert a newly authored service listing
INSERT INTO services (seller_id, category_id, title, slug, description, price, delivery_days, requirements, status)
VALUES (:seller_id, :category_id, :title, :slug, :description, :price, :delivery_days, :requirements, 'active');

-- Query 4.3: Insert uploaded sample images for a service showcase gallery
INSERT INTO service_images (service_id, image_path, is_primary)
VALUES (:service_id, :image_path, :is_primary);

-- Query 4.4: Update existing service details (Scope, Price, Turnaround, Requirements)
UPDATE services
SET category_id = :category_id,
    title = :title,
    description = :description,
    price = :price,
    delivery_days = :delivery_days,
    requirements = :requirements,
    updated_at = NOW()
WHERE id = :service_id 
  AND seller_id = :seller_id;

-- Query 4.5: Toggle service visibility state (Soft-delete / Hide / Unhide)
UPDATE services 
SET status = IF(status = 'active', 'hidden', 'active'),
    updated_at = NOW()
WHERE id = :service_id 
  AND seller_id = :seller_id;


-- 5. ORDER INITIATION AND STATE MACHINE TRACKING (public/order.php)

-- Query 5.1: Execute atomic order checkout using Stored Procedure (Recommended)
CALL sp_place_order(:service_id, :buyer_id, :initial_brief, @new_order_id, @order_number);
SELECT @new_order_id AS order_id, @order_number AS order_number;

-- Query 5.2: Direct SQL order checkout inside manual PDO transaction
START TRANSACTION;

SELECT seller_id, title, price, delivery_days
FROM services
WHERE id = :service_id AND status = 'active'
FOR UPDATE;

INSERT INTO orders (
  order_number, service_id, buyer_id, seller_id, last_changed_by,
  title_at_order, price_at_order, delivery_days_at_order,
  status, revision_count, created_at
) VALUES (
  :order_number, :service_id, :buyer_id, :seller_id, :buyer_id,
  :title_at_order, :price_at_order, :delivery_days_at_order,
  'pending', 0, NOW()
);

INSERT INTO order_messages (order_id, sender_id, message_type, content, created_at)
VALUES (LAST_INSERT_ID(), :buyer_id, 'initial_brief', :initial_brief, NOW());

COMMIT;

-- Query 5.3: Fetch order console details via View (Optimized)
SELECT * 
FROM vw_order_details 
WHERE order_id = :order_id 
  AND (buyer_id = :user_id OR seller_id = :user_id)
LIMIT 1;

-- Query 5.4: Fetch entire project brief and formal revision message thread
SELECT 
  m.id,
  m.order_id,
  m.sender_id,
  m.message_type,
  m.content,
  m.created_at,
  u.name AS sender_name
FROM order_messages m
JOIN users u ON m.sender_id = u.id
WHERE m.order_id = :order_id
ORDER BY m.created_at ASC;

-- Query 5.5: Fetch versioned deliverable links submitted by seller
SELECT 
  d.id,
  d.order_id,
  d.seller_id,
  d.version,
  d.deliverable_url,
  d.notes,
  d.created_at,
  u.name AS seller_name
FROM order_deliverables d
JOIN users u ON d.seller_id = u.id
WHERE d.order_id = :order_id
ORDER BY d.version DESC;

-- Query 5.6: Fetch immutable state machine transition audit trail
SELECT 
  h.id,
  h.order_id,
  h.changed_by,
  h.from_status,
  h.to_status,
  h.reason,
  h.created_at,
  u.name AS changed_by_name
FROM order_status_history h
JOIN users u ON h.changed_by = u.id
WHERE h.order_id = :order_id
ORDER BY h.created_at ASC;

-- Query 5.7: Transition 1 - Seller approves pending order
UPDATE orders 
SET status = 'in_progress',
    last_changed_by = :seller_id,
    updated_at = NOW()
WHERE id = :order_id 
  AND seller_id = :seller_id 
  AND status = 'pending';

-- Query 5.8: Transition 2 - Seller rejects pending order with mandatory reason
UPDATE orders 
SET status = 'cancelled',
    last_changed_by = :seller_id,
    cancelled_reason = :reason,
    updated_at = NOW()
WHERE id = :order_id 
  AND seller_id = :seller_id 
  AND status = 'pending';

-- Query 5.9: Transition 3/5 - Buyer cancels order with mandatory reason
UPDATE orders 
SET status = 'cancelled',
    last_changed_by = :buyer_id,
    cancelled_reason = :reason,
    updated_at = NOW()
WHERE id = :order_id 
  AND buyer_id = :buyer_id 
  AND status IN ('pending', 'in_progress');

-- Query 5.10: Transition 4 - Seller submits deliverable cloud link via Stored Procedure
CALL sp_submit_order_deliverable(:order_id, :seller_id, :deliverable_url, :notes);

-- Query 5.11: Transition 6 - Buyer accepts submitted work as completed
UPDATE orders 
SET status = 'completed',
    last_changed_by = :buyer_id,
    updated_at = NOW()
WHERE id = :order_id 
  AND buyer_id = :buyer_id 
  AND status = 'submitted';

-- Query 5.12: Transition 7 - Buyer requests revisions with written directive
-- (Note: Inserting revision_note fires database trigger trg_auto_increment_revision_count)
INSERT INTO order_messages (order_id, sender_id, message_type, content, created_at)
VALUES (:order_id, :buyer_id, 'revision_note', :revision_directive, NOW());

UPDATE orders 
SET status = 'in_progress',
    last_changed_by = :buyer_id,
    updated_at = NOW()
WHERE id = :order_id 
  AND buyer_id = :buyer_id 
  AND status = 'submitted';


-- 6. USER WORKSPACE AND DASHBOARD QUERIES (public/dashboard.php)

-- Query 6.1: Fetch active and past orders placed as Buyer
SELECT 
  o.id AS order_id,
  o.order_number,
  o.title_at_order,
  o.price_at_order,
  o.delivery_days_at_order,
  o.status,
  o.revision_count,
  o.created_at,
  seller.name AS seller_name
FROM orders o
JOIN users seller ON o.seller_id = seller.id
WHERE o.buyer_id = :buyer_id
ORDER BY o.created_at DESC;

-- Query 6.2: Fetch incoming orders received as Seller
SELECT 
  o.id AS order_id,
  o.order_number,
  o.title_at_order,
  o.price_at_order,
  o.delivery_days_at_order,
  o.status,
  o.revision_count,
  o.created_at,
  buyer.name AS buyer_name
FROM orders o
JOIN users buyer ON o.buyer_id = buyer.id
WHERE o.seller_id = :seller_id
ORDER BY o.created_at DESC;

-- Query 6.3: Calculate aggregated seller dashboard performance metrics
SELECT 
  COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_orders_count,
  COUNT(CASE WHEN status = 'in_progress' THEN 1 END) AS active_orders_count,
  COUNT(CASE WHEN status = 'submitted' THEN 1 END) AS submitted_orders_count,
  COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed_orders_count,
  COALESCE(SUM(CASE WHEN status = 'completed' THEN price_at_order ELSE 0 END), 0.00) AS total_revenue_php
FROM orders
WHERE seller_id = :seller_id;

-- Query 6.4: Fetch listings created by the authenticated seller with thumbnails
SELECT 
  s.id,
  s.title,
  s.slug,
  s.price,
  s.delivery_days,
  s.status,
  s.created_at,
  c.name AS category_name,
  img.image_path AS primary_image_path
FROM services s
JOIN categories c ON s.category_id = c.id
LEFT JOIN service_images img ON s.id = img.service_id AND img.is_primary = 1
WHERE s.seller_id = :seller_id
ORDER BY s.created_at DESC;


-- 7. ACCOUNT SETTINGS AND SELLER ONBOARDING (public/settings.php)

-- Query 7.1: Fetch current user profile and seller accreditation status
SELECT 
  u.id AS user_id,
  u.name,
  u.email,
  u.created_at AS member_since,
  sp.id AS seller_profile_id,
  sp.student_id,
  sp.id_card_path,
  sp.bio,
  sp.photo_path,
  sp.portfolio_url,
  sp.contact_handle,
  sp.status AS seller_status,
  sp.admin_feedback,
  sp.verified_at
FROM users u
LEFT JOIN seller_profiles sp ON u.id = sp.user_id
WHERE u.id = :user_id
LIMIT 1;

-- Query 7.2: Update personal account display name
UPDATE users 
SET name = :name,
    updated_at = NOW()
WHERE id = :user_id;

-- Query 7.3: Update user password hash
UPDATE users 
SET password_hash = :new_password_hash,
    updated_at = NOW()
WHERE id = :user_id;

-- Query 7.4: Submit or re-apply for "Become a Seller" accreditation
INSERT INTO seller_profiles (
  user_id, student_id, id_card_path, bio, photo_path, portfolio_url, contact_handle, status
) VALUES (
  :user_id, :student_id, :id_card_path, :bio, :photo_path, :portfolio_url, :contact_handle, 'pending'
)
ON DUPLICATE KEY UPDATE
  student_id = VALUES(student_id),
  id_card_path = VALUES(id_card_path),
  bio = VALUES(bio),
  photo_path = IF(VALUES(photo_path) IS NOT NULL AND VALUES(photo_path) != '', VALUES(photo_path), photo_path),
  portfolio_url = VALUES(portfolio_url),
  contact_handle = VALUES(contact_handle),
  status = 'pending',
  admin_feedback = NULL,
  updated_at = NOW();


-- 8. ADMINISTRATIVE DESK AND MODERATION (public/admin.php, public/report.php)

-- Query 8.1: Fetch queue of pending seller verification applications using View
SELECT * 
FROM vw_admin_pending_sellers 
ORDER BY applied_at ASC;

-- Query 8.2: Approve student seller application and grant accreditation
START TRANSACTION;

UPDATE seller_profiles
SET status = 'approved',
    verified_at = NOW(),
    admin_feedback = :approval_notes,
    updated_at = NOW()
WHERE id = :profile_id;

INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details, ip_address)
VALUES (:admin_id, 'seller_approved', 'seller_profiles', :profile_id, :approval_notes, :ip_address);

COMMIT;

-- Query 8.3: Reject student seller application with corrective feedback
START TRANSACTION;

UPDATE seller_profiles
SET status = 'rejected',
    admin_feedback = :rejection_feedback,
    updated_at = NOW()
WHERE id = :profile_id;

INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details, ip_address)
VALUES (:admin_id, 'seller_rejected', 'seller_profiles', :profile_id, :rejection_feedback, :ip_address);

COMMIT;

-- Query 8.4: Suspend verified seller privileges due to violation of campus honor code
START TRANSACTION;

UPDATE seller_profiles
SET status = 'suspended',
    admin_feedback = :suspension_reason,
    updated_at = NOW()
WHERE id = :profile_id;

UPDATE services
SET status = 'hidden',
    updated_at = NOW()
WHERE seller_id = :seller_user_id
  AND status = 'active';

INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details, ip_address)
VALUES (:admin_id, 'seller_suspended', 'seller_profiles', :profile_id, :suspension_reason, :ip_address);

COMMIT;

-- Query 8.5: Fetch accounts currently locked out by brute-force protection
SELECT id, name, email, failed_attempts, locked_until
FROM users
WHERE locked_until > NOW()
ORDER BY locked_until DESC;

-- Query 8.6: Unlock a locked student account and record audit log
START TRANSACTION;

UPDATE users 
SET failed_attempts = 0,
    locked_until = NULL,
    updated_at = NOW()
WHERE id = :user_id;

INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details, ip_address)
VALUES (:admin_id, 'user_unlocked', 'users', :user_id, 'Admin unlocked account following student inquiry.', :ip_address);

COMMIT;

-- Query 8.7: Submit a community report against a suspicious service (public/report.php)
INSERT INTO reports (reporter_id, service_id, reason, details, status)
VALUES (:reporter_id, :service_id, :reason, :details, 'pending');

-- Query 8.8: Fetch pending community reports for administrative moderation
SELECT 
  r.id AS report_id,
  r.reason,
  r.details,
  r.status,
  r.created_at AS reported_at,
  reporter.name AS reporter_name,
  s.id AS service_id,
  s.title AS service_title,
  s.status AS service_status,
  seller.id AS seller_id,
  seller.name AS seller_name
FROM reports r
JOIN users reporter ON r.reporter_id = reporter.id
JOIN services s ON r.service_id = s.id
JOIN users seller ON s.seller_id = seller.id
WHERE r.status = 'pending'
ORDER BY r.created_at ASC;

-- Query 8.9: Adjudicate community report and optionally hide prohibited listing
START TRANSACTION;

UPDATE reports 
SET status = :resolution_status,
    admin_notes = :admin_notes,
    resolved_by = :admin_id,
    updated_at = NOW()
WHERE id = :report_id;

UPDATE services 
SET status = 'hidden',
    updated_at = NOW()
WHERE id = :reported_service_id 
  AND :should_hide = 1;

INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details, ip_address)
VALUES (:admin_id, 'report_adjudicated', 'reports', :report_id, :admin_notes, :ip_address);

COMMIT;

-- Query 8.10: Platform-wide overview statistics for administrator desk dashboard
SELECT 
  (SELECT COUNT(*) FROM users WHERE is_admin = 0) AS total_students_count,
  (SELECT COUNT(*) FROM seller_profiles WHERE status = 'approved') AS verified_sellers_count,
  (SELECT COUNT(*) FROM seller_profiles WHERE status = 'pending') AS pending_applications_count,
  (SELECT COUNT(*) FROM services WHERE status = 'active') AS active_services_count,
  (SELECT COUNT(*) FROM orders WHERE status = 'completed') AS completed_contracts_count,
  (SELECT COALESCE(SUM(price_at_order), 0.00) FROM orders WHERE status = 'completed') AS gross_marketplace_volume_php,
  (SELECT COUNT(*) FROM reports WHERE status = 'pending') AS pending_reports_count;

-- Query 8.11: Fetch recent system-wide administrative audit trail
SELECT 
  a.id,
  a.action,
  a.entity_type,
  a.entity_id,
  a.details,
  a.ip_address,
  a.created_at,
  u.name AS actor_name,
  u.email AS actor_email
FROM audit_logs a
LEFT JOIN users u ON a.actor_id = u.id
ORDER BY a.created_at DESC
LIMIT 50;
