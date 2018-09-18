<?php
namespace Pixelstyle\AesMysql;

use \Exception;
use \ArrayAccess;
use \JsonSerializable;
use \Illuminate\Support\Arr;
use \Illuminate\Support\Str;
use \Illuminate\Contracts\Support\Jsonable;
use \Illuminate\Contracts\Support\Arrayable;
use \Illuminate\Contracts\Routing\UrlRoutable;
use \Illuminate\Contracts\Queue\QueueableEntity;
use \Illuminate\Database\Eloquent\Relations\Pivot;
use \Illuminate\Contracts\Queue\QueueableCollection;
use \Illuminate\Database\Query\Builder as QueryBuilder;
use \Illuminate\Database\ConnectionResolverInterface as Resolver;
use \Pixelstyle\AesMysql\MySqlCryptGrammar;

class Model extends \Illuminate\Database\Eloquent\Model {
	
	/**
	 * The columns that should be crypted while reading/writing
	 *
	 * @var array
	 */
	protected $crypted = array();

	/**
	 * Get a new query builder instance for the connection.
	 *
	 * @return \Illuminate\Database\Query\Builder
	 */
	protected function newBaseQueryBuilder()
	{
		$connection = $this->getConnection();
		$grammar = new MySqlCryptGrammar();
		$grammar->setCryptedColumns($this->crypted);

		return new QueryBuilder(
			$connection, $grammar, $connection->getPostProcessor(), $this->crypted
		);
	}
}