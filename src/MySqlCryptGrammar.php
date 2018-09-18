<?php
namespace Pixelstyle\AesMysql;

use \Illuminate\Support\Arr;
use \Illuminate\Database\Query\Builder;
use \Illuminate\Database\Query\JsonExpression;

class MySqlCryptGrammar extends \Illuminate\Database\Query\Grammars\MySqlGrammar {
	
	/**
	 * The columns that should be crypted while reading/writing
	 *
	 * @var array
	 */
	protected $crypted = array();

	public function setCryptedColumns ($crypted) {
		$this->crypted = $crypted;
	}

	private function encrypt ($value) {
		return 'AES_ENCRYPT('.$value.', \'PWD\')';
	}

	private function decrypt ($col, $as = false) {
		$ret = 'AES_DECRYPT('.$col.', \'PWD\')';
		if ($as === true) {
			$ret .= ' AS '.$col;
		} elseif ($as !== false) {
			$ret .= ' AS '.$as;
		}

		return $ret;
	}

	/**
	 * Convert an array of column names into a delimited string.
	 *
	 * @param  array   $columns
	 * @return string
	 */
	public function columnizeAndDecrypt(array $columns, $asRemoved = false)
	{
		return implode(', ', array_map([$this, 'wrapAndDecrypt'], $columns));
	}

	/**
	 * Wrap a value in keyword identifiers.
	 *
	 * @param  \Illuminate\Database\Query\Expression|string  $value
	 * @param  bool    $prefixAlias
	 * @return string
	 */
	public function wrapAndDecrypt($value, $prefixAlias = false, $addAliasIfCrypted = true)
	{
		if ($this->isExpression($value)) {
			return $this->getValue($value);
		}

		// If the value being wrapped has a column alias we will need to separate out
		// the pieces so we can wrap each of the segments of the expression on its
		// own, and then join these both back together using the "as" connector.
		if (stripos($value, ' as ') !== false) {
			return $this->wrapAliasedValueAndDecrypt($value, $prefixAlias);
		}
		
		$segments = explode('.', $value);
		$add_column_alias = '';
		if ($value != '*') {
			// encrypt column if necessary
			foreach ($segments as $segment) {
				if (in_array($segment, $this->crypted)) {
					if ($addAliasIfCrypted) {
						$add_column_alias = ' as '.$segments[count($segments) - 1];
					}

					return $this->decrypt($this->wrapSegments($segments)).$add_column_alias;
				}
			}
		}

		return $this->wrapSegments($segments);
	}

	/**
	 * Wrap a value that has an alias.
	 *
	 * @param  string  $value
	 * @param  bool  $prefixAlias
	 * @return string
	 */
	protected function wrapAliasedValueAndDecrypt($value, $prefixAlias = false)
	{
		$segments = preg_split('/\s+as\s+/i', $value);

		// If we are wrapping a table we need to prefix the alias with the table prefix
		// as well in order to generate proper syntax. If this is a column of course
		// no prefix is necessary. The condition will be true when from wrapTable.
		if ($prefixAlias) {
			$segments[1] = $this->tablePrefix.$segments[1];
		}

		return $this->wrapAndDecrypt($segments[0], false, false).' as '.$this->wrapValue($segments[1]);
	}



	/**
	 * Create query parameter place-holders for an array.
	 *
	 * @param  array   $values
	 * @return string
	 */
	public function parameterizeAndEncrypt(array $values)
	{
		return implode(', ', array_map([$this, 'parameterAndEncrypt'], $values));
	}

	/**
	 * Get the appropriate query parameter place-holder for a value.
	 *
	 * @param  mixed   $value
	 * @return string
	 */
	public function parameterAndEncrypt($value)
	{
		return $this->encrypt($this->isExpression($value) ? $this->getValue($value) : '?');
	}
	



	/**
	 * Compile a basic where clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereBasic(Builder $query, $where)
	{
		$value = $this->parameter($where['value']);

		// encrypt or decrypt column if necessary
		if (in_array($where['column'], $this->crypted)) {
			if (in_array($where['operator'], array('LIKE', 'NOT LIKE'))) {
				return $this->wrapAndDecrypt($where['column'], false, false).' '.$where['operator'].' '.$value;
			} elseif (in_array($where['column'], $this->crypted)) {
				$value = $this->parameterAndEncrypt($where['value']);
				return $this->wrap($where['column']).' '.$where['operator'].' '.$value;
			}
		}

		return $this->wrap($where['column']).' '.$where['operator'].' '.$value;
	}


	/**
	 * Compile a "where in" clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereIn(Builder $query, $where)
	{
		if (! empty($where['values'])) {

			// encrypt column if necessary
			if (in_array($where['column'], $this->crypted)) {
				$values = $this->parameterizeAndEncrypt($where['values']);
			} else {
				$values = $this->parameterize($where['values']);
			}
			
			return $this->wrap($where['column']).' in ('.$values.')';
		}

		return '0 = 1';
	}


	/**
	 * Compile a "where not in" clause.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $where
	 * @return string
	 */
	protected function whereNotIn(Builder $query, $where)
	{
		if (! empty($where['values'])) {

			// encrypt column if necessary
			if (in_array($where['column'], $this->crypted)) {
				$values = $this->parameterizeAndEncrypt($where['values']);
			} else {
				$values = $this->parameterize($where['values']);
			}
			
			return $this->wrap($where['column']).' not in ('.$values.')';
		}

		return '1 = 1';
	}
	

	public function compileSelect(Builder $query)
	{
		// If the query does not have any columns set, we'll set the columns to the
		// * character to just get all of the columns from the database. Then we
		// can build the query and concatenate all the pieces together as one.
		$original = $query->columns;

		if (is_null($query->columns)) {
			$query->columns = ['*'];
		}
		
		// add crypted columns
		if (in_array('*', $query->columns)) {
			foreach ($this->crypted as $crypted_col) {
				if (!in_array($crypted_col, $query->columns)) {
					$query->columns[] = $crypted_col;
				}
			}
		}

		// To compile the query, we'll spin through each component of the query and
		// see if that component exists. If it does we'll just call the compiler
		// function for the component which is responsible for making the SQL.



		$sql_components = [];
		foreach ($this->selectComponents as $component) {
			// To compile the query, we'll spin through each component of the query and
			// see if that component exists. If it does we'll just call the compiler
			// function for the component which is responsible for making the SQL.
			if (! is_null($query->$component)) {
				$method = 'compile'.ucfirst($component);
				if ($method == 'compileColumns') {
					$method = 'compileColumnsCrypted';
				}

				$sql_components[$component] = $this->$method($query, $query->$component);
			}
		}
		
		$sql = trim($this->concatenate($sql_components));

		$query->columns = $original;


		// original grammar function
		if ($query->unions) {
			$sql = '('.$sql.') '.$this->compileUnions($query);
		}

		return $sql;
	}

	/**
	 * Compile the "select *" portion of the query.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $columns
	 * @return string|null
	 */
	protected function compileColumnsCrypted(Builder $query, $columns)
	{
		// If the query is actually performing an aggregating select, we will let that
		// compiler handle the building of the select clauses, as it will need some
		// more syntax that is best handled by that function to keep things neat.
		if (! is_null($query->aggregate)) {
			return;
		}

		$select = $query->distinct ? 'select distinct ' : 'select ';

		return $select.$this->columnizeAndDecrypt($columns);
	}

	/**
	 * Compile an update statement into SQL.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $values
	 * @return string
	 */
	public function compileUpdate(Builder $query, $values)
	{
		$table = $this->wrapTable($query->from);

		// Each one of the columns in the update statements needs to be wrapped in the
		// keyword identifiers, also a place-holder needs to be created for each of
		// the values in the list of bindings so we can make the sets statements.
		$columns = collect($values)->map(function ($value, $key) {
			// encrypt column if necessary
			if (in_array($key, $this->crypted)) {
				$value = $this->parameterAndEncrypt($value);
			} else {
				$value = $this->parameter($value);
			}
			return $this->wrap($key).' = '.$value;
		})->implode(', ');

		// If the query has any "join" clauses, we will setup the joins on the builder
		// and compile them so we can attach them to this update, as update queries
		// can get join statements to attach to other tables when they're needed.
		$joins = '';

		if (isset($query->joins)) {
			$joins = ' '.$this->compileJoins($query, $query->joins);
		}

		// Of course, update queries may also be constrained by where clauses so we'll
		// need to compile the where clauses and attach it to the query so only the
		// intended records are updated by the SQL statements we generate to run.
		$wheres = $this->compileWheres($query);

		return trim("update {$table}{$joins} set $columns $wheres");
	}
	

	public function compileInsert(Builder $query, array $values)
	{
		// Essentially we will force every insert to be treated as a batch insert which
		// simply makes creating the SQL easier for us since we can utilize the same
		// basic routine regardless of an amount of records given to us to insert.
		$table = $this->wrapTable($query->from);

		if (! is_array(reset($values))) {
			$values = [$values];
		}

		$plain_columns = array_keys(reset($values));
		$columns = $this->columnize($plain_columns);

		// We need to build a list of parameter place-holders of values that are bound
		// to the query. Each insert should have the exact same amount of parameter
		// bindings so we will loop through the record and parameterize them all.
		$parameters = collect($values)->map(function ($record) {
			// check if column is crypted
			$retCols = array();
			foreach ($record as $col => $rec) {
				// encrypt column if necessary
				if (in_array($col, $this->crypted)) {
					$retCols[] = $this->parameterAndEncrypt($rec);
				} else {
					$retCols[] = $this->parameter($rec);
				}
			}
			return '('.implode(', ', $retCols).')';
		})->implode(', ');

		return "insert into $table ($columns) values $parameters";
	}
}