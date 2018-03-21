<?php
/**
 * @see       https://github.com/zendframework/zend-auradi-config for the canonical source repository
 * @copyright Copyright (c) 2017-2018 Zend Technologies USA Inc. (https://www.zend.com)
 * @license   https://github.com/zendframework/zend-auradi-config/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Zend\AuraDi\Config;

use ArrayObject;
use Aura\Di\Container;
use Aura\Di\ContainerConfigInterface;
use Aura\Di\Exception\ServiceNotFound;

use function array_search;
use function is_array;
use function is_callable;

/**
 * Configuration for the Aura.Di container.
 *
 * This class provides functionality for the following service types:
 *
 * - Aliases
 * - Delegators
 * - Factories
 * - Invokable classes
 * - Services (known instances)
 */
class Config implements ContainerConfigInterface
{
    /**
     * @var array
     */
    private $config;

    /**
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Configure the container
     *
     * - Adds the 'config' service.
     * - If delegators are defined, maps the service to lazyGetCall an
     *   ExpressiveAuraDelegatorFactory::build invocation using the configured
     *   delegator and whatever factory was responsible for it.
     * - If factories are defined, maps each factory class as a lazily
     *   instantiable service, and the service to lazyGetCall the factory to
     *   create the instance.
     * - If invokables are defined, maps each to lazyNew the target.
     * - If aliases are defined, maps each to lazyGet the target.
     */
    public function define(Container $container)
    {
        // Convert config to an object and inject it
        $container->set('config', new ArrayObject($this->config, ArrayObject::ARRAY_AS_PROPS));

        if (empty($this->config['dependencies'])
            || ! is_array($this->config['dependencies'])
        ) {
            return;
        }

        $dependencies = $this->config['dependencies'];

        // Inject delegator factories
        // This is done early because Aura.Di does not allow modification of a
        // service after creation. As such, we need to create custom factories
        // for each service with delegators.
        if (isset($dependencies['delegators'])
            && is_array($dependencies['delegators'])
        ) {
            $dependencies = $this->marshalDelegators($container, $dependencies);
        }

        // Inject services
        if (isset($dependencies['services'])
            && is_array($dependencies['services'])
        ) {
            foreach ($dependencies['services'] as $name => $service) {
                $container->set($name, $service);
            }
        }

        // Inject factories
        if (isset($dependencies['factories'])
            && is_array($dependencies['factories'])
        ) {
            foreach ($dependencies['factories'] as $service => $factory) {
                if (is_callable($factory)) {
                    $container->set($service, $container->lazy($factory, $container, $service));
                    continue;
                }

                $container->set($service, $container->lazy(function ($container, $service) use ($factory) {
                    if (! class_exists($factory)) {
                        throw new ServiceNotFound(sprintf(
                            'Service %s cannot be initialized by factory %s',
                            $service,
                            $factory
                        ));
                    }

                    $instance = new $factory();

                    if (! is_callable($instance)) {
                        throw new ServiceNotFound(sprintf(
                            'Service %s cannot be initalized by non invokable factory %s',
                            $service,
                            $factory
                        ));
                    }

                    return $instance($container, $service);
                }, $container, $service));
            }
        }

        // Inject invokables
        if (isset($dependencies['invokables'])
            && is_array($dependencies['invokables'])
        ) {
            foreach ($dependencies['invokables'] as $service => $class) {
                if ($service !== $class) {
                    $container->set($service, $container->lazyGet($class));
                }

                $container->set($class, $container->lazyNew($class));
            }
        }

        // Inject aliases
        if (isset($dependencies['aliases'])
            && is_array($dependencies['aliases'])
        ) {
            foreach ($dependencies['aliases'] as $alias => $target) {
                $container->set($alias, $container->lazyGet($target));
            }
        }
    }

    /**
     * This method is purposely a no-op.
     */
    public function modify(Container $container)
    {
    }

    /**
     * Marshal all services with delegators.
     *
     * @return array List of dependencies minus any services, factories, or
     *     invokables that match services using delegator factories.
     */
    private function marshalDelegators(Container $container, array $dependencies) : array
    {
        foreach ($dependencies['delegators'] as $service => $delegatorNames) {
            $factory = null;

            if (isset($dependencies['factories'][$service])) {
                // Marshal from factory
                $serviceFactory = $dependencies['factories'][$service];
                $factory = function () use ($service, $serviceFactory, $container) {
                    if (! class_exists($serviceFactory)) {
                        throw new ServiceNotFound(sprintf(
                            'Service %s cannot by initialized by factory %s; factory class does not exist',
                            $service,
                            $serviceFactory
                        ));
                    }

                    $factory = new $serviceFactory();

                    if (! is_callable($factory)) {
                        throw new ServiceNotFound(sprintf(
                            'Service %s cannot by initalized by factory %s; factory is not callable',
                            $service,
                            $serviceFactory
                        ));
                    }

                    return $factory($container, $service);
                };
                unset($dependencies['factories'][$service]);
            }

            if (isset($dependencies['invokables'])) {
                while (false !== ($key = array_search($service, $dependencies['invokables'], true))) {
                    // Marshal from invokable
                    $class = $dependencies['invokables'][$key];
                    $factory = function () use ($class) {
                        return new $class();
                    };
                    unset($dependencies['invokables'][$key]);

                    if ($key !== $service) {
                        $dependencies['aliases'][$key] = $service;
                    }
                }
            }

            if (! $factory) {
                continue;
            }

            $delegatorFactory = new DelegatorFactory($delegatorNames, $factory);
            $container->set(
                $service,
                $container->lazy([$delegatorFactory, 'build'], $container, $service)
            );
        }

        return $dependencies;
    }
}
