-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 24, 2025 at 11:39 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `padroom_borders`
--

-- --------------------------------------------------------

--
-- Table structure for table `emergency_contacts`
--

CREATE TABLE `emergency_contacts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `relationship` varchar(60) DEFAULT NULL,
  `phone` varchar(40) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `floors`
--

CREATE TABLE `floors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `house_id` bigint(20) UNSIGNED NOT NULL,
  `floor_label` varchar(20) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `floors`
--

INSERT INTO `floors` (`id`, `house_id`, `floor_label`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 1, 'Floor 1', 1, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(2, 1, 'Floor 2', 2, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(3, 2, 'Floor 1', 1, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(4, 2, 'Floor 2', 2, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(5, 3, 'Floor 1', 1, '2025-10-24 01:56:21', '2025-10-24 01:56:21'),
(6, 4, 'Floor 1', 1, '2025-10-24 12:24:47', '2025-10-24 12:24:47'),
(7, 4, 'Floor 2', 2, '2025-10-24 12:24:47', '2025-10-24 12:24:47');

-- --------------------------------------------------------

--
-- Table structure for table `houses`
--

CREATE TABLE `houses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `houses`
--

INSERT INTO `houses` (`id`, `name`, `address`, `notes`, `is_archived`, `archived_at`, `created_at`, `updated_at`) VALUES
(1, 'House 1 , Penrol Area', 'Zone8 210 , Lower Dagong Zayas', NULL, 1, '2025-10-24 03:17:44', '2025-10-23 18:32:16', '2025-10-23 19:17:44'),
(2, 'House 1 , 2 Storey Boarding Rooms', 'Zone8 210 Zayas Oroham , Lower Dagong', NULL, 0, NULL, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(3, 'House 2 , Green Terso House', 'Zone8 210 Zayas Oroham , Lower Dagong', NULL, 0, NULL, '2025-10-24 01:56:21', '2025-10-24 01:56:21'),
(4, 'House 3 , 2nd Floor Boarding House Design', 'Upper Carmen , Zone 10 , Purok 4, Carmen, City of Cagayan De Oro, Misamis Oriental, Northern Mindanao, 9000', NULL, 0, NULL, '2025-10-24 12:24:47', '2025-10-24 12:24:47');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `renter_id` bigint(20) UNSIGNED NOT NULL,
  `room_id` bigint(20) UNSIGNED DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `months_paid` int(11) NOT NULL,
  `payment_date` datetime NOT NULL DEFAULT current_timestamp(),
  `remarks` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `renter_id`, `room_id`, `amount`, `months_paid`, `payment_date`, `remarks`) VALUES
(2, 2, 34, 3000.00, 2, '2025-10-24 14:06:48', NULL),
(3, 3, 31, 2150.00, 1, '2025-10-24 14:25:22', NULL),
(4, 4, 32, 2150.00, 1, '2025-10-24 14:33:18', NULL),
(5, 5, 33, 2250.00, 1, '2025-10-24 20:25:52', NULL),
(6, 6, 33, 2350.00, 1, '2025-10-24 20:37:55', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `renters`
--

CREATE TABLE `renters` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tenant_id` bigint(20) UNSIGNED NOT NULL,
  `room_id` bigint(20) UNSIGNED NOT NULL,
  `start_date` date NOT NULL,
  `due_date` date NOT NULL,
  `monthly_rate` decimal(10,2) NOT NULL,
  `total_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','ended') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `renters`
--

INSERT INTO `renters` (`id`, `tenant_id`, `room_id`, `start_date`, `due_date`, `monthly_rate`, `total_paid`, `status`, `created_at`) VALUES
(2, 1, 34, '2025-10-24', '2025-12-24', 1500.00, 3000.00, 'active', '2025-10-24 06:06:48'),
(3, 2, 31, '2025-10-24', '2025-12-07', 1500.00, 2150.00, 'active', '2025-10-24 06:25:22'),
(4, 3, 32, '2025-10-24', '2025-12-07', 1500.00, 2150.00, 'active', '2025-10-24 06:33:17'),
(5, 4, 33, '2025-10-24', '2025-11-24', 1500.00, 2250.00, '', '2025-10-24 12:25:52'),
(6, 4, 33, '2025-10-24', '2025-12-10', 1500.00, 2350.00, 'active', '2025-10-24 12:37:54');

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `floor_id` bigint(20) UNSIGNED NOT NULL,
  `room_label` varchar(30) NOT NULL,
  `capacity` int(11) DEFAULT NULL,
  `status` enum('vacant','occupied','maintenance') NOT NULL DEFAULT 'vacant',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rooms`
--

INSERT INTO `rooms` (`id`, `floor_id`, `room_label`, `capacity`, `status`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 'Room 1', NULL, 'vacant', NULL, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(2, 1, 'Room 2', NULL, 'vacant', NULL, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(3, 1, 'Room 3', NULL, 'vacant', NULL, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(4, 1, 'Room 4', NULL, 'vacant', NULL, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(5, 1, 'Room 5', NULL, 'vacant', NULL, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(6, 1, 'Room 6', NULL, 'vacant', NULL, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(7, 1, 'Room 7', NULL, 'vacant', NULL, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(8, 1, 'Room 8', NULL, 'vacant', NULL, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(9, 2, 'Room 1', NULL, 'vacant', NULL, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(10, 2, 'Room 2', NULL, 'vacant', NULL, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(11, 2, 'Room 3', NULL, 'vacant', NULL, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(12, 2, 'Room 4', NULL, 'vacant', NULL, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(13, 2, 'Room 5', NULL, 'vacant', NULL, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(14, 2, 'Room 6', NULL, 'vacant', NULL, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(15, 2, 'Room 7', NULL, 'vacant', NULL, '2025-10-23 18:32:16', '2025-10-23 18:32:16'),
(16, 3, 'Room 101', NULL, 'vacant', NULL, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(17, 3, 'Room 102', NULL, 'vacant', NULL, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(18, 3, 'Room 103', NULL, 'vacant', NULL, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(19, 3, 'Room 104', NULL, 'vacant', NULL, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(20, 3, 'Room 105', NULL, 'vacant', NULL, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(21, 3, 'Room 106', NULL, 'vacant', NULL, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(22, 3, 'Room 107', NULL, 'vacant', NULL, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(23, 3, 'Room 108', NULL, 'vacant', NULL, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(24, 4, 'Room 201', NULL, 'vacant', NULL, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(25, 4, 'Room 202', NULL, 'vacant', NULL, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(26, 4, 'Room 203', NULL, 'vacant', NULL, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(27, 4, 'Room 204', NULL, 'vacant', NULL, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(28, 4, 'Room 205', NULL, 'vacant', NULL, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(29, 4, 'Room 206', NULL, 'vacant', NULL, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(30, 4, 'Room 207', NULL, 'vacant', NULL, '2025-10-23 19:18:51', '2025-10-23 19:18:51'),
(31, 5, 'Room 101', NULL, 'occupied', NULL, '2025-10-24 01:56:21', '2025-10-24 06:25:22'),
(32, 5, 'Room 102', NULL, 'occupied', NULL, '2025-10-24 01:56:21', '2025-10-24 06:33:18'),
(33, 5, 'Room 103', NULL, 'occupied', NULL, '2025-10-24 01:56:21', '2025-10-24 12:37:55'),
(34, 5, 'Room 104', NULL, 'occupied', NULL, '2025-10-24 01:56:21', '2025-10-24 06:06:48'),
(35, 5, 'Room 105', NULL, 'vacant', NULL, '2025-10-24 01:56:21', '2025-10-24 01:56:21'),
(36, 5, 'Room 106', NULL, 'vacant', NULL, '2025-10-24 01:56:21', '2025-10-24 01:56:21'),
(37, 5, 'Room 107', NULL, 'vacant', NULL, '2025-10-24 01:56:21', '2025-10-24 01:56:21'),
(38, 5, 'Room 108', NULL, 'vacant', NULL, '2025-10-24 01:56:21', '2025-10-24 01:56:21'),
(39, 6, 'Room 101', NULL, 'vacant', NULL, '2025-10-24 12:24:47', '2025-10-24 12:24:47'),
(40, 7, 'Room 201', NULL, 'vacant', NULL, '2025-10-24 12:24:47', '2025-10-24 12:24:47'),
(41, 7, 'Room 202', NULL, 'vacant', NULL, '2025-10-24 12:24:47', '2025-10-24 12:24:47'),
(42, 7, 'Room 203', NULL, 'vacant', NULL, '2025-10-24 12:24:47', '2025-10-24 12:24:47'),
(43, 7, 'Room 204', NULL, 'vacant', NULL, '2025-10-24 12:24:47', '2025-10-24 12:24:47'),
(44, 7, 'Room 205', NULL, 'vacant', NULL, '2025-10-24 12:24:47', '2025-10-24 12:24:47'),
(45, 7, 'Room 206', NULL, 'vacant', NULL, '2025-10-24 12:24:47', '2025-10-24 12:24:47'),
(46, 7, 'Room 207', NULL, 'vacant', NULL, '2025-10-24 12:24:47', '2025-10-24 12:24:47'),
(47, 7, 'Room 208', NULL, 'vacant', NULL, '2025-10-24 12:24:47', '2025-10-24 12:24:47'),
(48, 7, 'Room 209', NULL, 'vacant', NULL, '2025-10-24 12:24:47', '2025-10-24 12:24:47');

-- --------------------------------------------------------

--
-- Table structure for table `room_rate_history`
--

CREATE TABLE `room_rate_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `room_id` bigint(20) UNSIGNED NOT NULL,
  `monthly_rate` decimal(10,2) NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `room_rate_history`
--

INSERT INTO `room_rate_history` (`id`, `room_id`, `monthly_rate`, `effective_from`, `effective_to`, `notes`, `created_at`) VALUES
(2, 1, 1500.00, '2025-10-24', NULL, 'bulk update', '2025-10-23 18:47:53'),
(3, 2, 0.00, '2025-10-24', NULL, 'bulk update', '2025-10-23 18:47:53'),
(4, 3, 0.00, '2025-10-24', NULL, 'bulk update', '2025-10-23 18:47:53'),
(5, 4, 0.00, '2025-10-24', NULL, 'bulk update', '2025-10-23 18:47:53'),
(6, 5, 0.00, '2025-10-24', NULL, 'bulk update', '2025-10-23 18:47:53'),
(7, 6, 0.00, '2025-10-24', NULL, 'bulk update', '2025-10-23 18:47:53'),
(8, 7, 0.00, '2025-10-24', NULL, 'bulk update', '2025-10-23 18:47:53'),
(9, 8, 0.00, '2025-10-24', NULL, 'bulk update', '2025-10-23 18:47:53'),
(10, 9, 0.00, '2025-10-24', NULL, 'bulk update', '2025-10-23 18:47:53'),
(11, 10, 0.00, '2025-10-24', NULL, 'bulk update', '2025-10-23 18:47:53'),
(12, 11, 0.00, '2025-10-24', NULL, 'bulk update', '2025-10-23 18:47:53'),
(13, 12, 0.00, '2025-10-24', NULL, 'bulk update', '2025-10-23 18:47:53'),
(14, 13, 0.00, '2025-10-24', NULL, 'bulk update', '2025-10-23 18:47:53'),
(15, 14, 0.00, '2025-10-24', NULL, 'bulk update', '2025-10-23 18:47:53'),
(16, 15, 0.00, '2025-10-24', NULL, 'bulk update', '2025-10-23 18:47:53'),
(17, 31, 1500.00, '2025-10-24', NULL, 'bulk update', '2025-10-24 02:18:04'),
(18, 32, 1500.00, '2025-10-24', NULL, 'bulk update', '2025-10-24 02:18:04'),
(19, 33, 1500.00, '2025-10-24', NULL, 'bulk update', '2025-10-24 02:18:04'),
(20, 34, 1500.00, '2025-10-24', NULL, 'bulk update', '2025-10-24 02:18:04'),
(21, 35, 1500.00, '2025-10-24', NULL, 'bulk update', '2025-10-24 02:18:04'),
(22, 36, 1500.00, '2025-10-24', NULL, 'bulk update', '2025-10-24 02:18:04'),
(23, 37, 1500.00, '2025-10-24', NULL, 'bulk update', '2025-10-24 02:18:04'),
(24, 38, 1500.00, '2025-10-24', NULL, 'bulk update', '2025-10-24 02:18:04');

-- --------------------------------------------------------

--
-- Table structure for table `tenants`
--

CREATE TABLE `tenants` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `gender` varchar(20) NOT NULL,
  `age` int(11) NOT NULL,
  `civil_status` varchar(50) DEFAULT NULL,
  `contact_no` varchar(50) NOT NULL,
  `emergency_name` varchar(150) DEFAULT NULL,
  `emergency_contact` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tenants`
--

INSERT INTO `tenants` (`id`, `first_name`, `middle_name`, `last_name`, `gender`, `age`, `civil_status`, `contact_no`, `emergency_name`, `emergency_contact`, `address`, `is_archived`, `archived_at`, `created_at`) VALUES
(1, 'Erl Perseus', 'Gomez', 'Latras', 'Male', 21, 'Single', '09979925115', 'Eric P. Latras', '09098752194', 'Zone8 210 , Lower Dagong Zayas Oroham', 0, NULL, '2025-10-24 02:05:38'),
(2, 'Kenneth', '', 'Otero', 'Male', 22, 'Widowed', '09929915254', 'Ms , Otero', '09614242142', 'Sucbongcogon', 0, NULL, '2025-10-24 03:21:12'),
(3, 'Kevin', '', 'Migraso', 'Male', 21, 'Single', '09616142091', 'N / A', 'N / A', 'BONBON , Cagayan De Oro City', 0, NULL, '2025-10-24 14:29:31'),
(4, 'Eric', '', 'Latras', 'Male', 42, 'Married', '09616142091', 'Rachelle Latras', '09616142051', 'Zone 10 Upper Carmen, Carmen, City of Cagayan De Oro, Misamis Oriental, Northern Mindanao, 9000', 0, NULL, '2025-10-24 20:18:40'),
(5, 'John', '', 'Berte', 'Male', 30, 'Single', '09616142091', 'Johana Berte', '09616142091', 'dsafdasfasdfdsafsa, Carmen, City of Cagayan De Oro, Misamis Oriental, Northern Mindanao, 9000', 0, NULL, '2025-10-25 05:21:56'),
(6, 'Alysaa', '', 'America', 'Female', 22, 'Single', '09979925115', 'Alwano America', '09979925115', 'Zone 10 Upper Carmen, Carmen, City of Cagayan De Oro, Misamis Oriental, Northern Mindanao, 9000', 0, NULL, '2025-10-25 05:37:46');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','staff') NOT NULL DEFAULT 'admin',
  `full_name` varchar(120) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `full_name`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'admin123', 'admin123', 'admin', 'Administrator', 1, '2025-10-23 15:38:00', '2025-10-23 15:38:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `emergency_contacts`
--
ALTER TABLE `emergency_contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_emc_tenant` (`tenant_id`),
  ADD KEY `idx_emc_primary` (`tenant_id`,`is_primary`);

--
-- Indexes for table `floors`
--
ALTER TABLE `floors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_house_floor` (`house_id`,`floor_label`);

--
-- Indexes for table `houses`
--
ALTER TABLE `houses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_house_name` (`name`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payment_renter` (`renter_id`),
  ADD KEY `fk_payment_room` (`room_id`);

--
-- Indexes for table `renters`
--
ALTER TABLE `renters`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_renter_tenant` (`tenant_id`),
  ADD KEY `idx_renter_room` (`room_id`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_floor_room` (`floor_id`,`room_label`);

--
-- Indexes for table `room_rate_history`
--
ALTER TABLE `room_rate_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rate_room_from` (`room_id`,`effective_from`),
  ADD KEY `idx_rate_room_to` (`room_id`,`effective_to`);

--
-- Indexes for table `tenants`
--
ALTER TABLE `tenants`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tenant_name` (`last_name`,`first_name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `emergency_contacts`
--
ALTER TABLE `emergency_contacts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `floors`
--
ALTER TABLE `floors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `houses`
--
ALTER TABLE `houses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `renters`
--
ALTER TABLE `renters`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `room_rate_history`
--
ALTER TABLE `room_rate_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `tenants`
--
ALTER TABLE `tenants`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `emergency_contacts`
--
ALTER TABLE `emergency_contacts`
  ADD CONSTRAINT `fk_emc_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `floors`
--
ALTER TABLE `floors`
  ADD CONSTRAINT `fk_floors_house` FOREIGN KEY (`house_id`) REFERENCES `houses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payment_renter` FOREIGN KEY (`renter_id`) REFERENCES `renters` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payment_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `renters`
--
ALTER TABLE `renters`
  ADD CONSTRAINT `fk_renter_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_renter_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `rooms`
--
ALTER TABLE `rooms`
  ADD CONSTRAINT `fk_rooms_floor` FOREIGN KEY (`floor_id`) REFERENCES `floors` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `room_rate_history`
--
ALTER TABLE `room_rate_history`
  ADD CONSTRAINT `fk_rate_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
