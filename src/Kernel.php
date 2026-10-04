<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $configDirectory = $this->getProjectDir().'/config';

        $container->import($configDirectory.'/{packages}/*.yaml');
        $container->import($configDirectory.'/{packages}/'.$this->environment.'/*.yaml');
        $container->import($configDirectory.'/{services}.yaml');
        $container->import($configDirectory.'/{routes}.yaml');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }
}
