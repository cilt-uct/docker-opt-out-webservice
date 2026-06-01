CREATE DATABASE  IF NOT EXISTS `timetable` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;
USE `timetable`;
-- MySQL dump 10.13  Distrib 5.7.17, for macos10.12 (x86_64)
--
-- Host: srvubuclt001.uct.ac.za    Database: timetable
-- ------------------------------------------------------
-- Server version	5.7.21

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `course_optout`
--

DROP TABLE IF EXISTS `course_optout`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `course_optout` (
  `course_code` varchar(20) NOT NULL,
  `year` smallint(4) NOT NULL DEFAULT '2018',
  `dept` varchar(3) NOT NULL,
  `is_optout` tinyint(1) NOT NULL DEFAULT '0',
  `updated_by` varchar(36) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `oc_series` varchar(36) DEFAULT NULL,
  `vula_site_id` varchar(36) DEFAULT NULL,
  `is_timetabled` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`course_code`,`year`),
  KEY `course_year_idx` (`course_code`,`year`),
  KEY `fk_course_optout_uct_dept_idx` (`dept`),
  CONSTRAINT `fk_course_optout_uct_dept` FOREIGN KEY (`dept`) REFERENCES `uct_dept` (`dept`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8 */ ;
/*!50003 SET character_set_results = utf8 */ ;
/*!50003 SET collation_connection  = utf8_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ALLOW_INVALID_DATES,ERROR_FOR_DIVISION_BY_ZERO,TRADITIONAL,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`bowser`@`%`*/ /*!50003 TRIGGER `timetable`.`course_optout_BEFORE_INSERT` BEFORE INSERT ON `course_optout` FOR EACH ROW
BEGIN
SET new.updated_at := now();
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8 */ ;
/*!50003 SET character_set_results = utf8 */ ;
/*!50003 SET collation_connection  = utf8_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ALLOW_INVALID_DATES,ERROR_FOR_DIVISION_BY_ZERO,TRADITIONAL,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`bowser`@`%`*/ /*!50003 TRIGGER `timetable`.`course_optout_BEFORE_UPDATE` BEFORE UPDATE ON `course_optout` FOR EACH ROW
BEGIN
SET new.updated_at := now();
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `course_updates`
--

DROP TABLE IF EXISTS `course_updates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `course_updates` (
  `course_code` varchar(20) NOT NULL,
  `year` smallint(4) NOT NULL DEFAULT '2018',
  `convenor_name` varchar(255) DEFAULT NULL,
  `convenor_emplid` varchar(36) DEFAULT NULL,
  `convenor_eid` varchar(36) DEFAULT NULL,
  `updated_by` varchar(36) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Override for ps_courses',
  PRIMARY KEY (`course_code`,`year`),
  KEY `course_year_idx` (`course_code`,`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `dept_optout`
--

DROP TABLE IF EXISTS `dept_optout`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dept_optout` (
  `dept` varchar(3) NOT NULL,
  `is_optout` tinyint(1) DEFAULT '0',
  `updated_by` varchar(36) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `year` mediumint(4) NOT NULL DEFAULT '2018',
  UNIQUE KEY `dept_year` (`dept`,`year`),
  KEY `fk_dept_optout_uct_dept1_idx` (`dept`),
  CONSTRAINT `fk_dept_optout_uct_dept1` FOREIGN KEY (`dept`) REFERENCES `uct_dept` (`dept`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8 */ ;
/*!50003 SET character_set_results = utf8 */ ;
/*!50003 SET collation_connection  = utf8_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ALLOW_INVALID_DATES,ERROR_FOR_DIVISION_BY_ZERO,TRADITIONAL,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`bowser`@`%`*/ /*!50003 TRIGGER `timetable`.`dept_optout_BEFORE_INSERT` BEFORE INSERT ON `dept_optout` FOR EACH ROW
BEGIN
SET new.updated_at := now();
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8 */ ;
/*!50003 SET character_set_results = utf8 */ ;
/*!50003 SET collation_connection  = utf8_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ALLOW_INVALID_DATES,ERROR_FOR_DIVISION_BY_ZERO,TRADITIONAL,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`bowser`@`%`*/ /*!50003 TRIGGER `timetable`.`dept_optout_BEFORE_UPDATE` BEFORE UPDATE ON `dept_optout` FOR EACH ROW
BEGIN
 SET new.updated_at := now();
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `opencast_venues`
--

DROP TABLE IF EXISTS `opencast_venues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `opencast_venues` (
  `ca_name` varchar(20) NOT NULL,
  `sn_venue` varchar(20) DEFAULT NULL,
  `venue_name` varchar(100) DEFAULT NULL,
  `campus_code` varchar(15) DEFAULT NULL,
  PRIMARY KEY (`ca_name`),
  UNIQUE KEY `sn_venue` (`sn_venue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ps_courses`
--

DROP TABLE IF EXISTS `ps_courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ps_courses` (
  `course_code` varchar(20) NOT NULL,
  `acad_career` varchar(20) DEFAULT NULL,
  `term` smallint(4) NOT NULL DEFAULT '2018',
  `title` varchar(255) DEFAULT NULL,
  `dept` varchar(20) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `convenor_name` varchar(255) DEFAULT NULL,
  `convenor_emplid` varchar(36) DEFAULT NULL,
  `convenor_eid` varchar(36) DEFAULT NULL,
  `secret` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`course_code`,`term`),
  KEY `fk_ps_courses_course_updates1_idx` (`course_code`,`term`),
  CONSTRAINT `fk_ps_courses_course_optout` FOREIGN KEY (`course_code`, `term`) REFERENCES `course_optout` (`course_code`, `year`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `fk_ps_courses_course_updates` FOREIGN KEY (`course_code`, `term`) REFERENCES `course_updates` (`course_code`, `year`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8 */ ;
/*!50003 SET character_set_results = utf8 */ ;
/*!50003 SET collation_connection  = utf8_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ALLOW_INVALID_DATES,ERROR_FOR_DIVISION_BY_ZERO,TRADITIONAL,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`bowser`@`%`*/ /*!50003 TRIGGER `timetable`.`ps_courses_BEFORE_INSERT` BEFORE INSERT ON `ps_courses` FOR EACH ROW
BEGIN
SET new.secret := sha2(CONCAT(LOWER(NOW()), new.course_code, 'lecture recording opt out'), 256);
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `sn_timetable_versioned`
--

DROP TABLE IF EXISTS `sn_timetable_versioned`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sn_timetable_versioned` (
  `id` mediumint(6) unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` varchar(32) NOT NULL,
  `activity_inc` tinyint(3) unsigned DEFAULT '1',
  `course_code` varchar(20) NOT NULL,
  `class_section` varchar(10) NOT NULL,
  `acad_career` varchar(5) DEFAULT NULL,
  `acad_group` char(3) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `instruction_type` varchar(50) DEFAULT NULL,
  `weekdays` varchar(7) DEFAULT 'NNNNNNN',
  `venue` varchar(255) DEFAULT NULL,
  `duration_weeks` tinyint(2) DEFAULT NULL,
  `enrolment_count` smallint(4) unsigned DEFAULT NULL,
  `class_mtg_nbr` tinyint(3) unsigned DEFAULT NULL,
  `class_nbr` mediumint(5) unsigned DEFAULT NULL,
  `course_attr` varchar(20) DEFAULT NULL,
  `course_attr_value` varchar(20) DEFAULT NULL,
  `session_code` varchar(10) DEFAULT NULL,
  `class_type` varchar(3) DEFAULT NULL,
  `term` varchar(20) NOT NULL,
  `tt_version` mediumint(5) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `term_course_code_i` (`term`,`course_code`),
  KEY `activity_id` (`activity_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1580019 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `timetable_updates`
--

DROP TABLE IF EXISTS `timetable_updates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `timetable_updates` (
  `id` int(7) unsigned NOT NULL AUTO_INCREMENT,
  `mediapackage` varchar(128) NOT NULL,
  `title` varchar(100) DEFAULT NULL,
  `tt_version` mediumint(4) DEFAULT NULL,
  `old_venue` varchar(50) DEFAULT NULL,
  `new_venue` varchar(50) DEFAULT NULL,
  `startdate` varchar(50) DEFAULT NULL,
  `enddate` varchar(50) DEFAULT NULL,
  `conflicting_mediapackages` varchar(255) DEFAULT NULL,
  `update_timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1846 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `timetable_versions`
--

DROP TABLE IF EXISTS `timetable_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `timetable_versions` (
  `version` mediumint(5) unsigned NOT NULL AUTO_INCREMENT COMMENT 'signifies the row''s timetable version number',
  `tt_timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `current` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`version`)
) ENGINE=InnoDB AUTO_INCREMENT=174 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `uct_dept`
--

DROP TABLE IF EXISTS `uct_dept`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `uct_dept` (
  `dept` varchar(3) NOT NULL COMMENT 'Populated by PeopleSoft',
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `firstname` varchar(255) DEFAULT NULL,
  `lastname` varchar(255) DEFAULT NULL,
  `alt_email` varchar(255) DEFAULT NULL,
  `active` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `secret` varchar(128) NOT NULL DEFAULT 'empty',
  PRIMARY KEY (`dept`),
  UNIQUE KEY `dept_UNIQUE` (`dept`),
  KEY `dept_INDEX` (`dept`),
  KEY `active_INDEX` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8 */ ;
/*!50003 SET character_set_results = utf8 */ ;
/*!50003 SET collation_connection  = utf8_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ALLOW_INVALID_DATES,ERROR_FOR_DIVISION_BY_ZERO,TRADITIONAL,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`bowser`@`%`*/ /*!50003 TRIGGER `timetable`.`uct_dept_BEFORE_INSERT` BEFORE INSERT ON `uct_dept` FOR EACH ROW
BEGIN
  SET new.secret := sha2(CONCAT(LOWER(NOW()),new.dept, 'lecture recording opt out'), 256);
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `uct_workflow`
--

DROP TABLE IF EXISTS `uct_workflow`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `uct_workflow` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `year` smallint(5) unsigned NOT NULL DEFAULT '2018',
  `status` enum('init','start','run','dept','course','done') NOT NULL DEFAULT 'init',
  `date_start` timestamp NULL DEFAULT NULL,
  `date_dept` timestamp NULL DEFAULT NULL,
  `date_course` timestamp NULL DEFAULT NULL,
  `date_schedule` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT '1970-01-01 22:00:00',
  `active` tinyint(1) unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8 */ ;
/*!50003 SET character_set_results = utf8 */ ;
/*!50003 SET collation_connection  = utf8_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ALLOW_INVALID_DATES,ERROR_FOR_DIVISION_BY_ZERO,TRADITIONAL,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`bowser`@`%`*/ /*!50003 TRIGGER `timetable`.`BEFORE_INSERT`
BEFORE INSERT ON `timetable`.`uct_workflow`
FOR EACH ROW
BEGIN
	SET new.year = YEAR(NOW());
    SET new.created_at := now();
    SET new.updated_at := now();
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8 */ ;
/*!50003 SET character_set_results = utf8 */ ;
/*!50003 SET collation_connection  = utf8_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ALLOW_INVALID_DATES,ERROR_FOR_DIVISION_BY_ZERO,TRADITIONAL,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`bowser`@`%`*/ /*!50003 TRIGGER `timetable`.`uct_workflow_BEFORE_UPDATE` BEFORE UPDATE ON `uct_workflow` FOR EACH ROW
BEGIN
   SET new.updated_at := now();
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `uct_workflow_email`
--

DROP TABLE IF EXISTS `uct_workflow_email`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `uct_workflow_email` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` int(11) unsigned NOT NULL,
  `dept` varchar(3) NOT NULL,
  `course` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT '1970-01-01 22:00:00',
  `mail_to` varchar(255) NOT NULL DEFAULT '',
  `mail_cc` varchar(255) NOT NULL DEFAULT '',
  `hash` varchar(45) NOT NULL DEFAULT 'invalid',
  `state` tinyint(3) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `workflow_INDEX` (`workflow_id`),
  KEY `dept_INDEX` (`dept`),
  KEY `state_INDEX` (`state`),
  CONSTRAINT `dept_fk` FOREIGN KEY (`dept`) REFERENCES `uct_dept` (`dept`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `workflow_fk` FOREIGN KEY (`workflow_id`) REFERENCES `uct_workflow` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8 */ ;
/*!50003 SET character_set_results = utf8 */ ;
/*!50003 SET collation_connection  = utf8_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ALLOW_INVALID_DATES,ERROR_FOR_DIVISION_BY_ZERO,TRADITIONAL,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`bowser`@`%`*/ /*!50003 TRIGGER `timetable`.`uct_workflow_email_BEFORE_INSERT` BEFORE INSERT ON `uct_workflow_email` FOR EACH ROW
BEGIN
    SET new.created_at := now();
    SET new.updated_at := now();
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8 */ ;
/*!50003 SET character_set_results = utf8 */ ;
/*!50003 SET collation_connection  = utf8_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ALLOW_INVALID_DATES,ERROR_FOR_DIVISION_BY_ZERO,TRADITIONAL,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`bowser`@`%`*/ /*!50003 TRIGGER `timetable`.`uct_workflow_email_BEFORE_UPDATE` BEFORE UPDATE ON `uct_workflow_email` FOR EACH ROW
BEGIN
  SET new.updated_at := now();
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2018-07-05 11:25:05
