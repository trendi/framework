<?php

namespace Trensy\Mvc\View\Engine\Bladex;

use Closure;
use Countable;
use InvalidArgumentException;
use Trensy\Shortcut;
use Trensy\Mvc\View\Engine\Bladex\Engines\EngineResolver;
use Trensy\Mvc\View\Engine\Bladex\Support\Arr;
use Trensy\Mvc\View\Engine\Bladex\Support\Str;
use Trensy\Di;
use Trensy\Support\Dir;
use Trensy\Log;
use Trensy\Support\Tool;

class Factory
{
    use Shortcut;
    /**
     * The engine implementation.
     *
     * @var \Trensy\Mvc\View\Engine\Bladex\Engines\EngineResolver
     */
    protected $engines;

    /**
     * The view finder implementation.
     *
     * @var \Trensy\Mvc\View\Engine\Bladex\ViewFinderInterface
     */
    protected $finder;

    /**
     * Data that should be available to all templates.
     *
     * @var array
     */
    protected $shared = [];

    /**
     * Array of registered view name aliases.
     *
     * @var array
     */
    protected $aliases = [];

    /**
     * All of the registered view names.
     *
     * @var array
     */
    protected $names = [];

    /**
     * The extension to engine bindings.
     *
     * @var array
     */
    protected $extensions = ['blade.php' => 'bladex', 'php' => 'php'];

    /**
     * The view composer events.
     *
     * @var array
     */
    protected $composers = [];

    /**
     * All of the finished, captured sections.
     *
     * @var array
     */
    protected $sections = [];

    /**
     * The stack of in-progress sections.
     *
     * @var array
     */
    protected $sectionStack = [];

    /**
     * The stack of in-progress loops.
     *
     * @var array
     */
    protected $loopsStack = [];

    /**
     * All of the finished, captured push sections.
     *
     * @var array
     */
    protected $pushes = [];

    /**
     * The stack of in-progress push sections.
     *
     * @var array
     */
    protected $pushStack = [];

    /**
     * The number of active rendering operations.
     *
     * @var int
     */
    protected $renderCount = 0;

    protected $config = [];
    

    /**
     * Create a new view factory instance.
     *
     * @param  \Trensy\Mvc\View\Engine\Bladex\Engines\EngineResolver $engines
     * @param  \Trensy\Mvc\View\Engine\Bladex\ViewFinderInterface $finder
     * @return void
     */
    public function __construct(EngineResolver $engines, ViewFinderInterface $finder, $config)
    {
        $this->finder = $finder;
        $this->engines = $engines;
        $this->config = $config;
        $this->share('__env', $this);
    }

    public function requireStatic($path, $manifestFile="", $other=[], $useCache=0)
    {
        list($version) = $this->config;

        $ext = pathinfo($path, PATHINFO_EXTENSION);

        $otherStr = "";
        if ($other) {
            foreach ($other as $k => $v) {
                if (is_int($k)) {
                    $otherStr .= " " . $v;
                } else {
                    $otherStr .= " " . $k . "='" . $v . "'";
                }
            }
        }

        $cdnUrl = $this->config()->get("app.view.cdn_url");
        $cdnUrl = $cdnUrl ? rtrim($cdnUrl, '/') . "/" : "/";

        $manifestFile = $manifestFile ? $manifestFile : $this->config()->get('app.view.manifest');
        $manifestFile = trim($manifestFile, '/');

        $manifestKey = "app.view.manifest." . md5($manifestFile) . "." . $path;
        $cachePath = $this->syscache()->get($manifestKey);
        if (!$cachePath) {
            if ($manifestFile) {
                $dir = dirname($manifestFile);
                if (substr(trim($path, '/'), 0, strlen($dir)) == $dir) {

                    $manifestFile = Dir::formatPath(ROOT_PATH) . "public/" . $manifestFile;
                    if (is_file($manifestFile)) {
                        $json = file_get_contents($manifestFile);
                        if ($json) {
                            $manifestVaues = json_decode($json, true);
                            if(isset($manifestVaues[$path])){
                                $path = $manifestVaues[$path];
                            }else{
                                $path = substr(trim($path, '/'), strlen($dir) + 1);
                                $path = isset($manifestVaues[$path]) ? $manifestVaues[$path] : $path;
                            }
                        }
                    }
                    if (substr(trim($path, '/'), 0, strlen($dir)) != $dir) {
                        $path = $dir . "/" . $path;
                    }
                    $this->syscache()->set($manifestKey, $path);
                } else {
                    $manifestFile = Dir::formatPath(ROOT_PATH) . "public/" . $manifestFile;
                    if (is_file($manifestFile)) {
                        $json = file_get_contents($manifestFile);
                        if ($json) {
                            $manifestVaues = json_decode($json, true);
//                            Log::debug($manifestVaues);
//                            Log::debug("++++++".$path);
                            $path = isset($manifestVaues[$path]) ? $manifestVaues[$path] : $path;
                            $this->syscache()->set($manifestKey, $path);
                        }
                    }
                }
            }
        }else{
            $path = $cachePath;
        }

        $path = ltrim($path, '/');
        $path = $cdnUrl.$path;

        $versionStr = "";
        if(!$useCache){
            $versionStr = $version?"?".$version:"";
        }

        if($ext == 'js'){
            return "<script ".$otherStr." src=\"" . $path."\" type=\"text/javascript\"></script>" . PHP_EOL;
        }
        else if($ext == 'css'){
            return "<link  ".$otherStr." rel=\"stylesheet\" href=\"" . $path ."\">" . PHP_EOL;
        }
        else if($ext =='ico'){
            return "<link  ".$otherStr." rel=\"shortcut icon\" href=\"" . $path . $versionStr."\">" . PHP_EOL;
        }
        else{
            return $path.$versionStr;
        }
    }
    

    /**
     * Get the evaluated view contents for the given view.
     *
     * @param  string $path
     * @param  array $data
     * @param  array $mergeData
     * @return 
     */
    public function file($path, $data = [], $mergeData = [])
    {
        $data = array_merge($mergeData, $this->parseData($data));

        $view = new View($this, $this->getEngineFromPath($path), $path, $path, $data);

        return $view;
    }

    /**
     * Get the evaluated view contents for the given view.
     *
     * @param  string $view
     * @param  array $data
     * @param  array $mergeData
     * @return
     */
    public function make($view, $data = [], $mergeData = [])
    {
        if (isset($this->aliases[$view])) {
            $view = $this->aliases[$view];
        }

        $view = $this->normalizeName($view);

        list(,,,$bundles) = $this->config;

        if($bundles){
            foreach ($bundles as $hitsv){
                $hitsPath = Dir::formatPath(APPLICATION_PATH).ucwords($hitsv)."Bundle/Resources/templates";
                $this->prependNamespace($hitsv, $hitsPath);
            }
        }

        $path = $this->finder->find($view);

        $data = array_merge($mergeData, $this->parseData($data));

        $view = new View($this, $this->getEngineFromPath($path), $path, $path, $data);
        
        return $view;
    }

    public function widget()
    {
        $params = func_get_args();
        $widgetConfig = isset($this->config[2])?$this->config[2]:"";
        if(!$widgetConfig){
            throw  new \Exception("widget not found, please config!");
        }
        $widgetName = func_get_arg(0);
        if(!$widgetName){
            throw  new \Exception("widget name not found!");
        }
        unset($params[0]);
        $class = isset($widgetConfig[$widgetName])?$widgetConfig[$widgetName]:"";
        if(!$class){
            throw  new \Exception("widget class not found!");
        }
        $obj = Di::get($class);
        if(!method_exists($obj, "perform")){
            throw  new \Exception("method perform not found!");
        }
        $params = array_values($params);
        if($params){
            return $obj->perform($params);
        }else{
            return $obj->perform();
        }
    }

    /**
     * Normalize a view name.
     *
     * @param  string $name
     * @return string
     */
    protected function normalizeName($name)
    {
        $delimiter = ViewFinderInterface::HINT_PATH_DELIMITER;

        if (strpos($name, $delimiter) === false) {
            return str_replace('/', '.', $name);
        }

        list($namespace, $name) = explode($delimiter, $name);

        return $namespace . $delimiter . str_replace('/', '.', $name);
    }

    /**
     * Parse the given data into a raw array.
     *
     * @param  mixed $data
     * @return array
     */
    protected function parseData($data)
    {
        return !is_array($data) ? $data->toArray() : $data;
    }

    /**
     * Get the evaluated view contents for a named view.
     *
     * @param  string $view
     * @param  mixed $data
     * @return 
     */
    public function of($view, $data = [])
    {
        return $this->make($this->names[$view], $data);
    }

    /**
     * Register a named view.
     *
     * @param  string $view
     * @param  string $name
     * @return void
     */
    public function name($view, $name)
    {
        $this->names[$name] = $view;
    }

    /**
     * Add an alias for a view.
     *
     * @param  string $view
     * @param  string $alias
     * @return void
     */
    public function alias($view, $alias)
    {
        $this->aliases[$alias] = $view;
    }

    /**
     * Determine if a given view exists.
     *
     * @param  string $view
     * @return bool
     */
    public function exists($view)
    {
        try {
            $this->finder->find($view);
        } catch (InvalidArgumentException $e) {
            return false;
        }

        return true;
    }

    /**
     * Get the rendered contents of a partial from a loop.
     *
     * @param  string $view
     * @param  array $data
     * @param  string $iterator
     * @param  string $empty
     * @return string
     */
    public function renderEach($view, $data, $iterator, $empty = 'raw|')
    {
        $result = '';

        // If is actually data in the array, we will loop through the data and append
        // an instance of the partial view to the final result HTML passing in the
        // iterated value of this data array, allowing the views to access them.
        if (count($data) > 0) {
            foreach ($data as $key => $value) {
                $data = ['key' => $key, $iterator => $value];

                $result .= $this->make($view, $data)->render();
            }
        }

        // If there is no data in the array, we will render the contents of the empty
        // view. Alternatively, the "empty view" could be a raw string that begins
        // with "raw|" for convenience and to let this know that it is a string.
        else {
            if (Str::startsWith($empty, 'raw|')) {
                $result = substr($empty, 4);
            } else {
                $result = $this->make($empty)->render();
            }
        }

        return $result;
    }

    /**
     * Get the appropriate view engine for the given path.
     *
     * @param  string $path
     * @return \Trensy\Mvc\View\Engine\Bladex\Engines\EngineInterface
     *
     * @throws \InvalidArgumentException
     */
    public function getEngineFromPath($path)
    {
        if (!$extension = $this->getExtension($path)) {
            throw new InvalidArgumentException("Unrecognized extension in file: $path");
        }

        $engine = $this->extensions[$extension];

        return $this->engines->resolve($engine);
    }

    /**
     * Get the extension used by the view file.
     *
     * @param  string $path
     * @return string
     */
    protected function getExtension($path)
    {
        $extensions = array_keys($this->extensions);

        return Arr::first($extensions, function ($value) use ($path) {
            return Str::endsWith($path, '.' . $value);
        });
    }

    /**
     * Add a piece of shared data to the environment.
     *
     * @param  array|string $key
     * @param  mixed $value
     * @return mixed
     */
    public function share($key, $value = null)
    {
        if (!is_array($key)) {
            return $this->shared[$key] = $value;
        }

        foreach ($key as $innerKey => $innerValue) {
            $this->share($innerKey, $innerValue);
        }
    }
    

    /**
     * Parse a class based composer name.
     *
     * @param  string $class
     * @param  string $prefix
     * @return array
     */
    protected function parseClassEvent($class, $prefix)
    {
        if (Str::contains($class, '@')) {
            return explode('@', $class);
        }

        $method = Str::contains($prefix, 'composing') ? 'compose' : 'create';

        return [$class, $method];
    }


    /**
     * Start injecting content into a section.
     *
     * @param  string $section
     * @param  string $content
     * @return void
     */
    public function startSection($section, $content = '')
    {
        if ($content === '') {
            if (ob_start()) {
                $this->sectionStack[] = $section;
            }
        } else {
            $this->extendSection($section, $content);
        }
    }

    /**
     * Inject inline content into a section.
     *
     * @param  string $section
     * @param  string $content
     * @return void
     */
    public function inject($section, $content)
    {
        $this->startSection($section, $content);
    }

    /**
     * Stop injecting content into a section and return its contents.
     *
     * @return string
     */
    public function yieldSection()
    {
        if (empty($this->sectionStack)) {
            return '';
        }

        return $this->yieldContent($this->stopSection());
    }

    /**
     * Stop injecting content into a section.
     *
     * @param  bool $overwrite
     * @return string
     * @throws \InvalidArgumentException
     */
    public function stopSection($overwrite = false)
    {
        if (empty($this->sectionStack)) {
            throw new InvalidArgumentException('Cannot end a section without first starting one.');
        }

        $last = array_pop($this->sectionStack);

        if ($overwrite) {
            $this->sections[$last] = ob_get_clean();
        } else {
            $this->extendSection($last, ob_get_clean());
        }

        return $last;
    }

    /**
     * Stop injecting content into a section and append it.
     *
     * @return string
     * @throws \InvalidArgumentException
     */
    public function appendSection()
    {
        if (empty($this->sectionStack)) {
            throw new InvalidArgumentException('Cannot end a section without first starting one.');
        }

        $last = array_pop($this->sectionStack);

        if (isset($this->sections[$last])) {
            $this->sections[$last] .= ob_get_clean();
        } else {
            $this->sections[$last] = ob_get_clean();
        }

        return $last;
    }

    /**
     * Append content to a given section.
     *
     * @param  string $section
     * @param  string $content
     * @return void
     */
    protected function extendSection($section, $content)
    {
        if (isset($this->sections[$section])) {
            $content = str_replace('@parent', $content, $this->sections[$section]);
        }

        $this->sections[$section] = $content;
    }

    /**
     * Get the string contents of a section.
     *
     * @param  string $section
     * @param  string $default
     * @return string
     */
    public function yieldContent($section, $default = '')
    {
        $sectionContent = $default;

        if (isset($this->sections[$section])) {
            $sectionContent = $this->sections[$section];
        }

        $sectionContent = str_replace('@@parent', '--parent--holder--', $sectionContent);

        return str_replace(
            '--parent--holder--', '@parent', str_replace('@parent', '', $sectionContent)
        );
    }

    /**
     * Start injecting content into a push section.
     *
     * @param  string $section
     * @param  string $content
     * @return void
     */
    public function startPush($section, $content = '')
    {
        if ($content === '') {
            if (ob_start()) {
                $this->pushStack[] = $section;
            }
        } else {
            $this->extendPush($section, $content);
        }
    }

    /**
     * Stop injecting content into a push section.
     *
     * @return string
     * @throws \InvalidArgumentException
     */
    public function stopPush()
    {
        if (empty($this->pushStack)) {
            throw new InvalidArgumentException('Cannot end a section without first starting one.');
        }

        $last = array_pop($this->pushStack);

        $this->extendPush($last, ob_get_clean());

        return $last;
    }

    /**
     * Append content to a given push section.
     *
     * @param  string $section
     * @param  string $content
     * @return void
     */
    protected function extendPush($section, $content)
    {
        if (!isset($this->pushes[$section])) {
            $this->pushes[$section] = [];
        }
        if (!isset($this->pushes[$section][$this->renderCount])) {
            $this->pushes[$section][$this->renderCount] = $content;
        } else {
            $this->pushes[$section][$this->renderCount] .= $content;
        }
    }

    /**
     * Get the string contents of a push section.
     *
     * @param  string $section
     * @param  string $default
     * @return string
     */
    public function yieldPushContent($section, $default = '')
    {
        if (!isset($this->pushes[$section])) {
            return $default;
        }

        return implode(array_reverse($this->pushes[$section]));
    }

    /**
     * Flush all of the section contents.
     *
     * @return void
     */
    public function flushSections()
    {
        $this->renderCount = 0;

        $this->sections = [];
        $this->sectionStack = [];

        $this->pushes = [];
        $this->pushStack = [];
    }

    /**
     * Flush all of the section contents if done rendering.
     *
     * @return void
     */
    public function flushSectionsIfDoneRendering()
    {
        if ($this->doneRendering()) {
            $this->flushSections();
        }
    }

    /**
     * Increment the rendering counter.
     *
     * @return void
     */
    public function incrementRender()
    {
        $this->renderCount++;
    }

    /**
     * Decrement the rendering counter.
     *
     * @return void
     */
    public function decrementRender()
    {
        $this->renderCount--;
    }

    /**
     * Check if there are no active render operations.
     *
     * @return bool
     */
    public function doneRendering()
    {
        return $this->renderCount == 0;
    }

    /**
     * Add new loop to the stack.
     *
     * @param  \Countable|array $data
     * @return void
     */
    public function addLoop($data)
    {
        $length = is_array($data) || $data instanceof Countable ? count($data) : null;

        $parent = Arr::last($this->loopsStack);

        $this->loopsStack[] = [
            'iteration' => 0,
            'index' => 0,
            'remaining' => isset($length) ? $length : null,
            'count' => $length,
            'first' => true,
            'last' => isset($length) ? $length == 1 : null,
            'depth' => count($this->loopsStack) + 1,
            'parent' => $parent ? (object)$parent : null,
        ];
    }

    /**
     * Increment the top loop's indices.
     *
     * @return void
     */
    public function incrementLoopIndices()
    {
        $loop = &$this->loopsStack[count($this->loopsStack) - 1];

        $loop['iteration']++;
        $loop['index'] = $loop['iteration'] - 1;

        $loop['first'] = $loop['iteration'] == 1;

        if (isset($loop['count'])) {
            $loop['remaining']--;

            $loop['last'] = $loop['iteration'] == $loop['count'];
        }
    }

    /**
     * Pop a loop from the top of the loop stack.
     *
     * @return void
     */
    public function popLoop()
    {
        array_pop($this->loopsStack);
    }

    /**
     * Get an instance of the first loop in the stack.
     *
     * @return array
     */
    public function getFirstLoop()
    {
        return ($last = Arr::last($this->loopsStack)) ? (object) $last : null;
    }

    /**
     * Get the entire loop stack.
     *
     * @return array
     */
    public function getLoopStack()
    {
        return $this->loopsStack;
    }

    /**
     * Add a location to the array of view locations.
     *
     * @param  string $location
     * @return void
     */
    public function addLocation($location)
    {
        $this->finder->addLocation($location);
    }

    /**
     * Add a new namespace to the loader.
     *
     * @param  string $namespace
     * @param  string|array $hints
     * @return void
     */
    public function addNamespace($namespace, $hints)
    {
        $this->finder->addNamespace($namespace, $hints);
    }

    /**
     * Prepend a new namespace to the loader.
     *
     * @param  string $namespace
     * @param  string|array $hints
     * @return void
     */
    public function prependNamespace($namespace, $hints)
    {
        $this->finder->prependNamespace($namespace, $hints);
    }

    /**
     * Register a valid view extension and its engine.
     *
     * @param  string $extension
     * @param  string $engine
     * @param  \Closure $resolver
     * @return void
     */
    public function addExtension($extension, $engine, $resolver = null)
    {
        $this->finder->addExtension($extension);

        if (isset($resolver)) {
            $this->engines->register($engine, $resolver);
        }

        unset($this->extensions[$extension]);

        $this->extensions = array_merge([$extension => $engine], $this->extensions);
    }

    /**
     * Get the extension to engine bindings.
     *
     * @return array
     */
    public function getExtensions()
    {
        return $this->extensions;
    }

    /**
     * Get the engine resolver instance.
     *
     * @return \Trensy\Mvc\View\Engine\Bladex\Engines\EngineResolver
     */
    public function getEngineResolver()
    {
        return $this->engines;
    }

    /**
     * Get the view finder instance.
     *
     * @return \Trensy\Mvc\View\Engine\Bladex\ViewFinderInterface
     */
    public function getFinder()
    {
        return $this->finder;
    }

    /**
     * Set the view finder instance.
     *
     * @param  \Trensy\Mvc\View\Engine\Bladex\ViewFinderInterface $finder
     * @return void
     */
    public function setFinder(ViewFinderInterface $finder)
    {
        $this->finder = $finder;
    }

    /**
     * Get an item from the shared data.
     *
     * @param  string $key
     * @param  mixed $default
     * @return mixed
     */
    public function shared($key, $default = null)
    {
        return Arr::get($this->shared, $key, $default);
    }

    /**
     * Get all of the shared data for the environment.
     *
     * @return array
     */
    public function getShared()
    {
        return $this->shared;
    }

    /**
     * Check if section exists.
     *
     * @param  string $name
     * @return bool
     */
    public function hasSection($name)
    {
        return array_key_exists($name, $this->sections);
    }

    /**
     * Get the entire array of sections.
     *
     * @return array
     */
    public function getSections()
    {
        return $this->sections;
    }

    /**
     * Get all of the registered named views in environment.
     *
     * @return array
     */
    public function getNames()
    {
        return $this->names;
    }
}
