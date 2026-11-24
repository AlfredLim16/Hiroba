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
  (1, 'Hiroba Admin Desk', 'admin@hiroba.edu.ph', '$2y$10$wjLH/BtOGOEaZ8/4vl5sFu2l.wFr56ymV6/aZcPU9vtR2NGJUs/v.', 1, 1, 0, NULL, '2026-10-01 08:00:00');

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
