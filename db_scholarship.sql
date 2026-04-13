-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 01, 2026 at 06:52 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `db_scholarship`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `actor_user_id` int(10) UNSIGNED DEFAULT NULL,
  `actor_role` varchar(30) NOT NULL DEFAULT 'guest',
  `actor_name` varchar(150) DEFAULT NULL,
  `target_user_id` int(10) UNSIGNED DEFAULT NULL,
  `target_name` varchar(150) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(10) UNSIGNED DEFAULT NULL,
  `entity_name` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `details` longtext DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `actor_user_id`, `actor_role`, `actor_name`, `target_user_id`, `target_name`, `action`, `entity_type`, `entity_id`, `entity_name`, `description`, `details`, `ip_address`, `created_at`) VALUES
(1, 8, 'student', 'James', 8, 'James', 'logout', 'authentication', 8, 'James', 'User logged out.', '{\"role\":\"student\"}', '::1', '2026-04-01 11:21:57'),
(2, 1, 'super_admin', 'James', 1, 'James', 'login', 'authentication', 1, 'James', 'User logged in successfully.', '{\"email\":\"jaimslouis@gmail.com\",\"role\":\"super_admin\"}', '::1', '2026-04-01 11:54:01'),
(3, 1, 'super_admin', 'James', 1, 'James', 'logout', 'authentication', 1, 'James', 'User logged out.', '{\"role\":\"super_admin\"}', '::1', '2026-04-01 11:54:30'),
(4, 7, 'student', 'James Louis Rosario', 7, 'James Louis Rosario', 'login', 'authentication', 7, 'James Louis Rosario', 'User logged in successfully.', '{\"email\":\"chosenonet018@gmail.com\",\"role\":\"student\"}', '::1', '2026-04-01 15:35:20'),
(5, 7, 'student', 'jaymslouis', 7, 'jaymslouis', 'logout', 'authentication', 7, 'jaymslouis', 'User logged out.', '{\"role\":\"student\"}', '::1', '2026-04-01 15:42:22'),
(6, 7, 'student', 'James Louis Rosario', 7, 'James Louis Rosario', 'login', 'authentication', 7, 'James Louis Rosario', 'User logged in successfully.', '{\"email\":\"chosenonet018@gmail.com\",\"role\":\"student\"}', '::1', '2026-04-01 15:55:09'),
(7, 7, 'student', 'jaymslouis', 7, 'jaymslouis', 'logout', 'authentication', 7, 'jaymslouis', 'User logged out.', '{\"role\":\"student\"}', '::1', '2026-04-01 16:50:12');

-- --------------------------------------------------------

--
-- Table structure for table `applications`
--

CREATE TABLE `applications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `scholarship_id` int(11) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `probability_score` decimal(5,2) DEFAULT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_requirements`
--

CREATE TABLE `document_requirements` (
  `id` int(11) NOT NULL,
  `scholarship_id` int(11) NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `is_required` tinyint(1) DEFAULT 1,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_requirements`
--

INSERT INTO `document_requirements` (`id`, `scholarship_id`, `document_type`, `is_required`, `description`) VALUES
(33, 4, 'barangay_clearance', 1, 'Clearance from barangay'),
(34, 4, 'birth_certificate', 1, 'PSA or NSO issued birth certificate'),
(35, 4, 'certificate_of_indigency', 1, 'From barangay or municipal hall'),
(36, 4, 'good_moral', 1, 'Certificate of good moral character from school'),
(37, 5, 'birth_certificate', 1, 'PSA or NSO issued birth certificate'),
(38, 5, 'certificate_of_indigency', 1, 'From barangay or municipal hall'),
(39, 5, 'good_moral', 1, 'Certificate of good moral character from school'),
(40, 6, 'birth_certificate', 1, 'PSA or NSO issued birth certificate'),
(41, 6, 'certificate_of_indigency', 1, 'From barangay or municipal hall'),
(42, 6, 'income_tax', 1, 'ITR of parents or guardian (if applicable)'),
(43, 6, 'enrollment', 1, 'Certificate of enrollment or registration form'),
(44, 6, 'grades', 1, 'Official transcript of records or grade slip'),
(45, 2, 'birth_certificate', 1, 'PSA or NSO issued birth certificate'),
(46, 2, 'certificate_of_indigency', 1, 'From barangay or municipal hall'),
(47, 2, 'good_moral', 1, 'Certificate of good moral character from school'),
(48, 2, 'income_tax', 1, 'ITR of parents or guardian (if applicable)'),
(49, 2, 'recommendation', 1, 'Letter of recommendation from teacher/professor'),
(50, 2, 'id', 1, 'Government-issued ID (passport, driver\'s license, school ID, etc.)'),
(51, 3, 'certificate_of_indigency', 1, 'From barangay or municipal hall'),
(52, 3, 'good_moral', 1, 'Certificate of good moral character from school'),
(53, 3, 'income_tax', 1, 'ITR of parents or guardian (if applicable)'),
(54, 3, 'grades', 1, 'Official transcript of records or grade slip'),
(60, 7, 'barangay_clearance', 1, 'Clearance from barangay'),
(61, 7, 'birth_certificate', 1, 'PSA or NSO issued birth certificate'),
(62, 7, 'certificate_of_indigency', 1, 'From barangay or municipal hall'),
(63, 7, 'good_moral', 1, 'Certificate of good moral character from school'),
(64, 7, 'grades', 1, 'Official transcript of records or grade slip');

-- --------------------------------------------------------

--
-- Table structure for table `document_types`
--

CREATE TABLE `document_types` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `max_size` int(11) DEFAULT 5242880,
  `allowed_types` varchar(255) DEFAULT 'pdf,jpg,jpeg,png'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_types`
--

INSERT INTO `document_types` (`id`, `code`, `name`, `description`, `max_size`, `allowed_types`) VALUES
(1, 'id', 'Valid ID', 'Government-issued ID (passport, driver\'s license, school ID, etc.)', 5242880, 'pdf,jpg,jpeg,png'),
(2, 'birth_certificate', 'Birth Certificate', 'PSA or NSO issued birth certificate', 5242880, 'pdf,jpg,jpeg,png'),
(3, 'grades', 'Transcript of Records', 'Official transcript of records or grade slip', 5242880, 'pdf,jpg,jpeg,png'),
(4, 'good_moral', 'Good Moral Character', 'Certificate of good moral character from school', 5242880, 'pdf,jpg,jpeg,png'),
(5, 'enrollment', 'Proof of Enrollment', 'Certificate of enrollment or registration form', 5242880, 'pdf,jpg,jpeg,png'),
(6, 'income_tax', 'Income Tax Return', 'ITR of parents or guardian (if applicable)', 5242880, 'pdf,jpg,jpeg,png'),
(7, 'certificate_of_indigency', 'Certificate of Indigency', 'From barangay or municipal hall', 5242880, 'pdf,jpg,jpeg,png'),
(8, 'voters_id', 'Voter\'s ID/Certificate', 'For scholarships requiring voter status', 5242880, 'pdf,jpg,jpeg,png'),
(9, 'barangay_clearance', 'Barangay Clearance', 'Clearance from barangay', 5242880, 'pdf,jpg,jpeg,png'),
(10, 'medical_certificate', 'Medical Certificate', 'For health-related scholarships', 5242880, 'pdf,jpg,jpeg,png'),
(11, 'essay', 'Personal Essay', 'Essay or personal statement', 5242880, 'pdf,jpg,jpeg,png'),
(12, 'recommendation', 'Recommendation Letter', 'Letter of recommendation from teacher/professor', 5242880, 'pdf,jpg,jpeg,png');

-- --------------------------------------------------------

--
-- Table structure for table `gwa_issue_reports`
--

CREATE TABLE `gwa_issue_reports` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `document_id` int(10) UNSIGNED DEFAULT NULL,
  `extracted_gwa` decimal(4,2) DEFAULT NULL,
  `reported_gwa` decimal(4,2) DEFAULT NULL,
  `raw_ocr_value` decimal(6,2) DEFAULT NULL,
  `reason_code` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `status` enum('pending','reviewed','resolved','rejected') NOT NULL DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `scholarships`
--

CREATE TABLE `scholarships` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `eligibility` text DEFAULT NULL,
  `max_gwa` decimal(3,2) DEFAULT NULL,
  `min_gwa` decimal(3,2) DEFAULT 1.00,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `scholarships`
--

INSERT INTO `scholarships` (`id`, `name`, `description`, `eligibility`, `max_gwa`, `min_gwa`, `status`, `created_at`, `updated_at`) VALUES
(2, 'DOST-SEI Undergraduate Scholarship', 'Science and Engineering scholarship for STEM courses', 'GWA ≤ 2.0, STEM Course, CHED Accredited', NULL, 1.50, 'active', '2026-01-27 16:00:00', '2026-03-29 13:58:10'),
(3, 'LMP Scholarship Program', 'Local Government Unit scholarship', 'Barangay Resident, GWA ≤ 2.5', NULL, 2.50, 'active', '2026-01-27 16:00:00', '2026-03-29 13:58:19'),
(4, 'SM Foundation Scholarship', 'Scholarship for college students in need', 'GWA ≤ 2.0, Annual Family Income ≤ 150k', NULL, 1.75, 'active', '2026-01-29 08:58:14', '2026-03-29 13:57:29'),
(5, 'Ayala Foundation Scholarship', 'Merit-based scholarship for STEM students', 'GWA ≤ 1.5, STEM Course', NULL, 1.50, 'active', '2026-01-29 08:58:14', '2026-03-29 13:57:48'),
(6, 'University of the Philippines Scholarship', 'Internal scholarship for UP students', 'UP Student, GWA ≤ 1.75', NULL, 2.00, 'active', '2026-01-29 08:58:14', '2026-03-29 13:57:59'),
(7, 'CHED Merit Scholarship Program', 'Comprehensive scholarship for deserving Filipino students', 'GWA ≤ 2.00, CHED Accredited School', NULL, 2.00, 'active', '2026-03-18 09:54:32', '2026-03-29 15:37:32');

-- --------------------------------------------------------

--
-- Table structure for table `scholarship_data`
--

CREATE TABLE `scholarship_data` (
  `id` int(11) NOT NULL,
  `scholarship_id` int(11) NOT NULL,
  `image` varchar(500) DEFAULT NULL COMMENT 'Path to scholarship provider image/logo',
  `provider` varchar(155) DEFAULT NULL,
  `benefits` text DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `deadline` date DEFAULT NULL,
  `assessment_requirement` varchar(50) DEFAULT 'none',
  `assessment_link` varchar(255) DEFAULT NULL,
  `assessment_details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `target_applicant_type` varchar(50) NOT NULL DEFAULT 'all',
  `target_year_level` varchar(40) NOT NULL DEFAULT 'any',
  `required_admission_status` varchar(40) NOT NULL DEFAULT 'any',
  `preferred_course` varchar(150) DEFAULT NULL,
  `target_strand` varchar(80) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `scholarship_data`
--

INSERT INTO `scholarship_data` (`id`, `scholarship_id`, `image`, `provider`, `benefits`, `address`, `city`, `province`, `deadline`, `assessment_requirement`, `assessment_link`, `assessment_details`, `created_at`, `updated_at`, `target_applicant_type`, `target_year_level`, `required_admission_status`, `preferred_course`, `target_strand`) VALUES
(9, 2, '69ba7a895018b_1773828745.png', 'Department of Science and Technology', 'Tuition + Allowance + Book Allowance + Monthly Stipend', 'DOST-SEI Office, Bicutan, Taguig', 'Taguig City', 'Metro Manila', '2026-04-30', 'none', NULL, NULL, '2026-01-27 16:00:00', '2026-03-18 10:12:25', 'all', 'any', 'any', NULL, NULL),
(10, 3, NULL, 'Local Government Unit', 'Partial Tuition Coverage', 'Local Government Unit, Main Office', 'Manila', 'Metro Manila', '2026-05-15', 'none', NULL, NULL, '2026-01-27 16:00:00', '2026-01-29 08:58:14', 'all', 'any', 'any', NULL, NULL),
(11, 4, '69ba79cc5f173_1773828556.png', 'SM Foundation', 'Tuition + Allowance + Book Allowance', 'SM Head Office, Mall of Asia Complex', 'Pasay City', 'Metro Manila', '2026-06-30', 'none', NULL, NULL, '2026-01-29 08:58:14', '2026-03-18 10:09:16', 'all', 'any', 'any', NULL, NULL),
(12, 5, '69ba7a70a196e_1773828720.png', 'Ayala Foundation', 'Full Scholarship + Internship Opportunity', 'Ayala Avenue, Makati', 'Makati City', 'Metro Manila', '2026-07-15', 'none', NULL, NULL, '2026-01-29 08:58:14', '2026-03-18 10:12:00', 'all', 'any', 'any', NULL, NULL),
(13, 6, '69ba7a7b08623_1773828731.png', 'University of the Philippines', 'Tuition Discount + Stipend', 'UP Diliman Campus', 'Quezon City', 'Metro Manila', '2026-08-31', 'none', NULL, NULL, '2026-01-29 08:58:14', '2026-03-18 10:12:11', 'all', 'any', 'any', NULL, NULL),
(16, 7, '69ba76584f604_1773827672.jpg', 'Commission on Higher Education', 'Full Tuition + Monthly Stipend + Book Allowance', 'Samar Street', 'Taguig', 'Metro Manila', '2027-01-12', 'remote_examination', NULL, NULL, '2026-03-18 09:56:40', '2026-03-30 00:54:37', 'all', 'any', 'any', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `scholarship_location`
--

CREATE TABLE `scholarship_location` (
  `id` int(11) NOT NULL,
  `scholarship_id` int(11) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `scholarship_location`
--

INSERT INTO `scholarship_location` (`id`, `scholarship_id`, `latitude`, `longitude`) VALUES
(1, 1, 14.65325800, 121.05433000),
(2, 2, 14.51015700, 121.05020000),
(3, 3, 14.59951200, 120.98422200),
(4, 4, 14.53550000, 120.98200000),
(5, 5, 14.55472900, 121.02444500),
(6, 6, 14.65476400, 121.07099400),
(7, 1, 14.65325800, 121.05433000),
(8, 7, 14.51016633, 121.05018282);

-- --------------------------------------------------------

--
-- Table structure for table `scholarship_remote_exam_locations`
--

CREATE TABLE `scholarship_remote_exam_locations` (
  `id` int(10) UNSIGNED NOT NULL,
  `scholarship_id` int(10) UNSIGNED NOT NULL,
  `site_name` varchar(150) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `province` varchar(120) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `scholarship_remote_exam_locations`
--

INSERT INTO `scholarship_remote_exam_locations` (`id`, `scholarship_id`, `site_name`, `address`, `city`, `province`, `latitude`, `longitude`, `created_at`, `updated_at`) VALUES
(1, 7, 'Remote Testing Site A', 'Bacoor Boulevard', 'Bacoor', 'Cavite', 14.45858252, 120.96009064, '2026-03-30 08:54:37', NULL),
(2, 7, 'Remote Testing Sita B', 'General E. Topacio Street', 'Imus', 'Cavite', 14.42768026, 120.93720818, '2026-03-30 08:54:37', NULL),
(3, 7, 'Remote Testing Site C', 'San Agustin', 'Trece Martires', 'Cavite', 14.28028324, 120.86766815, '2026-03-30 08:54:37', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `signup_verifications`
--

CREATE TABLE `signup_verifications` (
  `email` varchar(255) NOT NULL,
  `code_hash` varchar(64) DEFAULT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `sent_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `signup_verifications`
--

INSERT INTO `signup_verifications` (`email`, `code_hash`, `verified`, `attempts`, `sent_at`, `expires_at`, `verified_at`, `created_at`, `updated_at`) VALUES
('jayms3rd@gmail.com', NULL, 1, 0, NULL, '2026-04-01 14:21:53', '2026-04-01 13:51:53', '2026-04-01 11:50:17', '2026-04-01 11:51:53');

-- --------------------------------------------------------

--
-- Table structure for table `student_data`
--

CREATE TABLE `student_data` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `firstname` varchar(255) NOT NULL,
  `lastname` varchar(255) NOT NULL,
  `middleinitial` varchar(10) NOT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `age` int(11) NOT NULL,
  `birthdate` date DEFAULT NULL,
  `gender` varchar(100) DEFAULT NULL,
  `course` varchar(255) NOT NULL,
  `gwa` decimal(3,2) DEFAULT NULL,
  `last_gwa_update` timestamp NULL DEFAULT NULL,
  `school` varchar(255) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `house_no` varchar(80) DEFAULT NULL,
  `street` varchar(120) DEFAULT NULL,
  `barangay` varchar(120) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `province` varchar(120) DEFAULT NULL,
  `parent_background` text DEFAULT NULL,
  `applicant_type` varchar(50) DEFAULT NULL,
  `shs_school` varchar(150) DEFAULT NULL,
  `shs_strand` varchar(80) DEFAULT NULL,
  `shs_graduation_year` smallint(5) UNSIGNED DEFAULT NULL,
  `shs_average` decimal(5,2) DEFAULT NULL,
  `admission_status` varchar(40) DEFAULT NULL,
  `target_college` varchar(150) DEFAULT NULL,
  `target_course` varchar(150) DEFAULT NULL,
  `year_level` varchar(40) DEFAULT NULL,
  `enrollment_status` varchar(40) DEFAULT NULL,
  `academic_standing` varchar(40) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_data`
--

INSERT INTO `student_data` (`id`, `student_id`, `firstname`, `lastname`, `middleinitial`, `suffix`, `age`, `birthdate`, `gender`, `course`, `gwa`, `last_gwa_update`, `school`, `address`, `house_no`, `street`, `barangay`, `city`, `province`, `parent_background`, `applicant_type`, `shs_school`, `shs_strand`, `shs_graduation_year`, `shs_average`, `admission_status`, `target_college`, `target_course`, `year_level`, `enrollment_status`, `academic_standing`) VALUES
(19, 7, 'James Louis', 'Rosario', 'H.', NULL, 21, '2005-01-12', NULL, 'Bachelor of Science in Information Technology', NULL, NULL, 'National College of Science & Technology', 'BLK 26 LOT 42, Viva Homes Estate, Salawag, Dasmariñas, Cavite', 'BLK 26 LOT 42', 'Viva Homes Estate', 'Salawag', 'Dasmariñas', 'Cavite', NULL, 'current_college', NULL, NULL, NULL, NULL, 'enrolled', NULL, 'Bachelor of Science in Information Technology', '3rd_year', 'currently_enrolled', 'good_standing');

-- --------------------------------------------------------

--
-- Table structure for table `student_location`
--

CREATE TABLE `student_location` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `location_name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_location`
--

INSERT INTO `student_location` (`id`, `student_id`, `latitude`, `longitude`, `location_name`) VALUES
(7, 7, 14.33677500, 120.98964660, 'Viva Homes Estate, Salawag, Dasmariñas, Cavite, Calabarzon, Philippines');

-- --------------------------------------------------------

--
-- Table structure for table `upload_history`
--

CREATE TABLE `upload_history` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `action` enum('upload','delete','verified','rejected') NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `password_reset_code_hash` varchar(64) DEFAULT NULL,
  `password_reset_attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `password_reset_sent_at` datetime DEFAULT NULL,
  `password_reset_expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `role` enum('super_admin','admin','provider','student') DEFAULT 'student',
  `access_level` tinyint(3) UNSIGNED NOT NULL DEFAULT 10,
  `status` enum('inactive','active') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `password_reset_code_hash`, `password_reset_attempts`, `password_reset_sent_at`, `password_reset_expires_at`, `created_at`, `role`, `access_level`, `status`) VALUES
(1, 'James', 'jaimslouis@gmail.com', '$2y$10$.t0nLR8Cu9KCFgQWIUInNuvMkGYZAWw8LXWbmlVPRQwGzDaZzePv6', NULL, 0, NULL, NULL, '2026-01-28 09:09:30', 'super_admin', 90, 'active'),
(7, 'jaymslouis', 'chosenonet018@gmail.com', '$2y$10$HoOYl4omcYmzf6d0U5l2oeudf/QgRtB7CVHFF5oDQf1U51VbnWGSW', NULL, 0, NULL, NULL, '2026-04-01 09:35:09', 'student', 10, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `user_documents`
--

CREATE TABLE `user_documents` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `status` enum('pending','verified','rejected') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `verified_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_activity_created_at` (`created_at`),
  ADD KEY `idx_activity_actor_user` (`actor_user_id`),
  ADD KEY `idx_activity_target_user` (`target_user_id`),
  ADD KEY `idx_activity_action` (`action`),
  ADD KEY `idx_activity_entity` (`entity_type`,`entity_id`);

--
-- Indexes for table `applications`
--
ALTER TABLE `applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_applications_user` (`user_id`),
  ADD KEY `fk_applications_scholarship` (`scholarship_id`);

--
-- Indexes for table `document_requirements`
--
ALTER TABLE `document_requirements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_scholarship_doc` (`scholarship_id`,`document_type`);

--
-- Indexes for table `document_types`
--
ALTER TABLE `document_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `gwa_issue_reports`
--
ALTER TABLE `gwa_issue_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gwa_issue_user` (`user_id`,`created_at`),
  ADD KEY `idx_gwa_issue_status` (`status`,`created_at`),
  ADD KEY `idx_gwa_issue_document` (`document_id`);

--
-- Indexes for table `scholarships`
--
ALTER TABLE `scholarships`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `scholarship_data`
--
ALTER TABLE `scholarship_data`
  ADD PRIMARY KEY (`id`),
  ADD KEY `scholarship_id` (`scholarship_id`),
  ADD KEY `idx_scholarship_data_target_applicant_type` (`target_applicant_type`),
  ADD KEY `idx_scholarship_data_target_year_level` (`target_year_level`),
  ADD KEY `idx_scholarship_data_required_admission_status` (`required_admission_status`);

--
-- Indexes for table `scholarship_location`
--
ALTER TABLE `scholarship_location`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `scholarship_remote_exam_locations`
--
ALTER TABLE `scholarship_remote_exam_locations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_remote_exam_scholarship` (`scholarship_id`);

--
-- Indexes for table `signup_verifications`
--
ALTER TABLE `signup_verifications`
  ADD PRIMARY KEY (`email`),
  ADD KEY `idx_signup_verifications_expires_at` (`expires_at`),
  ADD KEY `idx_signup_verifications_verified` (`verified`);

--
-- Indexes for table `student_data`
--
ALTER TABLE `student_data`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_student_data_user` (`student_id`);

--
-- Indexes for table `student_location`
--
ALTER TABLE `student_location`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_student_location_student` (`student_id`);

--
-- Indexes for table `upload_history`
--
ALTER TABLE `upload_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_history` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_access_level` (`access_level`),
  ADD KEY `idx_users_password_reset_expires_at` (`password_reset_expires_at`);

--
-- Indexes for table `user_documents`
--
ALTER TABLE `user_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_document_type` (`document_type`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `applications`
--
ALTER TABLE `applications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `document_requirements`
--
ALTER TABLE `document_requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `document_types`
--
ALTER TABLE `document_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `gwa_issue_reports`
--
ALTER TABLE `gwa_issue_reports`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `scholarships`
--
ALTER TABLE `scholarships`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `scholarship_data`
--
ALTER TABLE `scholarship_data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `scholarship_location`
--
ALTER TABLE `scholarship_location`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `scholarship_remote_exam_locations`
--
ALTER TABLE `scholarship_remote_exam_locations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `student_data`
--
ALTER TABLE `student_data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `student_location`
--
ALTER TABLE `student_location`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `upload_history`
--
ALTER TABLE `upload_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `user_documents`
--
ALTER TABLE `user_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `applications`
--
ALTER TABLE `applications`
  ADD CONSTRAINT `fk_applications_scholarship` FOREIGN KEY (`scholarship_id`) REFERENCES `scholarships` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_applications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `document_requirements`
--
ALTER TABLE `document_requirements`
  ADD CONSTRAINT `document_requirements_ibfk_1` FOREIGN KEY (`scholarship_id`) REFERENCES `scholarships` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `scholarship_data`
--
ALTER TABLE `scholarship_data`
  ADD CONSTRAINT `scholarship_data_ibfk_1` FOREIGN KEY (`scholarship_id`) REFERENCES `scholarships` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `student_data`
--
ALTER TABLE `student_data`
  ADD CONSTRAINT `fk_student_data_user` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_location`
--
ALTER TABLE `student_location`
  ADD CONSTRAINT `fk_student_location_student` FOREIGN KEY (`student_id`) REFERENCES `student_data` (`student_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_documents`
--
ALTER TABLE `user_documents`
  ADD CONSTRAINT `user_documents_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
