<?php

namespace Nails\Queue\Model;

use Nails\Common\Exception\ModelException;
use Nails\Common\Model\Base;
use Nails\Queue\Constants;

class Worker extends Base
{
    const TABLE               = NAILS_DB_PREFIX . 'queue_worker';
    const RESOURCE_NAME       = 'Worker';
    const RESOURCE_PROVIDER   = Constants::MODULE_SLUG;
    const DEFAULT_SORT_COLUMN = 'id';
    const DEFAULT_SORT_ORDER  = self::SORT_ASC;

    /**
     * No caching as we always want live lookups due to the
     * long running nature of workers and jobs
     */
    protected static $CACHING_ENABLED = false;

    /**
     * @throws ModelException
     */
    public function __construct()
    {
        parent::__construct();
        $this
            ->hasMany('jobs', 'Job', 'worker_id', Constants::MODULE_SLUG);
    }
}
