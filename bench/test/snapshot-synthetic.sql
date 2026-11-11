-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- A small synthetic dump in the shape `wp db export -` writes on a staging site (mysqldump 8.0, extended inserts,
-- prefix syn_). Every value is made up. test/snapshot.test.ts runs the sanitizer on it.
--
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!50503 SET NAMES utf8mb4 */;
SET @@SESSION.SQL_LOG_BIN= 0;
SET @@GLOBAL.GTID_PURGED=/*!80000 '+'*/ '3E11FA47-71CA-11E1-9E33-C80AA9429562:1-5';

--
-- Table structure for table `syn_users`
--

DROP TABLE IF EXISTS `syn_users`;
CREATE TABLE `syn_users` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_login` varchar(60) NOT NULL DEFAULT '',
  `user_pass` varchar(255) NOT NULL DEFAULT '',
  `user_nicename` varchar(50) NOT NULL DEFAULT '',
  `user_email` varchar(100) NOT NULL DEFAULT '',
  `user_url` varchar(100) NOT NULL DEFAULT '',
  `user_registered` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `user_activation_key` varchar(255) NOT NULL DEFAULT '',
  `user_status` int NOT NULL DEFAULT '0',
  `display_name` varchar(250) NOT NULL DEFAULT '',
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

LOCK TABLES `syn_users` WRITE;
INSERT INTO `syn_users` VALUES (1,'Voyager','$wp$2y$10$SyntheticHashOneSyntheticHashOneSyntheticHashOne1234','voyager','owner@synthetic-agency.test','','2024-01-01 00:00:00','',0,'Voyager'),(3,'editor_sam','$P$BSyntheticHashTwoSynthetic12','editor_sam','sam.editor@synthetic-agency.test','','2024-02-01 00:00:00','1700000000:$P$BSyntheticResetKey123',0,'Sam O\'Neil');
UNLOCK TABLES;

--
-- Table structure for table `syn_usermeta`
--

DROP TABLE IF EXISTS `syn_usermeta`;
CREATE TABLE `syn_usermeta` (
  `umeta_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL DEFAULT '0',
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`umeta_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

LOCK TABLES `syn_usermeta` WRITE;
INSERT INTO `syn_usermeta` VALUES (1,1,'nickname','Voyager'),(2,1,'syn_capabilities','a:1:{s:13:\"administrator\";b:1;}'),(3,1,'syn_user_level','10'),(4,1,'session_tokens','a:1:{s:64:\"0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef\";a:2:{s:10:\"expiration\";i:1790000000;s:2:\"ip\";s:11:\"203.0.113.9\";}}'),(5,1,'_application_passwords','a:1:{i:0;a:3:{s:4:\"uuid\";s:36:\"11111111-2222-3333-4444-555555555555\";s:4:\"name\";s:6:\"portal\";s:8:\"password\";s:34:\"$P$BSyntheticAppPasswordHash000001\";}}'),(6,1,'community-events-location','a:1:{s:2:\"ip\";s:11:\"203.0.113.0\";}'),(7,1,'_voyager_portal_clerk_email','owner@synthetic-agency.test'),(8,3,'syn_capabilities','a:1:{s:6:\"editor\";b:1;}'),(9,3,'syn_user_level','7'),(10,3,'first_name','Sam');
UNLOCK TABLES;

--
-- Table structure for table `syn_options`
--

DROP TABLE IF EXISTS `syn_options`;
CREATE TABLE `syn_options` (
  `option_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) NOT NULL DEFAULT '',
  `option_value` longtext NOT NULL,
  `autoload` varchar(20) NOT NULL DEFAULT 'yes',
  PRIMARY KEY (`option_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

LOCK TABLES `syn_options` WRITE;
INSERT INTO `syn_options` VALUES (1,'siteurl','https://staging.synthetic.test','on'),(2,'blogname','Synthetic Agency','on'),(3,'admin_email','owner@synthetic-agency.test','on'),(4,'syn_user_roles','a:2:{s:13:\"administrator\";a:2:{s:4:\"name\";s:13:\"Administrator\";s:12:\"capabilities\";a:2:{s:10:\"edit_pages\";b:1;s:18:\"edit_theme_options\";b:1;}}s:6:\"editor\";a:2:{s:4:\"name\";s:6:\"Editor\";s:12:\"capabilities\";a:2:{s:10:\"edit_pages\";b:1;s:12:\"upload_files\";b:1;}}}','on'),(5,'connectors_ai_anthropic_api_key','sk-ant-api03-SyntheticKeySyntheticKeySyntheticKey','auto'),(6,'postmark_settings','{\"api_key\":\"00000000-aaaa-bbbb-cccc-dddddddddddd\",\"sender_address\":\"hello@synthetic-agency.test\",\"force_html\":1}','auto'),(7,'wp_ai_client_provider_credentials','a:1:{s:9:\"anthropic\";a:2:{s:7:\"api_key\";s:44:\"sk-ant-api03-NestedSyntheticKeyNestedSynthet\";s:5:\"label\";s:4:\"main\";}}','auto'),(8,'voyager_site_email','hello@synthetic-agency.test','auto'),(9,'voyager_site_phone','(555) 010-0199','auto'),(10,'_transient_feed_abc','a:0:{}','off'),(11,'_site_transient_update_core','O:8:\"stdClass\":0:{}','off'),(12,'vwf_mcp_enabled','1','auto'),(13,'limit_login_logged','a:1:{s:11:\"203.0.113.7\";a:1:{s:5:\"admin\";i:4;}}','off'),(14,'theme_mods_voyagermark','a:2:{s:18:\"nav_menu_locations\";a:0:{}s:18:\"custom_css_post_id\";i:-1;}','on'),(15,'rank_math_analytics_all_services','a:2:{s:7:\"account\";s:27:\"owner@synthetic-agency.test\";s:12:\"access_token\";s:30:\"ya29.SyntheticGoogleAccessTkn1\";}','auto'),(16,'voyager_notion_api_token','secret_SyntheticNotionTokenSyntheticNotionToken1','auto'),(17,'blogdescription','It\'s a test, with a \\ backslash and a\nnewline','on'),(18,'cron','a:1:{i:1791346092;a:1:{s:32:\"recovery_mode_clean_expired_keys\";a:1:{s:32:\"40cd750bba9870f18aada2478b24840a\";a:2:{s:8:\"schedule\";s:5:\"daily\";s:4:\"args\";a:0:{}}}}}','on'),(19,'rewrite_rules','a:1:{s:13:\"^oauth/token$\";s:30:\"index.php?oauth_endpoint=token\";}','on'),(21,'voyager_block_census_theme','{\"voyagermark\\/case-a-named-client\":{\"title\":\"Case: A named client\"}}','off'),(20,'rank-math-options-titles','a:2:{s:26:\"noindex_password_protected\";s:2:\"on\";s:12:\"website_name\";s:10:\"Synth Site\";}','auto');
UNLOCK TABLES;

--
-- Table structure for table `syn_comments`
--

DROP TABLE IF EXISTS `syn_comments`;
CREATE TABLE `syn_comments` (
  `comment_ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `comment_post_ID` bigint unsigned NOT NULL DEFAULT '0',
  `comment_author` tinytext NOT NULL,
  `comment_author_email` varchar(100) NOT NULL DEFAULT '',
  `comment_author_url` varchar(200) NOT NULL DEFAULT '',
  `comment_author_IP` varchar(100) NOT NULL DEFAULT '',
  `comment_content` text NOT NULL,
  `comment_agent` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`comment_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

LOCK TABLES `syn_comments` WRITE;
INSERT INTO `syn_comments` VALUES (1,10,'A visitor','visitor@synthetic-mail.test','','198.51.100.4','Nice post.','Mozilla/5.0 (Synthetic)');
UNLOCK TABLES;

--
-- Table structure for table `syn_postmeta`
--

DROP TABLE IF EXISTS `syn_postmeta`;
CREATE TABLE `syn_postmeta` (
  `meta_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint unsigned NOT NULL DEFAULT '0',
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`meta_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

LOCK TABLES `syn_postmeta` WRITE;
INSERT INTO `syn_postmeta` VALUES (1,10,'service_price','$1,500/mo'),(2,10,'team_email','press@synthetic-agency.test'),(3,20,'_llar_digest_ip_stats','a:1:{s:11:\"203.0.113.7\";i:9;}'),(4,30,'_notion_body_html','<p>Internal notes</p>'),(5,10,'_webhook_secret','whsec_SyntheticWebhookSecret1234'),(6,31,'_edit_last','1'),(7,50,'client_name_meta','A named client'),(8,11,'_edit_last','1');
UNLOCK TABLES;

--
-- Table structure for table `syn_posts`
--

DROP TABLE IF EXISTS `syn_posts`;
CREATE TABLE `syn_posts` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `post_author` bigint unsigned NOT NULL DEFAULT '0',
  `post_content` longtext NOT NULL,
  `post_title` text NOT NULL,
  `post_excerpt` text NOT NULL,
  `post_status` varchar(20) NOT NULL DEFAULT 'publish',
  `post_name` varchar(200) NOT NULL DEFAULT '',
  `post_parent` bigint unsigned NOT NULL DEFAULT '0',
  `post_type` varchar(20) NOT NULL DEFAULT 'post',
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

LOCK TABLES `syn_posts` WRITE;
INSERT INTO `syn_posts` VALUES (10,1,'<!-- wp:paragraph --><p>Call us. Email hello@synthetic-agency.test.</p><!-- /wp:paragraph -->','Services','','publish','services',0,'page'),(11,1,'<!-- wp:paragraph --><p>Draft about pricing</p><!-- /wp:paragraph -->','Pricing draft','','draft','pricing-draft',0,'page'),(20,1,'','Login digest','','private','digest',0,'llar_digest_day'),(30,1,'Internal notes','A client name','','trash','client-name',0,'notion_page'),(31,1,'Internal notes','A client name','','inherit','30-revision-v1',30,'revision'),(32,1,'Old','Services','','inherit','10-revision-v1',10,'revision'),(40,1,'','Auto Draft','','auto-draft','',0,'post'),(50,1,'Unpublished case study for a named client','A named client','','draft','a-named-client',0,'portfolio'),(51,1,'Unpublished case study for a named client, earlier','A named client','','inherit','50-revision-v1',50,'revision'),(52,1,'Awaiting review','Pending page','','pending','pending-page',0,'page');
UNLOCK TABLES;

--
-- Table structure for table `syn_gf_entry`
--

DROP TABLE IF EXISTS `syn_gf_entry`;
CREATE TABLE `syn_gf_entry` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `form_id` mediumint unsigned NOT NULL,
  `ip` varchar(45) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

LOCK TABLES `syn_gf_entry` WRITE;
INSERT INTO `syn_gf_entry` VALUES (1,1,'198.51.100.77');
UNLOCK TABLES;

--
-- Table structure for table `syn_actionscheduler_logs`
--

DROP TABLE IF EXISTS `syn_actionscheduler_logs`;
CREATE TABLE `syn_actionscheduler_logs` (
  `log_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `action_id` bigint unsigned NOT NULL,
  `message` text NOT NULL,
  PRIMARY KEY (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

LOCK TABLES `syn_actionscheduler_logs` WRITE;
INSERT INTO `syn_actionscheduler_logs` VALUES (1,5,'sent lead to lead.owner@synthetic-mail.test');
UNLOCK TABLES;

--
-- Table structure for table `syn_voyager_activities`
--

DROP TABLE IF EXISTS `syn_voyager_activities`;
CREATE TABLE `syn_voyager_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(64) NOT NULL,
  `details` json DEFAULT NULL,
  `user_email` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

LOCK TABLES `syn_voyager_activities` WRITE;
INSERT INTO `syn_voyager_activities` VALUES (1,'user_login','{\"ip\":\"203.0.113.44\"}','staff@synthetic-agency.test');
UNLOCK TABLES;

--
-- Table structure for table `syn_voyager_jobs`
--

DROP TABLE IF EXISTS `syn_voyager_jobs`;
CREATE TABLE `syn_voyager_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_json` longtext,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

LOCK TABLES `syn_voyager_jobs` WRITE;
INSERT INTO `syn_voyager_jobs` VALUES (1,'{\"upload_url\":\"https://storage.synthetic.test/backup?sig=SyntheticSignature\"}');
UNLOCK TABLES;

-- Dump completed on 2026-10-05 22:17:22
