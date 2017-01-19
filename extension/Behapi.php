<?php
namespace Behapi\Extension;

use Behat\Testwork\ServiceContainer\Extension;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Behat\Testwork\Cli\ServiceContainer\CliExtension;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use GuzzleHttp\Client;
use GuzzleHttp\Subscriber\History;

use Predis\Client as RedisClient;

use Twig_Environment;
use Twig_Loader_Chain;

use Behapi\Extension\Tools\Bag;
use Behapi\Extension\Tools\Debug;
use Behapi\Extension\Tools\GuzzleFactory;

use Behapi\Extension\Cli\DebugController;
use Behapi\Extension\EventListener\Cleaner;

use Behapi\Extension\Initializer\Api;
use Behapi\Extension\Initializer\RedisAware;
use Behapi\Extension\Initializer\TwigInitializer;
use Behapi\Extension\Initializer\RestAuthentication;
use Behapi\Extension\Initializer\Wiz as WizInitializer;

/**
 * Extension which feeds the dependencies of behapi's features
 *
 * @author Baptiste Clavié <clavie.b@gmail.com>
 */
class Behapi implements Extension
{
    /** {@inheritDoc} */
    public function getConfigKey()
    {
        return 'behapi';
    }

    /** {@inheritDoc} */
    public function configure(ArrayNodeDefinition $builder)
    {
        $builder
            ->children()
                ->scalarNode('base_url')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()

                ->enumNode('environment')
                    ->values(['dev', 'test'])
                    ->defaultValue('dev')
                ->end()

                ->scalarNode('debug_formatter')
                    ->defaultValue('pretty')
                ->end()

                // TODO: add redis config here ?

                ->arrayNode('app')
                    ->children()
                        ->scalarNode('id')
                            ->info('Application ID to use')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()

                        ->scalarNode('secret')
                            ->info('Application Secret to use')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end();

    }

    /** {@inheritDoc} */
    public function initialize(ExtensionManager $extensionManager)
    {
    }

    /** {@inheritDoc} */
    public function load(ContainerBuilder $container, array $config)
    {
        $this->loadDebug($container, $config);

        $this->loadGuzzle($container, $config['base_url']);
        unset($config['base_url']);

        $this->loadRedis($container);
        $this->loadSubscribers($container);
        $this->loadTwig($container, $config['environment']);

        $this->loadInitializers($container, $config);
    }

    /** {@inheritDoc} */
    public function process(ContainerBuilder $container)
    {
        $definition = $container->getDefinition('behapi.subscriber.cleaner');

        foreach ($container->findTaggedServiceIds('behapi.bag') as $id => $tags) {
            foreach ($tags as $tag) {
                if (!isset($tag['reset']) || true !== $tag['reset']) {
                    continue;
                }

                $definition->addMethodCall('addBag', [new Reference($id)]);
            }
        }
    }

    private function loadDebug(ContainerBuilder $container, array $config)
    {
        $container->register('behapi.debug', Debug::class);

        $container->register('behapi.controller.debug', DebugController::class)
            ->addArgument(new Reference('output.manager'))
            ->addArgument(new Reference('behapi.debug'))
            ->addArgument($config['debug_formatter'])
            ->addTag(CliExtension::CONTROLLER_TAG, ['priority' => 10])
        ;
    }

    private function loadSubscribers(ContainerBuilder $container)
    {
        $container->register('behapi.subscriber.cleaner', Cleaner::class)
            ->addArgument(new Reference('guzzle.history'))
            ->addTag('event_dispatcher.subscriber')
        ;
    }

    private function loadRedis(ContainerBuilder $container)
    {
        // TODO: configure redis clients through the config tree
        $container->register('predis.client', RedisClient::class);
    }

    private function loadGuzzle(ContainerBuilder $container, $baseUrl)
    {
        $config = [
            'base_url' => $baseUrl,

            'defaults' => [
                'allow_redirects' => false,
                'exceptions' => false
            ]
        ];

        $container->register('guzzle.history', History::class)
            ->addArgument(1); // note : limit on the last request only ?

        $factory = new Definition(GuzzleFactory::class);
        $factory
            ->addMethodCall('addSubscriber', [
                new Reference('guzzle.history')
            ])
        ;

        $container->register('guzzle.client', Client::class)
            ->addArgument($config)
            ->setFactory([$factory, 'getClient']);
    }

    private function loadInitializers(ContainerBuilder $container, array $config)
    {
        $container->register('behapi.initializer.wiz', WizInitializer::class)
            ->addArgument($config['environment'])
            ->addArgument(new Reference('behapi.debug'))
            ->addTag('context.initializer')
        ;

        $container->register('behapi.initializer.api', Api::class)
            ->addArgument(new Reference('guzzle.client'))
            ->addArgument(new Reference('guzzle.history'))
            ->addTag('context.initializer')
        ;

        $container->register('wiz.initializer.redis', RedisAware::class)
            ->addArgument(new Reference('predis.client'))
            ->addTag('context.initializer')
        ;

        $container->register('wiz.initializer.authentication', RestAuthentication::class)
            ->addArgument($config['app']['id'])
            ->addArgument($config['app']['secret'])
            ->addTag('context.initializer')
        ;

        if (class_exists(Twig_Environment::class)) {
            $container->register('behapi.initializer.twig', TwigInitializer::class)
                ->addArgument(new Reference('twig'))
                ->addTag('context.initializer')
            ;
        }
    }

    private function loadTwig(ContainerBuilder $container, $environment)
    {
        if (!class_exists(Twig_Environment::class)) {
            return;
        }

        $container->register('twig.loader', Twig_Loader_Chain::class);

        $container->register('twig', Twig_Environment::class)
            ->addArgument(new Reference('twig.loader'))
            ->addArgument([
                'debug' => 'dev' === $environment,
                'cache' => sprintf('%s/../../app/cache/%s/twig/behat', __DIR__, $environment),
                'autoescape' => false
            ]);
    }
}
