<?php

namespace Curia\Database;

use stdClass;
use ReflectionClass;
use Curia\Collect\Str;
use Curia\Collect\Collection;


abstract class Model
{
    /**
     * The connection name for the model.
     *
     * @var string
     */
    protected $connection;

    /**
     * The connection manager instance.
     *
     * @var \Curia\Database\DatabaseManager
     */
    protected static $manager;

    /**
     * The query builder instance.
     *
     * @var \Curia\Database\QueryBuilder
     */
    protected $query;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The model's attributes.
     *
     * @var array
     */
    protected $attributes = [];

    /**
     * The model attribute's original state.
     *
     * @var array
     */
    // protected $original = [];

    /**
     * The changed model attributes.
     *
     * @var array
     */
    protected $changes = [];

    /**
     * Indicates if the model exists.
     *
     * @var bool
     */
    protected $exists = false;

    /**
     * Create a new Eloquent model instance.
     *
     * @param  array  $attributes
     * @return void
     */
    public function __construct()
    {
        $this->setDefaultTableName();
    }

    protected function setDefaultTableName()
    {
        if (! isset($this->table)) {
            $this->table = str_replace(
                '\\', '', Str::snake(Str::plural(class_basename($this)))
            );
        }
    }

    public static function setupManager(DatabaseManager $manager)
    {
        static::$manager = $manager;
    }

    public function save()
    {
        if ($this->exists) {
            $result = $this->query()
                    ->where($this->primaryKey, $this->attributes[$this->primaryKey])
                    ->update($this->changes);

            if ($result) {
                $this->changes = [];
            }

            return $result;
        }

        return $this->query()->insertGetId($this->attributes);
    }

    public static function create(array $attributes)
    {
        $model = static::make($attributes);

        $id = $model->save();

        $model = static::where($model->getPrimaryKey(), $id)->first();

        return $model;
    }

    public function getPrimaryKey()
    {
        return $this->primaryKey;
    }

    public function fill(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
    }

    public static function make(array $attributes)
    {
        return tap(new static, function ($model) use ($attributes) {
            $model->fill($attributes);
        });
    }

    /**
     * Get an attribute from the model.
     *
     * @param  string  $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        if (! $key || ! array_key_exists($key, $this->attributes)) {
            return;
        }

        return $this->attributes[$key];
    }

    /**
     * Set a given attribute on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return $this
     */
    public function setAttribute($key, $value)
    {
        $this->attributes[$key] = $value;
        $this->changes[$key] = $value;

        return $this;
    }

    /**
     * Create a new instance of the given model.
     *
     * @param  array  $attributes
     * @param  bool  $exists
     * @return static
     */
    public function newInstance($attributes = [], $exists = false)
    {
        $model = new static;

        $model->attributes = $attributes;

        $model->exists = $exists;

        return $model;
    }

    /**
     * Get a new query builder for the model's table.
     *
     * @return \Curia\Database\QueryBuilder
     */
    protected function query()
    {
        return static::$manager
                    ->connection($this->connection)
                    ->table($this->table)
                    ->setModel($this);
    }

    /**
     * Dynamically retrieve attributes on the model.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    /**
     * Dynamically set attributes on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Handle dynamic method calls into the model.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->query()->$method(...$parameters);
    }

    /**
     * Handle dynamic static method calls into the method.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        return (new static)->$method(...$parameters);
    }
}