<?php

namespace Nails\Admin\Queue;

use Nails\Admin\Constants;
use Nails\Admin\Controller\Base;
use Nails\Admin\Factory\Nav;
use Nails\Admin\Helper;
use Nails\Common\Exception\FactoryException;
use Nails\Factory;

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
        /** @var Nav $navGroup */
        $navGroup = Factory::factory('Nav', Constants::MODULE_SLUG);
        $navGroup->setLabel('Queue');
        if (userHasPermission('admin:queue:overview:view')) {
            $navGroup->addAction('Overview');
        }

        return $navGroup;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns an array of extra permissions for this controller
     *
     * @return array
     */
    public static function permissions(): array
    {
        return [
            'view' => 'Can view queue overview',
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * @throws FactoryException
     */
    public function index()
    {
        if (!userHasPermission('admin:queue:overview:view')) {
            unauthorised();
        }

        $this->data['page']->title = 'Queue &rsaquo; Overview';
        Helper::loadView('index');
    }
}
