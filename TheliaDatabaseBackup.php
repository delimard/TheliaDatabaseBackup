<?php

namespace TheliaDatabaseBackup;

use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;

use Thelia\Module\BaseModule;

class TheliaDatabaseBackup extends BaseModule
{
    /** @var string */
    const DOMAIN_NAME = 'theliadatabasebackup';


    /**
     * Defines how services are loaded in your modules
     *
     * @param ServicesConfigurator $servicesConfigurator
     */
    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([THELIA_MODULE_DIR . ucfirst(self::getModuleCode()). "/I18n/*"])
            ->autowire(true)
            ->autoconfigure(true);
    }


}
