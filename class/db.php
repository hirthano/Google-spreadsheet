<?php
	class DB
	{
		private static $instance;
		private $die_on_err;
		public $connection;
		private $max_trial = 3;
		private $db_config;
		private $defaultdb;
		private $deadlock_timeout = 20;
		private $table_locks;
		private $lock_before_use = true;
		public  $errno;
		private $last_query;
		public  $error;

		public function __construct($db_config)
		{
			$this->db_config = $db_config;
			$this->deadlock_timeout = 20;
			if (!isset($this->db_config['port']))
				$this->db_config['port'] = 3306;
			$this->connection = new mysqli(
				$db_config['host'],
				$db_config['user'],
				$db_config['pass'],
				$db_config['name'],
				$db_config['port']
			);
			$this->set_die_on_err($db_config['die_on_err']);
			mysqli_options($this->connection,
				MYSQLI_READ_DEFAULT_GROUP, "max_allowed_packet=90M");
			$this->connection->set_charset("utf8");
			if ($this->connection->errno != 0)
				if ($this->die_on_err)
					die ($this->connection->error . "\n");
			$this->my_update("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
		}

		public static function init($db_config)
		{
			if(is_null(self::$instance))
			{
				self::$instance = new DB($db_config);
			}
			return self::$instance;
		}

		public function __get($property)
		{
			if (isset($this->{$property}))
				return $this->{$property};
			if (isset($this->connection->{$property}))
				return $this->connection->{$property};
			else {
				trigger_error('Unknown property ' . $property . '()', E_USER_WARNING);
				return false;
			}
		}

		public function __call($name, $args)
		{
			if(method_exists($this->connection, $name))
			{
				return call_user_func_array(array($this->connection, $name), $args);
			} else {
				trigger_error('Unknown Method ' . $name . '()', E_USER_WARNING);
				return false;
			}
		}

		public function get_last_query()
		{
			return $this->last_query;
		}

		public function set_lock_before_use($bool)
		{
			$this->lock_before_use = true;
			if (isset($bool))
				if ($bool === FALSE)
					$this->lock_before_use = false;
		}

		public function set_die_on_err($bool)
		{
			$this->die_on_err = true;
			if (isset($bool))
				if (!$bool)
					$this->die_on_err = false;
		}

		public function set_lock_timeout($sec)
		{
			if (intval($sec) > 0)
				$this->deadlock_timeout = intval($sec);
		}

		public function query_log ($sql)
		{
			$run_time	= microtime(1);
			$result	= $this->run_query($sql);
			$execution_time	= round(microtime(1) - $run_time, 5);

			$affected_rows = (!$result) ? -1 : $this->connection->affected_rows;

			$escaped_sql	= $this->connection->escape_string(substr(trim($sql),0,250));
			$info	= $this->connection->info;

			$type 	= strtoupper(strtok($sql, " "));
			$sql	= "INSERT INTO log_sql (run_time, execution_time, result, type, query, info) VALUES ";
			$sql	.= "($run_time, $execution_time, $affected_rows, '$type', '$escaped_sql','$info')";

			$this->run_query($sql);
			return $result;
		}

		public function my_replace($table, $data)
		{
			if (count($data) <= 1)
				return;
			$columns = $data[0];

			$sql = "REPLACE INTO $table (`"
				. implode ("`, `", $columns) . "`) VALUES \n";

			$insert_row = array();
			$threshold = 1000;

			for ($i = 1; $i < count($data); $i++) {
				$insert_value = null;
				foreach ($data[$i] as $value) {
					if (isset($value))
						$insert_value[]	= "'"
							. $this->connection->escape_string($value) . "'";
					else
						$insert_value[] = "NULL";
					if (is_array($value))
						print_r($row);
				}
				$insert_row[] = "(" . implode(", ", $insert_value) . ")\n";
				if (($i % $threshold == 0) || ($i == count($data) - 1)) {
					$sql_str = $sql . implode (",", $insert_row);
					$res = $this->run_query($sql_str);
					if ($this->connection->errno == 0)
						$insert_row = array();
					else
						return $this->connection->errno;
				}
			}
			return $this->connection->errno;
		}

		public function lock($table)
		{
			if (!$this->lock_before_use)
				return true;
			$lock_timeout = $this->deadlock_timeout;
			$lock_time    = microtime(TRUE);
			$lock 	      = $this->my_select("SELECT GET_LOCK('${table}', $lock_timeout) AS `lock`");
			$lock_time    = microtime(TRUE) - $lock_time;
			if (!isset($lock[0]['lock'])) {
				$this->error = "cannot acquire lock to update ${table}";
				$this->errno = 1;
				return false;
			}
			if (intval($lock[0]['lock']) != 1) {
				$this->error = "cannot acquire lock to update ${table}";
				$this->errno = 1;
				return false;
			}
			@mlog("acquired lock for ${table} after $lock_time seconds", LOG_DEBUG3, FALSE);
			$this->table_locks[$table] = microtime(TRUE);
			return true;
		}

		public function release($table)
		{
			if (!$this->lock_before_use)
				return true;
			if (!isset($this->table_locks[$table])) {
				$this->error = "lock time for ${table} table is not found";
				$this->errno = 1;
				return false;
			}
			$lock_time = microtime(TRUE) - $this->table_locks[$table];
			unset($this->table_locks[$table]);
			$lock = $this->my_select("SELECT RELEASE_LOCK('${table}') AS `lock`");
			if (!isset($lock[0]['lock'])) {
				$this->error = "lock for ${table} table is not found";
				$this->errno = 1;
				return false;
			}
			if (intval($lock[0]['lock']) != 1) {
				$this->error = "lock for ${table} table is not established here";
				$this->errno = 1;
				return false;
			}
			@mlog("released lock for ${table} after $lock_time seconds", LOG_DEBUG3, FALSE);
			return true;
		}

		public function insert($table, $data, $ignore = true, $update_cols = null)
		{
			if (!is_array($data))
				return false;
			if (count($data) < 2)
				return false;
			if (is_array($update_cols)) {
				$values = array();
				$add	= array();
				foreach ($update_cols as $i => $col) {
					if (is_numeric($i) && intval($i) == $i) {
						$add[$col] 	  = $col;
						$values[$col] = "VALUES(`$col`)";
					} else {
						$add[$i] 	  = $i;
						$values[$i]   = isset($col) ? $col : "NULL";
					}
				}
				$update_cols = array_values($add);
			}
			$columns = $data[0];

			if ($ignore)
				$sql = "INSERT IGNORE INTO $table (`"
					. implode ("`, `", $columns) . "`) VALUES \n";
			else {
				$sql = "INSERT INTO $table (`"
					. implode ("`, `", $columns) . "`) VALUES \n";
				if (!isset($update_cols))
					$update_cols = $columns;
				if (!is_array($update_cols))
					$update_cols = $columns;
				$cols = $update_cols;
				if (count($cols) == 0)
					$cols = $columns;
				$on_dup = array();
				foreach ($cols as $col)
					$on_dup[] = "`$col` = " . (isset($values[$col]) ? $values[$col] : "VALUES(`$col`)");
				$on_dup = "ON DUPLICATE KEY UPDATE " . implode(", ", $on_dup);
			}
			$insert_row   = array();
			$threshold    = 1000;
			if (!$this->lock($table))
				return false;
			for ($i = 1; $i < count($data); $i++) {
				if (!isset($data[$i]))
					continue;
				if (!is_array($data[$i]))
					continue;
				$insert_value = null;
				foreach ($data[$i] as $value) {
					if (isset($value)) {
						if (doubleval($value) === $value)
							$insert_value[] = $value;
						elseif (intval($value) === $value)
							$insert_value[] = $value;
						else
							$insert_value[]	= "'" . $this->connection->escape_string($value) . "'";
					} else
						$insert_value[] = "NULL";
				}
				$insert_row[] = "(" . implode(", ", $insert_value) . ")\n";
				if (($i % $threshold == 0) || ($i == count($data) - 1)) {
					$sql_str = $sql . implode (",", $insert_row);
					if (!$ignore)
						$sql_str .= $on_dup;
					$res = $this->run_query($sql_str);
					if ($this->connection->errno == 0)
						$insert_row = array();
					else
						goto db_insert_return;
				}
			}
		db_insert_return:
			$this->release($table);
			return $this->connection->errno;
		}

		public function mass_update($table, $data, $key = null)
		{
			if (!is_array($data))
				return false;
			if (count($data) < 2)
				return false;
			if (!is_array($key)) {
				$this->error = "no key is supplied for update";
				$this->errno = 1;
				return false;
			}
			$columns = $data[0];
			$keys 	 = array_intersect($key, $columns);

			if (count($keys) == 0) {
				$this->error = "mass update is not possible without proper unique key combination: "
					. json_encode($key);
				$this->errno = 1;
				return false;
			}
			if (!isset($this->defaultdb)) {
				$db = explode(".", $table);
				if (count($db) < 2) {
					$this->error = "no default database found, cannot do update";
					$this->errno = 1;
					return false;
				}
				$this->defaultdb = $db[0];
			}

			$db = $this->defaultdb;
			$tmp_table = uniqid();
			$this->my_update("CREATE TEMPORARY TABLE $db.${tmp_table} LIKE $table");
			$this->insert("$db.${tmp_table}", $data);

			$join = array();
			foreach ($keys as $attr)
				$join[] = "a.`$attr` = b.`$attr`";
			$joins = implode(" AND ", $join);
			$sets  = implode(", ", $join);
			// Update the existing rows
			$this->my_update("
					UPDATE $table a
					LEFT JOIN
						$db.${tmp_table} b ON $joins
					SET
						$sets
					WHERE
						b.`$attr` IS NOT NULL
				");
			// Insert new rows
			$this->my_update("
					INSERT IGNORE INTO $table (`" . implode("`, `", $columns) . "`)
					SELECT a.`" . implode("`, a.`", $columns) . "`
					FROM
						$db.${tmp_table} a
					LEFT JOIN
						$table b ON $joins
					WHERE
						b.`$attr` IS NULL
				");
		}

		public function get_column_names($table)
		{
			$columns = $this->my_select("SHOW COLUMNS FROM $table");
			if ($this->connection->errno != 0)
				return $this->connection->errno;
			foreach ($columns as $column)
				$col[] = $column['Field'];
			return $col;
		}

		public function my_select($query)
		{
			// mlog($this->debug(), LOG_DETAIL, FALSE, '/home/hoatv/debug');
			$res = $this->run_query($query);
			if ($this->connection->errno == 0) {
				$ret = array();
				while ($row = $res->fetch_assoc())
					$ret[] = $row;
				if ($this->connection->errno != 0) {
					mlog($this->debug() . " || " . substr($query,0,60) , LOG_DETAIL, FALSE, '/home/hoatv/debug');
					if ($this->die_on_err)
						die ($this->connection->error . "\n");
				}
				return $ret;
			} else {
				mlog($this->debug() . " || " . substr($query,0,60) , LOG_DETAIL, FALSE, '/home/hoatv/debug');
				return $this->connection->errno;
			}
				
		}

		public function my_update($query)
		{
			// mlog($this->debug(), LOG_DETAIL, FALSE, '/home/hoatv/debug');
			$trial = 0;
			// $this->query_stats($query);
			$res = $this->run_query($query);
			if ($this->connection->errno == 0)
				return $this->connection->affected_rows;
			else {
				// mlog($this->debug() . " || " . substr($query,0,60) , LOG_DETAIL, FALSE, '/home/hoatv/debug');
				return $this->connection->errno;
			}	
		}

		public function query_stats($query)
		{
			global $global;

			$local_redis = get_redis_conn($global['local-redis-db']);

			$all_key = safecall($local_redis,"keys",array('PWS-Query-Stats'."*"),$err);
			if (isset($err)) {
				mlog($err, LOG_CRITICAL, TRUE, '/dev/null');
				return false;
			}

			if(!isset($all_key[0])) {
				mlog("No job in Redis!",LOG_DETAIL,FALSE,$this->log_dir);
				// safecall($local_redis,"set",
				// array('PWS-Query-Stats', json_encode(array(
				// 	'start_time' => date('Y-m-d h:m:s'),
				// 	'end_time'	=> date('Y-m-d h:m:s'),
				// 	'data'	=> array(
				// 		'sel_product' => 0,
				// 		'sel_order_item' => 0,
				// 	)
				// ))), $err);
				
				$data = array(
					'start_time' => date('Y-m-d h:m:s'),
					'end_time'	=> date('Y-m-d h:m:s'),
					'data'	=> array(
						'sel_product' => 0,
						'sel_order_item' => 0,
					)
				);

				if (isset($err)) {
				  mlog($err, LOG_CRITICAL, TRUE, '/dev/null');
				  return false;
				}
			} else {
				$data = json_decode(safecall($local_redis,"get", array($all_key[0]), $err),true);
			}

			if(strpos($query,'sel_product') !== FALSE) {
				$data['end_time'] = date('Y-m-d h:m:s');
				$data['data']['sel_product']++;
			}

			if(strpos($query,'sel_order_item') !== FALSE) {
				$data['end_time'] = date('Y-m-d h:m:s');
				$data['data']['sel_order_item']++;
			}
	  
			mlog("ReUpdate Redis queue",LOG_DETAIL,FALSE,'/dev/null');
			safecall($local_redis,"set",
			array('PWS-Query-Stats', json_encode($data)), $err);
			if (isset($err)) {
				mlog($err, LOG_CRITICAL, TRUE, '/dev/null');
				return false;
			}
			safecall($local_redis,"close",array(),$err);
		}

		

		private function run_query($query)
		{
			$trial = 0;
			if (!@$this->connection->ping()) {// In case conn is closed by child proc
				@$this->connection->close();
				$this->connection = new mysqli(
					$this->db_config['host'],
					$this->db_config['user'],
					$this->db_config['pass'],
					$this->db_config['name'],
					$this->db_config['port']
				);
				// mlog("something is wrong", LOG_CRITICAL, TRUE, "/dev/null", 1);
				// mlog("something is wrong", LOG_CRITICAL, TRUE, "/dev/null", 2);
				// mlog("something is wrong", LOG_CRITICAL, TRUE, "/dev/null", 3);
				// mlog("something is wrong", LOG_CRITICAL, TRUE, "/dev/null", 4);
				// echo $query . "\n";
				mysqli_options($this->connection,
					MYSQLI_READ_DEFAULT_GROUP, "max_allowed_packet=90M");
				$this->connection->set_charset("utf8");
				if (!is_object($this->connection)) {
					if ($this->die_on_err)
						die ("cannot establish mysql connection\n");
					return -1;
				}
				if ($this->connection->errno !== 0) {
					if ($this->die_on_err)
						die ($this->connection->error . "\n");
					return $this->connection->errno;
				}
				$this->my_update("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
			}
			while ($trial < $this->max_trial)
			{
				$trial++;
				$res = $this->connection->query($query);
				$this->last_query 	= $query;
				$this->error 		= $this->connection->error;
				$this->errno 		= $this->connection->errno;
				if ($this->connection->errno == 0)
					return $res;
				if (strpos($this->connection->error, "Deadlock") !== FALSE)
					usleep(rand(0, 1500000));
			}
			if ($this->connection->errno != 0) {
				if ($this->die_on_err)
					die ($this->connection->error . "\n" . substr($query, 0, 2000) . "...\n");
				else
					return $this->connection->errno;
			}
			return $res;
		}

		public function is_table($table)
		{
			$table = str_replace("`", "", $table);
			$res = $this->my_select("
				SELECT
					*
				FROM
					information_schema.tables
				WHERE
					CONCAT(TABLE_SCHEMA, '.', TABLE_NAME) = \"$table\"");
			if (count($res) > 0)
				return true;
			return false;
		}

		public function get_table_struct_fields($table)
		{
			if (!$this->is_table($table))
				return array();
			$res = $this->my_select("SHOW COLUMNS FROM $table");
			foreach ($res as $row)
				$ret[$row["Field"]] = $row;
			return $ret;
		}

		public function get_table_struct_str($table)
		{
			if (!$this->is_table($table))
				return "";
			$res = $this->my_select("SHOW CREATE TABLE $table");
			$res = preg_replace("/[\r\n]+/", "\n", $res[0]["Create Table"]);
			$res = explode("\n", $res);
			foreach ($res as $row) {
				$matches = null;
				preg_match("/^\s+\`([^\`]+)\` (.+)$/", $row, $matches);
				if (isset($matches[1]))
					if ($matches[1] != "")
						$fields[$matches[1]] = array(
							"col" => $matches[1],
							"attr" => preg_replace("/,$/", "", $matches[2])
						);
				$matches = null;
				preg_match("/^\s+(PRIMARY KEY|UNIQUE KEY|KEY) (.+),/", $row, $matches);
				if (isset($matches[1]))
					$fields[$matches[1]][] = $matches[2];
				$matches = null;
				preg_match("/ENGINE(.+)/", $row, $matches);
				if (isset($matches[1]))
					$fields["ENGINE"] = $matches[1];
			}
			return $fields;
		}

		public function sort_columns($to_table, $from_conn, $from_table)
		{
			$to_sort = true;
			while ($to_sort) {
				$to_attrs = array();
				$from_attrs = array();
				$to_sort = false;
				$to_struct_str = $this->get_table_struct_str($to_table);
				foreach ($to_struct_str as $attr)
					if (isset($attr['col']))
						$to_attrs[] = $attr['col'];
				$from_struct_str = $from_conn->get_table_struct_str($from_table);
				foreach ($from_struct_str as $attr)
					if (isset($attr['col']))
						$from_attrs[] = $attr['col'];
				for ($i = 1; $i < count($to_attrs); $i++)
					if (strcmp($from_attrs[$i], $to_attrs[$i]) != 0) {
						$col = $from_attrs[$i];
						if (!isset($to_struct_str[$col]))
							die ("Incompatible tables\n");
						$col_def = $to_struct_str[$col]['attr'];
						$prev_col = $from_attrs[$i - 1];
						echo "ALTER TABLE $to_table MODIFY `$col` $col_def AFTER `$prev_col`\n";
						$this->my_update("
								ALTER TABLE $to_table MODIFY
									`$col` $col_def AFTER `$prev_col`
							");
						$to_sort = true;
						break;
					}
			}
		}

		public function create_table_from_table($to_table, $from_conn = NULL, $from_table)
		{
			if (!isset($from_conn))
				$from_conn = $this;

			if ($this->is_table($to_table))
				return -1;
			if (!$from_conn->is_table($from_table))
				return -2;

			$from_struct_str = $from_conn->my_select("SHOW CREATE TABLE $from_table");
			$sql = $from_struct_str[0]["Create Table"];
			$sql = preg_replace("/CREATE TABLE \`[^\`]+`/", "CREATE TABLE $to_table", $sql);
			$this->my_update("SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0");
			$this->my_update($sql);
			return 0;
		}

		public function change_table_struct($to_table, $from_conn, $from_table)
		{
			if (!isset($from_conn))
				$from_conn = $this;
			if (!$from_conn->is_table($from_table))
				return -2;

			if (!$this->is_table($to_table))
				$this->create_table_from_table($to_table, $from_conn, $from_table);
			$to_struct_array = $this->get_table_struct_fields($to_table);
			$to_struct_str = $this->get_table_struct_str($to_table);
			$from_struct_array = $from_conn->get_table_struct_fields($from_table);
			$from_struct_str = $from_conn->get_table_struct_str($from_table);

			if (!isset($from_struct_str))
				return -2;
			if (count($from_struct_str) == 0)
				return -2;

			// Delete old fields
			foreach ($to_struct_array as $field => $attr)
				if (!isset($from_struct_array[$field]))
					$sql[] = "DROP COLUMN `$field`";
			if (isset($sql)) {
				$this->my_update("ALTER TABLE $to_table " . implode(",\n", $sql));
			}

			// Add new fields
			$prev_column = null;
			$sql = null;
			foreach ($from_struct_str as $field => $attr) {
				if (!isset($to_struct_array[$field]))
					if (!in_array($field, array("KEY", "PRIMARY KEY", "UNIQUE KEY", "ENGINE")))
						$sql[] = "ADD COLUMN `$field` "
							. $attr["attr"] . " "
							. (isset($prev_column)
								? "AFTER `$prev_column`" : "FIRST");
				$prev_column = $field;
			}

			// If primary key is missing, then add it in
			if ((!isset($to_struct_str["PRIMARY KEY"]))
					&& (isset($from_struct_str["PRIMARY KEY"])))
				$sql[] = "ADD PRIMARY KEY " . $from_struct_str["PRIMARY KEY"][0];

			if (isset($sql)) {
				$this->my_update("ALTER TABLE $to_table " . implode(",\n", $sql));
			}

			$this->sort_columns($to_table, $from_conn, $from_table);
			return 0;
		}

		public function get_affected_rows()
		{
			return $this->connection->affected_rows;
		}

		public function debug()
		{
		  $debug_details = debug_backtrace();
		  unset($debug_details[0]);
	  
		  $flow = array();
		  foreach ($debug_details as $row) {
			$flow[] = isset($row['class']) ? $row['class'] : '' ."-".$row['function']."-".$row['line'];
		  }
		  krsort($flow);
	  
		  return implode('||',array_values($flow));
		}
	}
	?>
