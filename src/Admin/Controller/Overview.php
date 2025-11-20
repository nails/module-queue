<?php

namespace Nails\Queue\Admin\Controller;

use Nails\Admin\Constants;
use Nails\Admin\Controller\Base;
use Nails\Admin\Factory\Nav;
use Nails\Admin\Helper;
use Nails\Common\Exception\FactoryException;
use Nails\Factory;
use Nails\Queue\Admin\Permission;

class Overview extends Base
{
    /**
     * Announces this controller's navGroups
     *
     * @return Nav|array|null
     * @throws FactoryException
     */
    public static function announce(): Nav|array|null
    {
        if (userHasPermission(Permission\Overview\View::class)) {
            /** @var Nav $navGroup */
            $navGroup = Factory::factory('Nav', Constants::MODULE_SLUG);
            $navGroup
                ->setLabel('Queue')
                ->addAction('Overview');
        }

        return $navGroup ?? null;
    }

    // --------------------------------------------------------------------------

    /**
     * @throws FactoryException
     */
    public function index()
    {
        if (!userHasPermission(Permission\Overview\View::class)) {
            unauthorised();
        }

        $this->data['page']->title = 'Queue &rsaquo; Overview';
        Helper::loadView('index');
    }
}
