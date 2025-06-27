<?php

namespace KimaiPlugin\SwissQrBundle;

use App\Plugin\PluginInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class SwissQrBundle extends Bundle implements PluginInterface
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);
    }
} 