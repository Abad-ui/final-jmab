-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 10, 2025 at 11:21 AM
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
(8, 'Super Lube', 'Strongest Lube', 'Lubricants', NULL, 3210.00, 100, 'https://m.media-amazon.com/images/I/61yFewKFB+L._SX522_.jpg', 'Synthetic', '', 0, '[]', '2025-03-03 05:11:55', '2025-03-03 05:11:55'),
(9, 'Delco', '', 'Batteries', NULL, 2031.00, 50, 'https://m.media-amazon.com/images/I/71QuCK6A4QL._AC_SX466_.jpg', 'Delco', '', 50, '[]', '2025-03-03 05:13:19', '2025-03-03 05:13:19'),
(11, 'Hosea', 'Nigger', 'Lubricants', NULL, 9888.00, 100, 'https://motulindia.com/blog/wp-content/uploads/2024/07/Automotive-Lubricant-Oil-2.jpg', 'Valvoline', '', 0, '[]', '2025-03-05 08:19:23', '2025-03-05 08:19:23'),
(12, 'BlueEarth Van', 'This is a BlueEarth Van tire.\n', 'Tires', NULL, 5705.00, 10, 'https://i.pinimg.com/736x/f0/4d/d7/f04dd747bbd1910d3f18751829d20eac.jpg', 'Yokohama', '14', 0, '[]', '2025-03-09 12:45:35', '2025-03-09 12:45:35'),
(13, 'Pilot Sport', 'This is a Pilot Sport tire.', 'Tires', NULL, 3380.00, 15, 'https://www.tirestickers.com/wp-content/uploads/2019/01/Michelin-side-PS4S-tirestickers.jpg', 'Michelin', '14', 0, '[]', '2025-03-09 12:46:23', '2025-03-09 12:46:23'),
(14, 'Bridgestone', 'This is a Bridgestone tire.', 'Tires', NULL, 5400.00, 8, 'https://i.ebayimg.com/images/g/41EAAOSwh3RghsI~/s-l1200.jpg', 'Bridgestone', '14', 0, '[]', '2025-03-09 12:47:30', '2025-03-09 12:47:30'),
(15, ' All-Terrain', 'Thisi s a BF Goodrich tire.', 'Tires', NULL, 5140.00, 15, 'https://aecbmesvcm.cloudimg.io/v7/https://cxf-prod.azureedge.net/b2c-experience-bfg-production/attachments/ck344dplw0nxz0jnozasbori6-bfgoodrich-all-terrain-t-a-sup-ko2-sup-home-background-md.png?w=412&h=412&org_if_sml=1&func=boundmin', 'BFGoodrich', '14', 0, '[]', '2025-03-09 12:48:51', '2025-03-09 12:48:51'),
(16, 'Goodyear', 'This is a Goodyear tire.', 'Tires', NULL, 76420.00, 2, 'https://mma.prnewswire.com/media/2047095/goodyear_400_tire_20230404.jpg', 'Goodyear', '14', 0, '[]', '2025-03-09 12:50:15', '2025-03-09 12:50:15'),
(17, 'Defender', 'This is a Michelin tire.', 'Tires', NULL, 1424.00, 14, 'https://images-cdn.ubuy.ae/64c3e35cc2eddd1359706794-michelin-defender-ltx-m-s-all-season.jpg', 'Michelin', '14', 0, '[]', '2025-03-09 12:51:10', '2025-03-09 12:51:10'),
(18, 'Atrezzo', 'This is a Sailun tire.', 'Tires', NULL, 2700.00, 10, 'https://s19532.pcdn.co/wp-content/uploads/2024/03/Atrezzo-Tcon-45%C2%B0-1_RFS.jpg', 'Sailun', '14', 0, '[]', '2025-03-09 12:52:21', '2025-03-09 12:52:21'),
(19, 'Radial', 'This is a Gajah Tunggal tire.', 'Tires', NULL, 3230.00, 10, 'https://gtradial.ph/wp-content/uploads/2018/06/GT879-30-300x300.jpg', 'Gajah Tunggal', '14', 0, '[]', '2025-03-09 12:53:11', '2025-03-09 12:53:11'),
(20, 'Power Plus', 'This is a Leisure & Marine battery.', 'Batteries', NULL, 1091.00, 5, 'https://advancedbatterysupplies.co.uk/wp-content/uploads/2015/02/lp75-leisure-battery-75ah-image.jpg', 'Leisure & Marine', '', 110, '[]', '2025-03-09 12:55:10', '2025-03-09 12:55:10'),
(21, 'Mercury', 'This is a Dyna Power battery.', 'Batteries', NULL, 3960.00, 8, 'https://ph-test-11.slatic.net/p/80462a91f011476443ca1cea4ef2da6e.jpg', 'Dyna Power', '', 110, '[]', '2025-03-09 12:56:26', '2025-03-09 12:56:26'),
(22, 'BR400', 'This is a Petron Blaze oil.', 'Oils', NULL, 860.00, 20, 'https://www.petron.com/wp-content/uploads/2020/10/Blaze-Racing-BR450-Premium-Multi-Grade-20W-50.jpg', 'Petron', '', 0, '[]', '2025-03-09 12:57:23', '2025-03-09 12:57:23'),
(23, 'BR800', 'This is a Petron Blaze oil.', 'Oils', NULL, 850.00, 20, 'https://www.petron.com/wp-content/uploads/2018/10/Blaze-Racing-BR630-Synthetic-Blend-5W-30.jpg', 'Petron', '', 0, '[]', '2025-03-09 12:58:22', '2025-03-09 12:58:22'),
(24, 'Repsol', 'This is a Repsol car lubricant.', 'Lubricants', NULL, 800.00, 15, 'https://lubricants.repsol.com/content/dam/aplicaciones/lubricante-moto/img-folder/responsive_movil.png', 'Repsol', '', 0, '[]', '2025-03-09 12:59:14', '2025-03-09 12:59:14'),
(25, 'Master', 'This is a Repsol car lubricant.', 'Lubricants', NULL, 820.00, 20, 'https://lubricants.repsol.com/content/dam/repsol-lubricantes/es/productos-y-servicios/lubricantes-imagenes/master-racing-0w-40.jpg', 'Repsol', '', 0, '[]', '2025-03-09 12:59:45', '2025-03-09 12:59:45');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
