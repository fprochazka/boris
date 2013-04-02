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
 * Representation of filtered table grouped by some column.
 * GroupedSelection is based on the great library NotORM http://www.notorm.com written by Jakub Vrana.
 *
 * @author     Jakub Vrana
 * @author     Jan Skrasek
 * @package Nette\Database\Table
 */
class NGroupedTableSelection extends NTableSelection
{
	/** @var NTableSelection referenced table */
	protected $refTable;

	/** @var string grouping column name */
	protected $column;

	/** @var int primary key */
	protected $active;



	/**
	 * Creates filtered and grouped table representation.
	 * @param  NTableSelection  $refTable
	 * @param  string  database table name
	 * @param  string  joining column
	 */
	public function __construct(NTableSelection $refTable, $table, $column)
	{
		$this->refTable = $refTable;
		$this->column = $column;
		parent::__construct($refTable->connection, $table, $refTable->reflection, $refTable->cache ? $refTable->cache->getStorage() : NULL);
	}



	/**
	 * Sets active group.
	 * @internal
	 * @param  int  primary key of grouped rows
	 * @return NGroupedTableSelection
	 */
	public function setActive($active)
	{
		$this->active = $active;
		return $this;
	}



	/** @deprecated */
	public function through($column)
	{
		trigger_error(__METHOD__ . '() is deprecated; use ' . __CLASS__ . '::related("' . $this->name . '", "' . $column . '") instead.', E_USER_WARNING);
		$this->column = $column;
		$this->delimitedColumn = $this->refTable->connection->getSupplementalDriver()->delimite($this->column);
		return $this;
	}



	public function select($columns)
	{
		if (!$this->sqlBuilder->getSelect()) {
			$this->sqlBuilder->addSelect("$this->name.$this->column");
		}

		return parent::select($columns);
	}



	public function order($columns)
	{
		if (!$this->sqlBuilder->getOrder()) {
			// improve index utilization
			$this->sqlBuilder->addOrder("$this->name.$this->column" . (preg_match('~\bDESC\z~i', $columns) ? ' DESC' : ''));
		}

		return parent::order($columns);
	}



	/********************* aggregations ****************d*g**/



	public function aggregation($function)
	{
		$aggregation = & $this->getRefTable($refPath)->aggregation[$refPath . $function . $this->getSql() . json_encode($this->sqlBuilder->getParameters())];

		if ($aggregation === NULL) {
			$aggregation = array();

			$selection = $this->createSelectionInstance();
			$selection->getSqlBuilder()->importConditions($this->getSqlBuilder());
			$selection->select($function);
			$selection->select("$this->name.$this->column");
			$selection->group("$this->name.$this->column");

			foreach ($selection as $row) {
				$aggregation[$row[$this->column]] = $row;
			}
		}

		if (isset($aggregation[$this->active])) {
			foreach ($aggregation[$this->active] as $val) {
				return $val;
			}
		}
	}



	public function count($column = NULL)
	{
		$return = parent::count($column);
		return isset($return) ? $return : 0;
	}



	/********************* internal ****************d*g**/



	protected function execute()
	{
		if ($this->rows !== NULL) {
			$this->observeCache = $this;
			return;
		}

		$hash = $this->getSpecificCacheKey();
		$accessedColumns = $this->accessedColumns;

		$referencing = & $this->refCache['referencing'][$this->getGeneralCacheKey()];
		$this->observeCache      = & $referencing['observeCache'];
		$this->accessedColumns   = & $referencing[$hash]['accessed'];
		$this->specificCacheKey  = & $referencing[$hash]['specificCacheKey'];
		$this->rows              = & $referencing[$hash]['rows'];
		$data                    = & $referencing[$hash]['data'];

		if ($data === NULL) {
			// we have not fetched any data yet => init accessedColumns by cached accessedColumns
			$this->accessedColumns = $accessedColumns;

			$limit = $this->sqlBuilder->getLimit();
			$rows = count($this->refTable->rows);
			if ($limit && $rows > 1) {
				$this->sqlBuilder->setLimit(NULL, NULL);
			}
			parent::execute();
			$this->sqlBuilder->setLimit($limit, NULL);
			$data = array();
			$offset = array();
			$this->accessColumn($this->column);
			foreach ((array) $this->rows as $key => $row) {
				$ref = & $data[$row[$this->column]];
				$skip = & $offset[$row[$this->column]];
				if ($limit === NULL || $rows <= 1 || (count($ref) < $limit && $skip >= $this->sqlBuilder->getOffset())) {
					$ref[$key] = $row;
				} else {
					unset($this->rows[$key]);
				}
				$skip++;
				unset($ref, $skip);
			}
		}

		$this->observeCache = $this;
		$this->data = & $data[$this->active];
		if ($this->data === NULL) {
			$this->data = array();
		} else {
			foreach ($this->data as $row) {
				$row->setTable($this); // injects correct parent GroupedSelection
			}
			reset($this->data);
		}
	}



	protected function getRefTable(& $refPath)
	{
		$refObj = $this->refTable;
		$refPath = $this->name . '.';
		while ($refObj instanceof NGroupedTableSelection) {
			$refPath .= $refObj->name . '.';
			$refObj = $refObj->refTable;
		}

		return $refObj;
	}



	/********************* manipulation ****************d*g**/



	public function insert($data)
	{
		if ($data instanceof Traversable && !$data instanceof NTableSelection) {
			$data = iterator_to_array($data);
		}

		if (NArrays::isList($data)) {
			foreach (array_keys($data) as $key) {
				$data[$key][$this->column] = $this->active;
			}
		} else {
			$data[$this->column] = $this->active;
		}

		return parent::insert($data);
	}



	public function update($data)
	{
		$builder = $this->sqlBuilder;

		$this->sqlBuilder = clone $this->sqlBuilder;
		$this->where($this->column, $this->active);
		$return = parent::update($data);

		$this->sqlBuilder = $builder;
		return $return;
	}



	public function delete()
	{
		$builder = $this->sqlBuilder;

		$this->sqlBuilder = clone $this->sqlBuilder;
		$this->where($this->column, $this->active);
		$return = parent::delete();

		$this->sqlBuilder = $builder;
		return $return;
	}

}