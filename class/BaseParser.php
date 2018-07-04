<?php
	require_once("miner_helper.php");
	require_once("Loader.php");
	require_once("url_to_absolute.php");

	class BaseParser
	{
		const QUIET 	= 0;
		const CRITICAL 	= 1;
		const DETAIL 	= 2;
		const DEBUG1 	= 6;
		const DEBUG2 	= 7;
		const DEBUG3 	= 8;

		protected $db;
		protected $die_on_err;
		protected $print_err;
		public $errno;		
		public $error;
		public $is_error;
		protected $loader;
		protected $db_name;
		protected $log_level					= 0;
		protected $logdir 						= "/var/log/crawler";
		protected $global;
		protected $downloaders;
		protected $id_domain;
		protected $domain;
		protected $template;
		protected $spider;
		protected $limit_wait;
		protected $parser_errors;
		protected $dltimeout;
		protected $parallel;
		protected $download_info;
		private   $children;
		private	  $max_reparse 					= 4;
		private	  $max_recrawl 					= 10;
		protected $processing_validity_duration = null;
		protected $processing_duration 			= null;
		protected $priority 					= null;
		protected $download_freq 				= null;
		protected $occupation_duration 			= null;
		private	  $priority_level				= array(
			'highest' 	=> 1000,
			'high'		=> 2000,
			'medium'	=> 3000,
			'low'		=> 4000,
			'lowest'	=> 5000,
		);

		public function __construct($global = null)
		{			
			$this->loader 	   	 = new Loader();
			$this->downloaders 	 = array();
			$this->parser_errors = array();
			$this->set_global($global);
			$this->set_domain();
		}

		public function set_custom_domain($domain)
		{
			if (!isset($domain)) {
				$this->log("domain not given for setting", LOG_CRITICAL, TRUE);
				return FALSE;
			}
			if (!is_string($domain)) {
				$this->log("domain must be a string", LOG_CRITICAL, TRUE);
				return FALSE;
			}
			if ($domain == '') {
				$this->log("domain must not be empty", LOG_CRITICAL, TRUE);
				return FALSE;
			}
			$this->domain = $domain;
			return TRUE;
		}

		// to be defined by extender
		protected function set_domain()
		{
			$this->domain 	  = null;
			$this->template   = __CLASS__;
		}

		public function set_log_root($log_root = null)
		{
			if (!is_string($log_root)) {
				$this->log('incorrect specification of log root', LOG_CRITICAL, TRUE);
				return FALSE;
			}
			$this->logdir = $log_root;
			return TRUE;
		}
	
		// to be defined by extender
		public function seed()
		{

		}

		public function set_global($global)
		{
			$this->global = $global;
			if (isset($global['download-log']))
				$this->logdir = $global['download-log'];
			return true;
		}

		public function parse($data, $download_info = null)
		{
			// check for rate limits and dead links
			$dead_found = false;
			if (!isset($data['result'])) {
				$this->log("link $data[id_link] was not downloaded by $download_info[address]", self::CRITICAL);
				return array(
					'status' => 'undownloaded',
					'parsed' => null,
				);
			}
			if (!isset($data["id_link"])) {
				$this->log("no link specified for parsing", self::CRITICAL);
				return false;
			}
			if (!isset($data['link_type'])) {
				$this->log("no link type specified for parsing", self::CRITICAL);
				return false;
			}
			$link_type = $data['link_type'];
			$id_link = $data["id_link"];
			if (preg_match("/^[0-9a-z]+$/", $id_link) !== 1) {
				$this->log("wrong link ID specified for parsing", self::CRITICAL);
				return false;
			}
			if (isset($download_info))
				$this->download_info = $download_info;
			if ($this->is_rate_limited($data)) {
				$this->log("link ${id_link} is rate limited for downloader $download_info[address]", self::CRITICAL);
				return array(
					'status' => 'rate-limited',
					'parsed' => null,
				);
			}
			if ($this->is_dead($data)) {
				$this->log("link ${id_link} is dead", self::DETAIL);
				return array(
					'status' => 'dead',
					'parsed' => null,
				);
			}
			if (!method_exists($this, "parse_$link_type")) {
				$this->log("domain {$this->domain} does not have method parse_$link_type", LOG_CRITICAL, TRUE);
				return array(
					'status' => 'no-parser',
					'parsed' => null,
				);
			}
			$this->children = array();
			$parsed = $this->{"parse_$link_type"}($data);
			$domain	= $this->domain;
			if ($parsed === FALSE) {
				$this->log("cannot parse data for link ${id_link} ($domain) crawled by $download_info[address]", self::CRITICAL);
				$log_name = $this->log_invalid($data, $download_info);
				$this->log("finished invalid path for link ${id_link} ($domain)", self::CRITICAL);
				return array(
					'status' => 'failed',
					'log'	 => $log_name,
					'parsed' => null,
				);
			}
			return array(
				'status'   => 'ok',
				'parsed'   => $parsed,
				'children' => $this->children,
			);
		}

		private function parse_($data)
		{
			return isset($data['result']) ? $data['result'] : null;
		}

		private function check_valid_url($url)
		{
			if (preg_match("/^(\{|\[)/", $url) !== 1) 
				$url = get_url($url);
			else {
				$parsed = json_decode($url, TRUE);
				$url = $parsed['url'];
			}
			if (!isset($url))
				return false;
			if (filter_var($url, FILTER_VALIDATE_URL) === FALSE)
				return false;
			return true;
		}

		public function get_cache($map)
		{
			if (!is_array($map))
				return $map;
			if (isset($this->global['cache'])) {
				$cache = get_redis_conn(@$this->global['cache']['host'], @$this->global['cache']['port'],
					ifnull(@$this->global['connect-timeout'], 1), $err);
				if (isset($err))
					$this->log($err, LOG_CRITICAL, TRUE);
			}
			
			// set everything to be updated to cache
			$updates = array();
			$search	 = array();
			foreach ($map as $key => $val) {
				if (!is_string($key))
					continue;
				$keys[] = $key;
				if (is_string($val))
					$updates[$key] = $val; //those to be updated
				else
					$search[$key] = $key; //those to be searched for values
			}
			if (!isset($keys))
				return $map;
			if (!isset($cache)) {
				$this->log("cannot communicate with cache", LOG_CRITICAL, TRUE);
				return $map;
			}

			// lookup in cache to find recent cache values
			// and unset those that have no conflicts
			$res = safecall($cache, "mGet", array($keys), $err, 3);
			if (isset($err)) {
				$this->log($err, LOG_CRITICAL, TRUE);
				$res = array();
			}
			if (is_array($res))
				foreach ($res as $i => $row) {
					// if we don't have value and a value is found, do no further
					if ((!is_string($map[$keys[$i]])) && $row !== FALSE) {
						$map[$keys[$i]] = $row;
						unset($search[$keys[$i]]);
					}
					// if we have a value, and there is no conflict, do no further
					if (is_string($map[$keys[$i]]) && $row !== FALSE)
						if ($map[$keys[$i]] == $row)
							unset($updates[$keys[$i]]);
				}


			// if cache mismatches are found, then update them to main cache
			if (count($updates) > 0) {
				$res = false;
				$count = 0;
				while ((!$res) && $count++ < 3) {
					$res = safecall($cache, "mSet", array($updates), $err, 3);
					if (isset($err))
						$this->log($err, LOG_CRITICAL, TRUE);
				}
			}
			if (count($search) > 0) {
				$res = safecall($cache, "mGet", array($search), $err, 3);
				if (isset($err))
					$this->log($err, LOG_CRITICAL, TRUE);
				foreach ($res as $i => $row)
					if ($row !== FALSE) {
						$map[$search[$i]] = $row;
						$updates[$search[$i]] = $row;
					}
			}
			return $map;
		}

		public function render($url, $data, $mode = 'simple', $timeout = 200, $size = NULL)
		{
			$timeout = max(200, intval($timeout));
			
			if (!in_array($mode, array('effortless', 'simple', 'striving', 'perfect')))
				return $data;
		
			switch ($mode) {
				case 'perfect':
					$args = "--headless --disable-gpu --renderer --dump-dom";
					if (is_array($size))
						$args .= " --window-size=\"$size[width],$size[height]\"";
					exec("google-chrome $args \"$url\" 2>/dev/null", $out);
					break;
				case 'effortless':
				case 'simple':
				case 'striving':
					if (!file_exists("misc/render/render.js")) {
						$this->log("render engine does not exist, use raw", LOG_CRITICAL, TRUE);
						return $data;
					}
					$file = md5($url);
					file_put_contents("misc/render/tmp/$file.html", $data);
					if (!file_exists("misc/render/tmp/$file.html")) {
						$this->log("cannot access render engine directory", LOG_CRITICAL, TRUE);
						return $data;
					}
					$timeout = intval($timeout);
					exec("phantomjs misc/render/render.js \"$url\" $file.html " . $this->domain ." $mode $timeout", $out);
					unlink("misc/render/tmp/$file.html");
					break;
			}			
			if (is_array($out))
				if (count($out) > 0)
					return implode("\n", $out);
			return $data;
		}

		public function check_url($new_url, $referer, $is_listing = FALSE, $is_image = FALSE)
		{
			if (!isset($new_url))
				return null;
			$new_url = url_to_absolute(encode_url($referer), encode_url($new_url));
			$dom 	 = get_domain($new_url);			
			if ($dom === FALSE) {
				$this->log("url ${new_url} is wrong from $referer", LOG_DETAIL, TRUE);
				return null;
			}
			if (!$is_image)
				if (strcmp($dom, $this->domain) != 0) {
					$this->log("domain $dom is wrong", LOG_DETAIL, false);
					return null;
				}
			return $new_url;
		}

		public function remove_params($new_url, $exclude = null, $include = null)
		{
			if (!is_string($new_url))
				return $new_url;
			if (!is_array($exclude))
				$exclude = array();
			$parsed = parse_url($new_url);
			if (is_string(@$parsed["query"])) {
				parse_str($parsed["query"], $queries);
				if (is_array($queries)) {
					foreach ($queries as $key => $val) {
						if (is_array($include)) {
							if (!in_array($key, $include))
								$filtered[] = "$key=$val";
							continue;
						}
						if (in_array($key, $exclude))
							$filtered[] = "$key=$val";
					}
					return encode_url(preg_replace("/\?([^#]+)/", isset($filtered) ? ("?" . implode("&", $filtered)) : "", $new_url));
				}
			}
			return $new_url;
		}
		
		// Add link found while parsing other links
		// and possibly refreshing the list of links
		// referred to by the parent(s)
		public function add_link($links)
		{
			if (!isset($this->children))
				$this->children = array();
		
			foreach ($links as $i => $link) {
				if (!isset($link['url'])) {
					$this->log("no url specified", self::DEBUG1, true);
					continue;
				}
				if (!$this->check_valid_url($link['url'])) {
					$this->log("Corrupted URL for link $link[parent]: $link[url]", self::CRITICAL, TRUE);
					continue;
				}
				if (strlen($links[$i]['url']) > 290)
					$this->log("WARNING: URL length for '" . $links[$i]['url'] 
						. "' exceededs max(290), using URL extension now, but links might be overwritten",
						self::CRITICAL, FALSE);
				$url = substr($links[$i]['url'], 0, 290);
				$id_link = sha1($url);
				$this->children[$id_link]['id_link']   			= $id_link;
				$this->children[$id_link]['domain'] 			= $this->domain;
				$this->children[$id_link]['url'] 				= $url;
				$this->children[$id_link]['url_ext']  			= ((strlen($links[$i]['url']) > 290)
					? substr($links[$i]['url'], 290) : null);
				$this->children[$id_link]['link_type'] 	 		= isset($links[$i]['link_type'])
					? $links[$i]['link_type'] : '';

				if (!isset($this->domain) && isset($link['domain']))
					$this->children[$id_link]['domain'] = $link['domain'];

				// set download frequency
				if (isset($links[$i]['download_freq']))
					$this->children[$id_link]['download_freq'] = $links[$i]['download_freq'];
				elseif (isset($this->download_freq))
					$this->children[$id_link]['download_freq'] = $this->download_freq;
				elseif (isset($this->global['download-freq']))
					$this->children[$id_link]['download_freq'] = $this->global['download-freq'];
				else
					$this->children[$id_link]['download_freq'] = null;

				// set processing validity duration
				if (isset($links[$i]['processing_validity_duration']))
					$this->children[$id_link]['processing_validity_duration'] = $links[$i]['processing_validity_duration'];
				elseif (isset($this->processing_validity_duration))
					$this->children[$id_link]['processing_validity_duration'] = $this->processing_validity_duration;
				elseif (isset($this->global['processing-validity-duration']))
					$this->children[$id_link]['processing_validity_duration'] = $this->global['processing-validity-duration'];
				else
					$this->children[$id_link]['processing_validity_duration'] = null;
				
				// set processing duration		
				if (isset($links[$i]['processing_duration']))
					$this->children[$id_link]['processing_duration'] = $links[$i]['processing_duration'];
				elseif (isset($this->processing_duration))
					$this->children[$id_link]['processing_duration'] = $this->processing_duration;
				elseif (isset($this->global['processing-duration']))
					$this->children[$id_link]['processing_duration'] = $this->global['processing-duration'];
				else
					$this->children[$id_link]['processing_duration'] = null;

				// set next download time
				$this->children[$id_link]['next_download_at'] = 
					is_numeric(@$links[$i]['next_download_at']) ? $links[$i]['next_download_at'] : null;

				// set notes
				$this->children[$id_link]['notes'] = isset($links[$i]['notes']) ? $links[$i]['notes'] : null;

				// set link group
				$this->children[$id_link]['link_group'] = 
					is_string(@$links[$i]['link_group']) ? $links[$i]['link_group'] : null;

				// set priority
				if (isset($links[$i]['priority'])) {
					if (isset($this->priority_level[$links[$i]['priority']]))
						$this->children[$id_link]['priority'] = $this->priority_level[$links[$i]['priority']];
					elseif (in_array($links[$i]['priority'], $this->priority_level))
						$this->children[$id_link]['priority'] = $links[$i]['priority'];
					else
						$this->children[$id_link]['priority'] = $this->priority_level['medium'];
				} elseif (isset($this->priority))
					$this->children[$id_link]['priority'] = $this->priority;
				else
					$this->children[$id_link]['priority'] = $this->priority_level['medium'];

				// set occupation duration
				if (isset($links[$i]['occupation_duration']))
					$this->children[$id_link]['occupation_duration'] = $links[$i]['occupation_duration'];
				elseif (isset($this->occupation_duration))
					$this->children[$id_link]['occupation_duration'] = $this->occupation_duration;
				elseif (isset($this->global['occupation-duration']))
					$this->children[$id_link]['occupation_duration'] = $this->global['occupation-duration'];
				else
					$this->children[$id_link]['occupation_duration'] = null;

				$this->children[$id_link]['position'] = @ifnull($links[$i]['position'], 0);
			}
		}
		
		public function get_errors()
		{
			return $this->parser_errors;
		}
		
		public function get_children()
		{
			return $this->children;
		}
		
		public function clear_link()
		{
			$this->children = array();
		}
				
		// Check if data is corrupted due to site rate limits
		// and update worker availability accordingly
		public function is_rate_limited($data)
		{
			return false;
		}
		
		public function is_dead($data)
		{
			return false;
		}
		
		// Log unparsable data for later examination
		public function log_invalid($data, $download_info)
		{
			$this->log("logging invalid data for link $data[id_link]", self::CRITICAL);			
			$name = rand_string(10);
			$this->parser_errors['download_info'] = $download_info;
			file_put_contents($this->global["failures-log"] . "/${name}.html", $data['result']);			
			file_put_contents($this->global["failures-log"] . "/${name}.json", json_encode($this->parser_errors));
			return $name;
		}
				
		public function set_log_level($lvl)
		{
			$this->log_level = intval($lvl);
		}
		
		public function get_id_domain()
		{
			return $this->id_domain;
		}
		
		public function get_domain()
		{
			return $this->domain;
		}

		protected function log($msg, $level, $is_error = false)
		{
			if (($this->log_level < $level) && (!$is_error))
				return;
			$this->logmsg = $msg;
			$trace = debug_backtrace();
			$func = isset($trace[1]["function"]) ? $trace[1]["function"] : "";
			$class = isset($trace[1]["class"]) ? $trace[1]["class"] : "";
			if ($is_error) {
				$msg = "[$class] ${func}: Error: $msg\n";
				fwrite(STDERR, $msg);
			} else {
				$msg = "[$class] ${func}: $msg\n";
				echo "$msg";
			}

			if (!isset($this->logdir))		
				return;			
			if (!is_dir($this->logdir))		
				return;
			$logdir = $this->logdir;
			if (filesize("${logdir}/log") > 1000000) {
				$tmp = "tmp." . rand_string(10);
				exec("cat ${logdir}/log > ${logdir}/$tmp");
				if (filesize("${logdir}/$tmp") > 1000000) {
					for ($i = 9; $i > 0; $i--)
						if (file_exists("${logdir}/log.$i")) {
							$output = array();
							exec("cat ${logdir}/log.$i > ${logdir}/log." . ($i + 1), $output);  
							if (count($output) > 0) {
								fwrite(STDERR, "Cannot access logging mechanism. Stopping.\n");
								exit();
							}
						}
					exec("cat ${logdir}/$tmp > ${logdir}/log.1");
				}
				exec("rm ${logdir}/$tmp");
				file_put_contents("${logdir}/log", "");				
			}			
			file_put_contents("${logdir}/log", "[" . date("Y-m-d H:i:s", time()) . "] " 
				. "$msg", FILE_APPEND);
		}		
	}
?>