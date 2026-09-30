<?php

namespace App\DependencyInjection;

use App\Agent\Model\SymfonyAiCodingModel;
use App\Agent\Tool\CodingToolInterface;
use App\Agent\Tool\ToolRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers the agent models declared under `cable_car.models` as services
 * (`cable_car.model.<name>`, tagged `cable_car.model`) and auto-tags every
 * coding tool so the runner only depends on the registry.
 */
final class CableCarExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('cable_car.workspaces_root', $config['workspaces_root']);
        $container->setParameter('cable_car.default_model', $config['default_model']);
        $container->setParameter('cable_car.limits', $config['limits']);
        $container->setParameter('cable_car.workspace', $config['workspace']);
        $container->setParameter('cable_car.commands', $config['commands']);
        $container->setParameter('cable_car.commands.path', $config['commands']['path']);

        $container->registerForAutoconfiguration(CodingToolInterface::class)
            ->addTag('cable_car.tool');

        foreach ($config['models'] as $name => $model) {
            $definition = (new Definition(SymfonyAiCodingModel::class))
                ->setArguments([
                    (string) $name,
                    $model['label'] ?? (string) $name,
                    $model['model'],
                    new Reference($model['platform']),
                    new Reference(ToolRegistry::class),
                    new Reference(LoggerInterface::class),
                    $model['options'],
                ])
                ->addTag('cable_car.model', ['name' => (string) $name]);

            $container->setDefinition('cable_car.model.' . $name, $definition);
        }
    }

    public function getAlias(): string
    {
        return 'cable_car';
    }
}
