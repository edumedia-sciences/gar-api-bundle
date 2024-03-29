<?php

namespace eduMedia\GarApiBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class eduMediaGarApiExtension extends Extension
{

    /**
     * @inheritDoc
     */
    public function load(array $configs, ContainerBuilder $container): void
    {

        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__).'/Resources/config'));
        $loader->load('services.yaml');

        $configuration = $this->getConfiguration($configs, $container);
        $config = $this->processConfiguration($configuration, $configs);

        $definition = $container->getDefinition('eduMedia\GarApiBundle\Service\GarApiService');

        $definition->replaceArgument(0, $config['distributor_id']);
        $definition->replaceArgument(1, $config['ssl_cert']);
        $definition->replaceArgument(2, $config['ssl_key']);
        $definition->replaceArgument(3, $config['remote_env']);
        $definition->replaceArgument(4, $config['cache_directory']);
        $definition->replaceArgument(5, $config['report_ssl_cert'] ?? null);
        $definition->replaceArgument(6, $config['report_ssl_key'] ?? null);
    }

    public function getAlias(): string
    {
        return 'edumedia_gar_api';
    }


}