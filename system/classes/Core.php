<?php
/**
 * Contains the most low-level helpers methods in Modseven:
 *
 * - Environment initialization
 * - Locating files within the cascading filesystem
 * - Auto-loading and transparent extension of classes
 * - Variable and path debugging
 *
 * @package    Modseven
 * @category   Base
 *
 * @copyright  (c) 2007-2016  Kohana Team
 * @copyright  (c) 2016-2019  Koseven Team
 * @copyright  (c) since 2019 Modseven Team
 * @license    https://koseven.ga/LICENSE
 */

namespace Modseven;

use JsonException;
use DirectoryIterator;
use Composer\Autoload\ClassLoader;

class Core
{
    // Release version and codename
    public const VERSION = '1.0.0';
    public const CODENAME = 'waehring';

    // Common environment type constants for consistency and convenience
    public const PRODUCTION = 10;
    public const STAGING = 20;
    public const TESTING = 30;
    public const DEVELOPMENT = 40;

    /**
     * Current environment name
     * @var int
     */
    public static int $environment = Core::PRODUCTION;

    /**
     * True if Modseven is running on windows
     * @var boolean
     */
    public static bool $is_windows = false;

    /**
     * Content Type of input and output
     * @var string
     */
    public static string $content_type = 'text/html';

    /**
     * Character set of input and output
     * @var string
     */
    public static string $charset = 'utf-8';

    /**
     * The name of the server Modseven is hosted upon
     * @var string
     */
    public static string $server_name = '';

    /**
     * list of valid host names for this instance
     * @var array
     */
    public static array $hostnames = [];

    /**
     * base URL to the application
     * @var string
     */
    public static string $base_url = '/';

    /**
     * Application index file, added to links generated by Modseven. Set by [Modseven::init]
     * @var string
     */
    public static string $index_file = 'index.php';

    /**
     * Whether to use caching for internal application functions or not
     * @var boolean
     */
    public static bool $caching = false;

    /**
     * Whether to enable [profiling](Modseven/profiling). Set by [Modseven::init]
     * @var boolean
     */
    public static bool $profiling = true;

    /**
     * Enable Modseven catching and displaying PHP errors and exceptions. Set by [Modseven::init]
     * @var boolean
     */
    public static bool $errors = true;

    /**
     * Types of errors to display at shutdown
     * @var array
     */
    public static array $shutdown_errors = [E_PARSE, E_ERROR, E_USER_ERROR];

    /**
     * set the X-Powered-By header
     * @var boolean
     */
    public static bool $expose = false;

    /**
     * logging object
     * @var  Log|null
     */
    public static ?Log $log = null;

    /**
     * config object
     * @var  Config|null
     */
    public static ?Config $config = null;

    /**
     * Composer Autoloader Object
     * @var ClassLoader
     */
    public static ClassLoader $autoloader;

    /**
     * Has [Modseven::init] been called?
     * @var boolean
     */
    protected static bool $_init = false;

    /**
     * Currently active modules
     * @var array
     */
    protected static array $_modules = [];

    /**
     * Include paths that are used to find files
     * @var  array
     */
    protected static array $_paths = [APPPATH, SYSPATH];

    /**
     * File path cache, used when caching is true in [Modseven::init]
     * @var  array
     */
    protected static array $_files = [];

    /**
     * Has the file path cache changed during this execution?  Used internally when when caching is true in [Modseven::init]
     * @var boolean
     */
    protected static bool $_files_changed = false;

    /**
     * Initializes the environment:
     *
     * - Determines the current environment
     * - Set global settings
     * - Sanitizes GET, POST, and COOKIE variables
     * - Converts GET, POST, and COOKIE variables to the global character set
     *
     * @param array $settings Array of settings.  See above.
     *
     * @throws  Exception
     */
    public static function init(?array $settings = NULL): void
    {
        if (static::$_init) {
            // Do not allow execution twice
            return;
        }

        // Modseven is now initialized
        static::$_init = TRUE;

        if (isset($settings['profile'])) {
            // Enable profiling
            static::$profiling = (bool)$settings['profile'];
        }

        // Start an output buffer
        ob_start();

        if (isset($settings['errors'])) {
            // Enable error handling
            static::$errors = (bool)$settings['errors'];
        }

        if (static::$errors === TRUE) {
            // Enable Modseven exception handling, adds stack traces and error source.
            set_exception_handler([Exception::class, 'handler']);

            // Enable Modseven error handling, converts all PHP errors to exceptions.
            set_error_handler([__CLASS__, 'error_handler']);
        }

        /**
         * Enable xdebug parameter collection in development mode to improve fatal stack traces.
         */
        if (static::$environment === static::DEVELOPMENT && extension_loaded('xdebug')) {
            ini_set('xdebug.collect_params', 3);
        }

        // Enable the Modseven shutdown handler, which catches E_FATAL errors.
        register_shutdown_function([__CLASS__, 'shutdown_handler']);

        if (isset($settings['expose'])) {
            static::$expose = (bool)$settings['expose'];
        }

        // Determine if we are running in a Windows environment
        static::$is_windows = (DIRECTORY_SEPARATOR === '\\');

        if (isset($settings['caching']))
        {
            // Enable or disable internal caching
            static::$caching = (bool)$settings['caching'];
        }

        // Initialize the Modules
        self::initModules();

        if (static::$caching === TRUE)
        {
            // Load the file path cache
            static::$_files = self::cache('\Modseven\Core::find_file()') ?? [];
        }

        if (isset($settings['charset'])) {
            // Set the system character set
            static::$charset = strtolower($settings['charset']);
        }

        if (function_exists('mb_internal_encoding')) {
            // Set the MB extension encoding to the same character set
            mb_internal_encoding(static::$charset);
        }

        if (isset($settings['base_url'])) {
            // Set the base URL
            static::$base_url = rtrim($settings['base_url'], '/') . '/';
        }

        if (isset($settings['index_file'])) {
            // Set the index file
            static::$index_file = trim($settings['index_file'], '/');
        }

        // Sanitize all request variables
        $_GET = self::sanitize($_GET);
        $_POST = self::sanitize($_POST);
        $_COOKIE = self::sanitize($_COOKIE);

        // Load the logger if one doesn't already exist
        if (!static::$log instanceof Log) {
            static::$log = Log::instance();
        }

        // Load the config if one doesn't already exist
        if (!static::$config instanceof Config) {
            static::$config = new Config;
        }
    }

    /**
     * Cache variables using current cache module
     *
     * @param string $name name of the cache
     * @param mixed $data data to cache
     * @param integer $lifetime number of seconds the cache is valid for
     *
     * @return  mixed    for getting
     *
     * @throws  Exception
     */
    public static function cache(string $name, $data = NULL, ?int $lifetime = NULL)
    {
        // deletes cache if lifetime expired
        if ($lifetime === 0) {
            return Cache::instance()->delete($name);
        }

        //no data provided we read
        if ($data === NULL) {
            return Cache::instance()->get($name);
        }

        //saves data
        return Cache::instance()->set($name, $data, $lifetime);
    }

    /**
     * Recursively sanitizes an input variable:
     *
     * - Normalizes all newlines to LF
     *
     * @param mixed $value any variable
     * @return  mixed   sanitized variable
     */
    public static function sanitize($value)
    {
        if (is_array($value) || is_object($value)) {
            foreach ($value as $key => $val) {
                // Recursively clean each value
                $value[$key] = self::sanitize($val);
            }
        } elseif (is_string($value)) {
            if (strpos($value, "\r") !== FALSE) {
                // Standardize newlines
                $value = str_replace(["\r\n", "\r"], "\n", $value);
            }
        }

        return $value;
    }

    /**
     * Cleans up the environment:
     *
     * - Restore the previous error and exception handlers
     * - Destroy the Modseven::$log and Modseven::$config objects
     *
     * @return  void
     */
    public static function deinit(): void
    {
        if (static::$_init) {

            if (static::$errors) {
                // Go back to the previous error handler
                restore_error_handler();

                // Go back to the previous exception handler
                restore_exception_handler();
            }

            // Destroy objects created by init
            static::$log = static::$config = NULL;

            // Reset internal storage
            static::$_modules = static::$_files = [];
            static::$_paths = [APPPATH, SYSPATH];

            // Reset file cache status
            static::$_files_changed = FALSE;

            // Modseven is no longer initialized
            static::$_init = FALSE;
        }
    }

    /**
     * Add a custom non-composer module
     *
     * @param array $modules list of module paths
     *
     * @return  array   enabled modules
     *
     * @throws Exception
     */
    public static function modules(?array $modules = NULL): array
    {
        if ($modules === NULL) {
            // Not changing modules, just return the current set
            return static::$_modules;
        }

        foreach ($modules as $namespace => $path)
        {
            if (is_dir($path)) {
                // Add the module to include paths
                self::register_module($namespace, $path);

                // include a modules initialization file
                $init = $path . 'init.php';

                if (is_file($init)) {
                    // Include the module initialization file once
                    require_once $init;
                }
            } else {
                // This module is invalid
                throw new Exception('Attempted to load an invalid or missing module \':module\' at \':path\'', [
                    ':module' => $namespace,
                    ':path' => $path,
                ]);
            }
        }

        return static::$_modules;
    }

    /**
     * Returns the the currently active include paths, including the
     * application, system, and each module's path.
     *
     * @return  array
     */
    public static function include_paths(): array
    {
        return static::$_paths;
    }

    /**
     * Recursively finds all of the files in the specified directory at any
     * location in the [Cascading Filesystem](modseven/files), and returns an
     * array of all the files found, sorted alphabetically.
     *
     * @param string $directory directory name
     * @param array $paths list of paths to search
     * @param string|array $ext only list files with this extension
     * @param bool $sort sort alphabetically
     *
     * @return  array
     */
    public static function list_files(?string $directory = NULL, array $paths = NULL, $ext = NULL, bool $sort = TRUE): array
    {
        if ($directory !== NULL) {
            // Add the directory separator
            $directory .= DIRECTORY_SEPARATOR;
        }

        if ($paths === NULL) {
            // Use the default paths
            $paths = static::$_paths;
        }

        if (is_string($ext)) {
            // convert string extension to array
            $ext = [$ext];
        }

        // Create an array for the files
        $found = [];

        foreach ($paths as $path) {
            if (is_dir($path . $directory)) {
                // Create a new directory iterator
                foreach (new DirectoryIterator($path . $directory) as $file) {
                    // Get the file name
                    $filename = $file->getFilename();

                    if (strpos($filename, '.') === 0 || $filename[strlen($filename) - 1] === '~') {
                        // Skip all hidden files and UNIX backup files
                        continue;
                    }

                    // Relative filename is the array key
                    $key = $directory . $filename;

                    if ($file->isDir()) {
                        if ($sub_dir = self::list_files($key, $paths, $ext, $sort)) {
                            if (isset($found[$key])) {
                                // Append the sub-directory list
                                $found[$key] += $sub_dir;
                            } else {
                                // Create a new sub-directory list
                                $found[$key] = $sub_dir;
                            }
                        }
                    } elseif ($ext === NULL || in_array('.' . $file->getExtension(), $ext, TRUE)) {
                        if (!isset($found[$key])) {
                            // Add new files to the list
                            $found[$key] = realpath($file->getPathname());
                        }
                    }
                }
            }
        }

        if ($sort) {
            // Sort the results alphabetically
            ksort($found);
        }

        return $found;
    }

    /**
     * Get a message from a file. Messages are arbitrary strings that are stored
     * in the `messages/` directory and reference by a key. Translation is not
     * performed on the returned values.  See [message files](modseven/files/messages)
     * for more information.
     *
     * @param string $file file name
     * @param string $path key path to get
     * @param mixed $default default value if the path does not exist
     *
     * @return  string|array  message string for the given path, complete message list, when no path is specified
     */
    public static function message(string $file, string $path = NULL, $default = NULL)
    {
        static $messages;

        if (!isset($messages[$file])) {
            // Create a new message list
            $messages[$file] = [];

            if ($files = self::find_file('messages', $file)) {
                foreach ($files as $f) {
                    // Combine all the messages recursively
                    $messages[$file] = Arr::merge($messages[$file], self::load($f));
                }
            }
        }

        if ($path === NULL) {
            // Return all of the messages
            return $messages[$file];
        }

        // Get a message using the path
        return Arr::path($messages[$file], $path, $default);
    }

    /**
     * Searches for a file in the [Cascading Filesystem](modseven/files), and
     * returns the path to the file that has the highest precedence, so that it
     * can be included.
     *
     * When searching the "config", "messages", or "i18n" directories, or when
     * the `$array` flag is set to true, an array of all the files that match
     * that path in the [Cascading Filesystem](modseven/files) will be returned.
     * These files will return arrays which must be merged together.
     *
     * @param string $dir directory name (views, i18n, classes, extensions, etc.)
     * @param string $file filename with subdirectory
     * @param string $ext extension to search for
     * @param boolean $array return an array of files?
     *
     * @return  array|string   a list of files when $array is TRUE, single file path
     */
    public static function find_file(string $dir, string $file, ?string $ext = NULL, bool $array = FALSE)
    {
        if ($ext === NULL) {
            // Use the default extension
            $ext = '.php';
        } elseif ($ext) {
            // Prefix the extension with a period
            $ext = ".{$ext}";
        } else {
            // Use no extension
            $ext = '';
        }

        // Create a partial path of the filename
        $path = $dir . DIRECTORY_SEPARATOR . $file . $ext;

        if (static::$caching === TRUE && isset(static::$_files[$path . ($array ? '_array' : '_path')])) {
            // This path has been cached
            return static::$_files[$path . ($array ? '_array' : '_path')];
        }

        if (static::$profiling === TRUE) {
            // Start a new benchmark
            $benchmark = Profiler::start('Modseven', __FUNCTION__);
        }

        if ($array || in_array($dir, ['config', 'i18n', 'messages'])) {
            // Include paths must be searched in reverse
            $paths = array_reverse(static::$_paths);

            // Array of files that have been found
            $found = [];

            foreach ($paths as $direct) {
                if (is_file($direct . $path)) {
                    // This path has a file, add it to the list
                    $found[] = $direct . $path;
                }
            }
        } else {
            // The file has not been found yet
            $found = FALSE;

            // If still not found. Search through $_paths
            if (!$found) {
                foreach (static::$_paths as $direct) {
                    if (is_file($direct . $path)) {
                        // A path has been found
                        $found = $direct . $path;
                        // Stop searching
                        break;
                    }
                }
            }
        }

        if (static::$caching === TRUE) {
            // Add the path to the cache
            static::$_files[$path . ($array ? '_array' : '_path')] = $found;

            // Files have been changed
            static::$_files_changed = TRUE;
        }

        if (isset($benchmark)) {
            // Stop the benchmark
            Profiler::stop($benchmark);
        }

        return $found;
    }

    /**
     * Loads a file within a totally empty scope and returns the output:
     *
     * @param string $file
     *
     * @return mixed;
     */
    public static function load(string $file)
    {
        return include $file;
    }

    /**
     * PHP error handler, converts all errors into Error_Exceptions. This handler
     * respects error_reporting settings.
     *
     * @param int         $code  Error Code
     * @param string      $error Error message
     * @param null|string $file  Error File
     * @param null|int    $line  Line number
     *
     * @return  TRUE
     *
     * @throws  \Modseven\Error\Exception
     */
    public static function error_handler(int $code, string $error, ?string $file = NULL, ?int $line = NULL): bool
    {
        if (error_reporting() & $code) {
            // This error is not suppressed by current error reporting settings
            // Convert the error into an Error_Exception
            throw new \Modseven\Error\Exception($error, NULL, $code, 0, $file, $line);
        }

        // Do not execute the PHP error handler
        return TRUE;
    }

    /**
     * Catches errors that are not caught by the error handler, such as E_PARSE.
     *
     * @throws Exception
     */
    public static function shutdown_handler(): void
    {
        if (!static::$_init) {
            // Do not execute when not active
            return;
        }

        try {
            if (static::$caching === TRUE && static::$_files_changed === TRUE) {
                // Write the file path cache
                static::cache('\Modseven\Core::find_file()', static::$_files);
            }
        } catch (\Exception $e) {
            // Pass the exception to the handler
            Exception::handler($e);
        }

        if (static::$errors && ($error = error_get_last()) && in_array($error['type'], static::$shutdown_errors, true)) {
            // Clean the output buffer
            ob_get_level() AND ob_clean();

            // Fake an exception for nice debugging
            Exception::handler(new \Modseven\Error\Exception($error['message'], NULL, $error['type'], 0, $error['file'], $error['line']));

            // Shutdown now to avoid a "death loop"
            exit(1);
        }
    }

    /**
     * Generates a version string based on the variables defined above.
     *
     * @return string
     */
    public static function version(): string
    {
        return 'Modseven ' . static::VERSION . ' (' . static::CODENAME . ')';
    }

    /**
     * Call this within your function to mark it deprecated.
     *
     * @param string $since Version since this function shall be marked deprecated.
     * @param string $replacement [optional] replacement function to use instead
     */
    public static function deprecated(string $since, string $replacement = ''): void
    {
        // Get current debug backtrace
        $calling = debug_backtrace()[1];

        // Extract calling class and calling function
        $class = $calling['class'];
        $function = $calling['function'];

        // Build message
        $msg = 'Function "' . $function . '" inside class "' . $class . '" is deprecated since version ' . $since .
            ' and will be removed within the next major release.';

        // Check if replacement function is provided
        if ($replacement) {
            $msg .= ' Please consider replacing it with "' . $replacement . '".';
        }

        // Log the deprecation
        $log = static::$log;
        $log->warning($msg);
    }

    /**
     * Register a module(s) namespace
     *
     * @param string $namespace  Namespace the module uses
     * @param string $path       Path to `classes` folder
     * @param bool   $prepend    Prepend (in case the namespace already exists)
     */
    public static function register_module(string $namespace, string $path, bool $prepend = false) : void
    {
        // Load the modules init.php if present
        $init = $path . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'init.php';
        if (is_readable($init))
        {
            require_once $init;
        }

        static::$autoloader->addPsr4($namespace, $path, $prepend);
    }

    /**
     * Function to initialize Modules.
     * Requires "init.php" if module is "modseven" flagged
     *
     * @throws Exception
     */
    public static function initModules() : void
    {
        $modules = [];

        // If caching enabled and cache load from cache
        if ((static::$caching === true) && ! empty($modules = self::cache('\Modseven\Core::initModules()') ?? []))
        {
            foreach ($modules as $name => $init)
            {
                require_once $init;
            }
        }

        // Grab installed packages from composer(s) installed.json
        $vendor = DOCROOT . 'vendor' . DIRECTORY_SEPARATOR;
        $file =  $vendor . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';

        if ( ! is_readable($file))
        {
            throw new Exception('Please run composer install before initializing modules.');
        }

        try
        {
            $installed = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        }
        catch (JsonException $e)
        {
            throw new Exception($e->getMessage(), null, $e->getCode(), $e);
        }

        // Only register init.php files from modules which have "modseven" set to true
        foreach ($installed as $module)
        {
            if (isset($module['extra']['modseven']) && $module['extra']['modseven'] === true)
            {
                $init = $vendor . $module['name'] . DIRECTORY_SEPARATOR . 'init.php';

                if (file_exists($init))
                {
                    $modules[$module['name']] = $init;
                    require_once $init;
                }
            }
        }

        // Cache if wanted
        if (static::$caching === true)
        {
            self::cache('\Modseven\Core\initModules()', $modules);
        }

    }
}
