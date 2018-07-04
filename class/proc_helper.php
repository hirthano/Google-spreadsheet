<?php
	function multi_exec($max_proc, &$params, $timeouts, $exec_callback, $completed_callback = null, $failed_callback = null)
	{
		$outcome = array();

		if (!isset($max_proc))
			$max_proc = 1;
		if (!is_numeric($max_proc))
			$max_proc = 1;

		if ((!is_array($params)) || (!is_array($timeouts)))
			return false;

		if (count($params) != count($timeouts))
			return false;

		if (!isset($exec_callback))
			return false;

		$params   = array_values($params);
		$timeouts = array_values($timeouts);
		$async 	  = array();
		for ($i = 0; $i < $max_proc; $i++)
			$async[] = new Async();

		$continue = true;
		$pos	  = 0;
		// stop after $global['daemon-lifetime'] hour (to restart), given that all children have finished
		while ($continue) {
			poll();
			$continue = false;

			// finding free daemons for uploading
			while ($pos < count($params)) {
				$found = false;
				for ($i = 0; $i < $max_proc; $i++)
					if ($async[$i]->get_status() == 'idle') {
						$async[$i]->set_callback($exec_callback);
						$async[$i]->set_args($params[$pos]);
						$async[$i]->set_timeout($timeouts[$pos++]);
						$async[$i]->run();
						$found = true;
						break;
					}
				if (!$found)
					break;
			}

			for ($i = 0; $i < $max_proc; $i++)
				switch ($async[$i]->get_status()) {
					case 'executing':
						$continue = true;
						break;
					case 'completed':
						$outcome[] = array(
							'input'  => $async[$i]->get_input(),
							'output' => $async[$i]->get_output(),
						);
						if (isset($completed_callback))
							$completed_callback($async[$i]->get_output(), $async[$i]->get_input());
						$async[$i]->reset();
						break;

					case 'failed':
						$outcome[] = array(
							'input'  => $async[$i]->get_input(),
							'failed' => true,
						);
						if (isset($failed_callback))
							$failed_callback();
						$async[$i]->reset();
						break;
				}
			if ($pos < count($params))
				$continue = TRUE;
		}
		return $outcome;
	}

	function safecall($obj, $callback_name, $params, &$err = null, $max_repeat = 0)
	{
		$rep = -1;
		while ($rep++ < $max_repeat) {
			$err = null;
			try {
				$ret = call_user_func_array(array($obj, $callback_name), is_array($params) ? $params : array());
			} catch (Exception $e) {
				$err = $e->getMessage();
				usleep(10000);
				continue;
			}
			return $ret;
		}
		return false;
	}

	function get_redis_conn($config, &$err = null)
	{
		global $redis_conns;

		$pid = getmypid();
		if (!isset($config['host'])) {
			$err = "no host found";
			return NULL;
		}

		$name = implode("__", $config);
		if (!isset($redis_conns[$pid][$name])) {
initiate_redis:
			$redis_conns[$pid][$name] = new Redis();
			$params = array('host' => $config['host']);
			if (isset($config['port']))
				$params['port'] = $config['port'];
			if (isset($config['timeout'])) {
				if (!isset($params['port']))
					$params['port'] = null;
				$params['timeout'] = $config['timeout'];
			}
			safecall($redis_conns[$pid][$name], "connect", $params, $err);
			if (isset($err))
				return null;
			$pong = safecall($redis_conns[$pid][$name], "ping", null, $err);
			if (isset($err))
				return null;
			if ($pong !== '+PONG')
				return null;
			return $redis_conns[$pid][$name];
		}

		$pong = safecall($redis_conns[$pid][$name], "ping", null, $err);
		if (isset($err))
			goto initiate_redis;
		if ($pong !== '+PONG')
			goto initiate_redis;
		return $redis_conns[$pid][$name];
	}

	function cassandra_batch_update($conn, $table, $config, $data, &$err = null)
	{
		if (!isset($table)) {
			$err = "table unspecified";
			return false;
		}
		if ($table == "") {
			$err = "table unspecified";
			return false;
		}
		if (!is_array($data)) {
			$err = "data is not properly specified";
			return false;
		}
		if (!isset($config['primary'])) {
			$err = "data config is not properly specified";
			return false;
		}
		if (!is_array($config['primary'])) {
			$err = "data config is not properly specified";
			return false;
		}
		if (!is_array($config['data'])) {
			$err = "data config is not properly specified";
			return false;
		}
		foreach ($data as $i => $row) {
			if (!is_array($row)) {
				$err = "data is not properly specified";
				return false;
			}
			$row_update = array();
			foreach (array_merge($config['data'], $config['primary']) as $col) {
				if (!isset($row[$col])) {
					$err = "column specification is incorrect";
					return false;
				}
				$row_update[$col] = $row[$col];
			}
			$data[$i] = $row_update;
		}
		$update_query = "UPDATE $table SET ";
		$col_updates = array();
		foreach ($config['data'] as $col)
			$col_updates[] = "$col = ?";
		$update_query .= implode(", ", $col_updates) . " WHERE ";
		$where = array();
		foreach ($config['primary'] as $col)
			$where[] = "$col = ?";
		$update_query .= implode(", ", $where);

		$prepared = safecall($conn, "prepare", array($update_query), $err);
		if (isset($err))
			return false;
		$batch    = new Cassandra\BatchStatement(Cassandra::BATCH_UNLOGGED);
		foreach ($data as $update)
			$batch->add($prepared, $update);
		safecall($conn, "execute", array($batch), $err);
		if (isset($err))
			return false;
		return true;
	}

	function get_pheanstalk_conn($config, &$err = null)
	{
		global $pheanstalk_conns;
		if (!isset($config['host'])) {
			$err = "no host found";
			return NULL;
		}
		$pid = getmypid();
		$name = implode("__", $config);
		$start = microtime(TRUE);
		if (!isset($pheanstalk_conns[$pid][$name])) {

initiate_pheanstalk:
			if (isset($config['port'])) {
				if (isset($config['timeout']))
					$pheanstalk_conns[$pid][$name] = new Pheanstalk\Pheanstalk($config['host'], $config['port'], $config['timeout']);
				else
					$pheanstalk_conns[$pid][$name] = new Pheanstalk\Pheanstalk($config['host'], $config['port']);
			} elseif (isset($timeout))
				$pheanstalk_conns[$pid][$name] = new Pheanstalk\Pheanstalk($config['host'], 11300, $config['timeout']);
			else
				$pheanstalk_conns[$pid][$name] = new Pheanstalk\Pheanstalk($config['host']);
			mlog("start Pheanstalk takes " . (microtime(TRUE) - $start) . " seconds", LOG_DEBUG3, FALSE, '/dev/null');

			if (!$pheanstalk_conns[$pid][$name]->getConnection()->isServiceListening()) {
				$err = "cannot connect to remote server $remote";
				return null;
			}
			mlog("check Pheanstalk connection takes " . (microtime(TRUE) - $start)
				. " seconds", LOG_DEBUG3, FALSE, '/dev/null');
			return $pheanstalk_conns[$pid][$name];
		}
		if (!$pheanstalk_conns[$pid][$name]->getConnection()->isServiceListening())
			goto initiate_pheanstalk;

		mlog("check Pheanstalk connection takes " . (microtime(TRUE) - $start)
			. " seconds", LOG_DEBUG3, FALSE, '/dev/null');
		return $pheanstalk_conns[$pid][$name];
	}

	function get_mysql_conn($remote, &$err = null, $force = FALSE)
	{
		global $mysql_conns;

		$pid 	= getmypid();
		$name 	= implode("__", $remote);
		if ((!isset($mysql_conns[$pid][$name])) || ($force)) {
			if (isset($mysql_conns[$pid][$name])) {
				safecall($mysql_conns[$pid][$name], "close", array(), $err, 3);
				unset($mysql_conns[$pid][$name]);
			}
			new_db($mysql_conns[$pid][$name], $remote);
		}
		if (isset($mysql_conns[$pid][$name])) {
			$data = safecall($mysql_conns[$pid][$name], "my_select", array("SELECT 1 + 1 AS val"), $err);
			if (isset($data[0]['val']))
				if ($data[0]['val'] == 2)
					return $mysql_conns[$pid][$name];
			return NULL;
		}
		$err = "cannot establish MYSQL connection";
		return null;
	}

	function get_cassandra_conn($remote, &$err = null)
	{
		global $cassandra_conns;

		$name = sha1(json_encode($remote));
		if (!isset($cassandra_conns[$name])) {
			try {
				$cluster = Cassandra::cluster();
				if (isset($remote['contacts']))
					if (is_array($remote['contacts']))
						safecall($cluster, "withContactPoints", $remote['contacts']);
				if (isset($remote['timeout'])) {
					$cluster->withConnectTimeout($remote['timeout']);
					$cluster->withDefaultTimeout($remote['timeout']);
					$cluster->withRequestTimeout($remote['timeout']);
				}
				if (isset($remote['max-conns']))
					$cluster->withConnectionsPerHost(2, $remote['max-conns']);
				if (isset($remote['port']))
					$cluster->withPort($remote['port']);
				if (isset($remote['user']) && isset($remote['pass']))
					$cluster->withCredentials($remote['user'], $remote['pass']);
				$remote->build();
				$session = $cluster->connect();
			} catch (Exception $e) {
				$err = $e->getMessage();
				return null;
			}
			$cassandra_conns[$name] = $session;
		}
		if (isset($cassandra_conns[$name])) {
			$res = safecall($cassandra_conns[$name], "execute",
				array("select keyspace_name from system_schema.tables limit 1"), $err);
			if (isset($err))
				return null;
			foreach ($res as $row) {
				if (isset($row['keyspace_name']))
					return $cassandra_conns[$name];
				$err = "cannot establish Cassandra connection";
				return null;
			}
		}
	}

	function close_redis_conn($remote)
	{
		global $redis_conns;
		$pid = getmypid();
		if (isset($redis_conns[$pid][$remote])) {
			safecall($redis_conns[$pid][$remote], "close", array());
			unset($redis_conns[$pid][$remote]);
		}
	}

	function close_pheanstalk_conn($remote)
	{
		global $pheanstalk_conns;
		$pid = getmypid();
		if (isset($pheanstalk_conns[$pid][$remote]))
			unset($pheanstalk_conns[$pid][$remote]);
	}
