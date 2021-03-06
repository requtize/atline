<?php
/**
 * This file is part of the Atline templating system package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Copyright (c) 2015 - 2017 by Adam Banaszkiewicz
 *
 * @license   MIT License
 * @copyright Copyright (c) 2015 - 2017, Adam Banaszkiewicz
 * @link      https://github.com/requtize/atline
 */

namespace Requtize\Atline;

use Requtize\Atline\Event\EventDispatcherInterface;
use Requtize\Atline\Event\EventDispatcher;

/**
 * @author Adam Banaszkiewicz https://github.com/requtize
 */
class Engine
{
    /**
     * Stores definition resolver Which is responsible for translating
     * view definitions into path to view-file.
     *
     * @var Atline\DefinitionResolverInterface
     */
    private $definitionResolver;

    /**
     * Stores cache path for storing cached files.
     * 
     * @var string
     */
    private $cachePath;

    /**
     * Stores default view definition for extending view.
     * 
     * @var string
     */
    private $defaultExtends;

    /**
     * Stores default view data to pass.
     * 
     * @var array
     */
    private $defaultData = [];

    /**
     * Tells, if views should be cached.
     * 
     * @var boolean
     */
    private $cached = true;

    private $environmentFactory;
    private $compilatorOptions = [];

    protected $eventDispatcher;

    /**
     * @param string      $cachePath Cache path.
     * @param Environment $env
     */
    public function __construct($cachePath, Callable $env)
    {
        $this->cachePath    = $cachePath;
        $this->environment  = $env;

        // If directory notexists, we try to create it.
        if(is_dir($this->cachePath) === false)
        {
            mkdir($this->cachePath, 0777, true);
        }

        $this->environmentFactory = $env;
        $this->setEventDispatcher(new EventDispatcher);
    }

    public function createEnv(View $view)
    {
        $env = call_user_func($this->environmentFactory);
        $env->setEngine($this);
        $env->setView($view);

        $this->eventDispatcher->dispatch('environment.create', [ $env ]);

        return $env;
    }

    public function setCompilatorOptions(array $options)
    {
        $this->compilatorOptions = $options;

        return $this;
    }

    public function setEventDispatcher(EventDispatcherInterface $dispatcher)
    {
        $this->eventDispatcher = $dispatcher;

        return $this;
    }

    public function getEventDispatcher()
    {
        return $this->eventDispatcher;
    }

    /**
     * Tells, if views should be cached.
     * If true - Cached views will be re-used.
     * If false - View will be always generated new.
     * 
     * @param boolean $boolean
     * @return self
     */
    public function setCached($boolean)
    {
        $this->cached = $boolean;

        return $this;
    }

    /**
     * Sets definition resolver. Translating view definitions into files path.
     * 
     * @param DefinitionResolverInterface $resolver
     * @return self
     */
    public function setDefinitionResolver(DefinitionResolverInterface $resolver)
    {
        $this->definitionResolver = $resolver;

        return $this;
    }

    /**
     * Gets definition resolver.
     * 
     * @return DefinitionResolverInterface
     */
    public function getDefinitionResolver()
    {
        return $this->definitionResolver;
    }

    /**
     * Sets default data to pass to view.
     * 
     * @param array $data Array of data.
     * @return self
     */
    public function setDefaultData(array $data)
    {
        $this->defaultData = $data;

        return $this;
    }

    /**
     * Sets view definition that should be extending for view wich has not set exyending view.
     * 
     * @param string $definition View definition.
     * @return self
     */
    public function setDefaultExtends($definition)
    {
        $this->defaultExtends = $definition;

        return $this;
    }

    /**
     * Renders view (definition in $definition variable) and pass data from $data variable.
     * Saves compiled views in Cache directory. If cached file already exists - its only
     * call it.
     *
     * @return  string Rendered content of view.
     */
    public function render($definition, array $data = [], array $compilatorOptions = [])
    {
        $className    = null;
        $compilers    = [];
        $index        = 0;
        $defExtAdded  = false;
        $definitionRoot = $definition;

        $this->eventDispatcher->dispatch('render.compile.before', [ $definitionRoot ]);

        do
        {
            $filepath = $this->definitionResolver->resolve($definition);

            $this->eventDispatcher->dispatch('render.compile_view.before', [ $definition, $filepath ]);

            $compilers[$index] = new Compiler($filepath, $this->cached, array_merge($this->compilatorOptions, $compilatorOptions));
            $compilers[$index]->setCachePath($this->cachePath);

            /**
             * We want the class name of first view.
             */
            if($className === null)
            {
                $className = $compilers[$index]->getClassName();
            }

            /**
             * If compiled version already exists, we dont need compile all view.
             */
            if($compilers[$index]->compiledExists())
            {
                $compilers[$index]->resolveExtending();
            }
            // Compile view and generate PHP Class content.
            else
            {
                $compilers[$index]->compile();
            }

            if($index > 0)
            {
                $compilers[$index - 1]->setExtendsClassname($compilers[$index]->getClassName());
            }

            // If there is no extending view, we render only this view.
            if($this->defaultExtends === null)
            {
                $compilers[$index]->setExtendedDefinition(false);
                $defExtAdded = true;
            }
            // Otherwise, we render all extendings view with this view.
            elseif($this->defaultExtends && $defExtAdded === false && $compilers[$index]->getExtendedDefinition() === null)
            {
                $compilers[$index]->setExtendedDefinition($this->defaultExtends);
                $defExtAdded = true;
            }

            $this->eventDispatcher->dispatch('render.compile_view.after', [ $definition, $filepath ]);

            $index++;
        }
        while ($definition = $compilers[$index - 1]->getExtendedDefinition());

        $this->eventDispatcher->dispatch('render.compile.after', [ $definitionRoot ]);

        /**
         * Save contents of PHP class into file if not exists yet.
         */
        foreach($compilers as $compiler)
        {
            if($compiler->compiledExists() === false)
            {
                $this->saveToCache($compiler->getClassName(), $compiler->__toString());
            }
        }

        // Default data to pass.
        $data = array_merge($data, $this->defaultData);

        return (new Runner)->run($this->cachePath."/{$className}.php", $className, $data, [ $this, 'createEnv' ]);
    }

    /**
     * Saves View Class content info file in Cache directory.
     * 
     * @param  string $className Class filename.
     * @param  string $content   Class content to save.
     * @return void
     */
    private function saveToCache($className, $content)
    {
        file_put_contents($this->cachePath."/{$className}.php", $content);
    }
}
