<?php
// 	require_once("miner_helper.php");
// 	require_once("Loader.php");
// 	require_once("url_to_absolute.php");

	class BaseProcessor
	{
		public $error			= NULL;
		protected $log_level	= 0;
		protected $logdir 		= "/dev/null";
		protected $global		= NULL;
		protected $process_name	= NULL;
		private $last_available = NULL;
		protected $redis_conf	= NULL;
		protected $redis_conn	= NULL;
		private $exec			= NULL;
		private $timeout		= NULL;
		private $max_mem		= 10000000000; // 10GB max memory for process
		private $alive_timeout	= 10;
		private $daemon_params	= NULL;
		private $call_ids		= NULL;
		private $repetition		= FALSE;
		private $pid			= NULL;

		public function __construct($global = NULL)
		{
			global $log_level;

			$this->pid = posix_getpid();
			if (!function_exists("mlog")) {
				fprintf(STDERR, "logging function mlog() is not available");
				exit;
			}
			if (!class_exists("Async")) {
				$this->log("engine for asynchronous execution is not available", LOG_CRITICAL, TRUE);
				exit;
			}
			if (!class_exists("Redis")) {
				$this->log("redis library is not available", LOG_CRITICAL, TRUE);
				exit;
			}
			if ((!function_exists("get_redis_conn")) || (!function_exists("safecall"))) {
				$this->log("proc_helper calls are not available, please include it", LOG_CRITICAL, TRUE);
				exit;
			}
			if (isset($log_level))
				$this->log_level = $log_level;
			$this->daemon_params = array(array());
			$this->call_ids		 = array(strtoupper(bin2hex(openssl_random_pseudo_bytes(50))));
			if (!is_array($global))
				return TRUE;
			foreach ($global as $key => $val)
				if (method_exists(array($this, "set_" . $key)))
					$this->{"set_" . $key}($val);
			return TRUE;
		}

		public function set_redis_conf($redis_conf = NULL)
		{
			if (!is_array($redis_conf))
				return $this->log("redis configuration is invalid");
			$this->redis_conf = $redis_conf;
			return TRUE;
		}

		public function set_repetition($rep = FALSE)
		{
			$this->repetition = ($rep === TRUE) ? TRUE : FALSE;
			return $this->repetition;
		}

		public function set_daemon_params($params)
		{
			if (!is_array($params))
				return $this->log("daemon params must be an array of array of params", LOG_CRITICAL, TRUE);
			$this->daemon_params 	= array_values($params);
			$ref 					= new ReflectionMethod(get_class($this), "main");
			$arg_count 				= $ref->getNumberOfParameters();
			for ($i = 0; $i < count($this->daemon_params); $i++) {
				if (count($this->daemon_params[$i]) > $arg_count) {
					$this->log("main() allows maximum $arg_count arguments, "
						. count($this->daemon_params[$i]) . " were given", LOG_CRITICAL, TRUE);
					return FALSE;
				}
				if (!isset($this->call_ids[$i]))
					$this->call_ids[$i] = strtoupper(bin2hex(openssl_random_pseudo_bytes(50)));
			}
			return TRUE;
		}

		public function set_process_name($name = NULL)
		{
			if (!is_string($name))
				return $this->log("process name is invalid", LOG_CRITICAL, TRUE);
			if ($name == '')
				return $this->log("process name is empty", LOG_CRITICAL, TRUE);
			$this->process_name = $name;
			return TRUE;
		}

		public function set_max_mem($mem = NULL)
		{
			if (!is_numeric($mem))
				return $this->log("max memory setting must be numeric", LOG_CRITICAL, TRUE);
			$this->max_mem = intval($mem);
			return TRUE;
		}

		public function set_alive_timeout($timeout = NULL)
		{
			if (!isset($timeout)) {
				$this->alive_timeout = NULL;
				return TRUE;
			}
			if (!is_numeric($timeout))
				return $this->log("alive response timeout setting must be numeric", LOG_CRITICAL, TRUE);
			$this->alive_timeout = intval($timeout);
			return TRUE;
		}

		public function set_last_available($timestamp = NULL)
		{
			if (!isset($timestamp))
				return FALSE;
			if (!is_numeric($timestamp))
				return $this->log("last available timestamp must be numeric", LOG_CRITICAL, TRUE);
			$this->last_available = intval($timestamp);
			return TRUE;
		}

		public function set_timeout($timeout = NULL)
		{
			if (!is_numeric($timeout))
				return $this->log("process timeout setting must be numeric", LOG_CRITICAL, TRUE);
			$this->timeout = intval($timeout);
			return TRUE;
		}

		public function set_log_root($log_root = null)
		{
			if (!is_string($log_root))
				return $this->log('incorrect specification of log root', LOG_CRITICAL, TRUE);
			if (!is_dir($log_root))
				return $this->log('incorrect specification of log root', LOG_CRITICAL, TRUE);
			$this->logdir = $log_root;
			return TRUE;
		}

		public function set_log_level($lvl)
		{
			$this->log_level = intval($lvl);
		}

		public function main()
		{
			return TRUE;
		}

		public function _run($i)
		{
			$duration = is_numeric($this->timeout) ? $this->timeout : 1000000000;
			$start = time();

			if (!is_array($this->redis_conf))
				return $this->log("no redis configuration exists, terminate now!", LOG_CRITICAL, TRUE);
			$this->redis_conf['aux'] = strtoupper(bin2hex(openssl_random_pseudo_bytes(50)));
			$this->redis_conn = get_redis_conn($this->redis_conf, $err);
			if (isset($err))
				return $this->log($err, LOG_CRITICAL, TRUE);
			if (isset($this->alive_timeout)){
				$alive_timeout = max(1, $this->alive_timeout) * 2;
				$event_name  = "processor_event:"
					. preg_replace("/\s+/", "", $this->process_name) . ":" . $this->call_ids[$i];
			}
			while ((time() - $start < $duration) && (memory_get_usage(TRUE) < $this->max_mem)) {
				usleep(50000);
				if (isset($this->alive_timeout))
					safecall($this->redis_conn, "set", array($event_name, time(), $alive_timeout), $err, 3);
				call_user_func_array(array($this, "main"), $this->daemon_params[$i]);
			}
			if (isset($this->alive_timeout))
				safecall($this->redis_conn, "del", array($event_name), $err, 3);
			if (memory_get_usage(TRUE) >= $this->max_mem)
				return $this->log("occupied memory exceeded limit " . $this->max_mem
					. " bytes for process " . $this->process_name . ":" . $this->call_ids[$i],
					LOG_CRITICAL, TRUE);
			return TRUE;
		}

		public function run($i = NULL)
		{
			global $async_process_registrations;

			if (!is_string($this->process_name))
				return $this->log("no process name given, cannot run", LOG_CRITICAL, TRUE);
			if ($this->process_name == '')
				return $this->log("process name is empty, cannot run", LOG_CRITICAL, TRUE);

			$daemons = range(0, count($this->daemon_params) - 1);
			if (is_numeric($i)) {
				if (!isset($this->daemon_params[$i]))
					return $this->log("cannot execute $i-th daemon for process " . $this->process_name,
						LOG_CRITICAL, TRUE);
				$daemons = array($i);
			}

			// we first reset the timestamp counter in Redis
			// to avoid them being used illegitimately
			foreach ($daemons as $i) {
				$event_name = "processor_event:"
				. preg_replace("/\s+/", "", $this->process_name) . ":" . $this->call_ids[$i];
				$keys[$event_name] = $event_name;
			}
			$this->redis_conn = get_redis_conn($this->redis_conf, $err);
			if (isset($err))
				return $this->log($err, LOG_CRITICAL, TRUE);
			safecall($this->redis_conn, "del", array(array_values($keys)), $err, 3);
			if (isset($err))
				return $this->log($err, LOG_CRITICAL, TRUE);

			foreach ($daemons as $i) {
				$event_name = "processor_event:"
				. preg_replace("/\s+/", "", $this->process_name) . ":" . $this->call_ids[$i];
				if (!isset($this->exec[$i])) {
					$this->exec[$i] = new Async();
					$this->exec[$i]->set_callback(array($this, "_run"));
					$this->exec[$i]->set_args($i);
					if (is_numeric($this->timeout))
						$this->exec[$i]->set_timeout($this->timeout + 2);
				}
				$this->last_available = time();
 				//echo "setting last available = " . $this->last_available . " for $event_name\n";
				$this->exec[$i]->run();
				$async_process_registrations[$this->pid][$event_name] = array(
					'process' => $this,
					'event_id' => $i,
				);
			}
			return TRUE;
		}

		public function get_status($i = NULL)
		{
			if (isset($this->exec[$i]))
				return $this->exec[$i]->get_status();
			return NULL;
		}

		public function get_alive_timeout()
		{
			return $this->alive_timeout;
		}

		public function get_last_available()
		{
			return $this->last_available;
		}

		public function is_repetitive()
		{
			return $this->repetition;
		}

		public function kill($i)
		{
			if ($this->exec[$i]->get_status() == 'executing') {
				return $this->exec[$i]->timeout_handler(0);
			}
			return TRUE;
		}

		public function watch()
		{
			global $async_process_registrations;
			if (!is_array($async_process_registrations))
				return TRUE;
			if (!isset($async_process_registrations[$this->pid]))
				return TRUE;
			poll();

			// retrieve the list of executing processes
			// and clean completed processes
			foreach ($async_process_registrations[$this->pid] as $event => $handler) {
				switch ($handler['process']->get_status($handler['event_id'])) {
					case 'completed':
						if ($handler['process']->is_repetitive()) {
							$handler['process']->run($handler['event_id']);
							$this->log("repeating process $event", LOG_CRITICAL, FALSE);
						} else
							unset($async_process_registrations[$this->pid][$event]);
						break;
					case 'failed':
						$this->log("process $event has failed, rerun it", LOG_CRITICAL, FALSE);
						sleep(100);
						$handler['process']->run($handler['event_id']);
						break;
					case 'executing':
						$check_status[$event] 	= $event;
						$event_to_id[$event]	= $handler['event_id'];
						break;
					default:
						unset($async_process_registrations[$this->pid][$event]);
				}
			}

			if (!isset($check_status))
				return TRUE;
			$check_status = array_values($check_status);
			$this->redis_conn = get_redis_conn($this->redis_conf, $err);
			if (isset($err))
				return $this->log($err, LOG_CRITICAL, TRUE);
			$get = safecall($this->redis_conn, "mGet", array($check_status), $err, 3);
			if (isset($err))
				return $this->log($err, LOG_CRITICAL, TRUE);
			$exec = safecall($this->redis_conn, "multi", array(Redis::PIPELINE), $err, 3);
			foreach ($check_status as $key)
				safecall($exec, "ttl", array($key), $err, 3);
			$ttls = safecall($exec, "exec", array(), $err, 3);
			$reset_timer = NULL;

			// restart hung process, signified by:
			// - timestamp in Redis not updated, or if not available (due to failure with Redis),
			// - timestamp stored in the process object
			foreach ($get as $i => $last_update) {
				$proc 			= $async_process_registrations[$this->pid][$check_status[$i]];
				$alive_timeout 	= $proc['process']->get_alive_timeout();
				$last_available = $proc['process']->get_last_available();
				if (!isset($alive_timeout))
					continue;
				if (!is_numeric($last_update)) {
					$last_update = $last_available;
				}
				$proc['process']->set_last_available($last_update);
				if (time() - $alive_timeout - 1 > $last_update) {
					$this->log("process $check_status[$i] is timed out, terminate it", LOG_CRITICAL, TRUE);
					if ($proc['process']->kill($proc['event_id']) === FALSE) {
						$this->log("cannot kill dead process for event " . $check_status[$i],
							LOG_CRITICAL, TRUE);
						continue;
					}
					if ($proc['process']->run($proc['event_id']) === FALSE) {
						$this->log("cannot restart dead process for event " . $check_status[$i],
							LOG_CRITICAL, TRUE);
						continue;
					}
					$reset_timer[$check_status[$i]] = $alive_timeout * 2;
				}
			}
			if (!isset($reset_timer))
				return TRUE;
			$exec = safecall($this->redis_conn, "multi", array(Redis::PIPELINE), $err, 3);
			foreach ($reset_timer as $event => $timeout)
				safecall($exec, "set", array($event, time(), $timeout), $err, 3);
			safecall($exec, "exec", array(), $err, 3);
			if (isset($err))
				return $this->log($err, LOG_CRITICAL, TRUE);
		}

		protected function log($msg, $level, $is_error = false, $trace_level = 2)
		{
			global $log_level;
			if (!function_exists("mlog")) {
				fprintf(STDERR, "logging function mlog() is not available");
				exit;
			}
			$tmp = $log_level;
			$log_level = $this->log_level;
			mlog($msg, $level, $is_error, $this->logdir, $trace_level);
			$log_level = $tmp;
			return !$is_error;
		}
	}
?>
