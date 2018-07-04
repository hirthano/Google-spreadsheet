<?php
require_once(dirname(__FILE__) . "/Loader.php");
define('LOG_QUIET', 0);
define('LOG_CRITICAL', 1);
define('LOG_DETAIL', 2);
define('LOG_DEBUG0', 5);
define('LOG_DEBUG1', 6);
define('LOG_DEBUG2', 7);
define('LOG_DEBUG3', 8);

//get fractional time presentation of microtime(TRUE)z
function get_frac_time($microtime, $micro = FALSE)
{
	if (!isset($microtime))
		return null;
	if (!is_numeric($microtime))
		return null;
	return date('Y-m-d H:i:s.', floor($microtime))
		. sprintf("%0" . ($micro ? 6 : 3) . "d", ($microtime * ($micro ? 1000000 : 1000) % ($micro ? 1000000 : 1000)));
}

function get_domain($url)
{
	$parsed = parse_url($url);
	if (!isset($parsed['host']))
		return false;
	$parsed = $parsed['host'];
	$parsed = explode(".", $parsed);
	if (!isset($parsed[1])) {
		mlog("no valid domain found for $url", LOG_CRITICAL, TRUE);
		return false;
	}
	$domain = $parsed[count($parsed) - 2] . "." . $parsed[count($parsed) - 1];
	if (in_array($parsed[count($parsed) - 2], array("net", "com", "org", "co", "ac", "edu", "gov", "mil", "int"))) {
		if (!isset($parsed[2])) {
			mlog("no valid domain found for $url", LOG_CRITICAL, TRUE);
			return false;
		}
		$domain = $parsed[count($parsed) - 3] . "." . $domain;
	}
	return $domain;
}

function print_table($data, $headers = true)
{
	if (!is_array($data))
		return;

	$data = array_values($data);
	if (!isset($data[0]))
		return;
	if (!is_array($data[0]))
		return;
	require_once 'Console/Table.php';
	$table = new Console_Table();
	if ($headers) {
		foreach ($data[0] as $key => $value)
			$heads[] = $key;
		$table->setHeaders($heads);
	}

	$size = array();
	foreach ($data as $row)
		$table->addRow($row);
	return $table->getTable();
}


// check and process any missed signals from Async class
function poll()
{
	global $async_poll_routine;
	usleep(50000);
	if (!class_exists("Async"))
		return;
	if (!isset($async_poll_routine))
		$async_poll_routine = new Async();
	$async_poll_routine->poll();
}

function logmsg($msg, $is_error = true, $commit = true)
{
	global $_POST;
	global $_GET;
	global $log;

	if (!isset($log))
		$log = array();
	if (count($_POST) + count($_GET) == 0) {
		if (is_array($msg))
			$msg = print_r($msg, true);
		if ($is_error)
			fwrite(STDERR, $msg . "\n");
		else
			fwrite(STDOUT, $msg . "\n");
	} else {
		if (is_array($msg))
			$log = array_merge($log, $msg);
		else {
			if ($is_error)
				$log["error"][] = $msg;
			else
				$log["status"][] = $msg;
		}
		if ($commit) {
			if (isset($log["error"]))
				if ((is_array($log["error"])) && (count($log["error"]) == 1))
					$log["error"] = $log["error"][0];
			if (isset($log["status"]))
				if ((is_array($log["status"])) && (count($log["status"]) == 1))
					$log["status"] = $log["status"][0];
			print json_encode($log);
		}
	}
}

function mlog($msg, $level, $is_error = false, $log_root = null, $trace_level = 1)
{
	global $logmsg;
	global $log_level;
	global $global;

	if (($log_level < $level) && (!$is_error))
		return;
	$logmsg = $msg;
	$trace = debug_backtrace();
	$func = isset($trace[$trace_level]["function"]) ? $trace[$trace_level]["function"] . ":" . $trace[$trace_level - 1]["line"] . ": " : "";
	$class = isset($trace[$trace_level]["class"]) ? "[" . $trace[$trace_level]["class"] . "] ": "";
	if ($is_error) {
		$msg = "${class}${func}Error: $msg\n";
		fwrite(STDERR, $msg);
	} else {
		$msg = "${class}${func}$msg\n";
		echo "$msg";
	}
	$logdir = null;
	if (!isset($log_root)) {
		if (isset($global["log-root"]))
			$logdir = $global["log-root"];
	} elseif (!is_dir($log_root))	{
		if (isset($global["log-root"]))
			$logdir = $global["log-root"];
		else
			$logdir = $log_root;
	} else
		$logdir = $log_root;
	if (!isset($logdir))
		return;
	if (!is_dir($logdir)) {
		if (!@mkdir($logdir, 0700, true))
			return;
	}
	if (!is_dir($logdir))
		return;
	if (!file_exists("${logdir}/log"))
		file_put_contents("${logdir}/log", "", FILE_APPEND);
	if (filesize("${logdir}/log") > 1000000) {
		$tmp = "tmp." . rand_string(10);
		exec("cat ${logdir}/log > ${logdir}/$tmp");
		if (filesize("${logdir}/$tmp") > 1000000) {
			for ($i = 9; $i > 0; $i--)
				if (file_exists("${logdir}/log.$i")) {
					$output = array();
					exec("cat ${logdir}/log.$i > ${logdir}/log." . ($i + 1), $output);
					if (count($output) > 0) {
						fwrite(stderr, "Cannot access logging mechanism. Stopping.\n");
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

function sleeper($secs)
{
	$secs = intval($secs);
	$time = time();
	while (time() - $time < $secs) {
		usleep(250000);
		usleep(250000);
		usleep(250000);
		usleep(250000);
	}
}

function get_url($base, $params = null)
{
    if (strncmp("https://", $base, 8) == 0)
        goto add_params;
    if (strncmp("http://", $base, 7) == 0)
        goto add_params;
    $base = "http://" . $base;

add_params:
	$base = preg_replace("/\?+$/", "", $base);
    if (is_array($params))
    	$params = http_build_query($params);
    if (strlen($params) > 0)
	    return $base . "?" . $params;
	return $base;
}

function fetch_url($config, &$error = null, &$redirected_url = NULL)
{
	if (isset($config['type'])) {
		if (!in_array($config['type'], array('GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS', 'HEAD')))
			$config['type'] = 'GET';
	} else
		$config['type'] = 'GET';
	$fetch_url_loader = new Loader();
	if (isset($config['cookie_file']))
		$fetch_url_loader->set_cookiefile($config['cookie_file']);
	if (isset($config['ignore_cert']))
		$fetch_url_loader->set_ignore_cert();
	if (isset($config['timeout']))
		$fetch_url_loader->set_timeout(intval($config['timeout']) + 1);
	if (isset($config['conn_timeout']))
		$fetch_url_loader->set_conn_timeout(intval($config['conn_timeout']) + 1);
	if (isset($config['iface']))
		$fetch_url_loader->set_interface($config['iface']);
	if (isset($config['cookie']))
		$fetch_url_loader->set_cookie($config['cookie']);
	if (isset($config['agent']))
		$fetch_url_loader->set_agent($config['agent']);
	if (isset($config['use_cli']))
		$fetch_url_loader->set_use_cli($config['use_cli']);
	else
		$fetch_url_loader->set_use_cli(FALSE);
	if (!isset($config['params'])) {
		$config['params'] = '';
	}
	if (isset($config['headers']))
		$fetch_url_loader->set_headers($config['headers']);
	if (isset($config['proxy']))
		$fetch_url_loader->set_proxy($config['proxy']);
	elseif (isset($config['socks']))
		$fetch_url_loader->set_socks($config['socks']);
	if ($config['type'] == 'GET') {
		$config['base'] = get_url($config['base'], $config['params']);
		$config['params'] = null;
	}
	$fetch_url_loader->http($config['base'], $config['type'], $config['params']);
	$redirected_url = $fetch_url_loader->get_redirected_url();
	if ($fetch_url_loader->errno() != 0) {
		$error = $fetch_url_loader->http_data;
		return false;
	}
	return $fetch_url_loader->http_data;
}

function ifnull($val1, $val2)
{
	if (!isset($val1))
		return $val2;
	if (strcmp($val1, "") == 0)
		return $val2;
	return $val1;
}

function get_csv($file, $sep = ",", $enc = "\"", $header = true, $limit = 10000000000)
{
	if (($fo = fopen($file, "r")) === FALSE) {
		fprintf(STDERR, "Cannot open CSV file $file for reading.\n");
		return null;
	}

	$ret = array();
	if ($header) {
		if (($buf = fgetcsv($fo, 0, $sep, $enc)) === FALSE) {
			fclose($fo);
			return array();
		}
		$fields = $buf;
		$field_count = count($fields);
	}

	$count = 0;
	while (($buf = fgetcsv($fo, 0, $sep, $enc)) !== FALSE) {
		$count++;
		if (!$header)
			$ret[] = $buf;
		else {
			$row = array();
			if (count($buf) < $field_count)
				print_r($buf);
			for ($i = 0; $i < $field_count; $i++)
				$row[$fields[$i]] = $buf[$i];
			$ret[] = $row;
		}
		if ($count == $limit)
			break;
	}
	fclose($fo);
	return $ret;
}

function write_csv($file, $data, $sep = ",", $enc = "\"", $header = true, $append = false, $limit = 1000000000)
{
	if (!is_array($data)) {
		fprintf(STDERR, "Data must be two-dimensional array\n");
		return false;
	}

	foreach ($data as $row) {
		if (!is_array($row)) {
			fprintf(STDERR, "Data must be two-dimensional array\n");
			return false;
		}
	}

	foreach ($data as $row) {
		foreach ($row as $key => $val)
			$head[] = $key;
		break;
	}

	if (!$append) {
		$fp = fopen($file, 'w');
		if ($header)
			fputcsv($fp, $head);
	} else
		$fp = fopen($file, 'a');
	foreach ($data as $row) {
		$row = array_values($row);
		fputcsv($fp, $row);
	}
	fclose($fp);
	return true;
}

function get_direct_distance($lat1, $lon1, $lat2, $lon2, $unit = "K")
{
	$theta = $lon1 - $lon2;
	$dist  = sin(deg2rad($lat1)) * sin(deg2rad($lat2))
		+ cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
	$dist  = acos($dist);
	$dist  = rad2deg($dist);
	$miles = $dist * 60 * 1.1515;
	$unit  = strtoupper($unit);

	if ($unit == "K")
		return ($miles * 1.609344);
	else if ($unit == "N")
		return ($miles * 0.8684);
	return $miles;
}

function get_google_gps($address, $key = null, $all_result = false, $addr_components = null)
{
	if (!isset($address))
		return array('addr' => null, 'lat' => null, 'lng' => null);
	if ($address == "")
		return array('addr' => '', 'lat' => null, 'lng' => null);
	$url = "https://maps.googleapis.com/maps/api/geocode/json?";
	$url .= "address=" . urlencode($address);
	if (isset($key))
		$url .= "&key=" . urlencode($key);
	$conn = curl_init();
    curl_setopt($conn, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($conn, CURLOPT_HEADER, 0);
    curl_setopt($conn, CURLOPT_URL, $url);
	$res 	= curl_exec($conn);
	$parsed = json_decode($res, true);
	if (!isset($parsed["status"])) {
		fprintf(STDERR, "Cannot connect to Google Geocode API.\n");
		fprintf(STDERR, "URL = $url\n");
		return array('lat' => null, 'lng' => null);
	}

	if ($parsed["status"] != "OK") {
		fprintf(STDERR, "Failed fetching geocode: " . $parsed["status"] . "\n");
		fprintf(STDERR, "URL = $url\n");
		return array('lat' => null, 'lng' => null);
	}

	if (count($parsed["results"]) == 0)
		return array('addr' => null, 'lat' => null, 'lng' => null);

	if ($all_result)
		return $parsed["results"];
	$res = $parsed["results"][0];
	if (is_array($addr_components))
		foreach ($addr_components as $locality)
			if (strpos(to_alpha_numeric_no_space($res["formatted_address"]),
					to_alpha_numeric_no_space($locality)) === FALSE)
			{
				fprintf(STDERR, "Parsed address does not meet requirement: "
					. $res["formatted_address"] . "\n");
				fprintf(STDERR, "Must match the following: ["
					. implode("], [", $addr_components) . "]\n");
				return array('addr' => null, 'lat' => null, 'lng' => null);
			}

	return array(
		'addr' => $res["formatted_address"],
		'lat'  => $res["geometry"]["location"]["lat"],
		'lng'  => $res["geometry"]["location"]["lng"],
	);
}

function get_google_distance($origin, $destination, $key = null, $addr_components = null)
{
	if ((!isset($origin)) || (!isset($destination)))
		return null;
	if (($origin == "") || ($destination == ""))
		return null;
	$url = "https://maps.googleapis.com/maps/api/distancematrix/json?";
	$url .= "origins=" . urlencode($origin);
	$url .= "&destinations=" . urlencode($destination);
	if (isset($key))
		$url .= "&key=" . urlencode($key);
	$conn = curl_init();
    curl_setopt($conn, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($conn, CURLOPT_HEADER, 0);
    curl_setopt($conn, CURLOPT_URL, $url);
	$res 	= curl_exec($conn);
	$parsed = json_decode($res, true);
	if (!isset($parsed["status"])) {
		fprintf(STDERR, "Cannot connecto Google DistanceMatrix API.\n");
		return null;
	}
	if ($parsed["status"] != "OK") {
		fprintf(STDERR, "Failed getting distance: " . $parsed["status"] . "\n");
		fprintf(STDERR, "URL = $url\n");
		fprintf(STDERR, "$res\n");
		return null;
	}

	$dest   = to_alpha_numeric_no_space($parsed["destination_addresses"][0]);
	$origin = to_alpha_numeric_no_space($parsed["origin_addresses"][0]);
	if (is_array($addr_components)) {
		if (isset($addr_components["dest"]))
			if (is_array($addr_components["dest"]))
				foreach ($addr_components["dest"] as $locality)
					if (strpos($dest, to_alpha_numeric_no_space($locality)) === FALSE) {
						fprintf(STDERR, "Parsed destination does not meet requirement: "
							. $parsed["destination_addresses"][0] . "\n");
						fprintf(STDERR, "Must match the following: ["
							. implode("], [", $addr_components["dest"]) . "]\n");
						return null;
					}
		if (isset($addr_components["origin"]))
			if (is_array($addr_components["origin"]))
				foreach ($addr_components["origin"] as $locality)
					if (strpos($origin, to_alpha_numeric_no_space($locality)) === FALSE) {
						fprintf(STDERR, "Parsed origin does not meet requirement: "
							. $parsed["origin_addresses"][0] . "\n");
						fprintf(STDERR, "Must match the following: ["
							. implode("], [", $addr_components["origin"]) . "]\n");
						return null;
					}
	}
	return $parsed["rows"][0]["elements"][0]["distance"]["value"];
}

function mobile_no_check($phone_no)
{
	$phone_no = str_replace("(0)", "", $phone_no);
	$phone_no = preg_replace("/[^0-9]/", "", $phone_no);
	if (strcmp(substr($phone_no, 0, 2), "00") == 0) {
		if (preg_match("/[^0-9]/", substr($phone_no, 2)) == 1)
			return "Failed";
		if (strcmp(substr($phone_no, 0, 3), "001") == 0)
			$phone_no = substr($phone_no, 2);
		else if (strcmp(substr($phone_no, 0, 3), "009") == 0)
			$phone_no = substr($phone_no, 2);
		else if (strcmp(substr($phone_no, 0, 4), "0088") == 0)
			$phone_no = substr($phone_no, 2);
		else if (strcmp(substr($phone_no, 0, 4), "0084") == 0)
			$phone_no = preg_replace("/^0+/", "", substr($phone_no, 4));
		else
			return "Failed";
	} else if (strcmp(substr($phone_no, 0, 1), "0") == 0) {
		if (strcmp(substr($phone_no, 0, 3), "084") == 0)
			$phone_no = preg_replace("/^0+/", "", substr($phone_no, 3));
		else
			$phone_no = substr($phone_no, 1);
	}
next_phase:
	if (strcmp(substr($phone_no, 0, 1), "1") == 0) {
		if (strlen($phone_no) < 10)
			return "Failed";
		$phone_no = substr($phone_no, 0, 10);
	} else if (strcmp(substr($phone_no, 0, 1), "9") == 0) {
		if (strlen($phone_no) < 9)
			return "Failed";
		$phone_no = substr($phone_no, 0, 9);
	} else if (preg_match("/^8[0-357-9]/", $phone_no) === 1) {
		if (strlen($phone_no) < 9)
			return "Failed";
		$phone_no = substr($phone_no, 0, 9);
	} else if (strcmp(substr($phone_no, 0, 2), "86") == 0) {
		if (strlen($phone_no) == 11) // if this is Chinese's number starting with 86 instead of Vietnamese 86
			return "Failed";
		if (strlen($phone_no) < 9)
			return "Failed";
		$phone_no = substr($phone_no, 0, 9);
	} else if (strcmp(substr($phone_no, 0, 2), "84") == 0) {
		$phone_no = preg_replace("/^0+/", "", substr($phone_no, 2));
		goto next_phase;
	}
	else
		return "Failed";

	return "0$phone_no";
}

function get_args($args)
{
	$short_args	= '';
	$long_args	= array();
	foreach ($args as $arg => $take_value) {
		$short_args		.= substr($arg, 0, 1) . ($take_value ? ":" : "");
		$long_args[]	= $arg;
	}
	return getopt($short_args, $long_args);
}

function is_cron($timestamp, $cron_def)
{
    $slots = explode(" ", strtolower($cron_def));
    if (!isset($slots[4])) {
        fprintf(STDERR, "Wrong CRON definition: $cron_def.\n");
        return -1;
    }
    $now       = explode("-", date("i-H-j-n-w", $timestamp));
    $range_min = array(
        0,
        0,
        1,
        1,
        0
    );
    $range_max = array(
        59,
        23,
        31,
        12,
        6
    );

    $slot_count = 0;
    foreach ($slots as $slot) {
    	$now_tmp = intval($now[$slot_count]);
        $confs = explode(",", $slot);
        $check  = false;
        foreach ($confs as $conf) {
        	$split = explode("/", $conf);
        	$step = 1;
        	if (isset($split[1]))
        		$step = intval($split[1]);
			if ($step == 0)
				$step = 1;
			$range = explode("-", $split[0]);
			if ($range[0] == "*") {
				$start = $range_min[$slot_count];
				$end   = $range_max[$slot_count];
			} else {
				$start = intval($range[0]);
				$end   = (isset($range[1]) ? intval($range[1]) : $start);
			}
			if ($now_tmp >= $start && $now_tmp <= $end
				&& ($now_tmp - $start) % $step == 0)
			{
				$check = true;
				break;
			}
        }
        if (!$check)
	        return false;
        $slot_count++;
    }
    return true;
}

function get_next_cron($cron_def, $sched_count = 1)
{
	$now = time();
	$max_check = 60 * 24 * 365;
	$tmp_sched = 0;
	$check_count = 0;
	while ($check_count < $max_check && $tmp_sched < $sched_count) {
		$now += 60;
		if (is_cron($now, $cron_def)) {
			echo date("Y-m-d H:i:s", $now) . "\n";
			$tmp_sched++;
		}
		$max_check++;
	}
}

function get_longest_common_subsequence($string_1, $string_2)
{
	global $performance;
	$start = microtime(TRUE);

    $string_1_length = strlen($string_1);
    $string_2_length = strlen($string_2);
    $return          = '';

    if ($string_1_length === 0 || $string_2_length === 0) {
        // No similarities
        return $return;
    }

    $longest_common_subsequence = array();

    // Initialize the CSL array to assume there are no similarities
    $longest_common_subsequence = array_fill(0, $string_1_length, array_fill(0, $string_2_length, 0));

    $largest_size = 0;

    for ($i = 0; $i < $string_1_length; $i++) {
        for ($j = 0; $j < $string_2_length; $j++) {
            // Check every combination of characters
            if ($string_1[$i] === $string_2[$j]) {
                // These are the same in both strings
                if ($i === 0 || $j === 0) {
                    // It's the first character, so it's clearly only 1 character long
                    $longest_common_subsequence[$i][$j] = 1;
                } else {
                    // It's one character longer than the string from the previous character
                    $longest_common_subsequence[$i][$j] = $longest_common_subsequence[$i - 1][$j - 1] + 1;
                }

                if ($longest_common_subsequence[$i][$j] > $largest_size) {
                    // Remember this as the largest
                    $largest_size = $longest_common_subsequence[$i][$j];
                    // Wipe any previous results
                    $return       = '';
                    // And then fall through to remember this new value
                }

                if ($longest_common_subsequence[$i][$j] === $largest_size) {
                    // Remember the largest string(s)
                    $return = substr($string_1, $i - $largest_size + 1, $largest_size);
                }
            }
            // Else, $CSL should be set to 0, which it was already initialized to
        }
    }

    // Return the list of matches
	$trace = debug_backtrace();
	$func = isset($trace[0]["function"]) ? $trace[0]["function"] . ": " : "";
	if (!isset($performance[$func]))
		$performance[$func] = 0;
	$performance[$func] += microtime(TRUE) - $start;

    return $return;
}

function to_alpha_numeric_no_space($text)
{
	$text = to_alpha_numeric($text);
    $text = preg_replace("/[\s]+/", "", $text);
    $text = trim($text);
    return isset($text) ? $text : "";
}

function to_alpha_numeric($text)
{
    $text = html_entity_decode($text);
    $text = unicodeC($text);
    $text = preg_replace("/(<\/[^>]+?>)/", " $1 ", $text);
    $text = preg_replace("/(<[^>\/][^>]*?>)/", " $1 ", $text);
    $text = to_ascii($text);
    $text = preg_replace("/([^0-9a-z]+)/", " ", $text);
    $text = preg_replace("/[\s]+/", " ", $text);
    $text = trim($text);
    return isset($text) ? $text : "";
}

function to_vn_numeric($text)
{
    $text = html_entity_decode($text);
    $text = unicodeC($text);
    $text = preg_replace("/(<\/[^>]+?>)/", ' $1 ', $text);
    $text = preg_replace("/(<[^>\/][^>]*?>)/", ' $1 ', $text);
    $text = mb_strtolower(strip_tags($text));
    $text = preg_replace("/[^0-9a-záàảãạăắặằẳẵâấầẩẫậđéèẻẽẹêếềểễệíìỉĩịóòỏõọôốồổỗộơớờởỡợộọộúùủũụưứừửữựùýỳỷỹỵ]+/iu", " ", $text);
    $text = preg_replace("/[\s]+/iu", " ", $text);
    $text = trim($text);
    return isset($text) ? $text : "";
}

function to_std_uni_sentence($text)
{
	return unicodeC(trim(preg_replace("/[\r\n\t]+/", " ", $text)));
}

function to_text($html)
{
    $CI = get_instance();
    $CI->load->library('Html2Text', $html);
    return trim($CI->Html2Text->getText());
}

function datetime_to_secs($datetime)
{
    $secs = 0;
    foreach ($datetime as $type => $count)
        switch ($type) {
            case "second":
                $secs += $count;
                break;

            case "minute":
                $secs += $count * 60;
                break;

            case "hour":
                $secs += $count * 3600;
                break;

            case "day":
                $secs += $count * 86400;
                break;

            case "month":
                $secs += $count * 2592000;
                break;

            case "year":
                $secs += $count * 946080000;
                break;
        }
    return $secs;
}

function solr_select($path)
{
    $url = curl_init();
    curl_setopt($url, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($url, CURLOPT_HEADER, 0);
    curl_setopt($url, CURLOPT_URL, $path);
    $max_trial = 3;
    $trial     = 0;
    while ($trial < $max_trial) {
        $trial++;
        $output = curl_exec($url);
        $parsed = json_decode($output);
        if (is_object($parsed))
            if (isset($parsed->response->numFound))
                return $parsed;
    }
    if ($trial == $max_trial)
        die("Something wrong while querying SOLR.\n" . print_r($output) . "\n");
    return $parsed;
}

function solr_insert($solr_path, $data)
{
    $data_string = json_encode($data);
    $path        = $solr_path . "/update?wt=json&commit=true";
    $url         = curl_init($path);
    curl_setopt($url, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($url, CURLOPT_POST, TRUE);
    curl_setopt($url, CURLOPT_HTTPHEADER, array(
        'Content-type: application/json'
    ));
    curl_setopt($url, CURLOPT_POSTFIELDS, $data_string);
    $trial     = 0;
    $max_trial = 3;
    $start     = microtime(true);
    while ($trial < $max_trial) {
        $trial++;
        $res = json_decode(curl_exec($url));
        if (!is_object($res))
            continue;
        if (!isset($res->responseHeader->status))
            continue;
        if ($res->responseHeader->status != 0)
            continue;
        break;
    }
    $end = microtime(true) - $start;
    echo "Adding to SOLR takes $end seconds. \n\n";
    if ($trial == $max_trial) {
        echo "Cannot add/replace posts to SOLR.\n";
        print_r($res);
        exit;
    }
}

function new_db(&$conn, $db_config)
{
    $max_trial = 3;
    $trial     = 0;
    while ($trial < $max_trial) {
        $trial++;
        @$conn = new DB($db_config);
        if ($conn->connection->connect_errno == 0) {
            $conn->my_update("SET character_set_database=utf8");
            $conn->my_update("SET NAMES 'utf8'");
            return;
        }

        sleep(1);
    }
    if ($trial == $max_trial) {
        echo "Error connecting to the following database:\n";
        print_r($db_config);
        echo "(" . $conn->connection->connect_errno . ") ";
        echo $conn->connection->connect_error . "\n";
        exit(1);
    }
}

function rand_string($size = 20)
{
    $bytes = openssl_random_pseudo_bytes($size);
    return strtoupper(bin2hex($bytes));
}

function unicodeC($text)
{
    return Normalizer::normalize($text, Normalizer::FORM_C);
}

function uni_normalize_table($id = -1, $table = "", $fields = NULL)
{
    if (($id == -1) || ($table == "") || ($fields == NULL))
        return;

    $this->db->select("$id, " . implode(', ', $fields));
    $query = $this->db->get($table);
    foreach ($query->result_array() as $row) {
        $res = array();
        foreach ($row as $key => $value)
            if ($key != $id)
                $res[$key] = unicodeC($value);
        $this->db->where($id, $row[$id]);
        $this->db->update($table, $res);
    }
}

function fix_periods($text)
{
    $text = preg_replace('/([^,|\.]\s?[^\d\s\.]{2,})[\s]?(\.)([^\d\s\.])/ui', '$1$2 $3', $text);
    return $text;
}

function to_array($text)
{
    $text = str_replace('\u00a0', ' ', $text);
    $text = str_replace('\u00b2', '2', $text);
    return json_decode($text, 1);
}

function regex_to_array($regex)
{
    $regex = explode("|", $regex);
    foreach ($regex as $r)
        if (strlen($r) > 1) {
            $result[] = trim($r);
        }
    return $result;
}

function to_sentence($text)
{
    $text = fix_periods($text);
    $text = preg_replace("/(\.\s)|\n/iu", "||| ", $text);

    $sentence = explode("||| ", $text);

    return filter_by_length($sentence);
}

function filter_by_length($input, $length = 3)
{
    foreach ($input as $i)
        if (strlen(trim($i)) >= $length)
            $result[] = $i;
    return $result;
}

function words_before($text, $position, $limit = 5)
{
    $text   = substr($text, 0, $position);
    $words  = preg_split("/\s|\.|,|:/ui", $text);
    $before = array_slice($words, -$limit);

    return trim(implode(" ", $before));
}

function words_after($text, $position, $limit = 5)
{
    $text  = substr($text, $position, strlen($text) - $position);
    $words = preg_split("/\s|\.|,|:/ui", $text);
    $after = array_slice($words, 0, $limit + 1);

    return trim(implode(" ", $after));
}

function uni_to_lower($text)
{
    mb_internal_encoding("UTF-8");
    $text    = Normalizer::normalize($text, Normalizer::FORM_C);
    $unicode = array();
}

function simplify($text, $blank = null, $remove = null)
{

    if (is_array($text)) {
        foreach ($text as $key => $value)
            $result[$key] = simplify($value, $blank, $remove);

        return $result;
    }

    mb_internal_encoding("UTF-8");

    $unicode = array(
        'a' => 'á|à|ả|ã|ạ|ă|ắ|ặ|ằ|ẳ|ẵ|â|ấ|ầ|ẩ|ẫ|ậ',
        'd' => 'đ',
        'e' => 'é|è|ẻ|ẽ|ẹ|ê|ế|ề|ể|ễ|ệ',
        'i' => 'í|ì|ỉ|ĩ|ị',
        'o' => 'ó|ò|ỏ|õ|ọ|ô|ố|ồ|ổ|ỗ|ộ|ơ|ớ|ờ|ở|ỡ|ợ|ộ|ọ|ộ',
        'u' => 'ú|ù|ủ|ũ|ụ|ư|ứ|ừ|ử|ữ|ự|ù',
        'y' => 'ý|ỳ|ỷ|ỹ|ỵ',
        'A' => 'Á|À|Ả|Ã|Ạ|Ă|Ắ|Ặ|Ằ|Ẳ|Ẵ|Â|Ấ|Ầ|Ẩ|Ẫ|Ậ',
        'D' => 'Đ',
        'E' => 'É|È|Ẻ|Ẽ|Ẹ|Ê|Ế|Ề|Ể|Ễ|Ệ',
        'I' => 'Í|Ì|Ỉ|Ĩ|Ị',
        'O' => 'Ó|Ò|Ỏ|Õ|Ọ|Ô|Ố|Ồ|Ổ|Ỗ|Ộ|Ơ|Ớ|Ờ|Ở|Ỡ|Ợ',
        'U' => 'Ú|Ù|Ủ|Ũ|Ụ|Ư|Ứ|Ừ|Ử|Ữ|Ự',
        'Y' => 'Ý|Ỳ|Ỷ|Ỹ|Ỵ'
    );
    $text    = Normalizer::normalize($text, Normalizer::FORM_C);
    foreach ($unicode as $nonUnicode => $uni) {
        $text = preg_replace("/($uni)/iu", $nonUnicode, $text);
        $text = preg_replace("/($uni)/iu", $nonUnicode, $text);
    }

    $text = preg_replace("/[^0-9a-zA-Z\:\.,\-\s()\|]/iu", "", $text);

    $text = mb_strtolower($text, "UTF-8");
    $text = str_replace('.', " ", $text);
    $text = preg_replace("/[\s]+/iu", " ", $text);



    if ($blank)
        $text = str_replace($blank, " ", $text);
    if ($remove)
        $text = str_replace($remove, "", $text);

    return trim($text);
}

function to_ascii($text)
{
    $unicode = array(
        'a' => 'á|à|ả|ã|ạ|ă|ắ|ặ|ằ|ẳ|ẵ|â|ấ|ầ|ẩ|ẫ|ậ',
        'd' => 'đ',
        'e' => 'é|è|ẻ|ẽ|ẹ|ê|ế|ề|ể|ễ|ệ',
        'i' => 'í|ì|ỉ|ĩ|ị',
        'o' => 'ó|ò|ỏ|õ|ọ|ô|ố|ồ|ổ|ỗ|ộ|ơ|ớ|ờ|ở|ỡ|ợ|ộ|ọ|ộ',
        'u' => 'ú|ù|ủ|ũ|ụ|ư|ứ|ừ|ử|ữ|ự|ù',
        'y' => 'ý|ỳ|ỷ|ỹ|ỵ',
        'A' => 'Á|À|Ả|Ã|Ạ|Ă|Ắ|Ặ|Ằ|Ẳ|Ẵ|Â|Ấ|Ầ|Ẩ|Ẫ|Ậ',
        'D' => 'Đ',
        'E' => 'É|È|Ẻ|Ẽ|Ẹ|Ê|Ế|Ề|Ể|Ễ|Ệ',
        'I' => 'Í|Ì|Ỉ|Ĩ|Ị',
        'O' => 'Ó|Ò|Ỏ|Õ|Ọ|Ô|Ố|Ồ|Ổ|Ỗ|Ộ|Ơ|Ớ|Ờ|Ở|Ỡ|Ợ',
        'U' => 'Ú|Ù|Ủ|Ũ|Ụ|Ư|Ứ|Ừ|Ử|Ữ|Ự',
        'Y' => 'Ý|Ỳ|Ỷ|Ỹ|Ỵ'
    );
    $text    = Normalizer::normalize($text, Normalizer::FORM_C);
    $text    = preg_replace("/[\x01-\x1f]/iu", " ", $text);
    $text    = preg_replace("/\s+/", " ", $text);
    foreach ($unicode as $nonUnicode => $uni) {
        $text = preg_replace("/($uni)/i", $nonUnicode, $text);
        $text = preg_replace("/($uni)/i", $nonUnicode, $text);
    }
    $text = mb_strtolower($text, "UTF-8");
    return trim($text);
}

function multiply($string, $times = 1, $delimiter = "-----")
{
    for ($i = 0; $i < $times; $i++)
        $result .= "\n" . $delimiter . "\n" . $string;
    return $result;
}

function scoring($text, $regex, $score = 1, $key = null)
{
    if (is_array($regex)) {
        foreach ($regex as $key => $value)
            $result = array_merge((array) $result, (array) scoring($text, $value, $score, $key));
        return $result;
    }

    $regex = (substr($regex, 0, 1) !== '/') ? "#\b($regex)\b#iu" : $regex;

    preg_match_all("$regex", $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

    foreach ((array) $matches as $match) {
        $found[value]  = $match[0][0];
        $found[key]    = is_null($key) ? $match[0][0] : $key;
        $found[score]  = $score * (1 + strlen($match[0][0]) / 50);
        $found[prefix] = words_before($text, $match[0][1], 5);
        $found[suffix] = words_after($text, $match[0][1] + strlen($match[0][0]), 5);
        $result[]      = $found;
    }

    return $result;
}

function key_score($list, $prefix = null, $suffix = null)
{
    foreach ((array) $list as $l) {
        if ($prefix && preg_match("#($prefix)#iu", $l[prefix]))
            $score[$l[key]] = $score[$l[key]] + $l[score];
        if ($suffix && preg_match("#\b($suffix)\b#iu", $l[suffix]))
            $score[$l[key]] = $score[$l[key]] + $l[score];
        if (is_null($prefix) && is_null($suffix))
            $score[$l[key]] = $score[$l[key]] + $l[score];
    }
    if (!$score)
        return null;
    arsort($score);

    return @key($score);
}
