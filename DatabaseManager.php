<?php

namespace Curia\Database;

use Curia\Collect\Config;
use Curia\Container\Container;

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
    public function __construct(Container $app = null)
    {
        $this->setupContainer($app ?? new Container);
    }

    /**
     * Setup the IoC container instance.
     *
     * @param  \Curia\Container\Container  $container
     * @return void
     */
    protected function setupContainer(Container $app)
    {
        $this->app = $app;

        if (! $this->app->bound('config')) {
            $this->app->instance('config', new Config);
        }
    }

    /**
     * 启动Model
     *
     * @return void
     */
    public function bootModel()
    {
        Model::setupManager($this);
    }

    /**
     * Get a database connection instance.
     *
     * @param  string  $name
     * @return \Curia\Database\Connection
     */
    public function connection($name = null)
    {
        $name = $name ?? $this->getDefaultConnectionName();
        
        // 如果没有连接就新建立一个连接
        if (! isset($this->connections[$name])) {
            $this->createConnection($name);
        }

        return $this->connections[$name];
    }

    /**
     * u
     * 
     * @return mixed
     */
    protected function getDefaultConnectionName()
    {
        return $this->app['config']['database.default'];
    }

    protected function createConnection($name)
    {
        $config = $this->app['config']['database.connections'][$name];

        $this->connections[$name] = new Connection($config);
    }

    public function addConnection(array $config, $name = 'default')
    {
        $connections = $this->app['config']['database.connections'];

        $connections[$name] = $config;

        $this->app['config']['database.default'] = 'default';
        $this->app['config']['database.connections'] = $connections;
    }

    public function __call($method, $parameters)
    {
        return $this->connection()->$method(...$parameters);
    }
}