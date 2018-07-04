<?php
// Version 20150113
libxml_use_internal_errors(true);
class Loader
{
	var $http_timeout	= 100;
    var $http_engine 	= null;
    var $http_handler 	= null;
    var $http_url 		= null;
    var $http_method 	= 'GET';
    var $http_request 	= null;
    var $cookie_path 	= null;
    var $cookie_data 	= null;
    var $http_data 		= null;    
    var $last_url 		= null;
    var $redirected_url = null;
    var $http_agent 	= null;
    var $dom_data 		= null;
    var $result_info 	= null;
    var $loop			= null;
    var $curlopts		= null;
    var $use_cli		= FALSE;
    var $errno			= 0;
    var $error			= null;
    
    function __construct($config = null)
    {
    	$this->curlopts = array();
        if (is_array($config))
            foreach ($config as $key => $value)
                $this->$key = $value;
        if (filter_var($config, FILTER_VALIDATE_URL))
            $this->http_url = $config;
        
//        $this->init_handler();
        if ($this->http_url)
            $this->load_url();
    }

    function get_result($output, $url = null, $method = null, $request = null)
    {
        if ($url)
            $this->extract_url($url, $method, $request);
        
        if ($output == 'data')
            return $this->http_data;
        else if ($output == 'text')
            return $this->dom_data->textContent;
        else if (is_array($output))
            return $this->extract($output);
        else
            return false;
    }
    
    function init_handler()
    {
        if (!$this->http_agent)
            $this->http_agent = $this->random_agent();
        
        if (!function_exists('curl_init') || $this->http_engine == 'stream')
            $this->init_stream();
        else
            $this->init_curl();
    }
    
    function init_curl()
    {
        $this->http_handler = curl_init();
        
        if ($this->cookie_data) {
            curl_setopt($this->http_handler, CURLOPT_COOKIE, $this->cookie_data);
            $this->curlopts['CURLOPT_COOKIE'] = $this->cookie_data;
        }
        else if (isset($this->cookie_path)) {
            if (strlen($this->cookie_data))
                file_put_contents(sys_get_temp_dir() . '/' . $this->cookie_path, $this->cookie_data);
            
            curl_setopt($this->http_handler, CURLOPT_COOKIEFILE, sys_get_temp_dir() . '/' . $this->cookie_path);
            curl_setopt($this->http_handler, CURLOPT_COOKIEJAR, sys_get_temp_dir() . '/' . $this->cookie_path);
			$this->curlopts['CURLOPT_COOKIEFILE'] = sys_get_temp_dir() . '/' . $this->cookie_path;
			$this->curlopts['CURLOPT_COOKIEJAR']  = sys_get_temp_dir() . '/' . $this->cookie_path;			
        }
                           
        curl_setopt($this->http_handler, CURLOPT_USERAGENT, $this->http_agent);
        curl_setopt($this->http_handler, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($this->http_handler, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($this->http_handler, CURLOPT_TIMEOUT, $this->http_timeout);
        curl_setopt($this->http_handler, CURLOPT_VERBOSE, FALSE);
        curl_setopt($this->http_handler, CURLOPT_AUTOREFERER, TRUE);
        curl_setopt($this->http_handler, CURLOPT_ENCODING, "gzip");
        
        $this->curlopts['CURLOPT_USERAGENT'] 	  = $this->http_agent;
        $this->curlopts['CURLOPT_AUTOREFERER'] 	  = TRUE;
        $this->curlopts['CURLOPT_TIMEOUT'] 		  = $this->http_timeout;
        $this->curlopts['CURLOPT_ENCODING'] 	  = 'gzip';
        $this->curlopts['CURLOPT_FOLLOWLOCATION'] = TRUE;
                
        $headers['Accept'] 			= "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8";
        $headers['Accept-Language'] = "Accept-Language: en-us,en;q=0.5";
        $headers['Accept-Charset'] 	= "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
        $headers['Keep-Alive'] 		= "Keep-Alive: 300";
        $headers['Connection']		= "Connection: keep-alive";
        
        $header = implode("\r\n", $headers);
        
        curl_setopt($this->http_handler, CURLOPT_HTTPHEADER, $headers);
        $this->curlopts['CURLOPT_HTTPHEADER'] = $headers;
        
        // Handle last url
        if ($this->last_url) {
            curl_setopt($this->http_handler, CURLOPT_REFERER, $this->last_url);
            $this->curlopts['CURLOPT_REFERER'] = $this->last_url;
        }        
        return true;
    }
    
    function init_stream()
    {
        $this->http_handler[method]     = $this->http_method;
        $this->http_handler[user_agent] = $this->http_agent;
        
        $headers['Accept'] 			= "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8";
        $headers['Accept-Language'] = "Accept-Language: en-us,en;q=0.5";
        $headers['Accept-Charset'] 	= "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7";
        $headers['Keep-Alive'] 		= "Keep-Alive: 300";
        
        if ($this->last_url)
            $headers[] = "Referer: " . $this->last_url;
        if ($this->cookie_data)
            $headers[] = "Cookie :" . $this->cookie_data;
        
        $this->http_handler[header] 		  = implode("\r\n", $headers);
        $this->curlopts['CURLOPT_HTTPHEADER'] = $headers;
        
        return true;
    }
    
    function raw_request($host, $request)
    {
        $service_port = getservbyname('www', 'tcp');
        $address      = gethostbyname($host);
        
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            echo "socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n";
        }
        $result = socket_connect($socket, $address, $service_port);
        if ($result === false) {
            echo "socket_connect() failed.\nReason: ($result) " . socket_strerror(socket_last_error($socket)) . "\n";
        }
        
        $chunks  = explode("\n", trim($request));
        $request = implode("\r\n", $chunks) . "\r\n\r\n";
        
        socket_write($socket, $request, strlen($request));
        
        $content = '';
        while ($data = socket_read($socket, 1024)) {
            $content .= $data;
            if (!$content_len) {
                if (preg_match('/Content-Length: ([0-9]*)/i', $content, $out)) {
                    $content_len = $out[1];
                    $split       = explode("\r\n\r\n", $data);
                    $data_len    = strlen($split[0]) + $content_len + 4;
                }
            }
            
            if (strlen($content) == $data_len)
                break;
            
            if (strlen($data) == 0)
                break;
        }
        
        socket_close($socket);
        
        $data = explode("\r\n\r\n", $content);
        
        $data[0] = null;
        
        $data = implode("\r\n\r\n", $data);
        return $data;
    }
    
    function set_use_cli($bool)
    {
    	$this->use_cli = $bool;
    }
    
    function get_redirected_url()
    {
    	return $this->redirected_url;
    }
    
    function curl_exec()
    {
    	if (!$this->use_cli) {
    		$this->http_data = curl_exec($this->http_handler);
			$this->result_info = curl_getinfo($this->http_handler);		
			if (curl_errno($this->http_handler)) {
				$this->errno 	 = curl_errno($this->http_handler);
				$this->error 	 = curl_error($this->http_handler);				
				$this->http_data = curl_error($this->http_handler);
			}
			$this->redirected_url = curl_getinfo($this->http_handler, CURLINFO_EFFECTIVE_URL);
			return $this->http_data;
		}
		
		$args = array();
		foreach ($this->curlopts as $opt => $val) {
			$val = str_replace("\"", "\\\"", $val);
			switch ($opt) {
				case 'CURLOPT_INTERFACE':
					$args[$opt] = "--interface \"$val\"";
					break;
				case 'CURLOPT_FOLLOWLOCATION':
					if ($val)
						$args[$opt] = "-L";
					break;
				case 'CURLTOPT_AUTOREFERER':
					if ($val)
						$args[$opt] = "--referer \";auto\"";
					break;
				case 'CURLOPT_ENCODING':
					if ($val == 'gzip')
						$args[$opt] = '--compressed';
					else
						$args[$opt] = "-H \"Accept-Encoding: $val\"";
					break;
				case 'CURLOPT_TIMEOUT':
					$args[$opt] = "-m \"$val\"";
					break;
				case 'CURLOPT_COOKIE':
					$args[$opt] = "-b \"$val\"";
					break;
				case 'CURLOPT_COOKIEFILE':
					$args[$opt] = "-b \"$val\"";
					break;
				case 'CURLOPT_COOKIEJAR':
					$args[$opt] = "--cookie-jar \"$val\"";
					break;		
				case 'CURLOPT_USERAGENT':
					$args[$opt] = "-A \"$val\"";
					break;
				case 'CURLOPT_CONNECTTIMEOUT':
					$args[$opt] = "--connect-timeout \"$val\"";
					break;
				case 'CURLOPT_SSL_VERIFYPEER':
					if ($val == 0)
						$args[$opt] = "-k";
					break;
				case 'CURLOPT_SSL_VERIFYHOST':
					if ($val == 0)
						$args[$opt] = "-k";
					break;
				case 'CURLOPT_PROXYTYPE':
					$proxy_type = $val;
					break;
				case 'CURLOPT_PROXY':
					if (!isset($proxy_type)) {
						$args[$opt] = "-x \"$val\"";
						break;
					}
					$parsed = parse_url($val);
					if (isset($parsed['scheme'])) {
						$args[$opt] = "-x \"$val\"";
						break;
					}					
					switch ($proxy_type) {
						case CURLPROXY_HTTP:
							$args[$opt] = "-x \"http://$val\"";
							break;
						case CURLPROXY_SOCKS4:
							$args[$opt] = "--socks4 \"$val\"";
							break;
						case CURLPROXY_SOCKS5:
							$args[$opt] = "--socks5 \"$val\"";
							break;						
					}
					break;
				case 'CURLOPT_HTTPHEADER':
					$args[$opt] = "";
					foreach ($val as $hdr)
						$args[$opt] .= "-H \"$hdr\" ";
					break;
				case 'CURLOPT_URL':
					$args[$opt] = "\"$val\"";
					break;
			}	
		}	
    	mlog("curl -w \"\\n%{url_effective}\" --globoff -s -S " . implode(" ", $args), LOG_DEBUG3, FALSE);
    	$err_file = "/tmp/curl.err." . rand(0, 1000000000);
    	$out_file = "/tmp/curl.out." . rand(0, 1000000000);
    	exec("curl -w \"\\n%{url_effective}\" --globoff -s -S " . implode(" ", $args) . " > ${out_file} 2>${err_file} || echo $?", $errno);
		if (isset($errno[0])) {
			$this->errno = intval($errno[0]);
			$this->error = file_get_contents($err_file);
			$this->http_data = $this->error;
		} else {
			$this->errno = 0;
			$this->error = null;
			$tmp_data = trim(file_get_contents($out_file));
			$exploded = explode("\n", $tmp_data);
			$this->redirected_url = $exploded[count($exploded) - 1];
			unset($exploded[count($exploded) - 1]);
			$this->http_data = implode("\n", $exploded);
		}
		if (file_exists($err_file))
			unlink($err_file);
		if (file_exists($out_file))
			unlink($out_file);
		return $this->http_data;
    }
    
    function curl_request()
    {
        if ($this->http_method == "GET") {
            curl_setopt($this->http_handler, CURLOPT_HTTPGET, TRUE);            
            curl_setopt($this->http_handler, CURLOPT_POST, FALSE);
			$this->curlopts['CURLOPT_HTTPGET'] = TRUE;
			$this->curlopts['CURLOPT_POST']    = FALSE;			
        } elseif ($this->http_method == "POST") {
            curl_setopt($this->http_handler, CURLOPT_HTTPGET, FALSE);
            curl_setopt($this->http_handler, CURLOPT_POST, TRUE);
            curl_setopt($this->http_handler, CURLOPT_POSTREDIR, 7);
			$this->curlopts['CURLOPT_HTTPGET'] = FALSE;
			$this->curlopts['CURLOPT_POST']    = TRUE;
			$this->curlopts['CURLOPT_POSTREDIR'] = 7; // This makes sure that redirection also carries on POST data
            if ($this->http_request) {
                curl_setopt($this->http_handler, CURLOPT_POSTFIELDS, $this->http_request);
                $this->curlopts['CURLOPT_POSTFIELDS'] = $this->http_request;
            }
        } else {
            curl_setopt($this->http_handler, CURLOPT_HTTPGET, FALSE);
            curl_setopt($this->http_handler, CURLOPT_POST, FALSE);
            curl_setopt($this->http_handler, CURLOPT_POSTREDIR, 7);
            curl_setopt($this->http_handler, CURLOPT_CUSTOMREQUEST, $this->http_method);
			$this->curlopts['CURLOPT_HTTPGET'] = FALSE;
			$this->curlopts['CURLOPT_POST']    = FALSE;
			$this->curlopts['CURLOPT_POSTREDIR'] = 7; // This makes sure that redirection also carries on POST data
            if ($this->http_request) {
                curl_setopt($this->http_handler, CURLOPT_POSTFIELDS, $this->http_request);
                $this->curlopts['CURLOPT_POSTFIELDS'] = $this->http_request;
            }        	
        }
                
        curl_setopt($this->http_handler, CURLOPT_URL, $this->http_url);
        $this->curlopts['CURLOPT_URL'] = $this->http_url;
		return $this->curl_exec();
    }
    
    function errno()
    {
    	return $this->errno;
    }
    
    function set_interface($iface)
    {
    	if (!isset($this->http_handler))
    		$this->init_handler();
    	curl_setopt($this->http_handler, CURLOPT_INTERFACE, $iface);
    	$this->curlopts['CURLOPT_INTERFACE'] = $iface;
    }

	function set_headers($headers)
	{
    	if (!isset($this->http_handler))
    		$this->init_handler();
		if (!is_array($headers))
			return false;
		foreach ($headers as $attr => $hdr)
			$this->curlopts['CURLOPT_HTTPHEADER'][$attr] = $hdr;
        curl_setopt($this->http_handler, CURLOPT_HTTPHEADER, $this->curlopts['CURLOPT_HTTPHEADER']);
	}
	
    function set_timeout($secs)
    {
    	$this->http_timeout = intval($secs);
    	if (!isset($this->http_handler))
    		$this->init_handler();
    	curl_setopt($this->http_handler, CURLOPT_TIMEOUT, intval($secs));
    	$this->curlopts['CURLOPT_TIMEOUT'] = intval($secs);
    }

    function set_conn_timeout($secs)
    {
    	if (!isset($this->http_handler))
    		$this->init_handler();
    	curl_setopt($this->http_handler, CURLOPT_CONNECTTIMEOUT, intval($secs));
    	$this->curlopts['CURLOPT_CONNECTTIMEOUT'] = intval($secs);
    }


    function set_cookie($cookie)
    {
    	$this->cookie_data = $cookie;
    	if (!isset($this->http_handler))
    		$this->init_handler();    
    	curl_setopt($this->http_handler, CURLOPT_COOKIE, $cookie);    	
    	$this->curlopts['CURLOPT_COOKIE'] = $cookie;
    }

	function set_cookiefile($cookie_file)
	{
    	$this->cookie_path = null;	
    	if (!isset($this->http_handler))
    		$this->init_handler();    
    	curl_setopt($this->http_handler, CURLOPT_COOKIEFILE, $cookie_file);    		
    	curl_setopt($this->http_handler, CURLOPT_COOKIEJAR, $cookie_file);    		    	
    	$this->curlopts['CURLOPT_COOKIEFILE'] = $cookie_file;
    	$this->curlopts['CURLOPT_COOKIEJAR'] = $cookie_file;
	}

	function set_ignore_cert()
	{
    	if (!isset($this->http_handler))
    		$this->init_handler();    
    	curl_setopt($this->http_handler, CURLOPT_SSL_VERIFYPEER, 0);
    	curl_setopt($this->http_handler, CURLOPT_SSL_VERIFYHOST, 0);
    	$this->curlopts['CURLOPT_SSL_VERIFYPEER'] = 0;
    	$this->curlopts['CURLOPT_SSL_VERIFYHOST'] = 0;
	}


    function set_agent($agent)
    {
    	if (!isset($this->http_handler))
    		$this->init_handler();    
    	$this->http_agent = $agent;
    	curl_setopt($this->http_handler, CURLOPT_USERAGENT, $agent);  
    	$this->curlopts['CURLOPT_USERAGENT'] = $agent;
    }

    function set_proxy($proxy)
    {
    	if (!isset($this->http_handler))
    		$this->init_handler();    
    	curl_setopt($this->http_handler, CURLOPT_PROXY, $proxy);  
    	$this->curlopts['CURLOPT_PROXY'] = $proxy;
    }

    function set_socks($socks)
    {
    	if (!isset($this->http_handler))
    		$this->init_handler();    
    	curl_setopt($this->http_handler, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
    	curl_setopt($this->http_handler, CURLOPT_PROXY, $socks);
    	$this->curlopts['CURLOPT_PROXYTYPE'] = CURLPROXY_SOCKS5;    	    	
    	$this->curlopts['CURLOPT_PROXY'] = $socks;    	
    }
    
    function stream_request()
    {
        if ($this->http_method == "POST") {
            $this->http_handler[method]  = "POST";
            $this->http_handler[content] = $this->http_request;
        }
        
        $ctx = stream_context_create(array(
            'http' => $this->http_handler
        ));
        $fp  = @fopen($this->http_url, 'rb', false, $ctx);
        if (!$fp)
            throw new Exception("Problem with " . $this->http_url . ", $php_errormsg");
        
        $response = stream_get_contents($fp);
        
        if ($response === false)
            throw new Exception("Problem reading data from " . $this->http_url . ", $php_errormsg");
        @fclose($fp);
        $this->http_data = $response;
        return $this->http_data;
    }
    
    function http($url = null, $method = null, $request = null)
    {
        if (!$this->http_handler)
	        $this->init_handler();

 		$this->http_url 		= ($url) ? $url : null;
 		$this->http_method 		= ($method) ? $method : null;
 		$this->http_request 	= ($request) ? $request : null;
 
        if ($this->http_method == "RAW") {
            $this->http_data = $this->raw_request($url, $request);
            return $this->http_data;
        }
        
        if (!$this->http_handler)
            return false;
        
        if (!function_exists('curl_init') || $this->http_engine == 'stream')
            return $this->stream_request();
        else
            return $this->curl_request();
    }    
    
    function load_url($url = null, $method = null, $request = null)
    {
        $this->dom_data          = new DOMDocument();
        $this->dom_data->recover = TRUE;
        $http_data		= $this->http($url, $method, $request);
		
    	return $this->dom_data->loadHTML(
    		mb_convert_encoding($http_data, 'HTML-ENTITIES', 'UTF-8')
    	);
    }
    
	function load_html($html)
	{
        $this->dom_data          = new DOMDocument();
        $this->dom_data->recover = TRUE;
		
    	return $this->dom_data->loadHTMLFile($html);		
	}
	
	function load_string($html)
	{
        $this->dom_data          = new DOMDocument();
        $this->dom_data->recover = TRUE;
		
    	return $this->dom_data->loadHTML(
    		mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));		
	}
	
    function remove($query)
    {
        $dom_data = new domxpath($this->dom_data);
        $elements = $dom_data->query($query);
        
        foreach ($elements as $element) {
            // This is a hint from the manual comments
            $element->parentNode->removeChild($element);
        }
        $this->http_data = $this->dom_data->saveXML();
        @$this->dom_data->loadHTML($this->http_data);
    }
    
    function find($query, $index = null, $output = 'txt', $dom = null)
    {       
        $result = array();     
        if (is_array($query)) {
            foreach ($query as $key => $value)
                if (substr($key, 0, 1) !== '_')
                    $result[$key] = $this->find($value, $index, $ouput, $dom);
            return $result;
        }
        
        if (!is_null($dom)) {
            $dom_data = new DOMDocument;
            $dom_data->appendChild($dom_data->importNode($dom, true));
        } else
            $dom_data = $this->dom_data;
        
        // convert DOMDocument into DOMxpath
        $dom_data = new Domxpath($dom_data);
        $query = explode('||', $query);
        $i = 0;
        
        while (!isset($dom_result->length)  && ($i < sizeof($query))) {
            $dom_result = $dom_data->query(trim($query[$i]));
            $i++;
        }
        if ($dom_result->length == 0)
            return null;
        

        for ($i = 0; $i < $dom_result->length; $i++) {
            
            if ($output == 'htm')
                $result[] = $this->save_html($dom_result->item($i));
            else if ($output == 'cln') {
                
                $html       = $this->save_html($dom_result->item($i));
                $html = $this->html_tidy($html, '0t0nr');
                $result[]	= html_entity_decode($this->html2txt($html));
                /*
                $node_data = new DOMDocument();
                $node_data->loadHTML($html);
                $result[] = trim($node_data->textContent);
            	*/
                
            } else if ($output == 'txt')
                $result[] = trim($dom_result->item($i)->textContent);
            else if ($output == 'dom')
                $result[] = $dom_result->item($i);
        }        
        
        unset($dom_data);
        
        if ($index == -1)
            return $result;
        else if (!is_null($index)) {
            return $result[$index];
        } else if ($dom_result->length == 1)
            return $result[0];
        
        return $result;
    }
    
    function extract($struct, $dom = null)
    {
        if (is_array($dom)) {
            foreach ($dom as $d) {
                $tmp = $this->extract($struct, $d);
                if (!is_null($tmp))
                    $result[] = $tmp;
                unset($tmp);
            }
            return $result;
        }
        
        foreach ($struct as $key => $value) {
            $type = substr($key, 0, 3);
            $key  = substr($key, 4);
            
            if ($type == "dom") {
                $path = $value["_path"];
                unset($value["_path"]);
                $result[$key] = $this->extract($value, $this->find($path, -1, 'dom', $dom));
                
            } else if ($type == "rgx") {
                $path = $value["_path"];
                unset($value["_path"]);
                
                $pattern = reset($value);
                $pos     = key($value);
                
                $subject = $this->find($path, 0, 'txt', $dom);
                if (@preg_match($pattern, $subject, $matches))
                    $match[] = trim($matches[$pos]);
                
//                print_r($matches);
                if (sizeof($match) == 1)
                    $result[$key] = $match[0];
                else
                    $result[$key] = null;
                unset($match);
                
            } else if ($type == "lst")
                $result[$key] = $this->find($value, null, 'txt', $dom);
			else            
                $result[$key] = $this->find($value, 0, $type, $dom);
        }
        
        return $result;
    }
    
    function extract_url($url, $struct)
    {
    	$this->load_url($url);
    	return $this->extract($struct);
    }
    
    function tablize($array, $parent = null)
    {
        
        // Handle non-array elements    
        $row = array();
        foreach ($array as $key => $value)
            if (!is_array($value))
                $row[$key] = $value;
        
        // Append to each parent 
        if (!is_null($parent))
            foreach ($parent as $pv)
                $result[] = array_merge($pv, $row);
        else
            $result[] = $row;
        
        $has_child = 0;
        foreach ($array as $key => $value)
            if (is_array($value)) {
                foreach ($value as $cv)
                    $stack[] = $this->tablize($cv, $result);
                $has_child++;
            }
        
        if ($has_child) {
            $result = null;
            foreach ($stack as $sv)
                foreach ($sv as $rv)
                    $result[] = $rv;
        }
        
        return $result;
    }
    
    function save_html($n, $outer = false)
    {
        $d = new DOMDocument('1.0');
        $b = $d->importNode($n->cloneNode(true), true);
        $d->appendChild($b);
        $h = $d->saveHTML();
        // remove outter tags 
        if (!$outer)
            $h = substr($h, strpos($h, '>') + 1, -(strlen($n->nodeName) + 4));
        unset($d);
        return $this->html_tidy($h, '1t0nr');
    }
    
    function clean_text($string)
    {
        $string = trim($string);
        $string = preg_replace("/\s\s+/u", " ", trim($string));
        $string = preg_replace("/\n+/u", "\n", trim($string));
        
        return $string;
    }
    
    function html2txt($document)
    {
        $search = array(
            "@<script[^>]*?>.*?</script>@si", // Strip out javascript 
            "@<[\/\!]*?[^<>]*?>@si", // Strip out HTML tags 
            "@<style[^>]*?>.*?</style>@siU", // Strip style tags properly 
            "@<![\s\S]*?--[ \t\n\r]*>@" // Strip multi-line comments including CDATA 
        );
        $text   = preg_replace($search, '', $document);
        $text	= trim(preg_replace('/[ \t]+/', ' ', preg_replace('/[\r\n]+/', "\n", $document)));
        return $text;
    }
    
    function html_tidy($t, $w)
    {
        // Tidy/compact HTM
        $p = 'body';
        if (strpos(' pre,script,textarea', "$p,")) {
            return $t;
        }
        $t = preg_replace('/\s+/u', ' ', preg_replace_callback(array(
            "/(<(!\[CDATA\[))(.+?)(\]\]>)/sm",
            "/(<(!--))(.+?)(-->)/sm",
            "`(<(pre|script|textarea)[^>]*?>)(.+?)(</\2>)`sm"
        ), create_function('$m', 'return $m[1]. str_replace(array("<", ">", "\n", "\r", "\t", " "), array("\x01", "\x02", "\x03", "\x04", "\x05", "\x07"), $m[3]). $m[4];'), $t));
        
        if (($w = strtolower($w)) == -1) {
            return str_replace(array(
                "\x01",
                "\x02",
                "\x03",
                "\x04",
                "\x05",
                "\x07"
            ), array(
                '<',
                '>',
                "\n",
                "\r",
                "\t",
                ' '
            ), $t);
        }
        
        $s = strpos(" $w", 't') ? "\t" : ' ';
        $s = preg_match('`\d`', $w, $m) ? str_repeat($s, $m[0]) : str_repeat($s, ($s == "\t" ? 1 : 2));
        $N = preg_match('`[ts]([1-9])`', $w, $m) ? $m[1] : 0;
        $a = array(
            'br' => 1
        );
        $b = array(
            'button' => 1,
            'input' => 1,
            'option' => 1,
            'param' => 1
        );
        $c = array(
            'caption' => 1,
            'dd' => 1,
            'dt' => 1,
            'h1' => 1,
            'h2' => 1,
            'h3' => 1,
            'h4' => 1,
            'h5' => 1,
            'h6' => 1,
            'isindex' => 1,
            'label' => 1,
            'legend' => 1,
            'li' => 1,
            'object' => 1,
            'p' => 1,
            'pre' => 1,
            'td' => 1,
            'textarea' => 1,
            'th' => 1
        );
        $d = array(
            'address' => 1,
            'blockquote' => 1,
            'center' => 1,
            'colgroup' => 1,
            'dir' => 1,
            'div' => 1,
            'dl' => 1,
            'fieldset' => 1,
            'form' => 1,
            'hr' => 1,
            'iframe' => 1,
            'map' => 1,
            'menu' => 1,
            'noscript' => 1,
            'ol' => 1,
            'optgroup' => 1,
            'rbc' => 1,
            'rtc' => 1,
            'ruby' => 1,
            'script' => 1,
            'select' => 1,
            'table' => 1,
            'tbody' => 1,
            'tfoot' => 1,
            'thead' => 1,
            'tr' => 1,
            'ul' => 1
        );
        $T = explode('<', $t);
        $X = 1;
        while ($X) {
            $n = $N;
            $t = $T;
            ob_start();
            if (isset($d[$p])) {
                echo str_repeat($s, ++$n);
            }
            echo ltrim(array_shift($t));
            for ($i = -1, $j = count($t); ++$i < $j;) {
                $r = '';
                list($e, $r) = explode('>', $t[$i]);
                $x = $e[0] == '/' ? 0 : (substr($e, -1) == '/' ? 1 : ($e[0] != '!' ? 2 : -1));
                $y = !$x ? ltrim($e, '/') : ($x > 0 ? substr($e, 0, strcspn($e, ' ')) : 0);
                $e = "<$e>";
                if (isset($d[$y])) {
                    if (!$x) {
                        if ($n) {
                            echo "\n", str_repeat($s, --$n), "$e\n", str_repeat($s, $n);
                        } else {
                            ++$N;
                            ob_end_clean();
                            continue 2;
                        }
                    } else {
                        echo "\n", str_repeat($s, $n), "$e\n", str_repeat($s, ($x != 1 ? ++$n : $n));
                    }
                    echo $r;
                    continue;
                }
                $f = "\n" . str_repeat($s, $n);
                if (isset($c[$y])) {
                    if (!$x) {
                        echo $e, $f, $r;
                    } else {
                        echo $f, $e, $r;
                    }
                } elseif (isset($b[$y])) {
                    echo $f, $e, $r;
                } elseif (isset($a[$y])) {
                    echo $e, $f, $r;
                } elseif (!$y) {
                    echo $f, $e, $f, $r;
                } else {
                    echo $e, $r;
                }
            }
            $X = 0;
        }
        $t = str_replace(array(
            "\n ",
            " \n"
        ), "\n", preg_replace('`[\n]\s*?[\n]+`', "\n", ob_get_contents()));
        ob_end_clean();
        if (($l = strpos(" $w", 'r') ? (strpos(" $w", 'n') ? "\r\n" : "\r") : 0)) {
            $t = str_replace("\n", $l, $t);
        }
        return str_replace(array(
            "\x01",
            "\x02",
            "\x03",
            "\x04",
            "\x05",
            "\x07"
        ), array(
            '<',
            '>',
            "\n",
            "\r",
            "\t",
            ' '
        ), $t);
        // eof
    }
    
    function random_agent()
    {
        $user_agents = array(
            "Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/29.0.1547.2 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/28.0.1468.0 Safari/537.36",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.8; rv:22.0) Gecko/20100101 Firefox/22.0",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.8; rv:21.0) Gecko/20100101 Firefox/21.0",
            "Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:22.0) Gecko/20130328 Firefox/22.0",
            "Mozilla/5.0 (Windows NT 6.0) yi; AppleWebKit/345667.12221 (KHTML, like Gecko) Chrome/23.0.1271.26 Safari/453667.1221",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_8_2) AppleWebKit/537.17 (KHTML, like Gecko) Chrome/24.0.1309.0 Safari/537.17",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_8_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/29.0.1547.65 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:25.0) Gecko/20100101 Firefox/25.0",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.6; rv:25.0) Gecko/20100101 Firefox/25.0",
            "Mozilla/5.0 (Windows NT 6.0; WOW64; rv:24.0) Gecko/20100101 Firefox/24.0",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10.8; rv:24.0) Gecko/20100101 Firefox/24.0",
            "Mozilla/5.0 (Windows NT 6.2; rv:22.0) Gecko/20130405 Firefox/23.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:23.0) Gecko/20130406 Firefox/23.0",
            "Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:23.0) Gecko/20131011 Firefox/23.0",
            "Mozilla/5.0 (Windows NT 6.2; rv:22.0) Gecko/20130405 Firefox/22.0",
            "Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:22.0) Gecko/20130328 Firefox/22.0",
            "Mozilla/5.0 (Windows NT 6.1; rv:22.0) Gecko/20130405 Firefox/22.0",
            "Mozilla/5.0 (Windows NT 6.2; Win64; x64; rv:16.0.1) Gecko/20121011 Firefox/21.0.1",
            "Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:16.0.1) Gecko/20121011 Firefox/21.0.1",
            "Mozilla/5.0 (Windows NT 6.2; Win64; x64; rv:21.0.0) Gecko/20121011 Firefox/21.0.0",
            "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:21.0) Gecko/20130331 Firefox/21.0",
            "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:21.0) Gecko/20100101 Firefox/21.0",
            "Mozilla/5.0 (X11; Linux i686; rv:21.0) Gecko/20100101 Firefox/21.0",
            "Mozilla/5.0 (Windows NT 6.2; rv:21.0) Gecko/20130326 Firefox/21.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:21.0) Gecko/20130401 Firefox/21.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:21.0) Gecko/20130331 Firefox/21.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:21.0) Gecko/20130330 Firefox/21.0",
            "Mozilla/5.0 (Windows NT 6.1; WOW64; rv:21.0) Gecko/20100101 Firefox/21.0",
            "Mozilla/5.0 (Windows NT 6.1; rv:21.0) Gecko/20130401 Firefox/21.0",
            "Mozilla/5.0 (Windows NT 6.1; rv:21.0) Gecko/20130328 Firefox/21.0",
            "Mozilla/5.0 (Windows NT 6.1; rv:21.0) Gecko/20100101 Firefox/21.0",
            "Mozilla/5.0 (Windows NT 5.1; rv:21.0) Gecko/20130401 Firefox/21.0",
            "Mozilla/5.0 (Windows NT 5.1; rv:21.0) Gecko/20130331 Firefox/21.0",
            "Mozilla/5.0 (Windows NT 5.1; rv:21.0) Gecko/20100101 Firefox/21.0",
            "Mozilla/5.0 (Windows NT 6.3; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/37.0.2049.0 Safari/537.36",
            "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/36.0.1985.67 Safari/537.36",
            "Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/36.0.1985.67 Safari/537.36"
        );
        
        return $user_agents[rand(0, sizeof($user_agents) - 1)];
    }
}
?>