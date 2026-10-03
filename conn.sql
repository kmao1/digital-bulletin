<<<<<<< HEAD
=======
<<<<<<< HEAD
USE `dsb`;

-- Add role column to officer table
ALTER TABLE `officer` ADD COLUMN `role` ENUM('Editor', 'Moderator', 'Admin Officer') NOT NULL DEFAULT 'Editor';

-- Add attachment path and priority columns to bulletin posts
ALTER TABLE `bulletin_posts` 
  ADD COLUMN `attachment` VARCHAR(255) NULL AFTER `content`,
=======
>>>>>>> 34f445f
USE `dsb`;

-- Add role column to officer table
ALTER TABLE `officer` ADD COLUMN `role` ENUM('Editor', 'Moderator', 'Admin Officer') NOT NULL DEFAULT 'Editor';

-- Add attachment path and priority columns to bulletin posts
ALTER TABLE `bulletin_posts` 
  ADD COLUMN `attachment` VARCHAR(255) NULL AFTER `content`,
<<<<<<< HEAD
=======
>>>>>>> 2f29cb9 (Add Firebase authentication)
>>>>>>> 34f445f
  ADD COLUMN `priority` ENUM('Normal', 'High') NOT NULL DEFAULT 'Normal' AFTER `category`;