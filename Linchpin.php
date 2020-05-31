<?php
/* ### Linchpin: A PDO Databse Wrapper ###
 *	Developer:	Loren Supernaw & lots of Googlefu
 *				Any extra functions are externally
 *				referenced by that function's definition
 *	Source:		https://github.com/supergnaw/linchpin
 *	Version:	7.0.0
 *	Date:		2020-04-13
 *
 * ### Purpose ###
 *	I just wanted to create my own "lightweight" but "powerful" PDO
 *	wrapper in PHP because I like making things and who doesn't love
 *	buzzwords. I designed this with the purpose of having it be the
 *	baseline for database interactions with future projects to allow
 *	for easier project development.
 *
 * ### SQL Executor ###
 *	- sqlexec(), sql_exec(), exec_sql()
 *	These all call the same function, I just added the aliases because
 *	I sometimes forget how I named the function. These are simple and
 *	execute queries with or without parameters. The first parameter passed
 *	is the query itself and the second, optional parameter is an array of
 *	key=>value pairs to use with the query.
 *
 * ### Query Builders ###
 *	These are a set of functions that build queries from user-defined
 *	inputs. They work well enough for simple queries but do not handle
 *	things like duplicate columns and multiple types of joins in the
 *	same query very well. It is best to use these either for simple
 *	tasks or in a development setting to help troubleshoot while you
 *	work out the kinks of your code. Or not at all. As the old adage
 *	goes, is the juice really worth the squeeze?
 *
 *	fetch_table()	- fetches a table
 *	insert_row()	- inserts a row into a table
 *	update_row()	- updates a row in a table
 *	delete_row()	- drops a row from a table
 *
 * ### Transactions ###
 *	- transexec()
 *	This functions identically to sqlexec() but instead processes an
 *	array of query => params all at once as a transaction instead of as
 *	individual queries. As of version 7.0.0, this function will also
 *	accept an sql transaction as a single string and any number of
 *	parameters as a single key => val array, and will parse the data
 *	as such. This enables the use of multiple of the same queries with
 *	different parameters, which is not possible using the original
 *	query => params formatting, and the original design may be removed
 *	in future versions. For now this functionality will remain.
 *
 * ### Things To Do ###
 * - expand insert/update/delete where parameters beyond a=b
 * - fix the where/filter implode to avoid single incorrect columns fucking up array to string conversions
 * - add BETWEEN operator to query builder, or not.
 * - add @variable functionality for columns in array_to_wheres/filter
 *
 * ### Helpful Resources ###
 *	The following resource helped in the creation of this class:
 *	http://culttt.com/2012/10/01/roll-your-own-pdo-php-class/
 *	http://code.tutsplus.com/tutorials/why-you-should-be-using-phps-pdo-for-database-access--net-12059
 */

# Configuration File
/*
 *	A typical config file should contain the database host, username,
 *	password, and database name to access the database. If the file does
 *	not exist, Linchipin will automatically generate one with the the
 *	with the appropriate contents, as exampled below:
	<?php
		define( 'DB_HOST', 'localhost' );
		define( 'DB_USER', 'root' );
		define( 'DB_PASS', '' );
		define( 'DB_NAME', 'databasename' );
	?>
*/
// script timing
if ( !defined( 'SCRIPT_START' )) define( 'SCRIPT_START', microtime( true ));

// optional config file;
define( 'DB_CONF', __DIR__ . DIRECTORY_SEPARATOR . 'linchpin_config.php' );

class Linchpin {
	// Class Variables
	public $dbh;		// database handler
	public $stmt;	// query statement holder
	public $err;		// error log array
	public $debug;	// debug log array

	##	1.0 Structs
	//	  ____  _                   _
	//	 / ___|| |_ _ __ _   _  ___| |_ ___
	//	 \___ \| __| '__| | | |/ __| __/ __|
	//	  ___) | |_| |  | |_| | (__| |_\__ \
	//	 |____/ \__|_|   \__,_|\___|\__|___/

	// Default constructor
	public function __construct() {
		// create config file if it doesn't exist
		if( !file_exists( DB_CONF )) $this->create_config();
		// load configuration file
		$this->load_config();
	}
	// Default destructor
	public function __destruct() {
		// close any existing database connection
		$this->close();
	}
	// Create configuration file if it doesn't exist
	function create_config() {
		$config = "<?php
		  define( 'DB_HOST', 'localhost' );
		  define( 'DB_USER', 'root' );
		  define( 'DB_PASS', '' );
		  define( 'DB_NAME', 'database_name' );";
		file_put_contents( DB_CONF, $config );
		if( file_exists( DB_CONF )) {
			die( "New Linchipin configuration file created, please update database info: " . DB_CONF );
		} else {
			die( "Could not create Linchpin configuration file, please verify directory permissions: " . DB_CONF );
		}
	}
	// Load configuration file or use passed vars
	function load_config() {
		if( defined( 'DB_CONF' )) {
			try {
				// include the configuration file
				if( !file_exists( DB_CONF )) {
					throw new Exception( 'Unable to locate Linchpin configuration file: ' . DB_CONF );
				} else {
					// load database configuration
					require_once( DB_CONF );

					// set database settings
					if( !defined( 'DB_HOST' )) throw new Exception( "Linchpin missing database host." );
					$host = DB_HOST;
					if( !defined( 'DB_USER' )) throw new Exception( "Linchpin missing database user." );
					$user = DB_USER;
					if( !defined( 'DB_PASS' )) throw new Exception( "Linchpin missing database password." );
					$pass = DB_PASS;
					if( !defined( 'DB_NAME' )) throw new Exception( "Linchpin missing database name." );
					$name = DB_NAME;
				}
			} catch( Exception $e ) {
				$this->err[] = $e->getMessage();
				return false;
			}
		} else {
			die( "Linchipin config file not defined." );
		}

		// set class vars
		$this->set_vars( $host, $user, $pass, $name );
		return true;
	}
	// Set class vairable defaults then connect
	public function set_vars( $host, $user, $pass, $name, $dir = "database_directory", $type = "mysql" ) {
		// set the class variables, use defined constants or passed variables
		$this->dbHost = $host;
		$this->dbUser = $user;
		$this->dbPass = $pass;
		$this->dbName = $name;
		$this->dbDir = $dir;
		$this->dbType = $type;
		$this->debug = array();

		// enable debug dump
		$this->logDebug = true;

		// delete existing connection
		if( !is_null( $this->dbh )) $this->dbh = null;
	}
	// Create database directory
	public function create_db_dir( $dir ) {
		if( is_dir( $dir )) {
			return true;
		} else {
			mkdir( $dir, 0755 );
			if( is_dir( $dir )) {
				return true;
			} else {
				$this->err[] = "Failed to create database directory.";
				return false;
			}
		}
	}

	##	2.0 Connections
	//	   ____                            _   _
	//	  / ___|___  _ __  _ __   ___  ___| |_(_) ___  _ __  ___
	//	 | |   / _ \| '_ \| '_ \ / _ \/ __| __| |/ _ \| '_ \/ __|
	//	 | |__| (_) | | | | | | |  __/ (__| |_| | (_) | | | \__ \
	//	  \____\___/|_| |_|_| |_|\___|\___|\__|_|\___/|_| |_|___/

	// Connect to database
	public function connect() {
		// check for existing connection
		if( true === $this->check_connection()) {
			return true;
		}

		// check connection parameters
		try {
			if( !property_exists( $this, 'dbHost' ))	throw new Exception ( 'Missing database connection property: host' );
			if( !property_exists( $this, 'dbUser' ))	throw new Exception ( 'Missing database connection property: user' );
			if( !property_exists( $this, 'dbPass' ))	throw new Exception ( 'Missing database connection property: password' );
			if( !property_exists( $this, 'dbName' ))	throw new Exception ( 'Missing database connection property: database name' );
			if( !property_exists( $this, 'dbDir' ))		throw new Exception ( 'Missing database connection property: database directory' );
			if( !property_exists( $this, 'dbType' ))	throw new Exception ( 'Missing database connection property: database type' );
		} catch( Exception $e ) {
			$this->err[] = $e->getMessage();
			return false;
		}

		// connection options
		$options = array(
			PDO::ATTR_PERSISTENT => true,
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
		);

		// create new connection
		try {
			switch( $this->dbType ) {
				case 'mssql':	// MS Sql Server
					$this->dbh = new PDO( "mssql:host={$this->dbHost};dbname={$this->dbName}", $this->dbUser, $this->dbPass, $options );
					if( $this->logDebug ) $this->debug[] = "connection created";
					break;
				case 'sybase':	// Sybase with PDO_DBLIB
					$this->dbh = new PDO( "sybase:host={$this->dbHost};dbname={$this->dbName}", $this->dbUser, $this->dbPass, $options );
					if( $this->logDebug ) $this->debug[] = "connection created";
					break;
				case 'sqlite':	// SQLite
					$this->dbh = new PDO( "sqlite:". $this->dbDir . DIRECTORY_SEPARATOR . $this->dbName);
					if( $this->logDebug ) $this->debug[] = "connection created";
					break;
				case 'mysql':	// Mysql
					$this->dbh = new PDO( "mysql:host={$this->dbHost};dbname={$this->dbName}", $this->dbUser, $this->dbPass, $options );
					if( $this->logDebug ) $this->debug[] = "connection created";
					break;
			}
		} catch( PDOException $exception ) {
			// catch error
			$this->err[] = "Failed to connect to database: " . $exception->getMessage();
			return false;
		}

		// connection successful
		return true;
	}
	// Test connection
	public function check_connection() {
		// check if connected already
		if ( !empty ( $this->dbh )) {
			// check if connection is still alive
			$this->prep( 'SELECT 1' );
			$this->execute();
			$res = $this->results();

			if ( 1 === $res[0]['1'] ) {
				if( $this->logDebug ) $this->debug[] = "connection exists";
				return true;
			} else {
				// kill dead connection
				$this->close();
				if( $this->logDebug ) $this->debug[] = "Connection lost.";	// debug
				return false;
			}
		} else {
			return false;
		}
	}
	// Disconnect from database
	public function close() {
		$this->dbh = null;		// secure
		unset ( $this->dbh );	// super secure
		if( empty( $this->dbh )) {
			return true;
		} else {
			return false;
		}
	}

	##	3.0 Statement Execution
	//	  ____  _        _                            _     _____                     _   _
	//	 / ___|| |_ __ _| |_ ___ _ __ ___   ___ _ __ | |_  | ____|_  _____  ___ _   _| |_(_) ___  _ __
	//	 \___ \| __/ _` | __/ _ \ '_ ` _ \ / _ \ '_ \| __| |  _| \ \/ / _ \/ __| | | | __| |/ _ \| '_ \
	//	  ___) | || (_| | ||  __/ | | | | |  __/ | | | |_  | |___ >  <  __/ (__| |_| | |_| | (_) | | | |
	//	 |____/ \__\__,_|\__\___|_| |_| |_|\___|_| |_|\__| |_____/_/\_\___|\___|\__,_|\__|_|\___/|_| |_|

	// Execute query with optional parameters
	public function sqlexec( $query, $params = null, $close = false ) {
		// verify query is a string
		if( true !== is_string( $query )) {
			$this->err[] = 'Error: Could not execute query because it is not a string.';
			return false;
		}

		// varify query is a valid string
		$query = trim( $query );
		if( empty( $query )) {
			$this->err[] = 'Error: empty string passed as query.';
			return false;
		}

		// verify varible and token numbers match
		if( !empty( $params )) {
			$vars = $this->verify_token_to_params( $query, $params );
			if( true != $vars ) {
				if( is_array( $vars )) {
					$params = $this->remove_extra_params( $params, $vars );
				} else {
					return false;
				}
			}
		}

		// connect to database
		if (!$this->connect()) return false;

		// debug
		if ( $this->logDebug ) $this->debug[] = "Connection created.";

		// prepare statement
		$this->prep( $query, $params );

		// bind parameters
		if ( !empty( $params ) && is_array( $params )) {
			foreach( $params as $name => $value ) {
				// bind parameters
				if( !$this->bind( $name, $value )) $this->err[] = "Could not bind {$value} to {$name}.";
				// debug
				if( $this->logDebug ) $this->debug[] = "Parameter bound: '{$value}' to `{$name}`";
			}
		}

		// execute & return
		if ( $this->execute()) {
			// debug
			if( $this->logDebug ) $this->debug[] = "Statement successfully executed.";

			// return results of query based on statement type
			$string = str_replace( array( "\n", "\t" ), array( " ", "" ), $query );
			$type = trim( strtoupper( strstr( $string, ' ', true )));
			switch( $type ) {
				case 'SELECT':	// return all resulting rows
				case 'SHOW':
					if( $this->logDebug ) $this->debug[] = "Return results.";
					$return = $this->results();
					break;
				case 'INSERT':	// return number of rows affected
				case 'UPDATE':
				case 'DELETE':
					// if requesting insert primary key
					if( false !== strpos( $string, 'LAST_INSERT_ID()' )) {
						$return = $this->results();
						$this->err[] = 'Returning last row insert ID';
					}
					// else return affected rows
					else {
						$count = $this->row_count();
						if( $this->logDebug ) $this->debug[] = "Return number of rows affected: {$count}";
						$return = $count;
					}
					break;
				default:		// i don't know what you want from me but it worked anyway
					if( $this->logDebug ) $this->debug[] = "No case for switch: {$type}";
					$return = true;
					break;
			}
		} else {
			$return = false;
		}

		// close the connection if requested
		if( true == $close ) $this->close();

		// return query results
		return $return;
	}
	// Prepare a statement query
	public function prep( $query, $params = null ) {
		try {
			if( empty( $params )) {
				$this->stmt = $this->dbh->prepare( $query );
			} else {
				$this->stmt = $this->dbh->prepare( $query, $params );
			}
			return true;
		} catch( PDOException $exception ) {
			$this->err[] = $exception->getMessage();
			return false;
		}
	}
	// Verify no missing or extra tokens/variables
	public function verify_token_to_params( $query, $params = array()) {
		// verify each token has an associated parameter
		$missingParams = array();
		preg_match_all( "/:[A-Za-z_][\w]+/", $query, $tokens );
		$tokens = $tokens[0];
		if( !empty( $tokens )) {
			foreach( $tokens as $token ) {
				$oken = ltrim( $token, ':' );
				if( !key_exists( $token, $params ) && !key_exists( $oken, $params )) {
					// token doesn't have a matching parameter
					$missingParams[] = $oken;
				}
			}
		}

		// verify each parameter has an associated token
		$extraParams = array();
		if( !empty( $params )) {
			foreach( $params as $var => $val ) {
				$var = ':' . ltrim( $var, ':' );
				if( !in_array( $var, $tokens )) {
					// parameter doesn't have a matching token
					$extraParams[] = $var;
				}
			}
		}

		// retun true of matching
		if( empty( $missingParams ) && empty( $extraParams )) {
			return true;
		}

		// return missing parameter
		if( empty( $missingParams ) && !empty( $extraParams )) {
			return $extraParams;
		}

		// oops, can't complete the query because you're missing parameters for included tokens
		if( !empty( $missingParams )) {
			$this->err[] = "Missing ".count( $missingParams )." parameters: ".implode( ', ', $missingParams );
			return false;
		}
	}

	// Remove extra parameters from an array
	function remove_extra_params( $params, $extra ) {
		foreach( $extra as $key ) {
			unset( $params[$key] );
			unset( $params[ltrim( $key, ':' )] );
		}
		return $params;
	}

	// Bind query parameters
	public function bind( $name, $value, $type = null, $table = null ) {
		// get value type if not set
		if( empty( $type )) {
			if( !empty( $table )) {
				$type = $this->col_datatype( $name, $table );
			} else {
				switch( true ) {
					case is_int( $value ):	// integer
						$type = PDO::PARAM_INT;
						break;
					case is_bool( $value ):	// boolean
						$type = PDO::PARAM_BOOL;
						break;
					case is_null( $value ):	// null
						$type = PDO::PARAM_NULL;
						break;
					case is_array( $value ):
						$this->err[] = "{$name} parameter value is an array: [" . implode( ', ', $value ) . "]";
						return false;
						break;
					default:				// string
						$type = PDO::PARAM_STR;
				}
			}
		}

		// backwards compatibility; older versions require colon prefix where newer versions do not
		if( ':' != substr( $name, 0, 1 )) $name = ':' . $name;

		// bind value to parameter
		if( true == $this->stmt->bindValue( $name, $value, $type )) {
			return true;
		} else {
			$this->err[] = "Failed to bind '{$value}' to :{$name} ({$type})";
			return false;
		}
	}
	// Execute a prepared statement
	public function execute() {
		// sececute query
		try {
			if( !$this->stmt->execute()) {
				$error = $this->stmt->errorInfo();
				$this->err[] = "{$error[2]} (MySQL error {$error[1]})";
			}
		} catch( Exception $e ) {
			$this->err[] = $e->getMessage();
			return false;
		}
		return true;
	}
	// Return associated array
	public function results() {
		return $this->stmt->fetchAll ( PDO::FETCH_ASSOC );
	}
	// Get the number of rows affected by the last query
	public function row_count() {
		return $this->stmt->rowCount();
	}

	##	4.0 Transactions
	//	  _____                               _   _
	//	 |_   _| __ __ _ _ __  ___  __ _  ___| |_(_) ___  _ __  ___
	//	   | || '__/ _` | '_ \/ __|/ _` |/ __| __| |/ _ \| '_ \/ __|
	//	   | || | | (_| | | | \__ \ (_| | (__| |_| | (_) | | | \__ \
	//	   |_||_|  \__,_|_| |_|___/\__,_|\___|\__|_|\___/|_| |_|___/

	// Begin a transaction
	public function trans_begin() {
		if( $this->dbh->beginTransaction()) {
			$this->dbh->setAttribute(PDO::ATTR_AUTOCOMMIT, FALSE);
			return true;
		} else {
			return false;
		}
	}
	// End a transaction and commit changes
	public function trans_end() { // add functionality for sqlite - sleep for a few seconds then try again
		if( $this->trans_active()) {
			if( $this->dbh->commit()) {
				$this->dbh->setAttribute(PDO::ATTR_AUTOCOMMIT, TRUE);
				return true;
			} else {
				return false;
			}
		} else {
			$this->err[] = "There is no active transaction.";
			return false;
		}
	}
	// Cancel a transaction and roll back changes
	public function trans_cancel() {
		return $this->dbh->rollBack();
	}
	// Check if transaction is currently active
	public function trans_active() {
		return $this->dbh->inTransaction();
	}
	// Perform a transaction of queries
	public function transexec( $queries, $testMode = false ) {
		// create new connection
		$this->connect();

		// make sure queries isn't empty
		if( $this->logDebug ) $this->debug[] = "Check transaction queries is not empty.";
		if( empty( $queries )) {
			$this->err[] = "Error: transaction failed because no queries were passed.";
			return false;
		}

		// check if queries is an array
		if( $this->logDebug ) $this->debug[] = "Check formatting if passed transaction queries.";
		if( !is_array( $queries )) {
			$this->err[] = "Warning: transactions must be an array of queries.";
			return false;
		}

		// separate a combined transaction
		if( is_array( $queries ) && 1 == count( $queries )) {
			$params = end( $queries );
			$sqls = key( $queries );
			$sqls = array_filter( explode( ';', $sqls ));
			if( 1 < count( $sqls )) {
				// if it was a combined transaction
				$queries = array();
				foreach( $sqls as $sql ) {
					$queries["{$sql};"] = $params;
				}
			}
			unset( $sqls );
			unset( $sql );
		}

		// verify no active transactions
		if( $this->logDebug ) $this->debug[] = "Check no transaction is currently active.";
		if( true == $this->trans_active()) {
			$this->err[] = "Warning: transaction is currently active.";
			return false;
		}

		// start the transaction
		if( $this->logDebug ) $this->debug[] = "Begin new transaction.";
		if( !$this->trans_begin()) {
			$this->err[] = "Error: could not begin transaction.";
			return false;
		}

		// verify the transaction has started
		if( !$this->trans_active()) {
			$this->err[] = "Error: transaction was requested but for some reason does not exist.";
			return false;
		}

		// loop through each query
		foreach( $queries as $sql => $params ) {
			// verify variable and token numbers match
			$vars = $this->verify_token_to_params( $sql, $params );
			if( true !== $vars ) {
				if( is_array( $vars )) {
					$params = $this->remove_extra_params( $params, $vars );
				} else {
					return false;
				}
			}

			// prepare
			$stmt = $this->dbh->prepare( $sql );

			// bind
			if( !empty( $params ) && is_array( $params )) {
				foreach( $params as $name => $value ) {
					switch( true ) {
						case is_null( $value ):
						case is_int( $value ):
							$type = PDO::PARAM_INT;
							break;
						case is_bool( $value ):
							$type = PDO::PARAM_BOOL;
							break;
						case is_null( $value ):
							$type = PDO::PARAM_NULL;
							break;
						default:
							$type = PDO::PARAM_STR;
					}
					if( ':' != substr ( $name, 0, 1 )) $name = ':' . $name;
					if( !$stmt->bindValue( $name, $value, $type )) {
						$this->err[] = "Error: could not bind '{$value}' to {$name}.";
					}
				}
			}

			// execute
			try {
				if( !$stmt->execute()) {
					$error = $stmt->errorInfo();
					$this->err[] = "{$error[2]} (MySQL error {$error[1]})";
				}
			} catch( Exception $e ) {
				$this->err[] = $e->getMessage();
				return false;
			}
			// results
			$res[] = $stmt->rowCount();/**/
		}

		// end/commit transaction
		if( $this->logDebug ) $this->debug[] = "Attempting to end/commit transaction...";
		if( true === $testMode ) {
			if( $this->logDebug ) $this->debug[] = "Test mode enabled, rolling back transaction (make sure table is InnoDB!)";
			if( !$this->trans_cancel()) {
				$this->err[] = "Error: test transaction could not be rolled back.";
				return false;
			} else {
				if( empty( $this->err )) {
					if( $this->logDebug ) $this->debug[] = "Complete: transaction tested successfully with no errors.";
					return $res;
				} else {
					$cnt = count( $this->err );
					$errz = ( 1 == $cnt ) ? 'error' : 'errors';
					if( $this->logDebug ) $this->debug[] = "Complete: transaction tested successfully but {$cnt} {$errz} occured.";
					return $res;
				}
			}
		} else {
			if( !empty( $this->err )) {
				// execution errors exist
				if( $this->logDebug ) $this->debug[] = "Errors present in error log, rolling back transaction.";
				if( !$this->trans_cancel()) {
					if( $this->logDebug ) $this->debug[] = "Failed to roll back transaction.";
				} else {
					if( $this->logDebug ) $this->debug[] = "Transaction rolled back successfully.";
				}
			} else {
				// no execution errors, attempt to commit
				if (!$this->trans_end()) {
					$this->err[] = "Error: could not commit changes.";
					if (!$this->trans_cancel()) { // rollback on failure
						$this->err[] = "Error: failed to rollback the transaction.";
						return false;
					} else {
						if( $this->logDebug ) $this->debug[] = "Transaction rolled back successfully.";
					}
				} else {
					if( $this->logDebug ) $this->debug[] = "Transaction completed successfully.";
					return $res;
				}
			}
		}
	}

	##	5.0 Schema
	//	  ____       _
	//	 / ___|  ___| |__   ___ _ __ ___   __ _
	//	 \___ \ / __| '_ \ / _ \ '_ ` _ \ / _` |
	//	  ___) | (__| | | |  __/ | | | | | (_| |
	//	 |____/ \___|_| |_|\___|_| |_| |_|\__,_|

	// Validate a table is in the database
	public function valid_table ( $table, $reload = false ) {
		// set persistant table variable between function calls
		static $tables = array();

		// check if database tables needs to be reloaded
		if (empty($tables) || true == $reload) {
			// debug
			if( $this->logDebug ) $this->debug[] = "Querying for tables.";

			// clear for new table
			$tables = array();

			$res = $this->sqlexec("SHOW TABLES");
			if ( !empty ( $res )) {
				foreach ($res as $key => $tbl) $tables[] = current($tbl);
			}
		}

		// check if table is present in database
		if (in_array($table, $tables)) {
			return true;
		} else {
			$this->err[] = "Warning: invalid table provided {$table}";
			return false;
		}
	}
	// Validate a column is in a table
	public function valid_column( $table, $column, $reload = false ) {
		// set persistence
		static $tbl = '';
		static $cols = array();

		// update table and columns
		if( $tbl != $table || $reload == true ) {
			$tbl = $table;
			$cols = array();
			$res = $this->column_types($tbl);

			if( false != $res ) {
				foreach( $res as $col ) {
					$cols[] = $col['Field'];
				}
			} else {
				return false;
			}
		}

		// verify column is valid
		if( in_array( $column, $cols )) {
			return true;
		} else {
			return false;
		}
	}
	// Gets table columns and attributes
	public function column_types( $table, $column = null ) {
		// verify table exists
		if (false == $this->valid_table($table)) return false;

		// get table columns
		$results = $this->sqlexec("SHOW COLUMNS IN `{$table}`", null, $table);

		// return results
		return $results;
	}
	// Gets datatype of column
	public function col_datatype( $column, $table ) {
		// verify table and column
		if (false == $this->valid_column($table, $column)) return false;

		// initialize static variables
		static $tbl = '';
		static $colData = array();

		// update table data
		if (empty($tbl) || $tbl != $table) {
			$tbl = $table;
			unset($colData);
			$cols = $this->column_types($tbl);
			foreach ($cols as $key => $col) {
				list($cols[$key]['Type']) = explode("(", $cols[$key]['Type']);
				$colData[$col['Field']] = $col;
			}
		}

		// default datatype arrays
		return $colData[$column]['Type'];
	}
	// Gets an array of columns for a table
	public function get_columns ( $table ) {
		// get calumn data
		$results = $this->column_types($table);

		// return false on error or empty
		if (false == $results) return false;

		// var prep
		$columns = array();
		// parse columns
		foreach ($results as $result) $columns[] = $result['Field'];
		// return data
		return $columns;
	}

	##	6.0 Table Display
	//	  _____     _     _        ____  _           _
	//	 |_   _|_ _| |__ | | ___  |  _ \(_)___ _ __ | | __ _ _   _
	//	   | |/ _` | '_ \| |/ _ \ | | | | / __| '_ \| |/ _` | | | |
	//	   | | (_| | |_) | |  __/ | |_| | \__ \ |_) | | (_| | |_| |
	//	   |_|\__,_|_.__/|_|\___| |____/|_|___/ .__/|_|\__,_|\__, |
	//										  |_|            |___/

	// Display two dimensional array or specified table as an ascii-styled table
	public function ascii_table( $table, $textFormat = array(), $borders = 2, $class = '' ) {
		// data check
		if (!is_array($table)) {
			if (false == $this->valid_table($table)) {
				$sql = "SELECT * FROM `{$table}`";
				$table = $this->sql_exec($sql);
			} else {
				return false;
			}
		}

		// var cleaning
		if ( !is_array ( $textFormat )) $textFormat = array ();
		if ( empty ( $borders )) $borders = 2;

		// get column widths
		$headers = array_keys(current($table));	// get column names
		$length = array();						// set lengths for each item
		foreach ($headers as $header) $length[$header] = strlen( utf8_decode ( strip_tags ( $header )));
		foreach ($table as $tr => $row) {
			// get max length of all items
			foreach ($row as $col => $item) {
				// strip html tags (invisible so would mess up widths)
				$item = strip_tags($item);
				// format numbers as needed
				if (array_key_exists($col, $textFormat)) $table[$tr][$col] = $this->num_format($item, $textFormat[$col]);
				// adds padding offsets for fomatting as needed
				$offsets = array('money' => 2, '$' => 2, 'percent' => 2, '%' => 2);
				$offset = (array_key_exists($col, $textFormat)
					&& array_key_exists($textFormat[$col], $offsets))
					? $offsets[$textFormat[$col]]
						: 0;
				// compare
				$length[$col] = max($length[$col], strlen ( utf8_decode ( $item )) + $offset);
			}
		}

		// aesthetic correction for header centering
		foreach ($length as $item => $len) if (( strlen ( utf8_decode ( $item )) % 2) != ($len % 2)) $length[$item] = $len + 1;

		// create divider
		$div = "+";
		$interval = ($borders > 1) ? "--+" : "---";	// h & z junction
		$vert = ($borders > 0) ? "|" : " ";			// vertical dividers
		foreach ($length as $header => $len) $div .= (str_repeat("-", $len)) . $interval;
		if ($borders > 0) $code[] = $div;			// add divider as long as borders included

		// add column headers
		$row = "";
		foreach ($headers as $header) {
			// $row .= "| " . strtoupper($header) . (str_repeat(" ", $length[$header] - strlen($header))) . " ";
			$row .= "{$vert} " . $this->ascii_format(strtoupper($header), $length[$header], 'center') . " ";
		}
		$code[] = "{$row}{$vert}";
		if ($borders > 1) $code[] = $div;

		// add each item
		foreach ($table as $row) {
			// begin row
			$line = "";
			foreach ($row as $key => $item) {
				// add item to row with appropriate padding
				$align = (array_key_exists($key, $textFormat)) ? $textFormat[$key] : 'left';
				$line .= "{$vert} " . $this->ascii_format($item, $length[$key], $align) . " ";
			}
			// add row and divider
			$code[] = "{$line}{$vert}";
			if ($borders > 2) $code[] = $div;
		}

		// bottom border
		if ($borders == 2 || $borders == 1) $code[] = $div;

		// implode and print
		$code = implode("\n", $code);
		$class = ( !empty ( $class )) ? "class = '{$class}'" : '';
		echo "<pre {$class}>{$code}</pre>";
	}
	// Add whitespace padding to a string
	public function ascii_format($html, $length = 0, $format = "left") {
		$text = preg_replace("/<[^>]*>/", "", $html);
		if (is_numeric($length) && $length > strlen ( utf8_decode ( $text ))) {
			// get proper length
			$length = max($length, strlen ( utf8_decode ( $text )));
			switch ($format) {
				case 'right':
				case 'r':
					$text = str_repeat(" ", $length - strlen ( utf8_decode ( $text ))) . $html;
					break;
				case 'center':
				case 'c':
					$temp = $length - strlen ( utf8_decode ( $text ));
					$left = floor($temp / 2);
					$right = ceil($temp / 2);
					$text = str_repeat(" ", $left) . $html . str_repeat(" ", $right);
					break;
				case 'money':
				case '$':
					$text = (is_numeric($text)) ? number_format($text, 2) : $text;
					$padd = $length - strlen ( utf8_decode ( $text )) - 2;
					$text = "$ " . str_repeat(" ", $padd) . $text;
					break;
				case 'percent':
				case '%';
					$padd = $length - strlen ( utf8_decode ( $text )) - 2;
					$text = str_repeat(" ", $padd) . $text . " %";
					break;
				case 'left':
				case 'l':
				default:
					$temp = $length - strlen ( utf8_decode ( $text ));
					$text = $html . str_repeat(" ", $temp);
					break;
			}
		}
		return $text;
	}
	// Formats a number according to the specified format
	public function num_format($num, $format) {
		switch ($format) {
			case 'money':
			case '$':
				$num = (is_numeric($num)) ? number_format($num, 2) : $num;
				break;
			case 'percent':
			case '%':
				$num = (is_numeric($num)) ? number_format($num, 3) : $num;
				break;
		}

		return $num;
	}
	// Display two dimensional array or specified table as an HTML table
	public function html_table($table, $class = null, $altHeaders = array(), $caption = null) {
		if( empty( $table )) {
			return false;
		}

		// data check
		if (!is_array($table)) {
			if (false == $this->valid_table($table)) {
				$sql = "SELECT * FROM `{$table}`";
				$table = $this->sql_exec($sql);
			} else {
				return false;
			}
		}

		// begin table code
		$code = array();
		$code[] = ( empty( $class )) ? "<table>" : "<table class = '{$class}'>";
		if (!empty($caption)) $code[] = "	<caption>{$caption}</caption>";

		// start table headers
		$headers = array_keys(current($table));
		if( empty( $altHeaders )) $altHeaders = array();
		foreach ($headers as $key => $header) if (array_key_exists($header, $altHeaders)) $headers[$key] = $altHeaders[$header];
		$code[] = "	<tr><th>" . implode("</th><th>", $headers) . "</th></tr>";

		// include tabular data
		foreach ($table as $row) $code[] = "		<tr><td>" . implode("</td><td>", $row) . "</td></tr>";

		// end table code
		$code[] = "</table>";

		// finalize and return
		$code = implode("\n", $code);
		return $code;
	}

	##	7.0 Query Builders
	//	   ___                          ____        _ _     _
	//	  / _ \ _   _  ___ _ __ _   _  | __ ) _   _(_) | __| | ___ _ __ ___
	//	 | | | | | | |/ _ \ '__| | | | |  _ \| | | | | |/ _` |/ _ \ '__/ __|
	//	 | |_| | |_| |  __/ |  | |_| | | |_) | |_| | | | (_| |  __/ |  \__ \
	//	  \__\_\\__,_|\___|_|   \__, | |____/ \__,_|_|_|\__,_|\___|_|  |___/
	//							|___/

	// Simple select for one table or many tables with joins and sorts with minimal optional parameters
	public function fetch_table ( $table, $where = null, $filter = null, $group = null, $limit = null, $join = null, $count = null ) {
		// initiate variables
		$params = array ();
		$sql = '';
		$wheres = array ();
		$filters = array ();
		$groups = array ();

		// where
		if ( is_array ( $where )) {
			foreach ( $where as $col => $val ) {
				// validate column is real
				if ( is_array ( $table )) {
					foreach ( $table as $tbl => $join ) { // for an array of tables
						if ( true == $this->valid_column ( $tbl, $col )) {
							if ( 'IS NULL' == strtoupper ( trim ( $val ))) {
								$temp = "`{$col}` IS NULL";
								if ( !in_array ( $temp, $wheres )) $wheres[] = $temp;
							} elseif ( 'IS NOT NULL' == strtoupper ( trim ( $val ))) {
								$temp = "`{$col}` IS NOT NULL";
								if ( !in_array ( $temp, $wheres )) $wheres[] = $temp;
							} elseif ( 'LIKE' == strtoupper ( substr ( trim ( $val ), 0, 4 ))) {
								$temp = "`{$col}` LIKE :{$col}";
								if ( !in_array ( $temp, $wheres )) {
									$wheres[] = $temp;
									$col = $this->increment_keys ( $col, $params );
									$params[$col] = trim ( str_replace ( 'LIKE', '', $val ));
								}
							} elseif ( 'IN' == strtoupper ( trim ( $val ))) {

							} elseif ( '>=' == substr ( $val, 0, 2 )) {
								$temp = "`{$col}` >= :{$col}";
								if ( !in_array ( $temp, $wheres )) {
									$wheres[] = $temp;
									$col = $this->increment_keys ( $col, $params );
									$params[$col] = trim ( str_replace ( '>=', '', $val ));
								}
							} elseif ( '>' == substr ( $val, 0, 1 )) {
								$temp = "`{$col}` > :{$col}";
								if ( !in_array ( $temp, $wheres )) {
									$wheres[] = $temp;
									$col = $this->increment_keys ( $col, $params );
									$params[$col] = trim ( str_replace ( '>', '', $val ));
								}
							} elseif ( '<=' == substr ( $val, 0, 2 )) {
								$temp = "`{$col}` <= :{$col}";
								if ( !in_array ( $temp, $wheres )) {
									$wheres[] = $temp;
									$col = $this->increment_keys ( $col, $params );
									$params[$col] = trim ( str_replace ( '<=', '', $val ));
								}
							} elseif ( '<' == substr ( $val, 0, 1 )) {
								$temp = "`{$col}` < :{$col}";
								if ( !in_array ( $temp, $wheres )) {
									$wheres[] = $temp;
									$col = $this->increment_keys ( $col, $params );
									$params[$col] = trim ( str_replace ( '<', '', $val ));
								}
							} elseif ( '!=' == substr ( $val, 0, 2 )) {
								$temp = "`{$col}` != :{$col}";
								if ( !in_array ( $temp, $wheres )) {
									$wheres[] = $temp;
									$col = $this->increment_keys ( $col, $params );
									$params[$col] = trim ( str_replace ( '!=', '', $val ));
								}
							} else {
								$temp = "`{$col}` = :{$col}";
								if ( !in_array ( $temp, $wheres )) {
									$wheres[] = $temp;
									$col = $this->increment_keys ( $col, $params );
									$params[$col] = $val;
								}
							}
						}
					}
				} else { // for a single table
					if ( true == $this->valid_column ( $table, $col )) {
						$temp = "`{$col}` = :{$col}";
						if ( !in_array ( $temp, $wheres )) {
							$wheres[] = $temp;
							$col = $this->increment_keys ( $col, $params );
							$params[$col] = $val;
						}
					} else {
					}
				}
			}
			$where = ( !empty ( $wheres )) ? 'WHERE ' . implode ( ' AND ', $wheres ) : '';
		}

		// filter
		if ( is_array ( $filter )) {
			foreach ( $filter as $col => $sort ) {
				// validate column is real
				if ( is_array ( $table )) { // for an array of tables
					foreach ( $table as $tbl => $join ) {
						if ( true == $this->valid_column ( $tbl, $col )) {
							$sort = ( 'ASC' == strtoupper ( $sort ) || 'DESC' == strtoupper ( $sort )) ? strtoupper ( $sort ) : 'ASC';
							$temp = "`{$col}` {$sort}";
							if ( !in_array ( $temp, $filters )) {
								$filters[] = $temp;
							}
						}
					}
				} else { // for a single table
					if ( true == $this->valid_column ( $table, $col )) {
						$sort = ( 'ASC' == strtoupper ( $sort ) || 'DESC' == strtoupper ( $sort )) ? strtoupper ( $sort ) : 'ASC';
						$temp = "`{$col}` {$sort}";
						if ( !in_array ( $temp, $filters )) {
							$filters[] = $temp;
						}
					}
				}
			}
			$filter = ( !empty ( $filters )) ? 'ORDER BY ' . implode ( ', ', $filters ) : '';
		}

		// group
		if ( is_array ( $group )) {
			foreach ( $group as $col ) {
				// validate column is real
				if ( is_array ( $table )) { // for an array of tables
					foreach ( $table as $tbl => $join ) {
						if ( true == $this->valid_column ( $tbl, $col )) {
							$temp = "`{$col}`";
							if ( !in_array ( $temp, $groups )) {
								$groups[] = $temp;
							}
						}
					}
				} else { // for a single table
					if ( true == $this->valid_column ( $table, $col )) {
						$temp = "`{$col}`";
						if ( !in_array ( $temp, $groups )) {
							$groups[] = $temp;
						}
					}
				}
			}
			$group = ( !empty ( $groups )) ? "GROUP BY " . implode ( ', ', $groups ) : '';
		}

		// limit
		if ( is_numeric ( $limit ) || ( is_array ( $limit ) && 2 > count ( $limit ))) {
			$limit = ( is_array ( $limit )) ? "LIMIT " . end ( $limit ) . " " : "LIMIT {$limit} ";
		} elseif ( is_array ( $limit )) {
			list ( $start, $stop ) = $limit;
			if ( is_numeric ( $start ) && is_numeric ( $stop )) {
				$limit = "LIMIT {$start}, {$stop} ";
			} else {
				$limit = '';
			}
		}

		// tables and joins
		if ( is_array ( $table )) {
			// joins
			$joins = array (
				'LEFT JOIN',
				'LEFT OUTER JOIN',
				'INNER JOIN',
				'OUTER JOIN',
				'FULL JOIN',
				'FULL OUTER JOIN',
				'RIGHT JOIN',
				'RIGHT OUTER JOIN',
				'JOIN',
			);
			$join = ( in_array ( $join, $joins )) ? $join : 'LEFT OUTER JOIN';
			foreach ( $table as $table => $col ) {
				if ( true == $this->valid_table ( $table ) ) {
					if ( is_array ( $col )) {
						/* how to get previous and next tables to validate columns??????????
						list ( $colA, $colB ) = $col;
						if ( true == $this->valid_column ( $table, $colA ) && $this->valid_column ( $table, $colB )) {

						}/***/
					} else {
						if ( true == $this->valid_column ( $table, $col )) {
							$sql .= ( empty ( $sql )) ? "SELECT * FROM `{$table}` " : "{$join} `{$table}` USING ( `{$col}` ) ";
						}
					}
				}
			}
		} else {
			if ( true == $this->valid_table ( $table )) $sql = "SELECT * FROM {$table} ";
		}
		// logic and ordering
		$sql .= "{$where} {$filter} ";

		// grouping
		if ( !empty ( $group )) $sql = "SELECT * FROM ( {$sql} ) `table` {$group} ";

		// limit
		$sql .= "{$limit}";

		// count
		if ( true === $count ) $sql = "SELECT COUNT(*) FROM ( {$sql} ) `records` ";

		// execute and return
		$res = $this->sql_exec ( $sql, $params );

		// get count
		if ( true === $count ) $res = end ( $res[0] );

		// return query results
		return $res;
	}
	// Insert row into database and update on duplicate primary key
	public function insert_row( $table, $params, $update = true) {
		// validate table
		if (false == $this->valid_table($table)) return false;

		// verify params
		if( empty( $params )) {
			$this->err[] = "Error: missing query parameters.";
			return false;
		}

		// prep columns and binding placeholders
		$columns = array_keys($params);
		$cols = ( count( $params ) > 1 ) ? implode( "`,`", $columns ) : current( $columns );
		$binds = ( count( $params ) > 1 ) ? implode( ", :", $columns ) : current( $columns );

		// create base query
		$query = "INSERT INTO `{$table}` (`{$cols}`) VALUES (:{$binds})";

		// update on duplicate primary key
		if (true == $update) {						// if update is set to true
			$query .= " ON DUPLICATE KEY UPDATE ";	// append duplicate to query
			$schema = $this->column_types($table);	// get table column data
			foreach ($schema as $col) {				// loop through table columns
				if ('PRI' != $col['Key'] && array_key_exists($col['Field'], $params)) {
					$updates[] = "`{$col['Field']}`=:update_{$col['Field']}";
					$params["update_{$col['Field']}"] = $params[$col['Field']];
				}
			}
			$query .= implode(",", $updates);
		}

		// execute query
		$res = $this->sql_exec( $query, $params );
		return $res;
	}
	// Update existing row from given key => value
	public function update_row( $table, $params, $key ) {
		// validate table
		if (false == $this->valid_table($table)) return false;
		if( $this->logDebug ) $this->debug[] = "Valid table: {$table}";

		// parse updates
		foreach ($params as $col => $val) {
			// validate table column
			if ($this->valid_column($table, $col)) $updates[] = "`{$col}`=:{$col}";
			if( $this->logDebug ) $this->debug[] = "Valid column: {$col}";
		}
		$updates = implode(',', $updates);

		// define where key
		foreach ($key as $col => $val) {
			if ($this->valid_column($table, $col)) $where = "`{$col}`='{$val}'";
			//$params[$col] = $val;
			if( $this->logDebug ) $this->debug[] = "Valid update on: {$where}";
		}

		// compile and execute
		$query = "UPDATE `{$table}` SET {$updates} WHERE {$where}";
		return $this->sql_exec($query, $params);
	}
	// Delete a row
	public function delete_row( $table, $params ) {
		// check for valid table
		if (false == $this->valid_table($table)) return false;
		if( $this->logDebug ) $this->debug[] = "Valid table: {$table}";

		foreach ($params as $col => $val) {
			if (false == $this->valid_column($table, $col)) return false;
			if( $this->logDebug ) $this->debug[] = "Valid column: {$col}";

			$where = "`{$col}`=:{$col}";
		}

		if (!empty( $wheres )) {
			$where = implode( ' AND ', $wheres );
		} else {
			$this->err[] = "Can't delete row without valid parameters";
			return false;
		}

		// compile and execute
		$query = "DELETE FROM `{$table}` WHERE {$where};";
		return $this->sql_exec($query, $params);
	}
	// Increment column keys; a fetch_table helper function
	public function increment_keys ( $key, $arr ) {
		if ( is_array ( $arr ) && array_key_exists ( $key, $arr )) {
			$i = 2;
			while ( array_key_exists ( "{$key}{$i}", $arr )) $i++;
			return "{$key}{$i}";
		} else {
			return $key;
		}
	}
	// Convert an array of clause => glue associations to a 'col <=> :var' string and parameter array
	public function array_to_wheres( $where, $tables = array()) {
		if( is_array( $where ) && !empty( $where )) {
			$wheres = array();
			$params = array();
			foreach ( $where as $clause => $bind ) {
				if( !empty( $clause )) {
					// parse clause
					$arr = explode( ' ', trim( $clause ));
					$col = $arr[0];
					unset( $arr[0] );
					$operand = strtoupper( $arr[1] );
					unset( $arr[1] );
					$val = trim( implode( ' ', $arr ));

					// prep bind variable
					$bind = strtoupper( $bind );

					// strip out ticks
					$col = str_replace( '`', '', trim( $col ));

					// search for table.column criteria
					$table = null;
					if( false != strpos( $col, '.' )) {
						list( $table, $col ) = explode( '.', $col );

						// verify table
						if( !array_key_exists( $table, $tables )) {
							if( true == $this->valid_table( $table )) {
								// verify column
								if( true != $this->valid_column( $table, $col )) {
									// column doesn't exist, next!
									continue;
								}
							}
						} else {
							if( true != in_array( $col, $table )) {
								// column doesn't exist, next!
								continue;
							}
						}
						$token = "{$table}_{$col}";
					} else {
						$token = $col;
					}
					
					// parse operand
					if( 'IS' == $operand ) {
						if( 'NULL' == strtoupper( $val ) || 'NOT NULL' == strtoupper( $val )) {
							$wheres[] = ( !empty( $table )) ? " {$bind} `{$table}`.`{$col}` {$operand} ".strtoupper( $val ) : " {$bind} `{$col}` {$operand} ".strtoupper( $val );
						}
						var_dump( $clause );
					} elseif ( in_array( $operand , array( 'LIKE','<','<=','=','!=','=>','>' ))) {
						$bind = ( in_array( $bind, array( '', 'WHERE', 'OR', 'AND' ))) ? $bind : '';
						$token = $this->increment_keys( $token, $params );
						$wheres[] = ( !empty( $table )) ? " {$bind} `{$table}`.`{$col}` {$operand} :{$token}" : " {$bind} `{$col}` {$operand} :{$token}";
						$params[$token] = $val;
					}
				}
			}
			$where = implode( $wheres );

			return array( $where, $params );
		} else {
			return array( '', null );
		}
	}
	// Convert an array of key => value associations to a colum => order string
	public function array_to_filter ( $filter ) {
		if ( is_array ( $filter )) {
			$filters = array ();
			foreach ( $filter as $col => $sort ) {
				$sort = ( 'ASC' == strtoupper ( $sort ) || 'DESC' == strtoupper ( $sort )) ? strtoupper ( $sort ) : 'ASC';
				$temp = "`{$col}` {$sort}";
				if ( !in_array ( $temp, $filters )) {
					$filters[] = $temp;
				}
			}
			$filter = ( !empty ( $filters )) ? 'ORDER BY ' . implode ( ', ', $filters ) : '';

			return $filter;
		}
	}
	public function array_to_group ( $group ) {
		if ( is_array ( $group )) {
			foreach ( $group as $col ) {
				$temp = "`{$col}`";
				if ( !in_array ( $temp, $groups )) {
					$groups[] = $temp;
				}
			}
			$group = ( !empty ( $groups )) ? "GROUP BY " . implode ( ', ', $groups ) : '';

			return $group;
		}
	}

	##	8.0 Helper Functions
	public function script_start() {
		if( !defined( 'SCRIPT_START' )) {
			define( 'SCRIPT_START', microtime( true ));
		}
	}

	public function script_execution_time() {
		if( !defined( 'SCRIPT_START' )) {
			define( 'SCRIPT_START', microtime( true ));
			return 0;
		} else {
			$now = microtime( true );
			$seconds = $now - SCRIPT_START;
			return $seconds;
		}
	}

	public function mk_dir( $dirs ) {
		if( !is_array( $dirs )) {
			die( "Path must be provided as an array" );
		} else {
			$path = "";
			foreach( $dirs as $dir ) {
				$path .= ( empty( $path )) ? $dir : DIRECTORY_SEPARATOR . $dir;
				if( !is_dir( $path )) {
					if( !mkdir( $path )) {
						$this->err[] = "failed to make path: {$path}";
						die( "failed to make path: {$path}" );
					}
				}
			}
		}
		return true;
	}

	##	XX.0 Debug
	//	  ____       _
	//	 |  _ \  ___| |__  _   _  __ _
	//	 | | | |/ _ \ '_ \| | | |/ _` |
	//	 | |_| |  __/ |_) | |_| | (_| |
	//	 |____/ \___|_.__/ \__,_|\__, |
	//							 |___/

	// Get debug info of a prepared statement
	public function debug_stmt() {
		// get debug data
		echo "<pre>";
		$this->stmt->debugDumpParams();
		echo "</pre>";
	}
	// Show debug log
	public function show_debug_log() {
		echo "<h3>Linchpin Debug Log</h3>";
		// check if it's empty
		if( empty( $this->debug )) {
			echo "<pre>no data in debug log.</pre>";
		} else {
			$this->disp( $this->debug );
		}
	}
	// Clear debug log
	public function clear_debug_log() {
		unset( $this->debug );
	}
	// Generate random string of a given length
	public function randstring( $len, $includeSpaces = false ) {
		// set vars
		$src = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		if( true === $includeSpaces ) $src .= '   ';
		$string = '';

		do {
			// random character from source & append character to string
			$string .= substr($src, rand(0, strlen($src) - 1), 1);
		} while (strlen($string) < $len);

		return $string;
	}
	// show errors
	public function show_err() {
		// if error container array is not empty
		if (!empty($this->err)) $this->disp($this->err);
	}
	// display array
	public static function disp($array, $backtrace = false) {
		//$debug = debug_backtrace();
		//echo "<pre>Display called from {$debug[1]['function']} line {$debug[1]['line']}\n\n";
		echo "<pre>";
		print_r($array);
		echo "</pre>";
	}
	// Get script resource usage
	public function resource_usage($reset = false) { # http://stackoverflow.com/questions/535020/tracking-the-script-execution-time-in-php
		if (true == $reset) unset($rustart);
		if (empty($rustart)) {
			static $rustart;
			$rustart = getrusage();
		} else {
			$rustop = getrusage();
			echo "This process used " . rutime($rustop, $rustart, "utime") . " ms for its computations\n";
			echo "It spent " . rutime($rustop, $rustart, "stime") . " ms in system calls\n";
		}
	}
}
