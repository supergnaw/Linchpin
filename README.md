# Linchpin
A MySQL PDO wrapper and "query manager" written in PHP.
- [Background](#background)
- [Class Usage](#class-usage)
  - [Declaring The Class](#declaring-the-class)
  - [Executing Queries](#executing-queries)
  - [Performing Transactions](#performing-transactions)
- [Query Builders](#query-builders)
  - [Insert Row](#insert-row)
  - [Update Row](#update-row)
  - [Delete Row](#delete-row)

## Background
The goal of this project project was to be a "lightweight" PDO wrapper class that could be easily incorporated into any project. It is meant to be a parent class from which you can extend your own classes from, but it is functional enough to just use as is, bare-bones.

## Class Usage
### Declaring The Class
Declaring the class used to require a configuration file, however this causes issues should the same script want to connect to multiple databases. To resolve this, each instance of the class will require it's own set of variables, as exampled below:
```PHP
$host = 'localhost';
$user = 'root';
$pass = '';
$name = 'database_name';
$cms = new Linchpin( $host, $user, $pass, $name );
```
The real meat and potatoes of this class is being used as a parent class. If you are using a child class to extend Linchipin, make sure to also call the parent contstruct to ensure the new class passes the connection details to Linchpin and loads the schema data:
```PHP
class ChildClass extends Linchpin {
    	// Constructor
    	public function __construct( $host, $user, $pass, $name ) {
	    	// database functions
	    	parent::__construct( $host, $user, $pass, $name );
    	}
}
```

### Executing Queries
Executing queries is done using the `sqlexec()` function:
```PHP
// call the class
$lp = new Linchpin();

$query = "SELECT * FROM `table`";
$results = $lp->sqlexec( $query );
```
To perform a query with variables, use the second argument for `sqlexec()` to pass an array of `[:name => 'value']` pairs:
```PHP
$query = "SELECT * FROM `table` WHERE `column_name` = :var_name";
$params = array(
    'var_name' => 'some value',
);
$results = $lp->sqlexec( $query, $params );
```

### Performing Transactions
To perform a transaction, you must roll up your queries and optional parameters into an array. The return value from a transaction will be an array with each element being the resulting affected rows for its respective query.
```PHP
// call the class
$lp = new Linchpin();

// stand alone query
$query1 = "INSERT INTO `table_a` ( `col_a`, `col_b`, `col_c` ) VALUES ( :col_a, :col_b, :col_c )";
$params1 = array(
    'col_a' => 'value 1',
    'col_b' => 2,
    'col_c' => 3,
);
// another different query
$query2 = "UPDATE `table_b` SET `col_1`=:val_1, `col_2`=:val_2 WHERE `col_foo` = 'bar'";
$params2 = array(
    'col_1' => 'string',
    'col_2' => 123.45,
);
// combine queries into a multidimensional array
$queries = array(
    $query1 => $params1,
    $query2 => $params2,
);
// perform the transaction
$results = $lp->transexec( $queries );
```

As of 7.0.0, you can also perform transactions with one array, using an aggregated string of queries as the key, and the array element being a second element with all the parameters for the entire transaction. The class will automatically sort the parameters for their associated tokens and perform the transaction. The example above could also be done like this:
```PHP
// stand alone query
// call the class
$lp = new Linchpin();

$sql = "INSERT INTO `table_a` ( `col_a`, `col_b`, `col_c` ) VALUES ( :col_a, :col_b, :col_c );
        UPDATE `table_b` SET `col_1`=:val_1, `col_2`=:val_2 WHERE `col_foo` = 'bar';";
        
$params = array(
    'col_a' => 'value 1',
    'col_b' => 2,
    'col_c' => 3,
    'col_1' => 'string',
    'col_2' => 123.45
);

$queries = array( $sql => $params );

// perform the transaction
$results = $lp->transexec( $queries );
```

To help initial programming, you can optionally attempt transactions in a "test mode" by passing true as the second parameter to the function. You can check the status via the debug variable:
```PHP
$results = $lp->trasexec( $queries, true );
print_r( $lp->debug );
```

This should output something along the lines of this:
```
Array
(
    [0] => Check formatting if passed transaction queries.
    [1] => Check no transaction is currently active.
    [2] => Begin new transaction.
    [3] => Attempting to end/commit transaction...
    [4] => Test mode enabled, rolling back transaction (make sure table is InnoDB!)
    [5] => Complete: transaction tested successfully with no errors.
)
```

If for some reason it says you do have errors, check the ``$class->err`` variable to see what's going on.

## Query Builders
These are a set of functions that build queries from user-defined inputs. They work well enough for simple queries but do not handle things like duplicate columns and multiple types of joins in the same query very well. It is best to use these either for simple tasks or in a development setting to help troubleshoot while you work out the kinks of your code. Or not at all. As the old adage goes, is the juce worth the squeeze?

### Insert Row
```PHP
insert_row( $table, $params, [ $update ]);
```
| Argument | Type | Description |
| --- | --- | --- |
| `$table` | String | The table name in the database on which to perform the insert. |
| `$params` | Array | Array of `['col' => 'val']` pairs where `col` is the column name and `val` is the value to insert. |
| `$update` | Boolean | Defined by 'col' => 'ASC' / 'DESC', not case sensitive. |

### Update Row
```PHP
update_row( $table, $params, $key );
```
| Argument | Type | Description |
| --- | --- | --- |
| `$table` | String | The table name in the database on which to perform the update. |
| `$params` | Array | Array of `['col' => 'val']` pairs where `col` is the column name and `val` is the value to update. |
| `$key` | String | The primary key of the row in `$table` on which to update. |

### Delete Row
```PHP
delete_row( $table, $params );
```
| Argument | Type | Description |
| --- | --- | --- |
| `$table` | String | The table name or names in the database. If an array is used, each table is the key in the array and the value is the type of join. |
| `$params` | Array | Defined by 'col' => 'value', so not good when you need to use 'col' twice. |
