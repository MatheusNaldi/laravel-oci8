<?php namespace yajra\Oci8\Query\Grammars;

use \Illuminate\Database\Query\Builder;

class OracleGrammar extends \Illuminate\Database\Query\Grammars\Grammar {

	/**
	 * The keyword identifier wrapper format.
	 *
	 * @var string
	 */
	protected $wrapper = '%s';

    /**
	 * Compile the lock into SQL.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  bool|string  $value
	 * @return string
	 */
	protected function compileLock(Builder $query, $value)
	{
		if (is_string($value)) return $value;

		return $value ? 'for update' : 'lock in share mode';
	}

	/**
	 * Compile a select query into SQL.
	 *
	 * @param  \Illuminate\Database\Query\Builder
	 * @return string
	 */
	public function compileSelect(Builder $query)
	{
		if (is_null($query->columns)) $query->columns = array('*');

		$components = $this->compileComponents($query);

		// If an offset is present on the query, we will need to wrap the query in
		// a big "ANSI" offset syntax block. This is very nasty compared to the
		// other database systems but is necessary for implementing features.
		if ($query->limit > 0 OR $query->offset > 0)
		{
			return $this->compileAnsiOffset($query, $components);
		}

		return trim($this->concatenate($components));
	}

	/**
	 * Create a full ANSI offset clause for the query.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $components
	 * @return string
	 */
	protected function compileAnsiOffset(Builder $query, $components)
	{
		$start = $query->offset + 1;

		$constraint = $this->compileRowConstraint($query);

		$sql = $this->concatenate($components);

		// We are now ready to build the final SQL query so we'll create a common table
		// expression from the query and get the records with row numbers within our
		// given limit and offset value that we just put on as a query constraint.
		$temp = $this->compileTableExpression($sql, $constraint, $query);

                return $temp;
	}

	/**
	 * Compile the limit / offset row constraint for a query.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @return string
	 */
	protected function compileRowConstraint($query)
	{
		$start = $query->offset + 1;

		if ($query->limit > 0)
		{
			$finish = $query->offset + $query->limit;

			return "between {$start} and {$finish}";
		}

		return ">= {$start}";
	}

	/**
	 * Compile a common table expression for a query.
	 *
	 * @param  string  $sql
	 * @param  string  $constraint
	 * @return string
 	 */
	protected function compileTableExpression($sql, $constraint, $query)
	{
            if ($query->limit > 0) {
                return "select t2.* from ( select rownum AS \"rn\", t1.* from ({$sql}) t1 ) t2 where t2.\"rn\" {$constraint}";
            } else {
                return "select * from ({$sql}) where rownum {$constraint}";
            }
	}

	/**
	 * Compile the "limit" portions of the query.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  int  $limit
	 * @return string
	 */
	protected function compileLimit(Builder $query, $limit)
	{
		return '';
	}

	/**
	 * Compile the "offset" portions of the query.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  int  $offset
	 * @return string
	 */
	protected function compileOffset(Builder $query, $offset)
	{
		return '';
	}

	/**
	* Compile a truncate table statement into SQL.
	*
	* @param  \Illuminate\Database\Query\Builder  $query
	* @return array
	*/
	public function compileTruncate(Builder $query)
	{
		return array('truncate table '.$this->wrapTable($query->from) => array());
	}

	/**
	 * Compile an insert statement into SQL.
	 *
	 * @param  \Illuminate\Database\Query\Builder  $query
	 * @param  array  $values
	 * @return string
	 */
	public function compileInsert(Builder $query, array $values)
	{
		// Essentially we will force every insert to be treated as a batch insert which
		// simply makes creating the SQL easier for us since we can utilize the same
		// basic routine regardless of an amount of records given to us to insert.
		$table = $this->wrapTable($query->from);

		if ( ! is_array(reset($values)))
		{
			$values = array($values);
		}

		$columns = $this->columnize(array_keys(reset($values)));

		// We need to build a list of parameter place-holders of values that are bound
		// to the query. Each insert should have the exact same amount of parameter
		// bindings so we can just go off the first list of values in this array.
		$parameters = $this->parameterize(reset($values));

		$value = array_fill(0, count($values), "($parameters)");

		if (count($value) > 1) {
			$insertQueries = array();
			foreach ($value as $parameter) {
				$parameter = (str_replace(array('(',')'), '', $parameter));
				$insertQueries[] = "select ". $parameter . " from dual ";
			}
			$parameters = implode('union all ', $insertQueries);


			return "insert into $table ($columns) $parameters";
		}
		$parameters = implode(', ', $value);
		return "insert into $table ($columns) values $parameters";

	}

}