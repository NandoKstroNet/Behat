<?php

/*
 * This file is part of the Behat.
 * (c) Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Behat\Behat\Gherkin\ServiceContainer;

use Behat\Behat\Translator\ServiceContainer\TranslatorExtension;
use Behat\Testwork\Cli\ServiceContainer\CliExtension;
use Behat\Testwork\Filesystem\ServiceContainer\FilesystemExtension;
use Behat\Testwork\ServiceContainer\Exception\ExtensionException;
use Behat\Testwork\ServiceContainer\Extension;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Behat\Testwork\ServiceContainer\ServiceProcessor;
use Behat\Testwork\Specification\ServiceContainer\SpecificationExtension;
use Behat\Testwork\Suite\ServiceContainer\SuiteExtension;
use ReflectionClass;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Behat gherkin extension.
 *
 * Extends Behat with gherkin suites and features.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class GherkinExtension implements Extension
{
    /*
     * Available services
     */
    const MANAGER_ID = 'gherkin';
    const KEYWORDS_DUMPER_ID = 'gherkin.keywords_dumper';

    /*
     * Available extension points
     */
    const LOADER_TAG = 'gherkin.loader';

    /**
     * @var ServiceProcessor
     */
    private $processor;

    /**
     * Initializes extension.
     *
     * @param null|ServiceProcessor $processor
     */
    public function __construct(ServiceProcessor $processor = null)
    {
        $this->processor = $processor ? : new ServiceProcessor();
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigKey()
    {
        return 'gherkin';
    }

    /**
     * {@inheritdoc}
     */
    public function initialize(ExtensionManager $extensionManager)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function configure(ArrayNodeDefinition $builder)
    {
        $builder
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('cache')
            ->defaultValue(
                is_writable(sys_get_temp_dir())
                    ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gherkin_cache'
                    : null
            )
            ->end()
            ->arrayNode('filters')
            ->defaultValue(array())
            ->useAttributeAsKey('name')
            ->prototype('scalar')
            ->end()
            ->end()
            ->end();
    }

    /**
     * {@inheritdoc}
     */
    public function load(ContainerBuilder $container, array $config)
    {
        $this->loadParameters($container);
        $this->loadGherkin($container);
        $this->loadKeywords($container);
        $this->loadParser($container);
        $this->loadDefaultLoaders($container, $config['cache']);
        $this->loadProfileFilters($container, $config['filters']);
        $this->loadSyntaxController($container);
        $this->loadFilterController($container);
        $this->loadSuiteWithPathsSetup($container);
        $this->loadFilesystemFeatureLocator($container);
    }

    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        $this->processLoaders($container);
    }

    /**
     * Loads default container parameters.
     *
     * @param ContainerBuilder $container
     */
    protected function loadParameters(ContainerBuilder $container)
    {
        $container->setParameter('gherkin.paths.lib', $this->getLibPath());
        $container->setParameter('gherkin.paths.i18n', '%gherkin.paths.lib%/i18n.php');
        $container->setParameter(
            'suite.generic.default_settings',
            array(
                'paths'    => array('%paths.base%/features'),
                'contexts' => array('FeatureContext')
            )
        );
    }

    /**
     * Returns gherkin library path.
     *
     * @return string
     */
    protected function getLibPath()
    {
        $reflection = new ReflectionClass('Behat\Gherkin\Gherkin');
        $libPath = rtrim(dirname($reflection->getFilename()) . '/../../../', DIRECTORY_SEPARATOR);

        return $libPath;
    }

    /**
     * Loads gherkin service.
     *
     * @param ContainerBuilder $container
     */
    protected function loadGherkin(ContainerBuilder $container)
    {
        $definition = new Definition('Behat\Gherkin\Gherkin');
        $container->setDefinition(self::MANAGER_ID, $definition);
    }

    /**
     * Loads keyword services.
     *
     * @param ContainerBuilder $container
     */
    protected function loadKeywords(ContainerBuilder $container)
    {
        $definition = new Definition('Behat\Gherkin\Keywords\CachedArrayKeywords', array(
            '%gherkin.paths.i18n%'
        ));
        $container->setDefinition('gherkin.keywords', $definition);

        $definition = new Definition('Behat\Gherkin\Keywords\KeywordsDumper', array(
            new Reference('gherkin.keywords')
        ));
        $container->setDefinition(self::KEYWORDS_DUMPER_ID, $definition);
    }

    /**
     * Loads gherkin parser.
     *
     * @param ContainerBuilder $container
     */
    protected function loadParser(ContainerBuilder $container)
    {
        $definition = new Definition('Behat\Gherkin\Parser', array(
            new Reference('gherkin.lexer')
        ));
        $container->setDefinition('gherkin.parser', $definition);

        $definition = new Definition('Behat\Gherkin\Lexer', array(
            new Reference('gherkin.keywords')
        ));
        $container->setDefinition('gherkin.lexer', $definition);
    }

    /**
     * Loads gherkin loaders.
     *
     * @param ContainerBuilder $container
     * @param string           $cachePath
     */
    protected function loadDefaultLoaders(ContainerBuilder $container, $cachePath)
    {
        $definition = new Definition('Behat\Gherkin\Loader\GherkinFileLoader', array(
            new Reference('gherkin.parser')
        ));

        if ($cachePath) {
            $cacheDefinition = new Definition('Behat\Gherkin\Cache\FileCache', array($cachePath));
        } else {
            $cacheDefinition = new Definition('Behat\Gherkin\Cache\MemoryCache');
        }

        $definition->addMethodCall('setCache', array($cacheDefinition));
        $definition->addTag(self::LOADER_TAG, array('priority' => 50));
        $container->setDefinition('gherkin.loader.gherkin_file', $definition);
    }

    /**
     * Loads profile-level gherkin filters.
     *
     * @param ContainerBuilder $container
     * @param array            $filters
     */
    protected function loadProfileFilters(ContainerBuilder $container, array $filters)
    {
        $gherkin = $container->getDefinition(self::MANAGER_ID);
        foreach ($filters as $type => $filterString) {
            $filter = $this->createFilterDefinition($type, $filterString);
            $gherkin->addMethodCall('addFilter', array($filter));
        }
    }

    /**
     * Loads syntax controller.
     *
     * @param ContainerBuilder $container
     */
    protected function loadSyntaxController(ContainerBuilder $container)
    {
        $definition = new Definition('Behat\Behat\Gherkin\Cli\SyntaxController', array(
            new Reference(self::KEYWORDS_DUMPER_ID),
            new Reference(TranslatorExtension::TRANSLATOR_ID)
        ));
        $definition->addTag(CliExtension::CONTROLLER_TAG, array('priority' => 550));
        $container->setDefinition(CliExtension::CONTROLLER_TAG . '.gherkin_syntax', $definition);
    }

    /**
     * Loads filter controller.
     *
     * @param ContainerBuilder $container
     */
    protected function loadFilterController(ContainerBuilder $container)
    {
        $definition = new Definition('Behat\Behat\Gherkin\Cli\FilterController', array(
            new Reference(self::MANAGER_ID)
        ));
        $definition->addTag(CliExtension::CONTROLLER_TAG, array('priority' => 700));
        $container->setDefinition(CliExtension::CONTROLLER_TAG . '.gherkin_filters', $definition);
    }

    /**
     * Loads suite with paths setup.
     *
     * @param ContainerBuilder $container
     */
    protected function loadSuiteWithPathsSetup(ContainerBuilder $container)
    {
        $definition = new Definition('Behat\Behat\Gherkin\Suite\Setup\SuiteWithPathsSetup', array(
            '%paths.base%',
            new Reference(FilesystemExtension::LOGGER_ID)
        ));
        $definition->addTag(SuiteExtension::SETUP_TAG, array('priority' => 50));
        $container->setDefinition(SuiteExtension::SETUP_TAG . '.suite_with_paths', $definition);
    }

    /**
     * Loads filesystem feature locator.
     *
     * @param ContainerBuilder $container
     */
    protected function loadFilesystemFeatureLocator(ContainerBuilder $container)
    {
        $definition = new Definition('Behat\Behat\Gherkin\Specification\Locator\FilesystemFeatureLocator', array(
            new Reference(self::MANAGER_ID),
            '%paths.base%'
        ));
        $definition->addTag(SpecificationExtension::LOCATOR_TAG, array('priority' => 50));
        $container->setDefinition(SpecificationExtension::LOCATOR_TAG . '.filesystem_feature', $definition);
    }

    /**
     * Processes all available gherkin loaders.
     *
     * @param ContainerBuilder $container
     */
    protected function processLoaders(ContainerBuilder $container)
    {
        $references = $this->processor->findAndSortTaggedServices($container, self::LOADER_TAG);
        $definition = $container->getDefinition(self::MANAGER_ID);

        foreach ($references as $reference) {
            $definition->addMethodCall('addLoader', array($reference));
        }
    }

    /**
     * Creates filter definition of provided type.
     *
     * @param string $type
     * @param string $filterString
     *
     * @return Definition
     *
     * @throws ExtensionException If filter type is not recognised
     */
    protected function createFilterDefinition($type, $filterString)
    {
        if ('role' === $type) {
            return new Definition('Behat\Gherkin\Filter\RoleFilter', array($filterString));
        }

        if ('name' === $type) {
            return new Definition('Behat\Gherkin\Filter\NameFilter', array($filterString));
        }

        if ('tags' === $type) {
            return new Definition('Behat\Gherkin\Filter\TagFilter', array($filterString));
        }

        throw new ExtensionException(sprintf(
            '`%s` filter is not supported by the `filters` option of gherkin extension. Supported types are %s.',
            $type,
            implode(', ', array('`role`', '`name`', '`tags`'))
        ), 'gherkin');
    }
}
