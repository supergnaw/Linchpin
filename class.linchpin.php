<?php
/* ### Linchpin: A PDO Databse Wrapper ###
 *	Developer:	Loren Supernaw & lots of Googlefu
 *				Any extra functions are externally
 *				referenced by that function's definition
 *	Version:	6.1.1
 *	Date:		2018-06-12
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
 *	fetch_table ()
 *	insert_row ()
 *	update_row ()
 *	delete_row ()
 *
 * ### Transactions ###
 *	- trans_exec()
 *	This functions identically to sql_exec() but instead processes an
 *	array of query=>params all at once as a transaction instead of as
 *	individual queries.
 *
 * ### Things To Do ###
 * - expand insert/update/delete where parameters beyond a=b
 * - fix the where/filter implode to avoid single incorrect columns fucking up array to string conversions
 * - add BETWEEN operator to query builder, or not.
 *
 * ### Helpful Resources ###
 *	The following resource helped in the creation of this class:
 *	http://culttt.com/2012/10/01/roll-your-own-pdo-php-class/
 *	http://code.tutsplus.com/tutorials/why-you-should-be-using-phps-pdo-for-database-access--net-12059
 */

# Configuration File
/*
 *	A typical config file should contain the database host, username, password, and database name to access, as
 *	exampled below:
	<?php
		define( 'DB_HOST', 'localhost' );
		define( 'DB_USER', 'root' );
		define( 'DB_PASS', '' );
		define( 'DB_NAME', 'databasename' );
	?>
*/
// optional config file;
define ( 'DB_CONF', 'config.linchpin.php' );

class Linchpin {
	// Class Variables
	public $dbh;		// database handler
	public $stmt;		// query statement holder
	public $err;		// error log array
	public $debug;		// debug log array

	##	1.0 Structs
	//	  ____  _                   _
	//	 / ___|| |_ _ __ _   _  ___| |_ ___
	//	 \___ \| __| '__| | | |/ __| __/ __|
	//	  ___) | |_| |  | |_| | (__| |_\__ \
	//	 |____/ \__|_|   \__,_|\___|\__|___/

	// Default constructor
	public function __construct( $host = 'localhost', $user = 'root', $pass = '', $name = '', $dir = 'database', $type = 'mysql' ) {
		// load configuration file or use passed vars
		if( defined( 'DB_CONF' )) {
			try {
				// get class settings
				if( file_exists( DB_CONF )) require_once( DB_CONF );
				$host = ( defined( 'DB_HOST' )) ? DB_HOST : $host;
				$user = ( defined( 'DB_USER' )) ? DB_USER : $user;
				$pass = ( defined( 'DB_PASS' )) ? DB_PASS : $pass;
				$name = ( defined( 'DB_NAME' )) ? DB_NAME : $name;
			} catch( Exception $e ) {
				$this->err[] = $e->getMessage();
			}
		}

		// set class vars
		$this->set_vars( $host, $user, $pass, $name, $dir, $type );
	}
	// Default destructor
	public function __destruct() {
		// close any existing database connection
		$this->close();
	}
	// Set class vairable defaults then connect
	public function set_vars( $host = "localhost", $user = "root", $pass = "", $name = "", $dir = "database", $type = "mysql" ) {
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
	public function connect () {
		// check for existing connection
		if( true === $this->check_connection ()) {
			$this->debug[] = "connection successful";
			return true;
		}

		// create new connection
		try {
			switch( $this->dbType ) {
				case 'mssql':	// MS Sql Server
					$this->dbh = new PDO( "mssql:host={$this->dbHost};dbname={$this->dbName}, {$this->dbUser}, {$this->dbPass}");
					break;
				case 'sybase':	// Sybase with PDO_DBLIB
					$this->dbh = new PDO( "sybase:host={$this->dbHost};dbname={$this->dbName}, {$this->dbUser}, {$this->dbPass}");
					break;
				case 'sqlite':	// SQLite
					$this->dbh = new PDO( "sqlite:". $this->dbDir . DIRECTORY_SEPARATOR . $this->dbName);
					break;
				case 'mysql':	// Mysql
					$this->dbh = new PDO( "mysql:host={$this->dbHost};dbname={$this->dbName}", $this->dbUser, $this->dbPass);
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
	public function sql_exec( $query, $params = null, $close = false ) {
		// verify query is a string
		if( true !== is_String( $query )) {
			$this->err[] = 'Error: Could not execute query because it is an array.';
			return false;
		}

		// verify varible and token numbers match
		if( true != $this->verify_token_to_variable( $query, $params )) {
			return false;
		}

		// varify query is a valid string
		if( empty( trim( $query ))) {
			$this->err[] = 'Error: empty string passed as query.';
			return false;
		}

		// connect to database
		if ( true == $connect ) if (!$this->connect()) return false;

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
				if( $this->logDebug ) $this->debug[] = "Paramater bound: '{$value}' to `{$name}`";
			}
		}

		// execute & return
		if ( $this->execute()) {
			// debug
			if( $this->logDebug ) $this->debug[] = "Statement successfully executed.";

			// return results of query based on statement type
			$string = str_replace( array( "\n", "\t" ), array( " ", "" ), $query );
			$type = trim( strtolower( strstr( $string, ' ', true )));
			switch( $type ) {
				case 'select':	// return all resulting rows
				case 'show':
					if( $this->logDebug ) $this->debug[] = "Return results.";
					$return = $this->results();
					break;
				case 'insert':	// return number of rows affected
				case 'update':
				case 'delete':
					$count = $this->row_count();
					if( $this->logDebug ) $this->debug[] = "Return number of rows affected: {$count}";
					$return = $count;
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
	public function verify_token_to_variable( $query, $params ) {
		// crosscheck tokens
		$missingTokens = array();
		preg_match_all( "/:\w+/", $query, $tokens );
		$tokens = end( $tokens );
		foreach( $tokens as $token ) {
			$oken = ltrim( $token, ':' );
			if( !key_exists( $token, $params ) && !key_exists( $oken, $params )) $missingTokens[] = $oken;
		}

		// crosscheck variables
		$missingVars = array();
		foreach( $params as $var => $val ) {
			$var = ':' . ltrim( $var, ':' );
			if( !in_array( $var, $tokens )) $missingVars[] = $var;
		}

		// error reporting
		if( empty( $missingTokens ) && empty( $missingVars )) {
			return true;
		} else {
			$msg = '<strong>Error:</strong> Number of tokens and variables do not match!';
			if( !empty( $missingTokens )) $msg .= '<br />Missing ' . count( $missingTokens ) . ' tokens: ' . implode( ', ', $missingTokens );
			if( !empty( $missingVars )) $msg .= '<br />Missing ' . count( $missingVars ) . ' variables: ' . implode( ', ', $missingVars );
			$this->err[] = $msg;
			return false;
		}
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
		// execute query
		if( !$this->stmt->execute ()) {
			// get error info
			$error = $this->stmt->errorInfo();

			//$this->err[] = "Statement: " . $this->stmt;
			// error logging
			$this->err[] = "MySQL error {$error[1]} ({$error[2]}).";
			if( $this->logDebug ) $this->debug[] = $this->stmt->errorInfo();

			// failed to execute
			return false;
		} else {
			return true;
		}
	}
	// Return associated array
	public function results() {
		return $this->stmt->fetchAll ( PDO::FETCH_ASSOC );
	}
	// Get the number of rows affected by the last query
	public function row_count() {
		return $this->stmt->rowCount();
	}
	// I sometimes get dyslexic
	public function exec_sql( $query, $params = null ) {
		return $this->sql_exec ( $query, $params );
	}
	// Notepad++ likes this version the most for autocomplete
	public function sqlexec( $query, $params = null ) {
		return $this->sql_exec ( $query, $params );
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
	public function trans_exec( $queries ) {
		// create new connection
		$this->connect();
		unset( $this->debug );

		// check if queries is an array
		if( $this->logDebug ) $this->debug[] = "Check formatting if passed transaction queries.";
		if (!is_array($queries)) {
			$this->err[] = "Warning: transactions must be an array of queries.";
			return false;
		}

		// make sure array isn't empty
		if( empty( $queries )) {
			$this->err[] = "Error: transaction failed because an empty array of queries was passed.";
			return false;
		}

		// verify no active transactions
		if( $this->logDebug ) $this->debug[] = "Check no transaction is currently active.";
		if (true == $this->trans_active()) {
			$this->err[] = "Warning: transaction is currently active.";
			return false;
		}

		// start the transaction
		if( $this->logDebug ) $this->debug[] = "Begin new transaction.";
		if (!$this->trans_begin()) {
			$this->err[] = "Error: could not begin transaction.";
			return false;
		}

		// verify the transaction has started
		if( !$this->trans_active()) {
			$this->err[] = "Error: transaction was requested but for some reason does not exist.";
			return false;
		}

		// loop through each query
		foreach ($queries as $sql => $params) {
			// verify variable and token numbers match
			if( false == $this->verify_token_to_variable( $sql, $params )) {
				return false;
			}

			// prepare
			$stmt = $this->dbh->prepare( $sql );

			// bind
			if( !empty( $params ) && is_array( $params )) {
				foreach( $params as $name => $value ) {
					switch( true ) {
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
					//var_dump( $error );
					//var_dump( $stmt );
					//var_dump( $sql );
					//var_dump( $params );
					$this->err[] = "{$error[2]} (MySQL error {$error[1]})";
				}
			} catch( Exception $e ) {
				var_dump( $e );
				var_dump( $stmt->errorInfo());
			}
			// results
			$res[] = $stmt->rowCount();/**/
		}
		// end/commit transaction
		if( true === $testMode ) {
			if( !$this->trans_cancel()) {
				$this->err[] = "Error: test transaction could not be rolled back.";
				return false;
			} else {
				$this->err[] = "Notice: transaction tested successfully with no errors.";
				return $res;
			}
		} else {
			if (!$this->trans_end()) {
				$this->err[] = "Error: could not commit changes.";
				if (!$this->trans_cancel()) { // rollback on failure
					$this->err[] = "Error: failed to rollback the transaction.";
					return false;
				} else {
					$this->err[] = "Transaction rolled back successfully.";
				}
			} else {
				return $res;
			}
		}
	}
	// Alias for trans_exec
	public function transexec( $queries ) {
		return $this->trans_exec( $queries );
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

			$res = $this->sql_exec("SHOW TABLES");
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
		$results = $this->sql_exec("SHOW COLUMNS IN `{$table}`", null, $table);

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
		$code[] = (empty($class)) ? "<table>" : "<table class = '{$class}'>";
		if (!empty($caption)) $code[] = "	<caption>{$caption}</caption>";

		// start table headers
		$headers = array_keys(current($table));
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
	// Convert an array of key => value associations to a column => var string
	public function array_to_wheres ( $where, $tables = null, $validCheck = true ) {
		if ( is_array ( $where )) {
			$wheres = array ();
			$params = array ();
			foreach ( $where as $col => $val ) {
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
			$where = ( !empty ( $wheres )) ? 'WHERE ' . implode ( ' AND ', $wheres ) : '';

			return array ( $where, $params );
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
		if (empty($this->debug)) {
			echo "<pre>no data in debug log.</pre>";
		} else {
			$this->disp($this->debug);
		}
	}
	// Clear debug log
	public function clear_debug_log() {
		unset ( $this->debug );
	}
	// Generate random string of a given length
	public function randstring($len, $includeSpaces = false) {
		// set vars
		$src = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		if (true === $includeSpaces) $src .= '   ';
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
	public function disp($array, $backtrace = false) {
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
