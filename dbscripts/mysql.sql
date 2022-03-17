/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;

CREATE TABLE `record` (
  `_id` varchar(255) NOT NULL PRIMARY KEY,
  `oai_id` varchar(255) NULL,
  `main_id` varchar(255) NULL,
  `source_id` varchar(255) NOT NULL,
  `dedup_id` bigint NULL,
  `format` varchar(255) NULL,
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  `date` datetime NOT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  `suppressed` tinyint(1) NOT NULL DEFAULT 0,
  `update_needed` tinyint(1) NOT NULL DEFAULT 0,
  `original_data` longtext NOT NULL,
  `normalized_data` longtext NULL,
  `mark` tinyint(1) NULL,
  KEY `oai_id` (`oai_id`),
  KEY `main_id` (`main_id`),
  KEY `dedup_id` (`dedup_id`),
  KEY `updated` (`updated`),
  KEY `source_update_needed` (`source_id`, `update_needed`),
  CONSTRAINT `fk_record_dedup_id` FOREIGN KEY (`dedup_id`) REFERENCES `dedup` (`_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `record_attrs` (
  `_id` integer NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `parent_id` varchar(255) NOT NULL,
  `attr` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  CONSTRAINT `fk_record_attrs_parent` FOREIGN KEY (`parent_id`) REFERENCES `record` (`_id`) ON DELETE CASCADE,
  KEY `attr_value` (`attr`, `value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `dedup` (
  `_id` bigint NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `changed` datetime NOT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  KEY `changed` (`changed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `dedup_attrs` (
  `_id` integer NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `parent_id` bigint NOT NULL,
  `attr` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  CONSTRAINT `dedup_attrs_parent` FOREIGN KEY (`parent_id`) REFERENCES `dedup` (`_id`) ON DELETE CASCADE,
  CONSTRAINT `dedup_attrs_value` FOREIGN KEY (`value`) REFERENCES `record` (`_id`) ON DELETE CASCADE -- This is always a record id
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `state` (
  `_id` varchar(255) NOT NULL PRIMARY KEY,
  `value` varchar(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `uriCache` (
  `_id` varchar(255) NOT NULL PRIMARY KEY,
  `timestamp` datetime NOT NULL,
  `url` varchar(8192) NULL,
  `data` longtext NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `ontologyEnrichment` (
  `_id` varchar(255) NOT NULL PRIMARY KEY,
  `type` varchar(255) NOT NULL,
  `prefLabels` MEDIUMTEXT NULL,
  `altLabels` MEDIUMTEXT NULL,
  `hiddenLabels` MEDIUMTEXT NULL
  `geoLocation` MEDIUMTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `logMessage` (
  `_id` integer NOT NULL PRIMARY KEY AUTO_INCREMENT,
  `timestamp` datetime NOT NULL,
  `context` varchar(255) NOT NULL,
  `message` longtext NOT NULL,
  `level` int(2) NOT NULL,
  `pid` int(9) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A trigger to make sure we're not linking to a deleted dedup record
DROP TRIGGER IF EXISTS record_before_update;
delimiter $$
CREATE TRIGGER record_before_update
BEFORE UPDATE
ON record FOR EACH ROW
BEGIN
    IF new.dedup_id <> old.dedup_id THEN
        set @deleted = (SELECT deleted FROM dedup WHERE _id=new.dedup_id);
        IF @deleted=1 THEN
            set @msg = CONCAT('Attempted linking ', new._id, ' to a deleted dedup record ', new.dedup_id);
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = @msg;
        END IF;
    END IF;
END$$
delimiter ;

/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
