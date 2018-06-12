# Linchpin
A MySQL PDO wrapper and "query manager" written in PHP.
- [Background](#background)
- [Class Usage](#class-usage)
  - [Declaring The Class](#declaring-the-class)
  - [Executing Queries](#executing-queries)
  - [Performing Transactions](#performing-transactions)
- [Query Builders](#query-builders)
  - [Fetch Table](#fetch-table)
  - [Insert Row](#insert-row)
  - [Update Row](#update-row)
  - [Delete Row](#delete-row)

## Background
The goal of this project project was to be a "lightweight" PDO wrapper class that could be easily incorporated into any project.

## Class Usage
### Declaring The Class
Declaring the class can be done two different ways. Either using a configuration file or passing variables when declaring the class. When using the configuration file, simply declare it like so:
```PHP
$lp = new linchipin();
```
If you are foregoing the configuration file, you must pass the database information to the class, like so:
```PHP
$host = 'localhost';
$user = 'root';
$pass = 'P4@ssw0rd';
$name = 'mydatabase';
$lp = new linchpin( $host, $user, $pass, $name );
```
### Executing Queries
Executing queries is done using the ```sqlexec()``` function:
```PHP
$query = "SELECT * FROM `table`";
$results = $lp->sqlexec( $query );
```
To perform a query with variables, use the second argument for ```sqlexec()``` to pass an array of :name => value pairs:
```PHP
$query = "SELECT * FROM `table` WHERE `column_name` = :var_name";
$params = array(
    'var_name' => 'some value',
);
$results = $lp->sqlexec( $query, $params );
```

### Performing Transactions
To perform a transaction, you must roll up your queries and optional parameters into an array.
```PHP
// stand alone query
$query1 = "INSERT INTO `table_a` (`col_a`, `col_b`, `col_c`) VALUES (:col_a, :col_b, :col_c)";
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

## Query Builders
These are a set of functions that build queries from user-defined inputs. They work well enough for simple queries but do not handle things like duplicate columns and multiple types of joins in the same query very well. It is best to use these either for simple tasks or in a development setting to help troubleshoot while you work out the kinks of your code. Or not at all. As the old adage goes, is the juce worth the squeeze?

### Fetch Table
```PHP
fetch_table( $table, [ $where, $filter, $group, $limit, $join, $count ]);
```
| Argument | Type | Description|
| --- | --- | --- |
| `$table` | String or Array | The table name or names in the database. If an array is used, each table is the key in the array and the value is the type of join. |
| `$where` | Array | Defined by 'col' => 'value', so not good when you need to use 'col' twice. |
| `$filter` | Array | Defined by 'col' => 'ASC' / 'DESC', not case sensitive. |
| `$group` | String or Array | Columns to group the query by: `col_1` or `array('col_1','col_2')` |
| `$limit` | Integer or Array | Sets limit of query; use a single number to set the limit of rows returned, or use an array of two numbers to declare a `LIMIT start, stop` expression on the query. |
| `$join` | Array | This doesn't work because the variable ends up overwritten earlier in the function. To be continued... |
| `$count` | Bool | Set this to `true` to return the row count; this will be under the key `COUNT(*)`.

### Insert Row
```PHP
insert_row( $table, $params, [ $update ]);
```

### Update Row
```PHP
update_row( $table, $params, $key );
```

### Delete Row
```PHP
delete_row( $table, $params );
```
