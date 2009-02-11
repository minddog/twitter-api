<?php;
/*
 * TwitterAPI - An up to date fully implemented api client for Twitter
 *
 * Copyright(c) 2009 Adam Ballai <aballai@gmail.com>
 *
 * Example Usage
 * $api_host = 'twitter.com';
 * $api_username = 'xxxxxxxx';
 * $api_password = 'xxxxxxxx';
 * $cache_config['memcache_servers'] = array("localhost");
 * $cache_config['memcache_port'] = 11211;
 * $cache_config['key_prefix'] = "twitter";
 *
 * $api = new TwitterAPI($api_host,
 *                       $api_username,
 *                       $api_password,
 *                       $cache_config);
 * $result = $api->statuses->followers(array('page' => 1));
 * var_dump($result);
 *
 */

class TwitterAPIError extends Exception {}

class TwitterAPI {
    private static $curl = null;
    
    public function __construct($api_host,
                                $api_username,
                                $api_password,
                                $cache_config = NULL)
    {

        $this->statuses = new TwitterAPINSWrapper($this, 'statuses');
        $this->users = new TwitterAPINSWrapper($this, 'users');
        $this->friends = new TwitterAPINSWrapper($this, 'friends');
        
        $this->api_username = $api_username; 
        $this->api_password = $api_password;
        $this->api_host = $api_host;
        if(!empty($cache_config)) {
            $this->setup_memcache($cache_config['memcache_servers'],
                                  $cache_config['memcache_port'],
                                  $cache_config['key_prefix']);
        }
    }

    public function setup_memcache($memcache_servers, $memcache_port, $key_prefix) {
        $this->memcache = new Memcache();
        foreach ($memcache_servers as $memcache_server) {
            $this->memcache->addServer($memcache_server, $memcache_port);
        }
        $this->key_prefix = $key_prefix;
    }

    public function build_key($url, $req_per_hour=1) {
        $stamp = intval(time() * ($req_per_hour / 3600));
        return $this->key_prefix . ':' . $stamp . ':' . $url;
    }

    function fetch($url, $req_per_hour=1) {
        if(!$this->memcache) {
            return $this->perform_request($url);
        }
        
        $key = $this->build_key($url, $req_per_hour);
        $value = $this->memcache->get($key);
        if (!$value) {
            $value = $this->perform_request($url);
            if (!$value) return null;
            $value = json_encode($value);
            $this->memcache->set($key, $value);
        }
        if (!$value) return null;
        return json_decode($value, true);
    }

    public function perform_request($url) {
        // Send the HTTP request.
        curl_setopt(self::$curl, CURLOPT_URL, $url);
        curl_setopt(self::$curl, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec(self::$curl);
        
        // Throw an exception on connection failure.
        if (!$response) throw new TwitterAPIError('Connection failed');
        
        // Deserialize the response string and store the result.
        $result = json_decode($response);
        
        return $result;
    }
    
    public function __call($method, $args) {
        static $api_cumulative_time = 0;
        $time = microtime(true);
        
        // Initialize CURL if called for the first time.
        if (is_null(self::$curl)) {
            self::$curl = curl_init();
        }
        
        $namespace = 'statuses';
        if (isset($args[0])) {
            $namespace = $args[0];
            unset($args[0]);
        }

        if(isset($args[1])) {
            $args = $args[1];
        }

        $id = NULL;
        if(isset($args["id"])) {
            $id = $args["id"];
            unset($args["id"]);
        }



        // Build the base URL.
        curl_setopt(self::$curl,
                    CURLOPT_USERPWD,
                    "{$this->api_username}:{$this->api_password}");
        curl_setopt(self::$curl, CURLOPT_POST, 1);
        curl_setopt(self::$curl, CURLOPT_POSTFIELDS, http_build_query($args));
        
        $url = ('http://' . $this->api_host . '/'
                . $namespace .'/'. $method
                . (isset($id)? '/'. $id : '')
                . '.json'
                . '?' . http_build_query($args));
        
        $result = $this->fetch($url);
        
        // If the result is a hash containing a key called 'error', assume
        // that an error occurred on the other end and throw an exception.
        // if (isset($result['error'])) {
            // throw new TwitterAPIError($result['error'], $result['code']);
        // } else {
            // return $result['result'];
        // }
        return $result;
    }

}

class TwitterAPINSWrapper {
    private $object;
    private $ns;
    
    function __construct($obj, $ns) {
        $this->object = $obj;
        $this->ns = $ns;
    }
    
    function __call($method, $args) {
        $args = array_merge(array($this->ns), $args);
        return call_user_func_array(array($this->object, $method), $args);
    }
}

?>