<?php

namespace Curia\Database;

class DatabaseManager
{
    /**
     * The current globally used instance.
     *
     * @var object
     */
    public static $instance;

    /**
     * The application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;
    
    /**
     * The active connection instances.
     *
     * @var array
     */
    protected $connections = [];

    /**
     * Create a new database manager instance.
     * 
     * @param \Curia\Framework\Application $app
     * @return void;
     */
    public function __construct($app = null)
    {
        $this->app = $app;

        static::$instance = $this;
    }
    
    public function connection($name = null)
    {
        $name = $name ?? $this->getDefaultConnectionName();
        
        // 如果没有连接就新建立一个连接
        if (! isset($this->connections[$name])) {
            $this->addConnection([], $name);
        }

        return $this->connections[$name];
    }

    protected function getDefaultConnectionName()
    {
        if (isset($this->app)) {
            return $this->app->config('database.default');
        }

        return 'default';
    }

    public function addConnection($config, $name = 'default')
    {
        if (isset($this->app)) {
            $config = $this->app->config('database.connections')[$name];
        }

        $this->connections[$name] = new Connection($config);
    }

    public function __call($method, $parameters)
    {
        return $this->connection()->$method(...$parameters);
    }
}