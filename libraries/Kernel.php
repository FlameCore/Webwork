<?php
/**
 * Infernum
 * Copyright (C) 2015 IceFlame.net
 *
 * Permission to use, copy, modify, and/or distribute this software for
 * any purpose with or without fee is hereby granted, provided that the
 * above copyright notice and this permission notice appear in all copies.
 *
 * @package  FlameCore\Infernum
 * @version  0.1-dev
 * @link     http://www.flamecore.org
 * @license  http://opensource.org/licenses/ISC ISC License
 */

namespace FlameCore\Infernum;

use FlameCore\Infernum\Configuration\SystemConfiguration;
use FlameCore\Infernum\Exceptions\ModuleNotInstalledException;
use FlameCore\Infernum\Exceptions\PluginNotInstalledException;
use FlameCore\Infernum\Exceptions\RouteNotFoundException;
use FlameCore\Infernum\Interfaces\ExtensionMeta;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Infernum Kernel
 *
 * @author   Christian Neff <christian.neff@gmail.com>
 */
final class Kernel implements \ArrayAccess
{
    /**
     * @var string
     */
    private $path;

    /**
     * @var bool
     */
    private $booted = false;

    /**
     * @var \FlameCore\Infernum\Container
     */
    private $container;

    /**
     * @var string|bool
     */
    private $domain = false;

    /**
     * @var bool
     */
    private $secure = false;

    /**
     * @var string|bool
     */
    private $pagePath = false;

    /**
     * @var \FlameCore\Infernum\Interfaces\ExtensionMeta|bool
     */
    private $runningExtension = false;

    /**
     * @var string|bool
     */
    private $loadedModule = false;

    /**
     * @var \FlameCore\Infernum\Plugin[]
     */
    private $loadedPlugins = array();

    /**
     * Initializes the Kernel.
     *
     * @param string $path The engine path
     */
    public function __construct($path)
    {
        $this->path = $path;

        $this->container = new Container('kernel', [
            'config' => 'array',
            'loader' => '\FlameCore\Infernum\ClassLoader',
            'logger' => '\Psr\Log\LoggerInterface',
            'cache' => '\FlameCore\Infernum\Cache',
            'router' => '\FlameCore\Infernum\Router'
        ]);

        set_error_handler([$this, 'handleError']);

        $this['cache'] = new Cache($this->getCachePath());

        $this['config'] = $this->cache('config', [$this, 'loadConfiguration']);

        $this['logger'] = new Logger('system', $this);
        $this['router'] = new Router($this);
    }

    /**
     * Gets the system path.
     *
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Returns whether the kernel is reads.
     *
     * @return bool
     */
    public function isReady()
    {
        return $this->booted;
    }

    /**
     * Gets the domain.
     *
     * @return bool|string
     */
    public function getDomain()
    {
        return $this->domain;
    }

    /**
     * Returns whether the connection is secure.
     *
     * @return bool
     */
    public function isSecure()
    {
        return $this->secure;
    }

    /**
     * Gets the requested page path.
     *
     * @return string|bool Returns the requested page path or FALSE if no request is handled yet.
     * @api
     */
    public function getPagePath()
    {
        return $this->pagePath;
    }

    /**
     * Gets the currently running extension.
     *
     * @return \FlameCore\Infernum\Interfaces\ExtensionMeta|bool Returns an abstraction object of the running extension or FALSE if no extension is running.
     * @api
     */
    public function getRunningExtension()
    {
        return $this->runningExtension;
    }

    /**
     * Gets the loaded module.
     *
     * @return string|bool Returns the name of the loaded module or FALSE if no module is loaded yet.
     * @api
     */
    public function getLoadedModule()
    {
        return $this->loadedModule;
    }

    /**
     * Lists all loaded plugins.
     *
     * @return array Returns an array of loaded plugins.
     * @api
     */
    public function getLoadedPlugins()
    {
        return $this->loadedPlugins;
    }

    /**
     * Returns the value of a setting.
     *
     * @param string $key The settings key in the form `<section>[:<keyname>]`
     * @param mixed $default Custom default value (optional)
     * @return mixed
     * @api
     */
    public function config($key, $default = false)
    {
        return isset($this['config'][$key]) ? $this['config'][$key] : $default;
    }

    /**
     * Handles the request.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request The request
     * @param \FlameCore\Infernum\Application $app The application context
     * @throws \LogicException if the kernel is not yet booted.
     * @api
     */
    public function handle(Request $request, Application $app)
    {
        if (!$this->isReady()) {
            throw new \LogicException('Kernel must be booted to handle requests.');
        }

        try {
            $this->pagePath = $request->query->get('p', '');

            if ($result = $this['router']->parse($this->pagePath)) {
                $module = $result['module'];
                $action = $result['action'];
                $arguments = $result['arguments'];
                $extra = $result['extra'];
            } else {
                list($module, $action) = explode(':', $app->setting('site.frontpage'));
                $arguments = null;
                $extra = null;
            }

            $module = $this->loadModule($module, $extra);

            foreach ($this->loadedPlugins as $plugin) {
                $this->runningExtension = $plugin;
                $plugin->run($app);
            }

            $this->runningExtension = $module;

            $response = $module->run($app, $request, $action, $arguments);
        } catch (RouteNotFoundException $e) {
            foreach ($this->loadedPlugins as $plugin) {
                $this->runningExtension = $plugin;
                $plugin->run($app);
            }

            $this->runningExtension = false;

            $view = new View('@global/404_body', $app);
            $response = new Response($view->render(), 404);
        }

        $response->prepare($request);
        $app->finalize($response);

        $response->send();
    }

    /**
     * Starts up the system.
     *
     * @param \Symfony\Component\HttpFoundation\Request $request The request
     * @return \FlameCore\Infernum\Site Returns the object of the recognized site.
     * @throws \RuntimeException if the site depends on a module or plugin which is not installed.
     * @api
     */
    public function boot(Request $request)
    {
        $this->domain = $request->server->get('SERVER_NAME');
        $this->secure = $request->isSecure();

        if ($this->config('enable_multisite') && $sites = $this->config('sites')) {
            // This is a multi-site installation, so we need to know the current domain name
            // Check if there is a site for the current domain, fall back to default site otherwise
            if (isset($sites[$this->domain])) {
                $sitename = $sites[$this->domain];
            } else {
                $sitename = $this->config('default_site', 'default');
            }
        } else {
            // This is a single-site installation, hence we use the default site
            $sitename = 'default';
        }

        $site = new Site($sitename, $this);

        try {
            foreach ($site->getPlugins() as $plugin) {
                $this->loadPlugin($plugin);
            }
        } catch (PluginNotInstalledException $e) {
            throw new \RuntimeException(sprintf('Site "%s" depends on plugin "%s" but it is not installed.', $sitename, $e->getPluginName()));
        }

        foreach ($site->getRoutes() as $route) {
            if (!$this->moduleExists($route['module'])) {
                throw new \RuntimeException(sprintf('Site "%s" depends on module "%s" but it is not installed.', $sitename, $route['module']));
            }

            $alias = isset($route['alias']) ? $route['alias'] : null;
            $extra = isset($route['extra']) ? $route['extra'] : null;
            $this['router']->mountModule($route['module'], $alias, $extra);
        }

        $this->booted = true;

        return $site;
    }

    /**
     * Loads the given module.
     *
     * @param string $moduleName The module name
     * @param mixed $extra The extra options (optional)
     * @return \FlameCore\Infernum\Module Returns the Module object.
     * @throws ModuleNotInstalledException if the module does not exist.
     * @throws \LogicException if the module's information could not be loaded.
     * @throws \RuntimeException if the module depends on a plugin which is not installed.
     * @api
     */
    public function loadModule($moduleName, $extra = null)
    {
        if (!$this->moduleExists($moduleName)) {
            throw new ModuleNotInstalledException($moduleName);
        }

        $module = new Module($moduleName, $this, $extra);

        try {
            foreach ($module->getRequiredPlugins() as $plugin) {
                $this->loadPlugin($plugin);
            }
        } catch (PluginNotInstalledException $e) {
            throw new \RuntimeException(sprintf('Module "%s" depends on plugin "%s" but it is not installed.', $moduleName, $e->getPluginName()));
        }

        $this->prepareExtension($module);

        $this->loadedModule = $moduleName;

        return $module;
    }

    /**
     * Checks whether a module exists.
     *
     * @param string $moduleName The module name
     * @return bool
     * @api
     */
    public function moduleExists($moduleName)
    {
        return is_dir($this->getModulePath($moduleName));
    }

    /**
     * Returns the path of the given module.
     *
     * @param string $moduleName The module name
     * @return string
     * @api
     */
    public function getModulePath($moduleName)
    {
        return $this->path.'/modules/'.$moduleName;
    }

    /**
     * Loads the given plugin.
     *
     * @param string $pluginName The plugin name
     * @return \FlameCore\Infernum\Plugin
     * @throws PluginNotInstalledException if the plugin does not exist.
     * @throws \LogicException if the plugin's information could not be loaded.
     * @api
     */
    public function loadPlugin($pluginName)
    {
        if (!isset($this->loadedPlugins[$pluginName])) {
            if (!$this->pluginExists($pluginName)) {
                throw new PluginNotInstalledException($pluginName);
            }

            $plugin = new Plugin($pluginName, $this);

            $this->prepareExtension($plugin);
            $plugin->boot();

            $this->loadedPlugins[$pluginName] = $plugin;
        } else {
            $plugin = $this->loadedPlugins[$pluginName];
        }

        return $plugin;
    }

    /**
     * Checks whether a plugin exists.
     *
     * @param string $pluginName The plugin name
     * @return bool
     * @api
     */
    public function pluginExists($pluginName)
    {
        return is_dir($this->getPluginPath($pluginName));
    }

    /**
     * Returns the path of the given plugin.
     *
     * @param string $pluginName The plugin name
     * @return string
     * @api
     */
    public function getPluginPath($pluginName)
    {
        return $this->path.'/plugins/'.$pluginName;
    }

    /**
     * Reads data from cache. The $callback is used to generate the data if missing or expired.
     *
     * @param string $name The name of the cache file
     * @param callable $callback The callback function that returns the data to store
     * @param int $lifetime The lifetime in seconds (Default: 86400)
     * @return mixed
     * @api
     */
    public function cache($name, callable $callback, $lifetime = 86400)
    {
        if ($this['cache']->contains($name)) {
            // We were able to retrieve data
            return $this['cache']->get($name);
        } else {
            // No data, so we use the given data callback and store the value
            $data = $callback();
            $this['cache']->set($name, $data, (int) $lifetime);
            return $data;
        }
    }

    /**
     * Gets the path to the cache directory. The directory is created, if it does not exist on the filesystem.
     *
     * @param string $subpath A sub path insiside base cache path (optional)
     * @return string Returns the full cache path.
     * @api
     */
    public function getCachePath($subpath = null)
    {
        $cachePath = $this->path.'/cache';

        if (isset($subpath)) {
            $cachePath .= '/'.$subpath;
        }

        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0777, true);
        }

        return $cachePath;
    }

    /**
     * Handles an error.
     *
     * @param int $code The error code
     * @param string $message The error message
     * @param string $file The file
     * @param int $line The line
     * @return bool
     * @throws \ErrorException
     * @internal
     */
    public function handleError($code, $message, $file, $line)
    {
        switch ($code) {
            case E_ERROR:
            case E_USER_ERROR:
            case E_RECOVERABLE_ERROR:
                throw new \ErrorException($message, $code, 2, $file, $line);

            case E_WARNING:
            case E_USER_WARNING:
                $this['logger']->warning($message);
        }

        return true;
    }

    /**
     * Loads the configuration.
     *
     * @return array
     * @throws \LogicException if the configuration file does not exist.
     * @throws \RuntimeException if the configuration could not be loaded.
     * @internal
     */
    public function loadConfiguration()
    {
        try {
            $config = new SystemConfiguration($this->path.'/config.yml');
            return $config->load();
        } catch (\Exception $e) {
            throw new \RuntimeException(sprintf('Unable to load system configuration: %s', $e->getMessage()));
        }
    }

    /**
     * Returns the value with specified key.
     *
     * @param string $offset The name of the key
     * @return mixed
     */
    public function offsetGet($offset)
    {
        return $this->container->get($offset);
    }

    /**
     * Returns whether or not a key exists.
     *
     * @param string $offset The name of the key
     * @return bool
     */
    public function offsetExists($offset)
    {
        return $this->container->has($offset);
    }

    /**
     * Assigns a value to the specified key.
     *
     * @param string $offset The name of the key
     * @param mixed $value The value to assign
     * @throws \InvalidArgumentException if a key with empty name should be set or if the value for a given internal key is invalid.
     * @throws \LogicException if an internal key should be overridden, which is not allowed.
     */
    public function offsetSet($offset, $value)
    {
        $this->container->set($offset, $value, true);
    }

    /**
     * Unsets the specified key.
     *
     * @param string $offset The name of the key
     * @throws \LogicException if the given key is an internal key, which cannot be unset.
     */
    public function offsetUnset($offset)
    {
        $this->container->remove($offset);
    }

    /**
     * Prepares the given extension.
     *
     * @param \FlameCore\Infernum\Interfaces\ExtensionMeta $extension The extension
     */
    protected function prepareExtension(ExtensionMeta $extension)
    {
        if (isset($this['loader']) && $extension->provides('libraries')) {
            $this['loader']->addSource($extension->getNamespace(), $extension->getPath());
        }
    }
}
