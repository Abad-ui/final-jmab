-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 20, 2025 at 12:39 PM
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
-- Database: `business-jmab`
--

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE `cart` (
  `cart_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL CHECK (`quantity` > 0),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cart`
--

INSERT INTO `cart` (`cart_id`, `user_id`, `product_id`, `quantity`, `updated_at`, `created_at`) VALUES
(2, 2, 4, 3, '2025-02-01 08:20:36', '2025-01-31 16:27:57'),
(3, 6, 2, 5, '2025-02-01 02:43:16', '2025-02-01 02:34:20'),
(4, 6, 4, 5, '2025-02-01 02:47:22', '2025-02-01 02:35:00'),
(5, 2, 7, 2, '2025-02-01 08:20:43', '2025-02-01 06:19:47'),
(6, 7, 2, 1, '2025-02-01 06:21:32', '2025-02-01 06:21:32'),
(7, 7, 6, 2, '2025-02-01 06:21:45', '2025-02-01 06:21:37'),
(8, 2, 5, 2, '2025-02-01 08:20:38', '2025-02-01 08:20:05'),
(9, 2, 6, 1, '2025-02-01 08:20:40', '2025-02-01 08:20:40'),
(10, 2, 8, 1, '2025-02-01 08:20:45', '2025-02-01 08:20:45'),
(11, 2, 9, 3, '2025-02-03 16:05:25', '2025-02-01 08:20:47'),
(12, 8, 2, 3, '2025-02-01 08:44:03', '2025-02-01 08:32:57'),
(13, 8, 4, 1, '2025-02-01 08:40:36', '2025-02-01 08:40:36'),
(14, 9, 2, 5, '2025-02-01 08:50:43', '2025-02-01 08:50:41'),
(15, 6, 8, 1, '2025-02-04 06:58:46', '2025-02-04 06:58:46'),
(16, 6, 9, 1, '2025-02-04 06:58:55', '2025-02-04 06:58:55'),
(17, 6, 6, 1, '2025-02-05 01:43:13', '2025-02-05 01:43:13'),
(18, 6, 7, 1, '2025-02-06 03:41:41', '2025-02-06 03:41:41'),
(19, 11, 2, 3, '2025-02-08 09:07:27', '2025-02-08 09:05:38'),
(20, 14, 4, 3, '2025-02-12 02:07:28', '2025-02-12 02:07:28'),
(21, 14, 5, 3, '2025-02-13 00:08:38', '2025-02-13 00:08:38'),
(22, 15, 6, 50, '2025-02-13 06:09:40', '2025-02-13 06:09:03'),
(58, 17, 5, 3, '2025-02-20 04:36:19', '2025-02-20 04:36:14'),
(59, 17, 4, 1, '2025-02-20 04:52:37', '2025-02-20 04:52:20');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `product_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('Tires','Oils','Batteries','Lubricants') NOT NULL,
  `subcategory` varchar(255) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL,
  `image_url` varchar(255) NOT NULL,
  `brand` varchar(255) NOT NULL,
  `size` varchar(50) DEFAULT NULL,
  `voltage` int(11) DEFAULT NULL,
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `name`, `description`, `category`, `subcategory`, `price`, `stock`, `image_url`, `brand`, `size`, `voltage`, `tags`, `created_at`, `updated_at`) VALUES
(2, 'Gulong ng Mundo', 'Best Tires', 'Tires', NULL, 150.00, 5, 'https://cdn-ghpgf.nitrocdn.com/EHutnJVvBZWFriLRnTAnoPDYCJXQGwdM/assets/images/optimized/rev-ef0d35b/tireworks.net/wp-content/uploads/sites/2/2018/08/reading-your-tires.png', 'GoodYear', 'P215/65 R15', NULL, '[]', '2025-01-28 06:58:28', '2025-01-28 06:58:28'),
(4, 'Baby Oil ni Diddy', 'Pinaka-dabest na oil', 'Oils', NULL, 150.00, 5, 'https://eneos.com.ph/wp-content/uploads/2023/05/0W-20.png', 'Eneos Oil', 'P215/65 R15', NULL, '[]', '2025-01-28 07:10:44', '2025-01-28 07:10:44'),
(5, 'Panther', 'The best battery in town', 'Batteries', NULL, 50.00, 50, 'https://s.alicdn.com/@sc04/kf/H9a94bfc2748447c6b847172ce27387de3.jpg_720x720q50.jpg', 'Royu', NULL, 50, '[]', '2025-02-01 05:45:45', '2025-02-01 05:45:45'),
(6, 'TripleA', 'The best battery in town', 'Batteries', NULL, 50.00, 50, 'https://upload.wikimedia.org/wikipedia/commons/thumb/e/ee/Photo-CarBattery.jpg/450px-Photo-CarBattery.jpg', 'Everydae', NULL, 1500, '[]', '2025-02-01 06:01:22', '2025-02-01 06:01:22'),
(7, 'Sulfur', 'Supper Slippery', 'Lubricants', NULL, 50.00, 50, 'https://s.alicdn.com/@sc04/kf/H5ce7b19aa14549b9928783e08bcc15bdc.jpg_720x720q50.jpg', 'Dove', NULL, 1500, '[]', '2025-02-01 06:05:30', '2025-02-01 08:39:56'),
(8, 'PureWhite', 'HAAHHAHHA', 'Lubricants', NULL, 50.00, 50, 'https://s.alicdn.com/@sc04/kf/H56cdd0f263a84b5fbb03db34dfb3c4c1S.jpg_720x720q50.jpg', 'Ballsack', NULL, NULL, '[]', '2025-02-01 06:06:48', '2025-02-01 08:41:11'),
(9, 'Wheel of fortune', 'The best wheels on the bus in town', 'Tires', NULL, 50.00, 50, 'https://s.alicdn.com/@sc04/kf/Hf914b7a01fa340f5a603d68b0392b8aeR.jpg_720x720q50.jpg', 'Yokohoma', 'P239/79 R18', NULL, '[]', '2025-02-01 06:15:05', '2025-02-01 06:15:05');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `roles` enum('customer','admin') NOT NULL DEFAULT 'customer',
  `address` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `first_name`, `last_name`, `email`, `password`, `roles`, `address`, `created_at`, `updated_at`) VALUES
(1, 'John', 'Doe', 'johndoe@gmail.com', '$2y$10$yRewlLGawna3tcEc8tBjue9V2d6tiz4KxjCkqNQsiZz8HLMjX8W2.', 'customer', NULL, '2025-01-28 06:00:39', '2025-01-28 06:00:39'),
(2, 'Marvin', 'Dagoat', 'marvindagoat@gmail.com', '$2y$10$Pj7gNBEMAt9AADdoJ82mWeeiVUbWCeoBeY8QTbNC9LyxJKP9795rq', 'customer', NULL, '2025-01-28 06:18:25', '2025-01-28 06:18:25'),
(3, 'Benny', 'Bun', 'bentot29@gmail.com', '$2y$10$Cv1q.d5bhmJeQnwZlCAsPON13XOpbGEquI8A14b4LjY5yUCdJHe8W', 'customer', NULL, '2025-01-29 09:55:50', '2025-01-29 09:55:50'),
(4, 'Felicia', 'Creator', 'feliciathegoat@gmail.com', '$2y$10$Z.GiTBB/zu3Zk00gfrzCd.YgVjFL5..ajS7JDdcL6dt.LJ024YXuS', 'customer', NULL, '2025-01-30 15:46:28', '2025-01-30 15:46:28'),
(5, 'Christian', 'Tenorio', 'tenorio@gmail.com', '$2y$10$1hM3Dq1PLPa4CKJxKgEaOeQfBNkKkGt.0oIp2KjikjhqaSF0qEelq', 'customer', NULL, '2025-01-31 03:01:36', '2025-01-31 03:01:36'),
(6, 'carl', 'petrola', 'petrola@gmail.com', '$2y$10$L8zxsGUOMfg8Yt9arHULWePUFgvHG4yFUEiv/2O0VbDYGrBU9MOTe', 'customer', NULL, '2025-02-01 02:33:41', '2025-02-01 02:33:41'),
(7, 'aaron', 'dagoat', 'aarondagoat@gmail.com', '$2y$10$8/KzxIipricL3JWrshnozeHwv.zkTRs0UFtVyui2oL3K5tiNqSoN6', 'customer', NULL, '2025-02-01 06:21:08', '2025-02-01 06:21:08'),
(8, 'da', 'baby', 'dababy@gmail.com', '$2y$10$2m8DSXNpmbgF1krOwxyPeOVUGYKnnKtNsfU64sOIa0kFbg1W/OZw.', 'customer', NULL, '2025-02-01 08:22:13', '2025-02-01 08:22:13'),
(9, 'ben', 'ten', 'benten@gmail.com', '$2y$10$b3ZF01aIseOV7TWbPUYdAeYwYkP7K1aqQuC.if2Af5LLIqm.w1n6K', 'customer', NULL, '2025-02-01 08:46:03', '2025-02-01 08:46:03'),
(10, 'Tony', 'Stark', 'ironman@gmail.com', '$2y$10$Np1I7yJ3yabMF6yjGztWBeXf4yxlvhTG0h77Fq4CUMi1BGpZMyi5e', 'customer', NULL, '2025-02-06 12:02:41', '2025-02-06 12:02:41'),
(11, 'Randelll', 'Ty', 'randell27ty@gmail.com', '$2y$10$z2oB6ZOkeA13YyjhnJuSl.cAqCcrpnJsR2YB6USu7SHPZ9iglPxhm', 'customer', NULL, '2025-02-08 09:02:02', '2025-02-08 09:02:02'),
(12, 'New', 'User', 'newUser@gmail.com', '$2y$10$4Zn2kIBY90AG2H3Jifx2y.rPyZjp/rxG02YDfIMeRcuQceCox7mAu', 'customer', NULL, '2025-02-09 17:25:48', '2025-02-09 17:25:48'),
(13, 'name', 'hehehe', 'nenengb@wahahah.com', '$2y$10$kCQj1K7wjQkXzD18wTaeGeJ8g.mF/mmB35.hIfQV9wJYqmyxj2O2i', 'customer', NULL, '2025-02-10 14:33:03', '2025-02-10 14:33:03'),
(14, 'Kaldager', 'Ist', 'kaldagerist@gmail.com', '$2y$10$hcZf3zae/JKMae.oWEBXx.t7PGKpTP59ImXkRcdUBbw5d6YaG0n6K', 'customer', NULL, '2025-02-12 02:07:05', '2025-02-12 02:07:05'),
(15, 'Randell', 'Ty', 'randell@gmail.com', '$2y$10$djRbnIBUXCLqM1M2Ne6VduPppUCbmptodkHch9k9b.veMorEo10n2', 'customer', NULL, '2025-02-13 06:06:10', '2025-02-13 06:06:10'),
(16, 'afsdasdf', 'aasdf', 'random@example.com', '$2y$10$dxliZ9qCKVVI0n4XsjbiCOMeeqvmJsz61h/VvDkfEdU98chH8qzxW', 'customer', NULL, '2025-02-14 03:33:45', '2025-02-14 03:33:45'),
(17, 'hehe', 'haha', 'sample@example.com', '$2y$10$yDOZa7I9g20aVK7hTeSX5OTQIKu0GSKDnp/mpKsdcDLMSuOJUZtIu', 'customer', NULL, '2025-02-14 03:44:54', '2025-02-14 03:44:54'),
(18, 'Aaron', 'Abad', 'profakerblah@gmail.com', '$2y$10$6ft4FxYZsBkMxjG.WV25uO7o2VtcbKc2Z.YmIloFwWovEsFPvYUGm', 'customer', NULL, '2025-02-20 04:33:16', '2025-02-20 04:33:16'),
(19, 'fran', 'cis', 'francis@gmail.com', '$2y$10$lMqq6tRjPgMpHvPPwlqJ1uySYH8Ab8hfy3ajUzNsWQkTMlGtPboIe', 'customer', NULL, '2025-02-20 04:37:41', '2025-02-20 04:37:41');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`cart_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `cart_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cart`
--
ALTER TABLE `cart`
  ADD CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
