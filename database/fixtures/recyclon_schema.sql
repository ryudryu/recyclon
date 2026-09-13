/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `arrival_queue` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `assigned_lorry_id` int(11) DEFAULT NULL,
  `driver_name` varchar(255) NOT NULL,
  `plate_number` varchar(64) DEFAULT NULL,
  `geolocation` varchar(255) NOT NULL,
  `status` enum('pending','ongoing','completed','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_arrival_queue_assigned_lorry` (`assigned_lorry_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking` (
  `booking_id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) DEFAULT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `lorry_id` int(11) DEFAULT NULL,
  `address` varchar(255) NOT NULL,
  `note` text DEFAULT NULL,
  `pickup_date` date DEFAULT NULL,
  `booking_date` date NOT NULL,
  `scheduled_date` datetime DEFAULT NULL,
  `status` enum('Pending','Scheduled','Completed','Cancelled') DEFAULT 'Pending',
  `booking_type` enum('pickup','walkin') NOT NULL DEFAULT 'pickup',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`booking_id`),
  KEY `customer_id` (`customer_id`),
  KEY `staff_id` (`staff_id`),
  KEY `lorry_id` (`lorry_id`),
  KEY `fk_booking_driver` (`driver_id`),
  CONSTRAINT `booking_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `booking_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `booking_ibfk_3` FOREIGN KEY (`lorry_id`) REFERENCES `lorries` (`lorry_id`),
  CONSTRAINT `fk_booking_driver` FOREIGN KEY (`driver_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gps_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `lorry_id` int(11) NOT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `recorded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `lorry_id` (`lorry_id`),
  KEY `recorded_at` (`recorded_at`),
  CONSTRAINT `gps_log_ibfk_1` FOREIGN KEY (`lorry_id`) REFERENCES `lorries` (`lorry_id`) ON DELETE CASCADE
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lorries` (
  `lorry_id` int(11) NOT NULL AUTO_INCREMENT,
  `plate_number` varchar(20) NOT NULL,
  `driver_id` int(11) DEFAULT NULL,
  `status` enum('Available','On Duty','Maintenance') DEFAULT 'Available',
  `current_lat` decimal(10,7) DEFAULT NULL,
  `current_long` decimal(10,7) DEFAULT NULL,
  `last_updated` datetime DEFAULT NULL,
  `destination_lat` double DEFAULT NULL,
  `destination_long` double DEFAULT NULL,
  `destination_set_at` datetime DEFAULT NULL,
  PRIMARY KEY (`lorry_id`),
  UNIQUE KEY `plate_number` (`plate_number`),
  KEY `driver_id` (`driver_id`),
  CONSTRAINT `lorries_ibfk_1` FOREIGN KEY (`driver_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `price_history` (
  `history_id` int(11) NOT NULL AUTO_INCREMENT,
  `waste_id` int(11) DEFAULT NULL,
  `old_price` decimal(10,2) DEFAULT NULL,
  `new_price` decimal(10,2) DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `fk_pricehistory_waste` (`waste_id`),
  KEY `fk_pricehistory_user` (`changed_by`),
  CONSTRAINT `fk_pricehistory_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_pricehistory_waste` FOREIGN KEY (`waste_id`) REFERENCES `waste_categories` (`waste_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sale_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) DEFAULT NULL,
  `waste_id` int(11) DEFAULT NULL,
  `weight_kg` decimal(10,2) DEFAULT NULL,
  `price_per_kg` decimal(10,2) DEFAULT NULL,
  `subtotal` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`item_id`),
  KEY `sale_id` (`sale_id`),
  KEY `waste_id` (`waste_id`),
  CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`),
  CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`waste_id`) REFERENCES `waste_categories` (`waste_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales` (
  `sale_id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) DEFAULT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `lorry_id` int(11) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `sale_date` datetime DEFAULT current_timestamp(),
  `payment_status` enum('Paid','Pending','Cancelled') DEFAULT 'Pending',
  PRIMARY KEY (`sale_id`),
  KEY `customer_id` (`customer_id`),
  KEY `staff_id` (`staff_id`),
  KEY `booking_id` (`booking_id`),
  KEY `lorry_id` (`lorry_id`),
  CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `sales_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `sales_ibfk_3` FOREIGN KEY (`booking_id`) REFERENCES `booking` (`booking_id`),
  CONSTRAINT `sales_ibfk_4` FOREIGN KEY (`lorry_id`) REFERENCES `lorries` (`lorry_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `ic_number` varchar(20) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account_number` varchar(50) DEFAULT NULL,
  `role` enum('Admin','Staff','Driver','Customer') DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `google_id` varchar(255) DEFAULT NULL,
  `auto_accept_bookings` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `waste_categories` (
  `waste_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(50) NOT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `unit` varchar(10) NOT NULL DEFAULT 'kg',
  `image_icon` varchar(255) DEFAULT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  PRIMARY KEY (`waste_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


