<?php

namespace Curia\Database;

use Curia\Collect\Arr;

Class Grammar
{
	/**
     * The value of the expression.
     *
     * @var mixed
     */
    protected static $value;

	/**
     * The grammar table prefix.
     *
     * @var string
     */
    protected static $tablePrefix = '';

    /**
     * The grammar specific operators.
     *
     * @var array
     */
    protected static $operators = [];

    /**
     * The components that make up a select clause.
     *
     * @var array
     */
    protected static $selectComponents = [
        'aggregate',
        'columns',
        'from',
        'joins',
        'wheres',
        'groups',
        'havings',
        'orders',
        'limit',
        'offset',
        'unions',
        'lock',
    ];

    /**
     * Compile a select query into SQL.
     *
     * @param  \Curia\Database\Builder $query
     * @return string
     */
    public static function compileSelect(QueryBuilder $query)
    {
        // If the query does not have any columns set, we'll set the columns to the
        // * character to just get all of the columns from the database. Then we
        // can build the query and concatenate all the pieces together as one.
        $original = $query->columns;

        if (is_null($query->columns)) {
            $query->columns = ['*'];
        }

        // To compile the query, we'll spin through each component of the query and
        // see if that component exists. If it does we'll just call the compiler
        // function for the component which is responsible for making the SQL.
        $sql = trim(static::concatenate(
            static::compileComponents($query))
        );

        $query->columns = $original;

        return $sql;
    }

    /**
     * Concatenate an array of segments, removing empties.
     *
     * @param  array   $segments
     * @return string
     */
    protected static function concatenate($segments)
    {
        return implode(' ', array_filter($segments, function ($value) {
            return (string) $value !== '';
        }));
    }

    /**
     * Compile the components necessary for a select clause.
     *
     * @param  QueryBuilder  $query
     * @return array
     */
    protected static function compileComponents(QueryBuilder $query)
    {
        $sql = [];

        foreach (static::$selectComponents as $component) {
            // To compile the query, we'll spin through each component of the query and
            // see if that component exists. If it does we'll just call the compiler
            // function for the component which is responsible for making the SQL.
            if (! is_null($query->$component)) {
                $method = 'compile'.ucfirst($component);

                $sql[$component] = static::$method($query, $query->$component);
            }
        }

        return $sql;
    }

    /**
     * Compile the "select *" portion of the query.
     *
     * @param  \Curia\Database\Builder $query
     * @param  array  $columns
     * @return string|null
     */
    protected static function compileColumns(QueryBuilder $query, $columns)
    {
        // If the query is actually performing an aggregating select, we will let that
        // compiler handle the building of the select clauses, as it will need some
        // more syntax that is best handled by that function to keep things neat.
        if (! is_null($query->aggregate)) {
            return;
        }

        $select = $query->distinct ? 'select distinct ' : 'select ';

        return $select.static::columnize($columns);
    }

    /**
     * Convert an array of column names into a delimited string.
     *
     * @param  array   $columns
     * @return string
     */
    public static function columnize(array $columns)
    {
        return implode(', ', array_map(['static', 'wrap'], $columns));
    }

    /**
     * Wrap a value in keyword identifiers.
     *
     * @param  \Illuminate\Database\Query\Expression|string  $value
     * @param  bool    $prefixAlias
     * @return string
     */
    public static function wrap($value)
    {
        if ($value instanceof Expression) {
            return $value->getValue();
        }

        if (strpos(strtolower($value), ' as ') !== false) {
            return static::wrapAliasedValue($value);
        }

        return static::wrapSegments(explode('.', $value));
    }

    /**
     * Wrap a value that has an alias.
     *
     * @param  string  $value
     * @param  bool  $prefixAlias
     * @return string
     */
    protected static function wrapAliasedValue($value)
    {
        $segments = preg_split('/\s+as\s+/i', $value);

        return static::wrap($segments[0]) . ' as ' . static::wrapValue($segments[1]);
    }

    /**
     * Wrap the given value segments.
     *
     * @param  array  $segments
     * @return string
     */
    static function wrapSegments($segments)
    {
        return collect($segments)->map(function ($segment, $key) use ($segments) {
            return $key == 0 && count($segments) > 1
                            ? static::wrapTable($segment)
                            : static::wrapValue($segment);
        })->implode('.');
    }

    /**
     * Wrap a table in keyword identifiers.
     *
     * @param  \Illuminate\Database\Query\Expression|string  $table
     * @return string
     */
    public static function wrapTable($table)
    {
        if (! $table instanceof Expression) {
            return static::wrap(static::$tablePrefix.$table, true);
        }

        return static::getValue($table);
    }

    /**
     * Wrap a single string in keyword identifiers.
     *
     * @param  string  $value
     * @return string
     */
    protected static function wrapValue($value)
    {
        if ($value !== '*') {
        	return '`' . str_replace('`', '``', $value) . '`';
	    }

	    return $value;
    }

    /**
     * Compile the "from" portion of the query.
     *
     * @param  \Curia\Database\Builder $query
     * @param  string  $table
     * @return string
     */
    protected static function compileFrom(QueryBuilder $query, $table)
    {
        return 'from ' . static::wrap($table);
    }

    /**
     * Compile the "limit" portions of the query.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  int  $limit
     * @return string
     */
    protected static function compileLimit(QueryBuilder $query, $limit)
    {
        return 'limit '.(int) $limit;
    }

    /**
     * Compile the "offset" portions of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  int  $offset
     * @return string
     */
    protected static function compileOffset(QueryBuilder $query, $offset)
    {
        return 'offset '.(int) $offset;
    }

    /**
     * Compile the "where" portions of the query.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @return string
     */
    protected static function compileWheres(QueryBuilder $query)
    {
        // Each type of where clauses has its own compiler function which is responsible
        // for actually creating the where clauses SQL. This helps keep the code nice
        // and maintainable since each clause has a very small method that it uses.
        if (is_null($query->wheres)) {
            return '';
        }

        // If we actually have some where clauses, we will strip off the first boolean
        // operator, which is added by the query builders for convenience so we can
        // avoid checking for the first clauses in each of the compilers methods.
        if (count($sql = static::compileWheresToArray($query)) > 0) {
            return static::concatenateWhereClauses($query, $sql);
        }

        return '';
    }

    /**
     * Get an array of all the where clauses for the query.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @return array
     */
    protected static function compileWheresToArray($query)
    {
        return collect($query->wheres)->map(function ($where) use ($query) {
            return $where['boolean'].' '.static::{"where{$where['type']}"}($query, $where);
        })->all();
    }

    /**
     * Compile a nested where clause.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereNested(QueryBuilder $query, $where)
    {
        // Here we will calculate what portion of the string we need to remove. If this
        // is a join clause query, we need to remove the "on" portion of the SQL and
        // if it is a normal query we need to take the leading "where" of queries.
        $offset = $query instanceof JoinClause ? 3 : 6;

        return '('.substr(static::compileWheres($where['query']), $offset).')';
    }

    /**
     * Compile a basic where clause.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereBasic(QueryBuilder $query, $where)
    {
        $value = static::parameter($where['value']);

        return static::wrap($where['column']).' '.$where['operator'].' '.$value;
    }

    /**
     * Compile a where condition with a sub-select.
     *
     * @param  \Curia\Database\QueryBuilder $query
     * @param  array   $where
     * @return string
     */
    protected static function whereSub(QueryBuilder $query, $where)
    {
        $select = static::compileSelect($where['query']);

        return static::wrap($where['column']).' '.$where['operator']." ($select)";
    }

    /**
     * Compile a "where null" clause.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereNull(QueryBuilder $query, $where)
    {
        return static::wrap($where['column']).' is null';
    }

    /**
     * Compile a "where not null" clause.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereNotNull(QueryBuilder $query, $where)
    {
        return static::wrap($where['column']).' is not null';
    }

    /**
     * Compile a "between" where clause.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereBetween(QueryBuilder $query, $where)
    {
        $between = $where['not'] ? 'not between' : 'between';

        return static::wrap($where['column']).' '.$between.' ? and ?';
    }

    /**
     * Compile a "where in" clause.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereIn(QueryBuilder $query, $where)
    {
        if (! empty($where['values'])) {
            return static::wrap($where['column']).' in ('.static::parameterize($where['values']).')';
        }

        return '0 = 1';
    }

    /**
     * Compile a "where not in" clause.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereNotIn(QueryBuilder $query, $where)
    {
        if (! empty($where['values'])) {
            return static::wrap($where['column']).' not in ('.static::parameterize($where['values']).')';
        }

        return '1 = 1';
    }

    /**
     * Compile a where in sub-select clause.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereInSub(QueryBuilder $query, $where)
    {
        return static::wrap($where['column']).' in ('.static::compileSelect($where['query']).')';
    }

    /**
     * Compile a where not in sub-select clause.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereNotInSub(QueryBuilder $query, $where)
    {
        return static::wrap($where['column']).' not in ('.static::compileSelect($where['query']).')';
    }

    /**
     * Get the appropriate query parameter place-holder for a value.
     *
     * @param  mixed   $value
     * @return string
     */
    public static function parameter($value)
    {
        return $value instanceof Expression ? $value->getValue($value) : '?';
    }

    /**
     * Format the where clause statements into one string.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $sql
     * @return string
     */
    protected static function concatenateWhereClauses($query, $sql)
    {
        $conjunction = $query instanceof JoinClause ? 'on' : 'where';

        return $conjunction.' ' . static::removeLeadingBoolean(implode(' ', $sql));
    }

    /**
     * Remove the leading boolean from a statement.
     *
     * @param  string  $value
     * @return string
     */
    protected static function removeLeadingBoolean($value)
    {
        return preg_replace('/and |or /i', '', $value, 1);
    }

    /**
     * Get the grammar specific operators.
     *
     * @return array
     */
    public static function getOperators()
    {
        return static::$operators;
    }

    /**
     * Create query parameter place-holders for an array.
     *
     * @param  array   $values
     * @return string
     */
    public static function parameterize(array $values)
    {
        return implode(', ', array_map(['static', 'parameter'], $values));
    }

    /**
     * Compile a "where date" clause.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereDate(QueryBuilder $query, $where)
    {
        return static::dateBasedWhere('date', $query, $where);
    }

    /**
     * Compile a "where time" clause.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereTime(QueryBuilder $query, $where)
    {
        return static::dateBasedWhere('time', $query, $where);
    }

    /**
     * Compile a "where day" clause.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereDay(QueryBuilder $query, $where)
    {
        return static::dateBasedWhere('day', $query, $where);
    }

    /**
     * Compile a "where month" clause.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereMonth(QueryBuilder $query, $where)
    {
        return static::dateBasedWhere('month', $query, $where);
    }

    /**
     * Compile a "where year" clause.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereYear(QueryBuilder $query, $where)
    {
        return static::dateBasedWhere('year', $query, $where);
    }

    /**
     * Compile a date based where clause.
     *
     * @param  string  $type
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function dateBasedWhere($type, QueryBuilder $query, $where)
    {
        $value = static::parameter($where['value']);

        return $type.'('.static::wrap($where['column']).') '.$where['operator'].' '.$value;
    }

    /**
     * Compile a where clause comparing two columns..
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereColumn(QueryBuilder $query, $where)
    {
        return static::wrap($where['first']).' '.$where['operator'].' '.static::wrap($where['second']);
    }

    /**
     * Compile a where exists clause.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereExists(QueryBuilder $query, $where)
    {
        return 'exists ('.static::compileSelect($where['query']).')';
    }

    /**
     * Compile a where exists clause.
     *
     * @param  \Curia\Database\QueryBuilder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereNotExists(QueryBuilder $query, $where)
    {
        return 'not exists ('.static::compileSelect($where['query']).')';
    }

    /**
     * Compile a raw where clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereRaw(QueryBuilder $query, $where)
    {
        return $where['sql'];
    }

    /**
     * Compile the "order by" portions of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $orders
     * @return string
     */
    protected static function compileOrders(QueryBuilder $query, $orders)
    {
        if (! empty($orders)) {
            return 'order by '.implode(', ', static::compileOrdersToArray($query, $orders));
        }

        return '';
    }

    /**
     * Compile the query orders to an array.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $orders
     * @return array
     */
    protected static function compileOrdersToArray(QueryBuilder $query, $orders)
    {
        return array_map(function ($order) {
            return ! isset($order['sql'])
                        ? static::wrap($order['column']).' '.$order['direction']
                        : $order['sql'];
        }, $orders);
    }

    /**
     * Compile the random statement into SQL.
     *
     * @param  string  $seed
     * @return string
     */
    public static function compileRandom($seed)
    {
        return 'RAND('.$seed.')';
    }

    /**
     * Compile the "group by" portions of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $groups
     * @return string
     */
    protected static function compileGroups(QueryBuilder $query, $groups)
    {
        return 'group by '.static::columnize($groups);
    }

    /**
     * Compile the "having" portions of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $havings
     * @return string
     */
    protected static function compileHavings(QueryBuilder $query, $havings)
    {
        $sql = implode(' ', array_map(['static', 'compileHaving'], $havings));

        return 'having '.static::removeLeadingBoolean($sql);
    }

    /**
     * Compile a single having clause.
     *
     * @param  array   $having
     * @return string
     */
    protected static function compileHaving(array $having)
    {
        // If the having clause is "raw", we can just return the clause straight away
        // without doing any more processing on it. Otherwise, we will compile the
        // clause into SQL based on the components that make it up from builder.
        if ($having['type'] === 'Raw') {
            return $having['boolean'].' '.$having['sql'];
        }

        return static::compileBasicHaving($having);
    }

    /**
     * Compile a basic having clause.
     *
     * @param  array   $having
     * @return string
     */
    protected static function compileBasicHaving($having)
    {
        $column = static::wrap($having['column']);

        $parameter = static::parameter($having['value']);

        return $having['boolean'].' '.$column.' '.$having['operator'].' '.$parameter;
    }

    /**
     * Compile a where row values condition.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected static function whereRowValues(QueryBuilder $query, $where)
    {
        $values = static::parameterize($where['values']);

        return '('.implode(', ', $where['columns']).') '.$where['operator'].' ('.$values.')';
    }

    /**
     * Compile the "join" portions of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $joins
     * @return string
     */
    protected static function compileJoins(QueryBuilder $query, $joins)
    {
        return collect($joins)->map(function ($join) use ($query) {
            $table = static::wrapTable($join->table);

            $nestedJoins = is_null($join->joins) ? '' : ' '.static::compileJoins($query, $join->joins);

            return trim("{$join->type} join {$table}{$nestedJoins} " . static::compileWheres($join));
        })->implode(' ');
    }

    /**
     * Compile a single union statement.
     *
     * @param  array  $union
     * @return string
     */
    protected static function compileUnion(array $union)
    {
        $conjunction = $union['all'] ? ' union all ' : ' union ';

        return $conjunction.'('.$union['query']->toSql().')';
    }

    /**
     * Compile the "union" queries attached to the main query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    protected static function compileUnions(QueryBuilder $query)
    {
        $sql = '';

        foreach ($query->unions as $union) {
            $sql .= static::compileUnion($union);
        }

        if (! empty($query->unionOrders)) {
            $sql .= ' '.static::compileOrders($query, $query->unionOrders);
        }

        if (isset($query->unionLimit)) {
            $sql .= ' '.static::compileLimit($query, $query->unionLimit);
        }

        if (isset($query->unionOffset)) {
            $sql .= ' '.static::compileOffset($query, $query->unionOffset);
        }

        return ltrim($sql);
    }

    /**
     * Compile an aggregated select clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $aggregate
     * @return string
     */
    protected static function compileAggregate(QueryBuilder $query, $aggregate)
    {
        $column = static::columnize($aggregate['columns']);

        // If the query has a "distinct" constraint and we're not asking for all columns
        // we need to prepend "distinct" onto the column name so that the query takes
        // it into account when it performs the aggregating operations on the data.
        if ($query->distinct && $column !== '*') {
            $column = 'distinct '.$column;
        }

        return 'select '.$aggregate['function'].'('.$column.') as aggregate';
    }

    /**
     * Compile an insert statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $values
     * @return string
     */
    public static function compileInsert(QueryBuilder $query, array $values)
    {
        // Essentially we will force every insert to be treated as a batch insert which
        // simply makes creating the SQL easier for us since we can utilize the same
        // basic routine regardless of an amount of records given to us to insert.
        $table = static::wrapTable($query->from);

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        $columns = static::columnize(array_keys(reset($values)));

        // We need to build a list of parameter place-holders of values that are bound
        // to the query. Each insert should have the exact same amount of parameter
        // bindings so we will loop through the record and parameterize them all.
        $parameters = collect($values)->map(function ($record) {
            return '('.static::parameterize($record).')';
        })->implode(', ');

        return "insert into $table ($columns) values $parameters";
    }

    /**
     * Compile an insert and get ID statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array   $values
     * @param  string  $sequence
     * @return string
     */
    public static function compileInsertGetId(QueryBuilder $query, $values, $sequence)
    {
        return static::compileInsert($query, $values);
    }

    /**
     * Compile an update statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $values
     * @return string
     */
    public static function compileUpdate(QueryBuilder $query, $values)
    {
        $table = static::wrapTable($query->from);

        // Each one of the columns in the update statements needs to be wrapped in the
        // keyword identifiers, also a place-holder needs to be created for each of
        // the values in the list of bindings so we can make the sets statements.
        $columns = static::compileUpdateColumns($values);

        // If the query has any "join" clauses, we will setup the joins on the builder
        // and compile them so we can attach them to this update, as update queries
        // can get join statements to attach to other tables when they're needed.
        $joins = '';

        if (isset($query->joins)) {
            $joins = ' '.static::compileJoins($query, $query->joins);
        }

        // Of course, update queries may also be constrained by where clauses so we'll
        // need to compile the where clauses and attach it to the query so only the
        // intended records are updated by the SQL statements we generate to run.
        $where = static::compileWheres($query);

        $sql = rtrim("update {$table}{$joins} set $columns $where");

        // If the query has an order by clause we will compile it since MySQL supports
        // order bys on update statements. We'll compile them using the typical way
        // of compiling order bys. Then they will be appended to the SQL queries.
        if (! empty($query->orders)) {
            $sql .= ' '.static::compileOrders($query, $query->orders);
        }

        // Updates on MySQL also supports "limits", which allow you to easily update a
        // single record very easily. This is not supported by all database engines
        // so we have customized this update compiler here in order to add it in.
        if (isset($query->limit)) {
            $sql .= ' '.static::compileLimit($query, $query->limit);
        }

        return rtrim($sql);
    }

    /**
     * Compile all of the columns for an update statement.
     *
     * @param  array  $values
     * @return string
     */
    protected static function compileUpdateColumns($values)
    {
        return collect($values)->map(function ($value, $key) {
            // if (static::isJsonSelector($key)) {
            //     return static::compileJsonUpdateColumn($key, new JsonExpression($value));
            // }

            return static::wrap($key).' = '.static::parameter($value);
        })->implode(', ');
    }

    /**
     * Prepare the bindings for an update statement.
     *
     * Booleans, integers, and doubles are inserted into JSON updates as raw values.
     *
     * @param  array  $bindings
     * @param  array  $values
     * @return array
     */
    public static function prepareBindingsForUpdate(array $bindings, array $values)
    {
        // $values = collect($values)->reject(function ($value, $column) {
        //     return $this->isJsonSelector($column) &&
        //         in_array(gettype($value), ['boolean', 'integer', 'double']);
        // })->all();

        $cleanBindings = Arr::except($bindings, ['join', 'select']);
        return array_values(
            array_merge($bindings['join'], $values, Arr::flatten($cleanBindings))
        );
    }

    /**
     * Compile an exists statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    public static function compileExists(QueryBuilder $query)
    {
        $select = static::compileSelect($query);

        return "select exists({$select}) as " . static::wrap('exists');
    }

    /**
     * Compile a delete statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    public static function compileDelete(QueryBuilder $query)
    {
        $table = static::wrapTable($query->from);

        $where = is_array($query->wheres) ? static::compileWheres($query) : '';

        return isset($query->joins)
                    ? static::compileDeleteWithJoins($query, $table, $where)
                    : static::compileDeleteWithoutJoins($query, $table, $where);
    }

    /**
     * Compile a delete query that uses joins.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $table
     * @param  array  $where
     * @return string
     */
    protected static function compileDeleteWithJoins($query, $table, $where)
    {
        $joins = ' '.static::compileJoins($query, $query->joins);

        $alias = strpos(strtolower($table), ' as ') !== false
                ? explode(' as ', $table)[1] : $table;

        return trim("delete {$alias} from {$table}{$joins} {$where}");
    }

    /**
     * Compile a delete query that does not use joins.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $table
     * @param  array  $where
     * @return string
     */
    protected static function compileDeleteWithoutJoins($query, $table, $where)
    {
        $sql = trim("delete from {$table} {$where}");

        // When using MySQL, delete statements may contain order by statements and limits
        // so we will compile both of those here. Once we have finished compiling this
        // we will return the completed SQL statement so it will be executed for us.
        if (! empty($query->orders)) {
            $sql .= ' '.static::compileOrders($query, $query->orders);
        }

        if (isset($query->limit)) {
            $sql .= ' '.static::compileLimit($query, $query->limit);
        }

        return $sql;
    }

    /**
     * Prepare the bindings for a delete statement.
     *
     * @param  array  $bindings
     * @return array
     */
    public static function prepareBindingsForDelete(array $bindings)
    {
        $cleanBindings = Arr::except($bindings, ['join', 'select']);

        return array_values(
            array_merge($bindings['join'], Arr::flatten($cleanBindings))
        );
    }

    /**
     * Compile a truncate table statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return array
     */
    public static function compileTruncate(QueryBuilder $query)
    {
        return ['truncate '.static::wrapTable($query->from) => []];
    }
}









