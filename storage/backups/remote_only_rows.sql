-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 8x479m.h.filess.io    Database: maintenance_db_worenever
-- ------------------------------------------------------
-- Server version	5.7.38-41-log

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `emergency_request_updates`
--

LOCK TABLES `emergency_request_updates` WRITE;
/*!40000 ALTER TABLE `emergency_request_updates` DISABLE KEYS */;
INSERT  IGNORE INTO `emergency_request_updates` VALUES (36,18,'created','Emergency service request submitted','Customer','2026-08-29 10:26:53'),(37,19,'created','Emergency service request submitted','Customer','2026-08-29 10:50:26'),(38,20,'created','Emergency service request submitted','Customer','2026-08-29 10:54:02'),(39,21,'created','Emergency service request submitted','Customer','2026-08-29 10:55:30'),(40,20,'declined','Request declined by admin','Admin','2026-08-29 10:55:40'),(41,22,'created','Emergency service request submitted','Customer','2026-08-29 10:59:10'),(42,22,'assigned','Mechanic assigned by admin','Admin','2026-09-09 02:57:31'),(43,22,'assigned','Mechanic assigned by admin','Admin','2026-09-09 02:57:59'),(44,22,'assigned','Mechanic assigned by admin','Admin','2026-09-09 03:01:19'),(45,22,'assigned','Mechanic assigned by admin','Admin','2026-09-09 03:04:27'),(46,22,'assigned','Mechanic assigned by admin','Admin','2026-09-09 03:04:37'),(47,22,'accepted','Request accepted by admin','Admin','2026-09-09 03:04:43'),(48,23,'created','Emergency service request submitted','Customer','2026-09-19 02:06:53'),(49,23,'assigned','Mechanic assigned by admin','Admin','2026-09-19 02:07:10'),(50,21,'assigned','Mechanic assigned by admin','Admin','2026-09-19 03:09:13'),(51,19,'assigned','Mechanic assigned by admin','Admin','2026-09-19 03:09:24'),(52,18,'assigned','Mechanic assigned by admin','Admin','2026-09-19 03:12:50'),(53,18,'assigned','Mechanic assigned by admin','Admin','2026-09-19 03:13:00'),(54,18,'assigned','Mechanic assigned by admin','Admin','2026-09-19 03:24:23'),(55,24,'created','Emergency service request submitted','Customer','2026-09-21 08:58:11'),(56,24,'assigned','Mechanic assigned by admin','Admin','2026-09-21 09:05:04'),(57,24,'assigned','Mechanic assigned by admin','Admin','2026-09-21 09:05:09'),(58,24,'accepted','Request accepted by admin','Admin','2026-09-21 09:05:25'),(59,25,'created','Emergency service request submitted','Customer','2026-09-21 11:30:01'),(60,25,'assigned','Mechanic assigned by admin','Admin','2026-09-21 11:32:02'),(61,25,'accepted','Request accepted by admin','Admin','2026-09-21 11:32:12');
/*!40000 ALTER TABLE `emergency_request_updates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `mechanic_specialties`
--

LOCK TABLES `mechanic_specialties` WRITE;
/*!40000 ALTER TABLE `mechanic_specialties` DISABLE KEYS */;
INSERT  IGNORE INTO `mechanic_specialties` VALUES (95,15),(100,15),(102,15),(105,15),(94,16),(99,16),(101,16),(104,16),(123,16),(93,17),(98,17),(101,17),(104,17),(123,17),(92,18),(96,18),(99,18),(102,18),(93,19),(94,19),(101,19),(106,19),(92,20),(95,20),(97,20),(102,20),(105,20),(106,20),(97,21),(100,21),(103,21),(96,22),(98,22),(103,22);
/*!40000 ALTER TABLE `mechanic_specialties` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-21 22:45:39
