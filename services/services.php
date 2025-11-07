<?php

use Nails\Common\Service\Database;
use Nails\Queue\Constants;
use Nails\Queue\Factory;
use Nails\Queue\Model;
use Nails\Queue\Resource;
use Nails\Queue\Service;

return [
    'services'  => [
        'Manager' => function (?Database $database = null, ?Model\Worker $workerModel = null, ?Model\Job $jobModel = null): Service\Manager {

            $database    = $database ?? \Nails\Factory::service('Database');
            $workerModel = $workerModel ?? \Nails\Factory::model('Worker', Constants::MODULE_SLUG);
            $jobModel    = $jobModel ?? \Nails\Factory::model('Job', Constants::MODULE_SLUG);

            if (class_exists('\App\Queue\Service\Manager')) {
                return new \App\Queue\Service\Manager($database, $workerModel, $jobModel);
            } else {
                return new Service\Manager($database, $workerModel, $jobModel);
            }
        },
    ],
    'models'    => [
        'Job'    => function (): Model\Job {
            if (class_exists('\App\Queue\Model\Job')) {
                return new \App\Queue\Model\Job();
            } else {
                return new Model\Job();
            }
        },
        'Worker' => function (): Model\Worker {
            if (class_exists('\App\Queue\Model\Worker')) {
                return new \App\Queue\Model\Worker();
            } else {
                return new Model\Worker();
            }
        },
    ],
    'resources' => [
        'Job'    => function ($resource, $model): Resource\Job {
            if (class_exists('\App\Queue\Resource\Job')) {
                return new \App\Queue\Resource\Job($resource, $model);
            } else {
                return new Resource\Job($resource, $model);
            }
        },
        'Worker' => function ($resource, $model): Resource\Worker {
            if (class_exists('\App\Queue\Resource\Worker')) {
                return new \App\Queue\Resource\Worker($resource, $model);
            } else {
                return new Resource\Worker($resource, $model);
            }
        },
    ],
    'factories' => [
        'Data' => function (array|string|int|float|bool|stdClass|null $data): Factory\Data {
            if (class_exists('\App\Queue\Factory\Data')) {
                return new \App\Queue\Factory\Data($data);
            } else {
                return new Factory\Data($data);
            }
        },
    ],
];
