--
-- Table structure for table `rm_record`
--

CREATE TABLE `rm_record` (
  `record_id` varchar(64) NOT NULL,
  `host_record_id` varchar(64),
  `source_id` varchar(64) NOT NULL,
  `oai_id` varchar(255),
  `created` datetime NOT NULL,
  `updated` datetime NOT NULL,
  `deleted` tinyint(1) NOT NULL,
  `format` varchar(64) NOT NULL,
  `original_data` text,
  `normalized_data` text,
  `dedup_key` varchar(255),
  `update_needed` tinyint(1) NOT NULL,
  PRIMARY KEY (`record_id`),
  INDEX (`oai_id`),
  INDEX (`updated`, `deleted`),
  INDEX (`source_id`),
  INDEX (`dedup_key`)
) ENGINE=InnoDB ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8 CHARSET=utf8;

CREATE TABLE `rm_record_dedup_candidate_key` (
  `record_id` varchar(64) NOT NULL,
  `candidate_key` varchar(255),
  PRIMARY KEY (`candidate_key`, `record_id`),
  INDEX (`record_id`),
  FOREIGN KEY (record_id) REFERENCES rm_record(record_id) ON DELETE CASCADE
) ENGINE=InnoDB CHARSET=utf8;

CREATE TABLE `rm_record_deleted_dedup_key` (
  `dedup_key` varchar(255),
  `updated` datetime NOT NULL,
  PRIMARY KEY (`dedup_key`)
) ENGINE=InnoDB CHARSET=utf8;

CREATE TABLE `rm_state` (
  `id` varchar(255) NOT NULL,
  `value` varchar(255),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB CHARSET=utf8;


