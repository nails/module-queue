<?php

namespace Nails\Queue\Database\Migration;

use Nails\Common\Interfaces;
use Nails\Common\Traits;

class Migration0 implements Interfaces\Database\Migration
{
    use Traits\Database\Migration;

    public function execute()
    {
        $this->query(
            <<<EOT
            CREATE TABLE `{{NAILS_DB_PREFIX}}queue_worker` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `token` varchar(32) NOT NULL,
                `queues` json DEFAULT NULL,
                `heartbeat` datetime NOT NULL,
                `created` datetime NOT NULL,
                `created_by` int unsigned DEFAULT NULL,
                `modified` datetime NOT NULL,
                `modified_by` int unsigned DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `token` (`token`),
                KEY `created_by` (`created_by`),
                KEY `modified_by` (`modified_by`),
                CONSTRAINT `{{NAILS_DB_PREFIX}}queue_worker_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE SET NULL,
                CONSTRAINT `{{NAILS_DB_PREFIX}}queue_worker_ibfk_2` FOREIGN KEY (`modified_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            EOT
        );
        $this->query(
            <<<EOT
            CREATE TABLE `{{NAILS_DB_PREFIX}}queue_job` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `queue` varchar(255) NOT NULL,
                `task` varchar(255) NOT NULL,
                `data` json DEFAULT NULL,
                `status` enum('PENDING','RUNNING','COMPLETE','FAILED') NOT NULL,
                `worker_id` int unsigned DEFAULT NULL,
                `available_at` datetime(4) NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `started` datetime(4) DEFAULT NULL,
                `finished` datetime(4) DEFAULT NULL,
                `errors` JSON NOT NULL,
                `attempts` int unsigned NOT NULL DEFAULT 0,
                `created` datetime NOT NULL,
                `created_by` int unsigned DEFAULT NULL,
                `modified` datetime NOT NULL,
                `modified_by` int unsigned DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `created_by` (`created_by`),
                KEY `modified_by` (`modified_by`),
                KEY `worker_id` (`worker_id`),
                KEY `status` (`status`,`queue`,`available_at`,`id`),
                CONSTRAINT `{{NAILS_DB_PREFIX}}queue_job_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE SET NULL,
                CONSTRAINT `{{NAILS_DB_PREFIX}}queue_job_ibfk_2` FOREIGN KEY (`modified_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE SET NULL,
                CONSTRAINT `{{NAILS_DB_PREFIX}}queue_job_ibfk_3` FOREIGN KEY (`worker_id`) REFERENCES `{{NAILS_DB_PREFIX}}queue_worker` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            EOT
        );
    }
}
