<?php

namespace KimaiPlugin\SwissQrBundle;

use App\Plugin\PluginInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class SwissQrBundle extends Bundle implements PluginInterface
{
    public function build(ContainerBuilder $container): void
    {
        // Register library autoload during container compile (before SwissQrService is loaded).
        require_once __DIR__ . '/Resources/plugin-autoload.php';
        parent::build($container);
    }

    public function boot(): void
    {
        require_once __DIR__ . '/Resources/plugin-autoload.php';
    }
} 