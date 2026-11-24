USE hiroba;

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE audit_logs;
TRUNCATE TABLE login_logs;
TRUNCATE TABLE reports;
TRUNCATE TABLE order_status_history;
TRUNCATE TABLE order_deliverables;
TRUNCATE TABLE order_messages;
TRUNCATE TABLE orders;
TRUNCATE TABLE service_images;
TRUNCATE TABLE services;
TRUNCATE TABLE categories;
TRUNCATE TABLE seller_profiles;
TRUNCATE TABLE users;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO users (id, name, email, password_hash, is_admin, is_active, failed_attempts, locked_until, created_at) VALUES
  (1, 'Hiroba Admin Desk', 'admin@hiroba.edu.ph', '$2y$10$wjLH/BtOGOEaZ8/4vl5sFu2l.wFr56ymV6/aZcPU9vtR2NGJUs/v.', 1, 1, 0, NULL, '2026-10-01 08:00:00'),
  (2, 'Juan Dela Cruz', 'juan.delacruz@campus.edu.ph', '$2y$10$wjLH/BtOGOEaZ8/4vl5sFu2l.wFr56ymV6/aZcPU9vtR2NGJUs/v.', 0, 1, 0, NULL, '2026-10-05 09:30:00'),
  (3, 'Maria Santos', 'maria.santos@campus.edu.ph', '$2y$10$wjLH/BtOGOEaZ8/4vl5sFu2l.wFr56ymV6/aZcPU9vtR2NGJUs/v.', 0, 1, 0, NULL, '2026-10-08 11:15:00'),
  (4, 'Carlo Reyes', 'carlo.reyes@campus.edu.ph', '$2y$10$wjLH/BtOGOEaZ8/4vl5sFu2l.wFr56ymV6/aZcPU9vtR2NGJUs/v.', 0, 1, 0, NULL, '2026-10-12 14:00:00'),
  (5, 'Bea Gomez', 'bea.gomez@campus.edu.ph', '$2y$10$wjLH/BtOGOEaZ8/4vl5sFu2l.wFr56ymV6/aZcPU9vtR2NGJUs/v.', 0, 1, 0, NULL, '2026-10-15 16:45:00'),
  (6, 'Mark Tan', 'mark.tan@campus.edu.ph', '$2y$10$wjLH/BtOGOEaZ8/4vl5sFu2l.wFr56ymV6/aZcPU9vtR2NGJUs/v.', 0, 1, 0, NULL, '2026-10-20 10:20:00');

INSERT INTO seller_profiles (id, user_id, student_id, id_card_path, bio, photo_path, portfolio_url, contact_handle, status, admin_feedback, verified_at, created_at) VALUES
  (1, 2, '2023-01452', 'uploads/ids/id_juan_2023.jpg', 'BS Information Technology student specializing in digital illustration, modern vector logos, and publication materials for campus organizations.', 'uploads/avatars/juan_avatar.jpg', 'https://behance.net/juandelacruz_design', '@juan_graphics_tg', 'approved', 'Documents verified with University Registrar records.', '2026-10-06 14:00:00', '2026-10-05 10:00:00'),
  (2, 3, '2022-09881', 'uploads/ids/id_maria_2022.jpg', 'Senior Multimedia Arts student and campus creative guild illustrator. Experienced in digital character commissions, original character (OC) design, and custom avatars.', 'uploads/avatars/maria_avatar.jpg', 'https://drive.google.com/drive/folders/sample_portfolio', '@maria_editor_campus', 'approved', 'Verified enrolled senior publication editor.', '2026-10-09 10:30:00', '2026-10-08 12:00:00'),
  (3, 4, '2024-11002', 'uploads/ids/id_carlo_2024.jpg', 'Freshman computer engineering student offering basic C++ and Python tutoring and logic diagram drafting.', 'uploads/avatars/carlo_avatar.jpg', 'https://github.com/carlo-reyes-student', '@carlo_tutoring', 'pending', NULL, NULL, '2026-10-12 14:30:00');

INSERT INTO categories (id, name, slug, description, created_at) VALUES
  (1, 'Logo & Brand Identity', 'logo-design', 'Custom vector brand marks, badges, emblems, and style guides for campus clubs and student organizations.', '2026-10-01 08:00:00'),
  (2, 'Art Commissions & Illustration', 'art-commissions', 'Custom digital character illustrations, anime commissions, original characters (OCs), and custom profile avatars.', '2026-10-01 08:00:00'),
  (3, '3D Modeling & Rendering', '3d-modeling', 'Blender 3D assets, CAD models, 3D printing preparation (STL), and architectural visualization.', '2026-10-01 08:00:00'),
  (4, 'Video Editing & Motion Graphics', 'video-editing', 'Reels, TikToks, YouTube cuts, event highlight reels, motion graphics, and vlog editing.', '2026-10-01 08:00:00'),
  (5, 'Slide Deck & Presentation Design', 'presentation-design', 'Thesis defense presentations, capstone decks, pitch slides, and infographic layout design.', '2026-10-01 08:00:00'),
  (6, 'UI/UX & Web Prototype Design', 'ui-ux-design', 'Figma prototypes, user interface wireframes, mobile app layouts, and responsive landing pages.', '2026-10-01 08:00:00'),
  (7, 'Tutorials & Mentorship', 'tutorials-mentorship', '1-on-1 peer mentoring and tutoring in programming (Python, C++, Web), calculus, and design tools.', '2026-10-01 08:00:00'),
  (8, 'Campus Event Pubmats & Posters', 'event-pubmats', 'Student council publicity materials, event posters, social media announcement kits, and club flyers.', '2026-10-01 08:00:00'),
  (9, 'Audio & Podcast Editing', 'audio-editing', 'Voiceover audio cleanup, podcast editing, audio mixing, and background noise removal.', '2026-10-01 08:00:00'),
  (10, 'Photography & Photo Retouching', 'photo-retouching', 'Graduation photo editing, portrait color grading, event photo retouching, and Lightroom enhancements.', '2026-10-01 08:00:00');

INSERT INTO services (id, seller_id, category_id, title, slug, description, price, delivery_days, requirements, status, created_at) VALUES
  (1, 2, 1, 'Custom Vector Club Logo Design', 'custom-vector-club-logo-design', 'I will create a clean, modern vector logo design for your student council, academic organization, or thesis group project. Deliverables include high-res PNG, transparent badge, and SVG vector files.', 750.00, 3, 'Please provide: 1. Organization name and slogan; 2. Preferred color palette; 3. Visual theme or sample peg images.', 'active', '2026-10-07 10:00:00'),
  (2, 2, 5, 'Academic Slide Deck & Presentation Design', 'academic-slide-deck-presentation-design', 'Transform your bullet-point notes into a professional PowerPoint or Canva slide deck (up to 15 slides). Ideal for research presentations and capstone defenses.', 500.00, 2, 'Send your finalized bulleted content or word script, along with required fonts or university logos.', 'active', '2026-10-08 14:00:00'),
  (3, 3, 2, 'Custom Digital Art Commission & Character Illustration', 'custom-digital-art-commission-character-illustration', 'I will draw high-resolution digital art commissions, including original characters (OCs), anime portraits, profile avatars, and fanart. Deliverables include 300 DPI print-ready PNG, transparent background version, and layered PSD/clip files.', 850.00, 4, 'Please provide: 1. Character reference sheets or visual pegs; 2. Desired pose and facial expression; 3. Canvas orientation and lighting preference.', 'active', '2026-10-10 09:00:00'),
  (4, 2, 8, 'Campus Event Pubmat & Social Media Kit', 'campus-event-pubmat-social-media-kit', 'Full promotional suite for student org events: 1 square Instagram post, 1 portrait story, and 1 Facebook banner tailored to campus audiences.', 600.00, 4, 'Event title, date, venue, guest speaker details, sponsor logos, and org branding guidelines.', 'active', '2026-10-11 11:30:00'),
  (5, 2, 3, 'Blender 3D Prop & Low-Poly Asset Modeling', 'blender-3d-prop-low-poly-asset-modeling', 'Custom 3D modeling for game prototypes, animations, 3D printing (STL), or architectural models in Blender. Deliverables include .blend, .obj, .fbx, and rendered turnaround PNGs.', 950.00, 5, 'Please provide: 1. Concept sketches, blueprints, or orthographic reference photos; 2. Target polygon budget or 3D printing specifications; 3. Required export formats.', 'active', '2026-10-14 10:00:00'),
  (6, 2, 4, 'Campus Event Video Highlight & Social Reel Editing', 'campus-event-video-highlight-social-reel-editing', 'Fast-paced, dynamic video editing for your student organization events, TikToks, or YouTube recaps. Includes motion titles, color grading, sound design, and royalty-free music sync.', 800.00, 3, 'Please provide: 1. Raw footage links via Google Drive or cloud storage; 2. Event program or video timeline notes; 3. Preferred background music mood or branding assets.', 'active', '2026-10-15 14:00:00');

INSERT INTO service_images (id, service_id, image_path, is_primary, created_at) VALUES
  (1, 1, 'uploads/services/logo_design_sample_1.jpg', 1, '2026-10-07 10:00:00'),
  (2, 1, 'uploads/services/logo_design_sample_2.jpg', 0, '2026-10-07 10:05:00'),
  (3, 2, 'uploads/services/slide_deck_sample_1.jpg', 1, '2026-10-08 14:00:00'),
  (4, 3, 'uploads/services/proofreading_sample_1.jpg', 1, '2026-10-10 09:00:00'),
  (5, 4, 'uploads/services/pubmat_sample_1.jpg', 1, '2026-10-11 11:30:00');

INSERT INTO orders (id, order_number, service_id, buyer_id, seller_id, last_changed_by, title_at_order, price_at_order, delivery_days_at_order, status, revision_count, cancelled_reason, created_at) VALUES
  (1, 'HRB-2026-1001', 1, 5, 2, 5, 'Custom Vector Club Logo Design', 750.00, 3, 'completed', 0, NULL, '2026-10-16 10:00:00'),
  (2, 'HRB-2026-1002', 3, 6, 3, 3, 'Custom Digital Art Commission & Character Illustration', 850.00, 4, 'submitted', 1, NULL, '2026-10-18 13:00:00'),
  (3, 'HRB-2026-1003', 2, 5, 2, 2, 'Academic Slide Deck & Presentation Design', 500.00, 2, 'in_progress', 0, NULL, '2026-10-21 09:30:00'),
  (4, 'HRB-2026-1004', 1, 6, 2, 6, 'Custom Vector Club Logo Design', 750.00, 3, 'pending', 0, NULL, '2026-10-22 15:00:00');

INSERT INTO order_messages (id, order_id, sender_id, message_type, content, created_at) VALUES
  (1, 1, 5, 'initial_brief', 'Hi Juan! Need a logo for our Computer Science Society hackathon titled "CodeQuest 2026". Primary colors: Cyan and Dark Navy. Modern cyber aesthetic.', '2026-10-16 10:00:00'),
  (2, 2, 6, 'initial_brief', 'Hello Maria, here are the visual reference pegs for my original character (OC) for our comic anthology project. Looking forward to your sketch!', '2026-10-18 13:00:00'),
  (3, 2, 6, 'revision_note', 'Hi Maria, thank you for the line art draft. Could you adjust the hair color slightly to match the reference palette? Everything else looks amazing!', '2026-10-19 16:30:00'),
  (4, 3, 5, 'initial_brief', 'Need a 12-slide presentation for our final marketing case study by Friday. Content outlines attached in Google Doc.', '2026-10-21 09:30:00'),
  (5, 4, 6, 'initial_brief', 'Hi, placing an order for our debate team club logo. Hope you can accept!', '2026-10-22 15:00:00');

INSERT INTO order_deliverables (id, order_id, seller_id, version, deliverable_url, notes, created_at) VALUES
  (1, 1, 2, 1, 'https://drive.google.com/drive/folders/codequest_logo_v1_final', 'Here are all the vector files, PNG transparency badge, and typography sheet. Thank you!', '2026-10-17 14:00:00'),
  (2, 2, 3, 1, 'https://drive.google.com/drive/folders/art_commission_maria_v1', 'First rough color sketch and character linework for buyer review.', '2026-10-19 11:00:00'),
  (3, 2, 3, 2, 'https://drive.google.com/drive/folders/art_commission_maria_v2_final', 'Final 300 DPI high-res illustration with adjusted color palette and transparent avatar PNG.', '2026-10-20 09:00:00');

INSERT INTO order_status_history (id, order_id, changed_by, from_status, to_status, reason, created_at) VALUES
  (1, 1, 2, 'pending', 'in_progress', 'Seller accepted brief and began vector drafting.', '2026-10-16 10:30:00'),
  (2, 1, 2, 'in_progress', 'submitted', 'Seller uploaded deliverables to cloud drive and submitted link.', '2026-10-17 14:00:00'),
  (3, 1, 5, 'submitted', 'completed', 'Buyer inspected deliverables and formally accepted the work.', '2026-10-17 18:00:00'),
  (4, 2, 3, 'pending', 'in_progress', 'Seller accepted art brief and began character rough sketch.', '2026-10-18 13:30:00'),
  (5, 2, 3, 'in_progress', 'submitted', 'First pass color sketch submitted for buyer review.', '2026-10-19 11:00:00'),
  (6, 2, 6, 'submitted', 'in_progress', 'Hair palette adjustment requested.', '2026-10-19 16:30:00'),
  (7, 2, 3, 'in_progress', 'submitted', 'Re-submitted with final color adjustments and high-res PNG export.', '2026-10-20 09:00:00'),
  (8, 3, 2, 'pending', 'in_progress', 'Seller started slide layout drafting in Canva.', '2026-10-21 10:00:00');

INSERT INTO reports (id, reporter_id, service_id, reason, details, status, admin_notes, resolved_by, created_at) VALUES
  (1, 5, 4, 'other', 'Inquiry regarding event dates on the preview pubmat. Verified benign.', 'dismissed', 'Listing inspected; content compliant with campus guidelines.', 1, '2026-10-21 16:00:00');

INSERT INTO login_logs (id, user_id, email_attempted, ip_address, user_agent, status, failure_reason, created_at) VALUES
  (1, 1, 'admin@hiroba.edu.ph', '192.168.1.10', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0', 'success', NULL, '2026-10-22 08:15:00'),
  (2, 2, 'juan.delacruz@campus.edu.ph', '192.168.1.25', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15', 'success', NULL, '2026-10-22 09:00:00'),
  (3, 5, 'bea.gomez@campus.edu.ph', '192.168.1.42', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148', 'failed', 'invalid_password', '2026-10-22 09:30:12'),
  (4, 5, 'bea.gomez@campus.edu.ph', '192.168.1.42', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148', 'success', NULL, '2026-10-22 09:32:45'),
  (5, NULL, 'unregistered.student@campus.edu.ph', '192.168.1.88', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Firefox/121.0', 'failed', 'user_not_found', '2026-10-22 10:14:00'),
  (6, 6, 'mark.tan@campus.edu.ph', '192.168.1.60', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0', 'failed', 'invalid_password', '2026-10-22 11:00:10'),
  (7, 6, 'mark.tan@campus.edu.ph', '192.168.1.60', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0', 'failed', 'invalid_password', '2026-10-22 11:01:05'),
  (8, 6, 'mark.tan@campus.edu.ph', '192.168.1.60', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0', 'failed', 'invalid_password', '2026-10-22 11:02:22'),
  (9, 6, 'mark.tan@campus.edu.ph', '192.168.1.60', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0', 'failed', 'invalid_password', '2026-10-22 11:03:40'),
  (10, 6, 'mark.tan@campus.edu.ph', '192.168.1.60', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0', 'failed', 'invalid_password', '2026-10-22 11:04:15'),
  (11, 6, 'mark.tan@campus.edu.ph', '192.168.1.60', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0', 'locked', 'account_locked', '2026-10-22 11:04:16');

INSERT INTO audit_logs (id, actor_id, action, entity_type, entity_id, details, ip_address, created_at) VALUES
  (1, 1, 'seller_approved', 'seller_profiles', 1, 'Verified student credentials and approved seller status.', '192.168.1.10', '2026-10-06 14:00:00'),
  (2, 1, 'seller_approved', 'seller_profiles', 2, 'Verified publication editor credentials and approved status.', '192.168.1.10', '2026-10-09 10:30:00'),
  (3, 1, 'report_reviewed', 'reports', 1, 'Inspected reported service #4; dismissed as compliant.', '192.168.1.10', '2026-10-21 16:00:00');
