<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 * @package Nette\Database\Table
 */



/**
 * Filtered table representation.
 * Selection is based on the great library NotORM http://www.notorm.com written by Jakub Vrana.
 *
 * @author     Jakub Vrana
 * @author     Jan Skrasek
 *
 * @property-read string $sql
 * @package Nette\Database\Table
 */
class NTableSelection extends NObject implements Iterator, ArrayAccess, Countable
{
	/** @var NConnection */
	protected $connection;

	/** @var IReflection */
	protected $reflection;

	/** @var ICacheStorage */
	protected $cache;

	/** @var NSqlBuilder */
	protected $sqlBuilder;

	/** @var string table name */
	protected $name;

	/** @var string primary key field name */
	protected $primary;

	/** @var string|bool primary column sequence name, FALSE for autodetection */
	protected $primarySequence = FALSE;

	/** @var NTableRow[] data read from database in [primary key => ActiveRow] format */
	protected $rows;

	/** @var NTableRow[] modifiable data in [primary key => ActiveRow] format */
	protected $data;

	/** @var bool */
	protected $dataRefreshed = FALSE;

	/** @var mixed cache array of Selection and GroupedSelection prototypes */
	protected $globalRefCache;

	/** @var mixed */
	protected $refCache;

	/** @var string */
	protected $specificCacheKey;

	/** @var array of [conditions => [key => ActiveRow]]; used by GroupedSelection */
	protected $aggregation = array();

	/** @var array of touched columns */
	protected $accessedColumns;

	/** @var array of earlier touched columns */
	protected $previousAccessedColumns;

	/** @var bool should instance observe accessed columns caching */
	protected $observeCache = FALSE;

	/** @var bool recheck referencing keys */
	protected $checkReferenced = FALSE;

	/** @var array of primary key values */
	protected $keys = array();



	/**
	 * Creates filtered table representation.
	 * @param  NConnection
	 * @param  string  database table name
	 */
	public function __construct(NConnection $connection, $table, IReflection $reflection, ICacheStorage $cacheStorage = NULL)
	{
		$this->name = $table;
		$this->connection = $connection;
		$this->reflection = $reflection;
		$this->cache = $cacheStorage ? new NCache($cacheStorage, 'Nette.Database.' . md5($connection->getDsn())) : NULL;
		$this->primary = $reflection->getPrimary($table);
		$this->sqlBuilder = new NSqlBuilder($table, $connection, $reflection);
		$this->refCache = & $this->getRefTable($refPath)->globalRefCache[$refPath];
	}



	public function __destruct()
	{
		$this->saveCacheState();
	}



	public function __clone()
	{
		$this->sqlBuilder = clone $this->sqlBuilder;
	}



	/**
	 * @return NConnection
	 */
	public function getConnection()
	{
		return $this->connection;
	}



	/**
	 * @return IReflection
	 */
	public function getDatabaseReflection()
	{
		return $this->reflection;
	}



	/**
	 * @return string
	 */
	public function getName()
	{
		return $this->name;
	}



	/**
	 * @return string|array
	 */
	public function getPrimary()
	{
		if ($this->primary === NULL) {
			throw new LogicException("Table \"{$this->name}\" does not have a primary key.");
		}
		return $this->primary;
	}



	/**
	 * @return string
	 */
	public function getPrimarySequence()
	{
		if ($this->primarySequence === FALSE) {
			$this->primarySequence = NULL;

			$primary = $this->getPrimary();
			$driver = $this->connection->getSupplementalDriver();
			if ($driver->isSupported(ISupplementalDriver::SUPPORT_SEQUENCE)) {
				foreach ($driver->getColumns($this->name) as $column) {
					if ($column['name'] === $primary) {
						$this->primarySequence = $column['vendor']['sequence'];
						break;
					}
				}
			}
		}

		return $this->primarySequence;
	}



	/**
	 * @param  string
	 * @return NTableSelection provides a fluent interface
	 */
	public function setPrimarySequence($sequence)
	{
		$this->primarySequence = $sequence;
		return $this;
	}



	/**
	 * @return string
	 */
	public function getSql()
	{
		return $this->sqlBuilder->buildSelectQuery($this->getPreviousAccessedColumns());
	}



	/**
	 * Loads cache of previous accessed columns and returns it.
	 * @internal
	 * @return array|false
	 */
	public function getPreviousAccessedColumns()
	{
		if ($this->cache && $this->previousAccessedColumns === NULL) {
			$this->accessedColumns = $this->previousAccessedColumns = (array) $this->cache->load($this->getGeneralCacheKey());
		}

		return array_keys(array_filter((array) $this->previousAccessedColumns));
	}



	/**
	 * @internal
	 * @return NSqlBuilder
	 */
	public function getSqlBuilder()
	{
		return $this->sqlBuilder;
	}



	/********************* quick access ****************d*g**/



	/**
	 * Returns row specified by primary key.
	 * @param  mixed primary key
	 * @return NTableRow or FALSE if there is no such row
	 */
	public function get($key)
	{
		$clone = clone $this;
		return $clone->wherePrimary($key)->fetch();
	}



	/**
	 * Returns next row of result.
	 * @return NTableRow or FALSE if there is no row
	 */
	public function fetch()
	{
		$this->execute();
		$return = current($this->data);
		next($this->data);
		return $return;
	}



	/**
	 * Returns all rows as associative array.
	 * @param  string
	 * @param  string column name used for an array value or NULL for the whole row
	 * @return array
	 */
	public function fetchPairs($key, $value = NULL)
	{
		$return = array();
		foreach ($this as $row) {
			$return[is_object($row[$key]) ? (string) $row[$key] : $row[$key]] = ($value ? $row[$value] : $row);
		}
		return $return;
	}



	/********************* sql selectors ****************d*g**/



	/**
	 * Adds select clause, more calls appends to the end.
	 * @param  string for example "column, MD5(column) AS column_md5"
	 * @return NTableSelection provides a fluent interface
	 */
	public function select($columns)
	{
		$this->emptyResultSet();
		$this->sqlBuilder->addSelect($columns);
		return $this;
	}



	/**
	 * @deprecated
	 */
	public function find($key)
	{
		trigger_error(__METHOD__ . '() is deprecated; use $selection->wherePrimary() instead.', E_USER_WARNING);
		return $this->wherePrimary($key);
	}



	/**
	 * Adds condition for primary key.
	 * @param  mixed
	 * @return NTableSelection provides a fluent interface
	 */
	public function wherePrimary($key)
	{
		if (is_array($this->primary) && NValidators::isList($key)) {
			foreach ($this->primary as $i => $primary) {
				$this->where($primary, $key[$i]);
			}
		} elseif (is_array($key)) { // key contains column names
			$this->where($key);
		} else {
			$this->where($this->getPrimary(), $key);
		}

		return $this;
	}



	/**
	 * Adds where condition, more calls appends with AND.
	 * @param  string condition possibly containing ?
	 * @param  mixed
	 * @param  mixed ...
	 * @return NTableSelection provides a fluent interface
	 */
	public function where($condition, $parameters = array())
	{
		if (is_array($condition)) { // where(array('column1' => 1, 'column2 > ?' => 2))
			foreach ($condition as $key => $val) {
				if (is_int($key)) {
					$this->where($val);	// where('full condition')
				} else {
					$this->where($key, $val);	// where('column', 1)
				}
			}
			return $this;
		}

		$args = func_get_args();
		if (call_user_func_array(array($this->sqlBuilder, 'addWhere'), $args)) {
			$this->emptyResultSet();
		}

		return $this;
	}



	/**
	 * Adds order clause, more calls appends to the end.
	 * @param  string for example 'column1, column2 DESC'
	 * @return NTableSelection provides a fluent interface
	 */
	public function order($columns)
	{
		$this->emptyResultSet();
		$this->sqlBuilder->addOrder($columns);
		return $this;
	}



	/**
	 * Sets limit clause, more calls rewrite old values.
	 * @param  int
	 * @param  int
	 * @return NTableSelection provides a fluent interface
	 */
	public function limit($limit, $offset = NULL)
	{
		$this->emptyResultSet();
		$this->sqlBuilder->setLimit($limit, $offset);
		return $this;
	}



	/**
	 * Sets offset using page number, more calls rewrite old values.
	 * @param  int
	 * @param  int
	 * @return NTableSelection provides a fluent interface
	 */
	public function page($page, $itemsPerPage)
	{
		return $this->limit($itemsPerPage, ($page - 1) * $itemsPerPage);
	}



	/**
	 * Sets group clause, more calls rewrite old values.
	 * @param  string
	 * @param  string
	 * @return NTableSelection provides a fluent interface
	 */
	public function group($columns, $having = NULL)
	{
		$this->emptyResultSet();
		$this->sqlBuilder->setGroup($columns, $having);
		return $this;
	}



	/********************* aggregations ****************d*g**/



	/**
	 * Executes aggregation function.
	 * @param  string select call in "FUNCTION(column)" format
	 * @return string
	 */
	public function aggregation($function)
	{
		$selection = $this->createSelectionInstance();
		$selection->getSqlBuilder()->importConditions($this->getSqlBuilder());
		$selection->select($function);
		foreach ($selection->fetch() as $val) {
			return $val;
		}
	}



	/**
	 * Counts number of rows.
	 * @param  string  if it is not provided returns count of result rows, otherwise runs new sql counting query
	 * @return int
	 */
	public function count($column = NULL)
	{
		if (!$column) {
			$this->execute();
			return count($this->data);
		}
		return $this->aggregation("COUNT($column)");
	}



	/**
	 * Returns minimum value from a column.
	 * @param  string
	 * @return int
	 */
	public function min($column)
	{
		return $this->aggregation("MIN($column)");
	}



	/**
	 * Returns maximum value from a column.
	 * @param  string
	 * @return int
	 */
	public function max($column)
	{
		return $this->aggregation("MAX($column)");
	}



	/**
	 * Returns sum of values in a column.
	 * @param  string
	 * @return int
	 */
	public function sum($column)
	{
		return $this->aggregation("SUM($column)");
	}



	/********************* internal ****************d*g**/



	protected function execute()
	{
		if ($this->rows !== NULL) {
			return;
		}

		$this->observeCache = $this;

		try {
			$result = $this->query($this->getSql());

		} catch (PDOException $exception) {
			if (!$this->sqlBuilder->getSelect() && $this->previousAccessedColumns) {
				$this->previousAccessedColumns = FALSE;
				$this->accessedColumns = array();
				$result = $this->query($this->getSql());
			} else {
				throw $exception;
			}
		}

		$this->rows = array();
		$usedPrimary = TRUE;
		$result->setFetchMode(PDO::FETCH_ASSOC);
		foreach ($result as $key => $row) {
			$row = $this->createRow($result->normalizeRow($row));
			$primary = $row->getSignature(FALSE);
			$usedPrimary = $usedPrimary && $primary;
			$this->rows[($tmp=$primary) ? $tmp : $key] = $row;
		}
		$this->data = $this->rows;

		if ($usedPrimary && $this->accessedColumns !== FALSE) {
			foreach ((array) $this->primary as $primary) {
				$this->accessedColumns[$primary] = TRUE;
			}
		}
	}



	protected function createRow(array $row)
	{
		return new NTableRow($row, $this);
	}



	protected function createSelectionInstance($table = NULL)
	{
		return new NTableSelection($this->connection, ($tmp=$table) ? $tmp : $this->name, $this->reflection, $this->cache ? $this->cache->getStorage() : NULL);
	}



	protected function createGroupedSelectionInstance($table, $column)
	{
		return new NGroupedTableSelection($this, $table, $column);
	}



	protected function query($query)
	{
		return $this->connection->queryArgs($query, $this->sqlBuilder->getParameters());
	}



	protected function emptyResultSet()
	{
		$this->rows = NULL;
		$this->specificCacheKey = NULL;
	}



	protected function saveCacheState()
	{
		if ($this->observeCache === $this && $this->cache && !$this->sqlBuilder->getSelect() && $this->accessedColumns != $this->previousAccessedColumns) {
			$this->cache->save($this->getGeneralCacheKey(), $this->accessedColumns);
		}
	}



	/**
	 * Returns Selection parent for caching.
	 * @return NTableSelection
	 */
	protected function getRefTable(& $refPath)
	{
		return $this;
	}



	/**
	 * Returns general cache key indenpendent on query parameters or sql limit
	 * Used e.g. for previously accessed columns caching
	 * @return string
	 */
	protected function getGeneralCacheKey()
	{
		return md5(serialize(array(__CLASS__, $this->name, $this->sqlBuilder->getConditions())));
	}



	/**
	 * Returns object specific cache key dependent on query parameters
	 * Used e.g. for reference memory caching
	 * @return string
	 */
	protected function getSpecificCacheKey()
	{
		if ($this->specificCacheKey) {
			return $this->specificCacheKey;
		}

		return $this->specificCacheKey = md5($this->getSql() . json_encode($this->sqlBuilder->getParameters()));
	}



	/**
	 * @internal
	 * @param  string|NULL column name or (NULL to reload all columns & disable columns cache)
	 * @param  bool
	 */
	public function accessColumn($key, $selectColumn = TRUE)
	{
		if (!$this->cache) {
			return;
		}

		if ($key === NULL) {
			$this->accessedColumns = FALSE;
			$currentKey = key((array) $this->data);
		} elseif ($this->accessedColumns !== FALSE) {
			$this->accessedColumns[$key] = $selectColumn;
		}

		if ($selectColumn && !$this->sqlBuilder->getSelect() && $this->previousAccessedColumns && ($key === NULL || !isset($this->previousAccessedColumns[$key]))) {
			$this->previousAccessedColumns = FALSE;
			$this->emptyResultSet();
			$this->dataRefreshed = TRUE;

			if ($key === NULL) {
				// we need to move iterator in resultset
				$this->execute();
				while (key($this->data) !== $currentKey) {
					next($this->data);
				}
			}
		}
	}



	/**
	 * @internal
	 * @param  string
	 */
	public function removeAccessColumn($key)
	{
		if ($this->cache && is_array($this->accessedColumns)) {
			$this->accessedColumns[$key] = FALSE;
		}
	}



	/**
	 * Returns if selection requeried for more columns.
	 * @return bool
	 */
	public function getDataRefreshed()
	{
		return $this->dataRefreshed;
	}



	/********************* manipulation ****************d*g**/



	/**
	 * Inserts row in a table.
	 * @param  mixed array($column => $value)|Traversable for single row insert or Selection|string for INSERT ... SELECT
	 * @return NTableRow or FALSE in case of an error or number of affected rows for INSERT ... SELECT
	 */
	public function insert($data)
	{
		if ($data instanceof NTableSelection) {
			$data = $data->getSql();

		} elseif ($data instanceof Traversable) {
			$data = iterator_to_array($data);
		}

		$return = $this->connection->query($this->sqlBuilder->buildInsertQuery(), $data);
		$this->checkReferenced = TRUE;

		if (!is_array($data)) {
			return $return->rowCount();
		}

		if (!is_array($this->primary) && !isset($data[$this->primary]) && ($id = $this->connection->getInsertId($this->getPrimarySequence()))) {
			$data[$this->primary] = $id;
		}

		$row = $this->createRow($data);
		if ($signature = $row->getSignature(FALSE)) {
			$this->rows[$signature] = $row;
		}

		return $row;
	}



	/**
	 * Updates all rows in result set.
	 * Joins in UPDATE are supported only in MySQL
	 * @param  array|Traversable ($column => $value)
	 * @return int number of affected rows or FALSE in case of an error
	 */
	public function update($data)
	{
		if ($data instanceof Traversable) {
			$data = iterator_to_array($data);

		} elseif (!is_array($data)) {
			throw new InvalidArgumentException;
		}

		if (!$data) {
			return 0;
		}

		return $this->connection->queryArgs(
			$this->sqlBuilder->buildUpdateQuery(),
			array_merge(array($data), $this->sqlBuilder->getParameters())
		)->rowCount();
	}



	/**
	 * Deletes all rows in result set.
	 * @return int number of affected rows or FALSE in case of an error
	 */
	public function delete()
	{
		return $this->query($this->sqlBuilder->buildDeleteQuery())->rowCount();
	}



	/********************* references ****************d*g**/



	/**
	 * Returns referenced row.
	 * @param  string
	 * @param  string
	 * @param  bool  checks if rows contains the same primary value relations
	 * @return NTableSelection or array() if the row does not exist
	 */
	public function getReferencedTable($table, $column, $checkReferenced = FALSE)
	{
		$referenced = & $this->refCache['referenced'][$this->getSpecificCacheKey()]["$table.$column"];
		if ($referenced === NULL || $checkReferenced || $this->checkReferenced) {
			$this->execute();
			$this->checkReferenced = FALSE;
			$keys = array();
			foreach ($this->rows as $row) {
				if ($row[$column] === NULL) {
					continue;
				}

				$key = $row[$column] instanceof NTableRow ? $row[$column]->getPrimary() : $row[$column];
				$keys[$key] = TRUE;
			}

			if ($referenced !== NULL) {
				$a = array_keys($keys);
				$b = array_keys($referenced->rows);
				sort($a);
				sort($b);
				if ($a === $b) {
					return $referenced;
				}
			}

			if ($keys) {
				$referenced = $this->createSelectionInstance($table);
				$referenced->where($referenced->getPrimary(), array_keys($keys));
			} else {
				$referenced = array();
			}
		}

		return $referenced;
	}



	/**
	 * Returns referencing rows.
	 * @param  string
	 * @param  string
	 * @param  int primary key
	 * @return NGroupedTableSelection
	 */
	public function getReferencingTable($table, $column, $active = NULL)
	{
		$prototype = & $this->refCache['referencingPrototype']["$table.$column"];
		if (!$prototype) {
			$prototype = $this->createGroupedSelectionInstance($table, $column);
			$prototype->where("$table.$column", array_keys((array) $this->rows));
		}

		$clone = clone $prototype;
		$clone->setActive($active);
		return $clone;
	}



	/********************* interface Iterator ****************d*g**/



	public function rewind()
	{
		$this->execute();
		$this->keys = array_keys($this->data);
		reset($this->keys);
	}



	/** @return NTableRow */
	public function current()
	{
		if (($key = current($this->keys)) !== FALSE) {
			return $this->data[$key];
		} else {
			return FALSE;
		}
	}



	/**
	 * @return string row ID
	 */
	public function key()
	{
		return current($this->keys);
	}



	public function next()
	{
		next($this->keys);
	}



	public function valid()
	{
		return current($this->keys) !== FALSE;
	}



	/********************* interface ArrayAccess ****************d*g**/



	/**
	 * Mimic row.
	 * @param  string row ID
	 * @param  NTableRow
	 * @return NULL
	 */
	public function offsetSet($key, $value)
	{
		$this->execute();
		$this->rows[$key] = $value;
	}



	/**
	 * Returns specified row.
	 * @param  string row ID
	 * @return NTableRow or NULL if there is no such row
	 */
	public function offsetGet($key)
	{
		$this->execute();
		return $this->rows[$key];
	}



	/**
	 * Tests if row exists.
	 * @param  string row ID
	 * @return bool
	 */
	public function offsetExists($key)
	{
		$this->execute();
		return isset($this->rows[$key]);
	}



	/**
	 * Removes row from result set.
	 * @param  string row ID
	 * @return NULL
	 */
	public function offsetUnset($key)
	{
		$this->execute();
		unset($this->rows[$key], $this->data[$key]);
	}

}
