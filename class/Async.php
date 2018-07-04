<?php
	class Async
	{
		private $magic_end = 'wiuh2lj3h407sdf2039';
		private $start_time;
		private $is_timeout;
		private $debug_level = 0;
		private $callback_args;
		private $dummy_args;
		private $tmp_args;
		private $exec_args;
		private $callback_args_is_object;
		private $callback;
		private $status;
		private $cpid;
		private $pid;
		private $call_id;
		private $output_pipe;
		private $out;
		private $poll_time;

		public function __construct()
		{
			global $output_handler_indicator;
			global $async_handlers;

			$this->pid = posix_getpid();
			if (!isset($output_handler_indicator[$this->pid])) {
				$output_handler_indicator[$this->pid] = stream_socket_pair(
					STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
				stream_set_blocking($output_handler_indicator[$this->pid][1], false);
			}
			$args 		   		 		   = func_get_args();
			$this->timeout 		 		   = 0;
			$this->status  		 		   = 'idle';
			$this->callback_args 		   = array();
			$this->dummy_args 	 		   = array();
			$this->exec_args	 		   = array();
			$this->callback_args_is_object = array();
			if (count($args) > 0)
				$this->set_callback($args[0]);
			if (count($args) > 1)
				call_user_func_array(array($this, "set_args"), array_slice($args, 1));
			if (!@is_array($async_handlers[$this->pid]))
				$async_handlers[$this->pid] = array();
		}

		public function set_args(&$v1 = null, &$v2 = null, &$v3 = null, &$v4 = null,
			&$v5 = null, &$v6 = null, &$v7 = null, &$v8 = null, &$v9 = null,
			&$v10 = null, &$v11 = null, &$v12 = null, &$v13 = null, &$v14 = null,
			&$v15 = null, &$v16 = null, &$v17 = null, &$v18 = null, &$v19 = null)
		{
			$this->callback_args		   = array();
			$this->dummy_args 			   = array();
			$this->tmp_args				   = array();
			$this->callback_args_is_object = array();
			$this->exec_args			   = array();
			for ($i = 1; $i <= func_num_args(); $i++) {
				$this->callback_args[$i - 1] = &${"v" . $i};
				$this->dummy_args[$i - 1]	 = unserialize(serialize(${"v" . $i}));
				$this->tmp_args[$i - 1]		 = unserialize(serialize(${"v" . $i}));
				$this->exec_args[$i - 1]	 = &$this->tmp_args[$i - 1];

				if (is_object(${"v" . $i}))
					$this->callback_args_is_object[] = true;
				else
					$this->callback_args_is_object[] = false;
			}
			$this->status = 'pending';
			$this->out	  = null;
		}

		public function set_callback($callback)
		{
			if (!is_callable($callback))
				return false;
			$this->callback = $callback;
			$this->status   = 'pending';
			$this->out		= null;
		}

		public function reset()
		{
			$this->callback_args		   = array();
			$this->dummy_args 			   = array();
			$this->tmp_args				   = array();
			$this->callback_args_is_object = array();
			$this->exec_args			   = array();
			$this->callback				   = null;
			$this->status				   = 'idle';
			$this->out					   = null;
		}

		public function set_timeout($seconds)
		{
			$this->timeout = intval($seconds);
		}

		// Handle output after execution
		public function output_handler($signal)
		{
			global $async_handlers;
			global $output_handler_indicator;

			if (in_array($signal, array(SIGUSR1, SIGUSR2))) {
				while (($read = fgets($output_handler_indicator[$this->pid][1])) !== false) {
					// We sleep a bit after each read to make sure
					// later we will capture other jobs who return
					// while we're handling this job
					usleep(50000);
					$read = trim($read, "\n");
					if ($read == $this->magic_end)
						break;
					if (!isset($async_handlers[$this->pid][$read]))
						continue;
					if (!($async_handlers[$this->pid][$read]['executed'])) {
						call_user_func_array($async_handlers[$this->pid][$read]['output_handler'], array(0));
						$async_handlers[$this->pid][$read]['executed'] = true;
					}
				}
				$new_vector = array();
				foreach ($async_handlers[$this->pid] as $call_id => $event)
					if (!$event['executed'])
						$new_vector[$call_id] = $event;
				$async_handlers[$this->pid] = $new_vector;
				if (count($async_handlers[$this->pid]) == 0)
					return TRUE;
				foreach ($new_vector as $call_id => $event) {}
				if (!pcntl_signal(SIGUSR1, $event['output_handler']))
					$this->debug(0, "Cannot register SIGUSR1 signal");
				if (!pcntl_signal(SIGUSR2, $event['output_handler']))
					$this->debug(0, "Cannot register SIGUSR2 signal");
				return TRUE;
			}
			if ($this->status == 'executing') {
				$this->debug(1, "Getting output from child");
				$output = "";
				while (($read = fgets($this->output_pipe[1])) !== false) {
					if ($read == ($this->magic_end . "\n"))
						break;
					$output .= $read;
				}
				$output = trim($output, "\n");
				$this->debug(1, "Finish getting output of size " . strlen($output) . " from child");
				if ($this->parse_output($output))
					$this->status = 'completed';
				else
					$this->status = 'failed';
				pcntl_waitpid($this->cpid, $status);
				fclose($this->output_pipe[0]);
				fclose($this->output_pipe[1]);
				$this->debug(1, "Output handler completed");
				return TRUE;
			}
			return TRUE;
		}

		// Handle timeout signal - SIGALRM
		public function timeout_handler($signal)
		{
			global $async_handlers;

			// If the handler is triggered by signal
			// Then execute all registered handlers
			// whose timeouts have expired
			if ($signal == SIGALRM) {
				foreach ($async_handlers[$this->pid] as $call_id => $event)
					if ((!$event['executed']) && ($event['timeout'] <= time())) {
						$this->debug(3, "timeout found for event $call_id with timeout $event[timeout], now is " . time());
						$async_handlers[$this->pid][$call_id]['executed'] = true;

						// check to make sure that the process was
						// not killed unothordoxically by calling timeout_handler(0)
						// and then respawned by some other invoker
						if ($event['proc']->get_call_id() == $call_id)
							call_user_func_array($event['timeout_handler'], array(0));
					}
				$new_vector = array();
				foreach ($async_handlers[$this->pid] as $call_id => $event)
					if (!$event['executed']) {
						$new_vector[$call_id] = $event;
						$last_handler = $event['timeout_handler'];
					}
				$async_handlers[$this->pid] = $new_vector;
				if (count($new_vector) == 0)
					return TRUE;
				$min_time = 1000000000000;
				foreach ($new_vector as $call_id => $event)
					$min_time = min($min_time, $event['timeout']);
				pcntl_signal(SIGALRM, $last_handler);
				pcntl_alarm(max(1, $min_time - time()));
				return TRUE;
			}

			// If the handler is triggered by another handler
			$return = TRUE;
			if ($this->status == 'executing') {
// 				$this->debug(3, print_r(debug_backtrace(), TRUE));
				$this->debug(1, "Handling timeout");
				$this->status = 'failed';
				if (!is_numeric($this->cpid))
					goto timeout_handler_close;
				if ($this->cpid <= 0)
					goto timeout_handler_close;

				//Try terminating process gracefully
				posix_kill($this->cpid, SIGTERM);
				$start = microtime(TRUE);
				while (microtime(TRUE) - $start < 10) {
					$stat = pcntl_waitpid($this->cpid, $status, WNOHANG);
					if (($stat == $this->cpid) || ($stat == -1))
						break;
					usleep(100000);
				}
				if (($stat == $this->cpid) || ($stat == -1))
					goto timeout_handler_close;
				$this->debug(0, "cannot kill timed out process " . $this->cpid . " with SIGTERM, try SIGKILL now");

				//Try terminating process forcefully
				posix_kill($this->cpid, SIGKILL);
				$start = microtime(TRUE);
				while (microtime(TRUE) - $start < 10) {
					$stat = pcntl_waitpid($this->cpid, $status, WNOHANG);
					if (($stat == $this->cpid) || ($stat == -1))
						break;
					usleep(100000);
				}
				if (($stat == $this->cpid) || ($stat == -1))
					goto timeout_handler_close;
				$this->debug(0, "cannot even kill timed out process " . $this->cpid . " with SIGKILL");
				$return = FALSE;

timeout_handler_close:
				fclose($this->output_pipe[0]);
				fclose($this->output_pipe[1]);
				return $return;
			}
			return $return;
		}

		public function poll()
		{
			$now = microtime(true);
			if (!isset($this->poll_time)) {
				$this->poll_time = $now;
				return true;
			}
			if ($now - $this->poll_time >= 0.25) {
				pcntl_signal_dispatch();
				$this->poll_time = $now;
				$this->output_handler(SIGUSR1);
				$this->timeout_handler(SIGALRM);
			}
		}

		public function set_handler()
		{
			global $async_handlers; // The list of output handlers

			$call_id = strtoupper(bin2hex(openssl_random_pseudo_bytes(50)));
			$timeout = 10000000;
			if (isset($this->timeout))
				if (is_numeric($this->timeout))
					if ($this->timeout > 0)
						$timeout = intval($this->timeout) + 1;
			$async_handlers[$this->pid][$call_id] = array(
				'output_handler'  => array($this, "output_handler"),
				'timeout_handler' => array($this, "timeout_handler"),
				'proc'			  => $this,
				'timeout'		  => time() + $timeout,
				'executed' 		  => false,
			);
			$this->debug(3, "preparing handlers for $call_id, with timeout " . $async_handlers[$this->pid][$call_id]['timeout']);
			$this->call_id = $call_id;
			$this->output_handler(SIGUSR2);
			$this->timeout_handler(SIGALRM);
		}

		public function get_output()
		{
			if ($this->status != 'completed')
				return null;
			return $this->out;
		}

		private function parse_output($out)
		{
			$this->debug(1, "Parsing output");
			$this->debug(3, $out);
			$output = unserialize($out);
			$this->debug(2, print_r($output, TRUE));
			if (!isset($output["aux"]))
				return false;
			$this->out = $output["out"];
			foreach ($output["aux"] as $v => $val)
				$this->callback_args[intval($v)] = $val;
			$this->debug(1, "End parsing\n");
			return true;
		}

		private function make_output()
		{
			$aux_args = array();
			for ($i = 0; $i < count($this->exec_args); $i++) {
				if ($this->callback_args_is_object[$i]) {
					if (!is_object($this->tmp_args[$i])) {
						$aux_args[$i] = $this->tmp_args[$i];
						continue;
					}
					if (get_class($this->tmp_args[$i])
						!= get_class($this->dummy_args[$i]))
					{
						$aux_args[$i] = $this->tmp_args[$i];
						continue;
					}
					if ($this->dummy_args[$i] != $this->exec_args[$i]) {
						$aux_args[$i] = $this->tmp_args[$i];
						continue;
					}
				}
				if (!$this->callback_args_is_object[$i]) {
					if (is_object($this->tmp_args[$i])) {
						$aux_args[$i] = $this->tmp_args[$i];
						continue;
					}
					if ($this->dummy_args[$i] !== $this->exec_args[$i]) {
						$aux_args[$i] = $this->exec_args[$i];
						continue;
					}
				}
			}
			return serialize(array('out' => $this->out, 'aux' => $aux_args));
		}

		public function run()
		{
			global $output_handler_indicator;
			if (!is_callable($this->callback))
				return false;
			$this->set_handler();

			$this->output_pipe = stream_socket_pair(
				STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
			$this->status 	   = 'executing';
			$pid 		  	   = pcntl_fork();


			// If this is the child, then do the following
			// - execute the routine
			// - signal its completion and sends handler ID
			// - wait for handler to acknowledge
			// - check which variables should be returned
			// - send those variables back
			// - wait for handler to acknowledge
			// - terminate
			if ($pid == 0) {
				$this->cpid = 0;
				$this->out = call_user_func_array($this->callback, $this->exec_args);
				$count = 0;
				$buf = array($this->call_id . "\n");
				while ( ($read = fgets($output_handler_indicator[$this->pid][1])) !== false) {
					if ($read != ($this->magic_end . "\n"))
						$buf[] = $read;
				}
				$buf[] = $this->magic_end . "\n";
				fwrite($output_handler_indicator[$this->pid][0], implode("", $buf));
				$out = $this->make_output();
				$len = strlen($out);
				srand(time());
				$sig = (rand(1, 2) == 1) ? SIGUSR1 : SIGUSR2;
				posix_kill(posix_getppid(), $sig);
				usleep(100000);
				$this->debug(1, "Writing output of size $len back to parent");
				fwrite($this->output_pipe[0], $out . "\n");
				fwrite($this->output_pipe[0], $this->magic_end . "\n");
				$this->debug(1, "End writing, quiting");
				fclose($output_handler_indicator[$this->pid][0]);
				fclose($output_handler_indicator[$this->pid][1]);
				fclose($this->output_pipe[0]);
				fclose($this->output_pipe[1]);
				exit;
			}
			$this->cpid = intval($pid);
			return true;
		}

		public function get_status()
		{
			return $this->status;
		}

		public function get_input()
		{
			return $this->dummy_args;
		}

		public function set_debug_level($level = 0)
		{
			$this->debug_level = intval($level);
		}

		public function get_pid()
		{
			return $this->cpid;
		}

		public function get_call_id()
		{
			return $this->call_id;
		}

		private function debug($level, $msg)
		{
			if ($level <= $this->debug_level)
				fwrite(STDERR, $msg . "\n");
		}
	}
?>
