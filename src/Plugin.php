<?php
/**
 * Composer plugin for config assembling
 *
 * @link      https://github.com/hiqdev/composer-config-plugin
 * @package   composer-config-plugin
 * @license   BSD-3-Clause
 * @copyright Copyright (c) 2016-2018, HiQDev (http://hiqdev.com/)
 */

namespace hiqdev\composer\config;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use hiqdev\composer\config\exceptions\BadConfigurationException;
use hiqdev\composer\config\exceptions\FailedReadException;
use hiqdev\composer\config\readers\ReaderFactory;

/**
 * Plugin class.
 *
 * @author Andrii Vasyliev <sol@hiqdev.com>
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var Package[] the array of active composer packages
     */
    protected $packages;

    private $alternatives = [];

    private $outputDir;

    private $rootPackage;

    /**
     * @var array config name => list of files
     */
    protected $files = [
        'dotenv'  => [],
        'defines' => [],
        'params'  => [],
    ];

    protected $colors = ['red', 'green', 'yellow', 'cyan', 'magenta', 'blue'];

    /**
     * @var array package name => configs as listed in `composer.json`
     */
    protected $originalFiles = [];

    /**
     * @var Builder
     */
    protected $builder;

    /**
     * @var Composer instance
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    public $io;

    /**
     * Initializes the plugin object with the passed $composer and $io.
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * Returns list of events the plugin is subscribed to.
     * @return array list of events
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_AUTOLOAD_DUMP => [
                ['onPostAutoloadDump', 0],
            ],
        ];
    }

    /**
     * This is the main function.
     * @param Event $event
     */
    public function onPostAutoloadDump(Event $event)
    {
        $this->io->overwriteError('<info>Assembling config files</info>');

        $this->builder = new Builder();

        $this->initAutoload();
        $this->scanPackages();
        $this->reorderFiles();
        $this->showDepsTree();

        $this->builder->setOutputDir($this->outputDir);
        $this->builder->buildAllConfigs($this->files);

        $save = $this->files;
        foreach ($this->alternatives as $name => $files) {
            $this->files = $save;
            $builder = $this->builder->createAlternative($name);
            $this->addFiles($this->rootPackage, $files);
            $builder->buildAllConfigs($this->files);
        }
    }

    protected function initAutoload()
    {
        $dir = dirname(dirname(dirname(__DIR__)));
        require_once "$dir/autoload.php";
    }

    protected function scanPackages()
    {
        foreach ($this->getPackages() as $package) {
            if ($package->isComplete()) {
                $this->processPackage($package);
            }
        }
    }

    protected function reorderFiles(): void
    {
        foreach (array_keys($this->files) as $name) {
            $this->files[$name] = $this->getAllFiles($name);
        }
        foreach ($this->files as $name => $files) {
            $this->files[$name] = $this->orderFiles($files);
        }
    }

    protected function getAllFiles(string $name): array
    {
        if (empty($this->files[$name])) {
            return[];
        }
        $res = [];
        foreach ($this->files[$name] as $file) {
            if (strncmp($file, '$', 1) === 0) {
                $res = array_merge($res, $this->getAllFiles(substr($file, 1)));
            } else {
                $res[] = $file;
            }
        }

        return $res;
    }

    protected function orderFiles(array $files): array
    {
        if (empty($files)) {
            return [];
        }
        $keys = array_combine($files, $files);
        $res = [];
        foreach ($this->orderedFiles as $file) {
            if (isset($keys[$file])) {
                $res[] = $file;
            }
        }

        return $res;
    }

    /**
     * Scans the given package and collects packages data.
     * @param Package $package
     */
    protected function processPackage(Package $package)
    {
        $files = $package->getFiles();
        $this->originalFiles[$package->getPrettyName()] = $files;

        if (!empty($files)) {
            $this->addFiles($package, $files);
        }
        if ($package->isRoot()) {
            $this->rootPackage = $package;
            $this->loadDotEnv($package);
            $devFiles = $package->getDevFiles();
            if (!empty($devFiles)) {
                $this->addFiles($package, $devFiles);
            }
            $this->outputDir = $package->getOutputDir();
            $alternatives = $package->getAlternatives();
            if (is_string($alternatives)) {
                $this->alternatives = $this->readConfig($package, $alternatives);
            } elseif (is_array($alternatives)) {
                $this->alternatives = $alternatives;
            } elseif (!empty($alternatives)) {
                throw new BadConfigurationException('alternatives must be array or path to configuration file');
            }
        }

        $aliases = $package->collectAliases();

        $this->builder->mergeAliases($aliases);
        $this->builder->setPackage($package->getPrettyName(), array_filter([
            'name' => $package->getPrettyName(),
            'version' => $package->getVersion(),
            'reference' => $package->getSourceReference() ?: $package->getDistReference(),
            'aliases' => $aliases,
        ]));
    }

    private function readConfig($package, $file): array
    {
        $path = $package->preparePath($file);
        if (!file_exists($path)) {
            throw new FailedReadException("failed read file: $file");
        }
        $reader = ReaderFactory::get($this->builder, $path);

        return $reader->read($path);

    }

    protected function loadDotEnv(Package $package)
    {
        $path = $package->preparePath('.env');
        if (file_exists($path) && class_exists('Dotenv\Dotenv')) {
            $this->addFile($package, 'dotenv', $path);
        }
    }

    /**
     * Adds given files to the list of files to be processed.
     * Prepares `defines` in reversed order (outer package first) because
     * constants cannot be redefined.
     * @param Package $package
     * @param array $files
     */
    protected function addFiles(Package $package, array $files)
    {
        foreach ($files as $name => $paths) {
            $paths = (array) $paths;
            if ('defines' === $name) {
                $paths = array_reverse($paths);
            }
            foreach ($paths as $path) {
                $this->addFile($package, $name, $path);
            }
        }
    }

    protected $orderedFiles = [];

    protected function addFile(Package $package, string $name, string $path)
    {
        $path = $package->preparePath($path);
        if (!isset($this->files[$name])) {
            $this->files[$name] = [];
        }
        if (in_array($path, $this->files[$name], true)) {
            return;
        }
        if ('defines' === $name) {
            array_unshift($this->orderedFiles, $path);
            array_unshift($this->files[$name], $path);
        } else {
            array_push($this->orderedFiles, $path);
            array_push($this->files[$name], $path);
        }
    }

    /**
     * Sets [[packages]].
     * @param Package[] $packages
     */
    public function setPackages(array $packages)
    {
        $this->packages = $packages;
    }

    /**
     * Gets [[packages]].
     * @return Package[]
     */
    public function getPackages()
    {
        if (null === $this->packages) {
            $this->packages = $this->findPackages();
        }

        return $this->packages;
    }

    /**
     * Plain list of all project dependencies (including nested) as provided by composer.
     * The list is unordered (chaotic, can be different after every update).
     */
    protected $plainList = [];

    /**
     * Ordered list of package in form: package => depth
     * For order description @see findPackages.
     */
    protected $orderedList = [];

    /**
     * Returns ordered list of packages:
     * - listed earlier in the composer.json will get earlier in the list
     * - childs before parents.
     * @return Package[]
     */
    public function findPackages()
    {
        $root = new Package($this->composer->getPackage(), $this->composer);
        $this->plainList[$root->getPrettyName()] = $root;
        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages() as $package) {
            $this->plainList[$package->getPrettyName()] = new Package($package, $this->composer);
        }
        $this->orderedList = [];
        $this->iteratePackage($root, true);

        $res = [];
        foreach (array_keys($this->orderedList) as $name) {
            $res[] = $this->plainList[$name];
        }

        return $res;
    }

    /**
     * Iterates through package dependencies.
     * @param Package $package to iterate
     * @param bool $includingDev process development dependencies, defaults to not process
     */
    protected function iteratePackage(Package $package, $includingDev = false)
    {
        $name = $package->getPrettyName();

        /// prevent infinite loop in case of circular dependencies
        static $processed = [];
        if (isset($processed[$name])) {
            return;
        } else {
            $processed[$name] = 1;
        }

        /// package depth in dependency hierarchy
        static $depth = 0;
        ++$depth;

        $this->iterateDependencies($package);
        if ($includingDev) {
            $this->iterateDependencies($package, true);
        }
        if (!isset($this->orderedList[$name])) {
            $this->orderedList[$name] = $depth;
        }

        --$depth;
    }

    /**
     * Iterates dependencies of the given package.
     * @param Package $package
     * @param bool $dev which dependencies to iterate: true - dev, default - general
     */
    protected function iterateDependencies(Package $package, $dev = false)
    {
        $deps = $dev ? $package->getDevRequires() : $package->getRequires();
        foreach (array_keys($deps) as $target) {
            if (isset($this->plainList[$target]) && empty($this->orderedList[$target])) {
                $this->iteratePackage($this->plainList[$target]);
            }
        }
    }

    protected function showDepsTree()
    {
        if (!$this->io->isVerbose()) {
            return;
        }

        foreach (array_reverse($this->orderedList) as $name => $depth) {
            $deps = $this->originalFiles[$name];
            $color = $this->colors[$depth % count($this->colors)];
            $indent = str_repeat('   ', $depth - 1);
            $package = $this->plainList[$name];
            $showdeps = $deps ? '<comment>[' . implode(',', array_keys($deps)) . ']</>' : '';
            $this->io->write(sprintf('%s - <fg=%s;options=bold>%s</> %s %s', $indent, $color, $name, $package->getFullPrettyVersion(), $showdeps));
        }
    }
}
