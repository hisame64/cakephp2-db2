<?php
/**
 * DB2 ibm driver for DBO
 *
 * PHP versions 5
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Marco Ferragina
 * @package       datasources
 * @subpackage    datasources.models.datasources.dbo
 * @license       MIT License (http://www.opensource.org/licenses/mit-license.php)
 */

App::uses('DboSource', 'Model/Datasource');


///////////////////// NOTE /////////////////////////////
//
// Insert queries needs journaling, read here:
// http://forums.zend.com/viewtopic.php?f=68&t=8616
//
////////////////////////////////////////////////////////

/**
 * Short description for class.
 *
 * Long description for class
 *
 * @package       cake
 * @subpackage    cake.cake.libs.model.datasources.dbo
 */
class DB2 extends DboSource {

/**
 * Driver description
 *
 * @var string
 */
	public $description = "IBM DB2 DBO Driver";

/**
 * Database keyword used to assign aliases to identifiers.
 *
 * @var string
 */
	public $alias = "";

/**
 * Table/column starting quote
 *
 * @var string
 */
	public $startQuote = "";

/**
 * Table/column end quote
 *
 * @var string
 */
	public $endQuote = "";
    


    private $queryFields = array();
/**
 * Columns
 *
 * @var array
 */
	//var $columns = array();
    public $columns = array('primary_key' => array('name' => 'integer NOT NULL GENERATED BY DEFAULT AS IDENTITY (START WITH 1 INCREMENT BY 1)'),
                            'string' => array('type' => 'varchar', 'limit' => '255'),
                            'char' => array('type' => 'char', 'limit' => '255'),
                            'varchar' => array('type' => 'varchar', 'limit' => '255'),
                            'clob' => array('type' => 'text'),
                            'integer' => array('type' => 'int', 'limit' => '11'),
                            'smallint' => array('type' => 'int', 'limit' => '6'),
                            'float' => array('type' => 'float'),
                            'numeric' => array('type' => 'numeric'),
                            'decimal' => array('type' => 'numeric'),
                            'datetime' => array('type' => 'datetime', 'format' => 'Y-m-d h:i:s', 'formatter' => 'date'),
                            'timestamp' => array('type' => 'datetime', 'format' => 'Y-m-d h:i:s', 'formatter' => 'date'),
                            'timestmp' => array('type' => 'datetime', 'format' => 'Y-m-d h:i:s', 'formatter' => 'date'),
                            'time' => array('type' => 'time', 'format' => 'h:i:s', 'formatter' => 'date'),
                            'date' => array('type' => 'date', 'format' => 'Y-m-d', 'formatter' => 'date'),
                            'binary' => array('type' => 'blob'),
                            'boolean' => array('type' => 'tinyint', 'limit' => '1'));

/**
 * Whether or not to cache the results of DboSource::name() and DboSource::conditions()
 * into the memory cache.  Set to false to disable the use of the memory cache.
 *
 * @var boolean.
 */
	public $cacheMethods = true;

/**
* Connects to the database using options in the given configuration array.
*
* @return boolean True if the database could be connected, else false
*/
	public function connect() {
		$this->connected = false;
		try {
			$flags = array(
				PDO::ATTR_PERSISTENT => $this->config['persistent'],
				PDO::ATTR_EMULATE_PREPARES => true,
			);
			if (!empty($this->config['encoding'])) {
				//$flags[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES ' . $config['encoding'];
			}
			$this->_connection = new PDO(
				"ibm:*LOCAL", "{$this->config['login']}", "{$this->config['password']}", $flags
			);
            $query = "SET CURRENT SCHEMA = {$this->config['database']}";
            $this->_connection->query($query);

			$this->_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$this->connected = true;
		} catch (PDOException $e) {
			throw new MissingConnectionException(array('class' => $e->getMessage()));
		}
		return $this->connected;
	}

/**
 * Check if the ODBC extension is installed/loaded
 *
 * @return boolean
 */
	public function enabled() {
		return in_array('ibm', PDO::getAvailableDrivers());
	}

/**
 * Returns an array of sources (tables) in the database.
 *
 * @return array Array of tablenames in the database
 */
    public function listSources() {
        $cache = parent::listSources();
        if ($cache != null) {
            return $cache;
        }

        $result = $this->_connection->query("select * from qsys2.systables where TABLE_SCHEMA = '".$this->config['database']."'");
        $tables = array_map('trim', $result->fetchAll(PDO::FETCH_COLUMN, 0));
        unset($result);
        parent::listSources($tables);
        return $tables;
    }

/**
 * Returns an array of the fields in given table name.
 *
 * @param Model $model Model object to describe
 * @return array Fields in table. Keys are name and type
 */
    public function describe($model) {
        $cache = parent::describe($model);
        if ($cache != null) {
            //return $cache;
        }

        $fields = array();

        $sql = "select column_name, data_type, length, numeric_scale from qsys2.syscolumns where table_schema = '".$this->config['database']."' and table_name = '".strtoupper($this->fullTableName($model, false))."'";

        $fields = array();
        try{
            $result = $this->_connection->query($sql);
        } catch (PDOException $e) {
            debug($e->getMessage());
        }
        if ($result instanceof PDOStatement == false) {
            return $fields;
        }
        $rows = $result->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($rows)) {
            return $fields;
        }
        unset($result);

        foreach ($rows as $row) {
            $cols = array_keys($row);
            $row['DATA_TYPE'] = strtolower(trim($row['DATA_TYPE']));
            $fields[strtolower($row['COLUMN_NAME'])] = $this->columns[$row['DATA_TYPE']];
            $fields[strtolower($row['COLUMN_NAME'])]['length'] = $row['LENGTH'];
        }
        $this->_cacheDescription($model->tablePrefix . $model->table, $fields);
        #debug($fields);

        return $fields;
    }

/**
 * Enter description here...
 *
 * @param unknown_type $results
 */
	public function resultSet(&$results) {
		$numFields = $results->columnCount();
        
        $this->map = array();
        $index = 0; 
        foreach ($this->queryFields as $column) {
            $model = "";
            $name = "";
            $tmp = explode(".", $column);
            $model = $tmp[0];
            if (count($tmp) > 1) $name = $tmp[1];
            if (!empty($name) && strpos($name, $this->virtualFieldSeparator) === false) {
                $this->map[$index++] = array($model, $name, "VAR_STRING");
            }
            else {
                $this->map[$index++] = array(0, $column, "VAR_STRING");
            }
        }
	}

/**
 * Fetches the next row from the current result set
 *
 * @return unknown
 */

	/**
 * Fetches the next row from the current result set
 *
 * @return mixed array with results fetched and mapped to column names or false if there is no results left to fetch
 */
	public function fetchResult() {
		if ($row = $this->_result->fetch()) {
			$resultRow = array();
			foreach ($this->map as $col => $meta) {
				list($table, $column, $type) = $meta;
				if (strpos($column,'COUNT(')!== false) {
					$column = 'count';
				}
                if (is_resource($row[$col])) {
                    $resultRow[$table][$column] = stream_get_contents($row[$col], 64000);
                }
                else {
                    $resultRow[$table][$column] = trim($row[$col]);
                    if ($type === 'boolean' && !is_null($row[$col])) {
                        $resultRow[$table][$column] = $this->boolean($resultRow[$table][$column]);
                    }
                }
			}
			return $resultRow;
		}
		$this->_result->closeCursor();
		return false;
	}

    
    /*	
    public function create(Model $model, $fields = null, $values = null) {
        $idx = 0;
        foreach ($values as $value) {
            $values[$idx] = strtoupper($value);
            $idx++;
        }
        return parent::create($model, $fields, $values);
    }

	public function update(Model $model, $fields = array(), $values = null, $conditions = null) {
        $idx = 0;
        foreach ($values as $value) {
            $values[$idx] = strtoupper($value);
            $idx++;
        }
        return parent::update($model, $fields, $values, $conditions);
    }
    */

/**
 * Renders a final SQL statement by putting together the component parts in the correct order
 *
 * @param string $type type of query being run. e.g select, create, update, delete, schema, alter.
 * @param array $data Array of data to insert into the query.
 * @return string Rendered SQL expression to be run.
 */
    public function renderStatement($type, $data) {
        extract($data);

        switch (strtolower($type)) {
            case 'select':
                $sql = "SELECT {$fields} FROM {$table} {$alias} {$joins} {$conditions} {$group} {$order}";
                if (!empty($limit)) {
                    $pieces = explode(", ", $fields);
                    $t = explode(".", $pieces[0]);
                    $objName =  $t[0];
                    $ordert1 = "";
                    $ordert2 = "";
                    if (!empty($order)) {
                        $ordert1 = 'ORDER BY ORDER OF t1';
                        $ordert2 = 'ORDER BY ORDER OF t2';
                    }
                    $limit_sql = "SELECT * 
                                  FROM ( SELECT t1.*, row_number() OVER() AS CakeRowNum
                                         FROM (".$sql.") AS t1 $ordert1 
                                       ) AS t2 
                                  WHERE t2.CakeRowNum BETWEEN ".$limit." ".$ordert2;
                    return $limit_sql;
                }
                return $sql;
            default:
                return parent::renderStatement($type, $data);
        }
    }



/**
 * Returns a limit statement in the correct format for the particular database.
 *
 * @param integer $limit Limit of results returned
 * @param integer $offset Offset from which to start results
 * @return string SQL limit/offset statement
 */
	public function limit($limit, $offset = null) {
        if ($limit) {
            if (is_null($offset)) {
                return "0 AND $limit";
            }
            else {
                $start = $offset + 1;
                $end = $offset + $limit;
                return "$start AND $end";
            }
        }
		return null;
	}

	/**
 * Returns an SQL calculation, i.e. COUNT() or MAX()
 *
 * @param model $model
 * @param string $func Lowercase name of SQL function, i.e. 'count' or 'max'
 * @param array $params Function parameters (any values must be quoted manually)
 * @return string An SQL calculation function
 */
	public function calculate($model, $func, $params = array()) {
		$params = (array)$params;

		switch (strtolower($func)) {
			case 'count':
				if (!isset($params[0])) {
					$params[0] = '*';
				}
				if (!isset($params[1])) {
					$params[1] = 'count';
				}
				if (is_object($model) && $model->isVirtualField($params[0])){
					$arg = $this->__quoteFields($model->getVirtualField($params[0]));
				} else {
					$arg = $this->name($params[0]);
				}
				return "COUNT($arg) AS $params[1]";
			case 'max':
			case 'min':
				if (!isset($params[1])) {
					$params[1] = $params[0];
				}
				if (is_object($model) && $model->isVirtualField($params[0])) {
					$arg = $this->__quoteFields($model->getVirtualField($params[0]));
				} else {
					$arg = $this->name($params[0]);
				}
				return strtoupper($func) . '(' . $arg . ') AS ' . $this->name($params[1]);
			break;
		}
	}

/**
 * Returns a quoted and escaped string of $data for use in an SQL statement.
 *
 * @param string $data String to be prepared for use in an SQL statement
 * @param string $column The column into which this data will be inserted
 * @return string Quoted and escaped data
 */
	public function value($data, $column = null) {
		if (is_array($data) && !empty($data)) {
			return array_map(
				array(&$this, 'value'),
				$data, array_fill(0, count($data), $column)
			);
		} elseif (is_object($data) && isset($data->type, $data->value)) {
			if ($data->type == 'identifier') {
				return $this->name($data->value);
			} elseif ($data->type == 'expression') {
				return $data->value;
			}
		} elseif (in_array($data, array('{$__cakeID__$}', '{$__cakeForeignKey__$}'), true)) {
			return $data;
		}

		if ($data === null || (is_array($data) && empty($data))) {
			return 'NULL';
		}

		if (empty($column)) {
			$column = $this->introspectType($data);
		}
		switch ($column) {
			case 'binary':
				return $this->_connection->quote($data, PDO::PARAM_LOB);
			break;
			case 'boolean':
				return $this->_connection->quote($this->boolean($data, true), PDO::PARAM_BOOL);
			break;
			case 'string':
			case 'char':
			case 'varchar':
                $data = str_replace("'", "''", $data);
				return "'$data'";
			default:
				if ($data === '') {
					return 'NULL';
				}
				if (is_float($data)) {
					return sprintf('%F', $data);
				}
				return "'$data'";
			break;
		}
	}

/**
 * Builds and generates an SQL statement from an array.	 Handles final clean-up before conversion.
 *
 * @param array $query An array defining an SQL query
 * @param Model $model The model object which initiated the query
 * @return string An executable SQL statement
 * @see DboSource::renderStatement()
 */
	public function buildStatement($query, $model) {
        if (count($query['fields']) == 1) {
            $this->queryFields = array_map('trim', explode(',', $query['fields'][0]));
        } else {
            $this->queryFields = $query['fields'];
        }
		$query = array_merge(array('offset' => null, 'joins' => array()), $query);
		return parent::buildStatement($query, $model);
	}


/**
 * Generates the fields list of an SQL query.
 *
 * @param Model $model
 * @param string $alias Alias table name
 * @param mixed $fields
 * @param boolean $quote If false, returns fields array unquoted
 * @return array
 */
	public function fields(Model $model, $alias = null, $fields = array(), $quote = true) {
		if (empty($fields) && !$model->schema(true)) {
			$fields = '*';
		}
		return parent::fields($model, $alias, $fields, $quote);
	}

}
