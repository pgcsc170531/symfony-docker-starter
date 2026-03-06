-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: database:3306
-- Generation Time: Feb 28, 2026 at 02:18 AM
-- Server version: 8.0.44
-- PHP Version: 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `landlord_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `agent`
--

CREATE TABLE `agent` (
  `id` int NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `email` varchar(180) NOT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `commission_percentage` decimal(5,2) NOT NULL,
  `bank_details` longtext,
  `created_at` datetime NOT NULL,
  `roles` json NOT NULL,
  `password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `agent`
--

INSERT INTO `agent` (`id`, `full_name`, `email`, `phone_number`, `commission_percentage`, `bank_details`, `created_at`, `roles`, `password`) VALUES
(1, 'MUHUSIN UMAR', 'muhusin@gmail.com', '+2348031819670', 20.00, 'jaiz bank 08044404040', '2025-12-27 20:52:32', '[\"ROLE_AGENT\"]', '$2y$13$exp7OjBu2zuim1.3dKLw8OEsKqqPaTyW3LWHmkQ/vp7Hh8MxvDT1K');

-- --------------------------------------------------------

--
-- Table structure for table `credit_request`
--

CREATE TABLE `credit_request` (
  `id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` varchar(20) NOT NULL,
  `proof_filename` varchar(255) DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `admin_note` longtext,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `school_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `credit_request`
--

INSERT INTO `credit_request` (`id`, `amount`, `status`, `proof_filename`, `reference`, `admin_note`, `created_at`, `updated_at`, `school_id`) VALUES
(1, 1000.00, 'APPROVED', NULL, 'CR-6973F3A80169C', NULL, '2026-01-23 22:18:16', '2026-01-23 22:29:44', 13),
(2, 5000.00, 'APPROVED', 'JIS-1A-6973f69084f77.pdf', 'CR-6973F672BF76A', NULL, '2026-01-23 22:30:10', '2026-01-23 22:31:18', 13),
(3, 1000.00, 'APPROVED', 'JIS-3A-6973f7da49821.pdf', 'CR-6973F7CA096E7', NULL, '2026-01-23 22:35:54', '2026-01-23 22:36:35', 13);

-- --------------------------------------------------------

--
-- Table structure for table `global_settings`
--

CREATE TABLE `global_settings` (
  `id` int NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `global_settings`
--

INSERT INTO `global_settings` (`id`, `setting_key`, `setting_value`, `description`) VALUES
(1, 'sms_price', '12', 'Current price per SMS unit for all schools');

-- --------------------------------------------------------

--
-- Table structure for table `messenger_messages`
--

CREATE TABLE `messenger_messages` (
  `id` bigint NOT NULL,
  `body` longtext NOT NULL,
  `headers` longtext NOT NULL,
  `queue_name` varchar(190) NOT NULL,
  `created_at` datetime NOT NULL,
  `available_at` datetime NOT NULL,
  `delivered_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `plan`
--

CREATE TABLE `plan` (
  `id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `price` decimal(12,2) NOT NULL,
  `min_students` int NOT NULL DEFAULT '0',
  `max_students` int DEFAULT NULL,
  `duration_months` int NOT NULL DEFAULT '4',
  `free_credit_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `is_trial` tinyint NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `plan`
--

INSERT INTO `plan` (`id`, `name`, `price`, `min_students`, `max_students`, `duration_months`, `free_credit_amount`, `is_trial`) VALUES
(1, 'Starter', 50000.00, 0, 100, 4, 500.00, 0),
(2, 'Growth Plan', 120000.00, 101, 400, 4, 1500.00, 0),
(3, 'Scale Plan', 250000.00, 0, NULL, 4, 3000.00, 0),
(4, 'Free Trial Plan', 0.00, 0, NULL, 4, 1000.00, 1);

-- --------------------------------------------------------

--
-- Table structure for table `school`
--

CREATE TABLE `school` (
  `id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `subdomain` varchar(255) NOT NULL,
  `database_name` varchar(255) NOT NULL,
  `db_user` varchar(255) NOT NULL,
  `db_password` varchar(255) DEFAULT NULL,
  `db_host` varchar(255) NOT NULL,
  `db_driver` varchar(255) NOT NULL,
  `is_active` tinyint NOT NULL,
  `agent_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `custom_domain` varchar(255) DEFAULT NULL,
  `principal_name` varchar(255) NOT NULL,
  `principal_email` varchar(255) NOT NULL,
  `principal_password` varchar(255) NOT NULL,
  `wallet_balance` decimal(12,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `school`
--


-- --------------------------------------------------------

--
-- Table structure for table `subscription`
--

CREATE TABLE `subscription` (
  `id` int NOT NULL,
  `start_date` datetime NOT NULL,
  `status` varchar(20) NOT NULL,
  `school_id` int NOT NULL,
  `end_date` datetime NOT NULL,
  `plan_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `subscription`
--

INSERT INTO `subscription` (`id`, `start_date`, `status`, `school_id`, `end_date`, `plan_id`) VALUES
(1, '2026-01-11 22:31:24', 'ACTIVE', 9, '2026-05-11 22:31:24', 4),
(2, '2026-01-11 23:25:38', 'TRIAL', 10, '2026-01-25 23:25:38', 4),
(3, '2026-01-11 23:33:28', 'TRIAL', 11, '2026-01-25 23:33:28', 4),
(4, '2026-01-13 02:50:43', 'TRIAL', 12, '2026-01-27 02:50:43', 4),
(5, '2026-01-19 20:49:05', 'ACTIVE', 1, '2026-05-19 20:49:05', 2),
(6, '2026-01-21 18:36:58', 'TRIAL', 13, '2026-02-04 18:36:58', 4),
(7, '2026-01-21 19:46:30', 'TRIAL', 14, '2026-02-04 19:46:30', 4),
(8, '2026-01-21 20:01:37', 'TRIAL', 15, '2026-02-04 20:01:37', 4),
(9, '2026-01-21 20:53:17', 'TRIAL', 16, '2026-02-04 20:53:17', 4);

-- --------------------------------------------------------

--
-- Table structure for table `subscription_payment`
--

CREATE TABLE `subscription_payment` (
  `id` int NOT NULL,
  `reference` varchar(50) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` varchar(20) NOT NULL,
  `proof_of_payment` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `verified_at` datetime DEFAULT NULL,
  `school_id` int NOT NULL,
  `plan_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `subscription_payment`
--

INSERT INTO `subscription_payment` (`id`, `reference`, `amount`, `status`, `proof_of_payment`, `created_at`, `verified_at`, `school_id`, `plan_id`) VALUES
(1, 'SUB-DE5CC3', 120000.00, 'CANCELLED', NULL, '2026-01-19 16:38:05', NULL, 1, 2),
(2, 'SUB-3755EA', 50000.00, 'APPROVED', 'HASSAN-USMAN-KATSINA-POLYTECHNIC-CONSULTANCY-SERVICES-LIMITED-KATSINA-STATE-CIVIL-SERVICE-PROMOTION-EXAMINATION-ONLINE-REGISTRATION-FORM-2025-MBASEScreenshot-2026-01-17-092022-jpgWhatsApp-Image-2026-01-11-at-16-38-696e75870bd32.pdf', '2026-01-19 16:39:31', '2026-01-19 18:32:33', 1, 1),
(3, 'SUB-35E8FC', 50000.00, 'CANCELLED', NULL, '2026-01-19 18:42:11', NULL, 1, 1),
(4, 'SUB-94E3CB', 50000.00, 'CANCELLED', NULL, '2026-01-19 18:44:57', NULL, 1, 1),
(5, 'SUB-D302E8', 50000.00, 'CANCELLED', NULL, '2026-01-19 20:38:37', NULL, 1, 1),
(6, 'SUB-D0846C', 120000.00, 'APPROVED', 'JIS-3A-696e98a4a4396.pdf', '2026-01-19 20:48:13', '2026-01-19 20:49:05', 1, 2),
(7, 'SUB-B777B2', 50000.00, 'PENDING', NULL, '2026-01-19 21:50:19', NULL, 12, 1);

-- --------------------------------------------------------

--
-- Table structure for table `support_ticket`
--

CREATE TABLE `support_ticket` (
  `id` int NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` longtext NOT NULL,
  `status` varchar(20) NOT NULL,
  `created_at` datetime NOT NULL,
  `school_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE `user` (
  `id` int NOT NULL,
  `email` varchar(180) NOT NULL,
  `roles` json NOT NULL,
  `password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `user`
--

INSERT INTO `user` (`id`, `email`, `roles`, `password`) VALUES
(1, 'admin@gmail.com', '[\"ROLE_SUPER_ADMIN\"]', '$2y$13$zEi0Du1FXmOW6ynFviiR/OKPEfeQKGJwivi5OziJQFHR4quMO7Zmu');

-- --------------------------------------------------------

--
-- Table structure for table `wallet_transaction`
--

CREATE TABLE `wallet_transaction` (
  `id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `type` varchar(10) NOT NULL,
  `description` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  `school_id` int NOT NULL,
  `balance_after` decimal(12,2) NOT NULL,
  `reference` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `wallet_transaction`
--

INSERT INTO `wallet_transaction` (`id`, `amount`, `type`, `description`, `created_at`, `school_id`, `balance_after`, `reference`) VALUES
(1, 1000.00, 'CREDIT', 'Top-up: CR-6973F3A80169C', '2026-01-23 22:29:44', 13, 1000.00, 'CR-6973F3A80169C'),
(2, 5000.00, 'CREDIT', 'Top-up: CR-6973F672BF76A', '2026-01-23 22:31:18', 13, 6000.00, 'CR-6973F672BF76A'),
(3, 1000.00, 'CREDIT', 'Top-up: CR-6973F7CA096E7', '2026-01-23 22:36:35', 13, 7000.00, 'CR-6973F7CA096E7'),
(4, 3.50, 'DEBIT', 'SMS Notification', '2026-02-04 22:45:55', 1, 2996.50, NULL),
(5, 3.50, 'DEBIT', 'SMS Notification', '2026-02-04 22:53:25', 1, 2993.00, NULL),
(6, 3.50, 'DEBIT', 'SMS Notification', '2026-02-04 22:56:13', 1, 2989.50, NULL),
(7, 3.50, 'DEBIT', 'SMS Notification', '2026-02-04 22:56:21', 1, 2986.00, NULL),
(8, 3.50, 'DEBIT', 'SMS Notification', '2026-02-04 23:17:43', 16, 4996.50, NULL),
(9, 3.50, 'DEBIT', 'SMS Notification', '2026-02-04 23:19:55', 16, 4993.00, NULL),
(10, 3.50, 'DEBIT', 'SMS Notification', '2026-02-04 23:20:06', 16, 4989.50, NULL),
(11, 3.50, 'DEBIT', 'SMS Notification', '2026-02-05 11:32:53', 16, 4986.00, NULL),
(12, 3.50, 'DEBIT', 'SMS Notification', '2026-02-05 11:35:32', 16, 4982.50, NULL),
(13, 3.50, 'DEBIT', 'SMS Notification', '2026-02-11 20:13:30', 1, 2982.50, NULL),
(14, 3.50, 'DEBIT', 'SMS Notification', '2026-02-21 23:45:31', 1, 2979.00, NULL),
(15, 3.50, 'DEBIT', 'SMS Notification', '2026-02-21 23:47:45', 1, 2975.50, NULL),
(16, 3.50, 'DEBIT', 'SMS Notification', '2026-02-21 23:50:48', 1, 2972.00, NULL),
(17, 3.50, 'DEBIT', 'SMS Notification', '2026-02-21 23:52:00', 1, 2968.50, NULL),
(18, 15.00, 'DEBIT', 'SMS Notification to 2348031819670', '2026-02-22 01:19:10', 1, 2934.50, 'SMS-3017717231499317243814208'),
(19, 15.00, 'DEBIT', 'SMS to 2348031819670', '2026-02-22 01:29:36', 1, 2919.50, 'SMS-3017717237755582133057650'),
(20, 15.00, 'DEBIT', 'SMS to 2348031819670', '2026-02-22 01:32:07', 1, 2904.50, 'SMS-3017717239270643860098195'),
(21, 15.00, 'DEBIT', 'SMS to 2348031819670', '2026-02-22 01:36:56', 1, 2889.50, 'SMS-3017717242160379578319402'),
(22, 15.00, 'DEBIT', 'SMS to 2348031819670', '2026-02-22 01:37:44', 1, 2874.50, 'SMS-3017717242637355764502752'),
(23, 15.00, 'DEBIT', 'SMS to 2348031819670', '2026-02-22 01:42:20', 16, 185.00, 'SMS-3017717245401268555411567'),
(24, 15.00, 'DEBIT', 'SMS to 2348031819670', '2026-02-22 01:46:36', 16, 170.00, 'SMS-3017717247955551606162105'),
(25, 12.00, 'DEBIT', 'SMS to 2348031819670', '2026-02-22 02:38:56', 16, 158.00, 'SMS-3017717279358584448121119'),
(26, 12.00, 'DEBIT', 'SMS to 2348031819670', '2026-02-22 02:48:28', 13, 6988.00, 'SMS-3017717285085779731951692');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `agent`
--
ALTER TABLE `agent`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `UNIQ_268B9C9DE7927C74` (`email`);

--
-- Indexes for table `credit_request`
--
ALTER TABLE `credit_request`
  ADD PRIMARY KEY (`id`),
  ADD KEY `IDX_113E8B0C32A47EE` (`school_id`);

--
-- Indexes for table `global_settings`
--
ALTER TABLE `global_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `UNIQ_3223F6EB5FA1E697` (`setting_key`);

--
-- Indexes for table `messenger_messages`
--
ALTER TABLE `messenger_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `IDX_75EA56E0FB7336F0` (`queue_name`),
  ADD KEY `IDX_75EA56E0E3BD61CE` (`available_at`),
  ADD KEY `IDX_75EA56E016BA31DB` (`delivered_at`);

--
-- Indexes for table `plan`
--
ALTER TABLE `plan`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `school`
--
ALTER TABLE `school`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `UNIQ_F99EDABBC1D5962E` (`subdomain`),
  ADD KEY `IDX_F99EDABB3414710B` (`agent_id`);

--
-- Indexes for table `subscription`
--
ALTER TABLE `subscription`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `UNIQ_A3C664D3C32A47EE` (`school_id`),
  ADD KEY `IDX_A3C664D3E899029B` (`plan_id`);

--
-- Indexes for table `subscription_payment`
--
ALTER TABLE `subscription_payment`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `UNIQ_1E3D6496AEA34913` (`reference`),
  ADD KEY `IDX_1E3D6496C32A47EE` (`school_id`),
  ADD KEY `IDX_1E3D6496E899029B` (`plan_id`);

--
-- Indexes for table `support_ticket`
--
ALTER TABLE `support_ticket`
  ADD PRIMARY KEY (`id`),
  ADD KEY `IDX_1F5A4D53C32A47EE` (`school_id`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `UNIQ_8D93D649E7927C74` (`email`);

--
-- Indexes for table `wallet_transaction`
--
ALTER TABLE `wallet_transaction`
  ADD PRIMARY KEY (`id`),
  ADD KEY `IDX_7DAF972C32A47EE` (`school_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `agent`
--
ALTER TABLE `agent`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `credit_request`
--
ALTER TABLE `credit_request`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `global_settings`
--
ALTER TABLE `global_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `messenger_messages`
--
ALTER TABLE `messenger_messages`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `plan`
--
ALTER TABLE `plan`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `school`
--
ALTER TABLE `school`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `subscription`
--
ALTER TABLE `subscription`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `subscription_payment`
--
ALTER TABLE `subscription_payment`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `support_ticket`
--
ALTER TABLE `support_ticket`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user`
--
ALTER TABLE `user`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `wallet_transaction`
--
ALTER TABLE `wallet_transaction`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `credit_request`
--
ALTER TABLE `credit_request`
  ADD CONSTRAINT `FK_113E8B0C32A47EE` FOREIGN KEY (`school_id`) REFERENCES `school` (`id`);

--
-- Constraints for table `school`
--
ALTER TABLE `school`
  ADD CONSTRAINT `FK_F99EDABB3414710B` FOREIGN KEY (`agent_id`) REFERENCES `agent` (`id`);

--
-- Constraints for table `subscription`
--
ALTER TABLE `subscription`
  ADD CONSTRAINT `FK_A3C664D3C32A47EE` FOREIGN KEY (`school_id`) REFERENCES `school` (`id`),
  ADD CONSTRAINT `FK_A3C664D3E899029B` FOREIGN KEY (`plan_id`) REFERENCES `plan` (`id`);

--
-- Constraints for table `subscription_payment`
--
ALTER TABLE `subscription_payment`
  ADD CONSTRAINT `FK_1E3D6496C32A47EE` FOREIGN KEY (`school_id`) REFERENCES `school` (`id`),
  ADD CONSTRAINT `FK_1E3D6496E899029B` FOREIGN KEY (`plan_id`) REFERENCES `plan` (`id`);

--
-- Constraints for table `support_ticket`
--
ALTER TABLE `support_ticket`
  ADD CONSTRAINT `FK_1F5A4D53C32A47EE` FOREIGN KEY (`school_id`) REFERENCES `school` (`id`);

--
-- Constraints for table `wallet_transaction`
--
ALTER TABLE `wallet_transaction`
  ADD CONSTRAINT `FK_7DAF972C32A47EE` FOREIGN KEY (`school_id`) REFERENCES `school` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
