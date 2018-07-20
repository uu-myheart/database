<?php

namespace Curia\Framework\Database;

class DatabaseManager
{
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
    public function __construct($app)
    {
        $this->app = $app;
    }
    
    public function connection($name = null)
    {
        $name = $name ?? $this->getDefaultConnectionName();
        
        // 如果没有连接就新建立一个连接
        if (! isset($this->connections[$name])) {
            $this->connections[$name] = $this->createConnection($name);
        }

        return $this->connections[$name];
    }

    protected function getDefaultConnectionName()
    {
        return $this->app->config('database.default');
    }

    protected function createConnection($name)
    {
        $config = $this->app->config('database.connections')[$name];

        return new Connection($config);
    }

    public function __call($method, $parameters)
    {
        return $this->connection()->$method(...$parameters);
    }
}