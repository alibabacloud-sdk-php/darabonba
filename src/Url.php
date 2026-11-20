<?php

namespace AlibabaCloud\Dara;

class Url
{

    private $url = '';

    private $path = '';

    private $pathname = '';

    private $protocol = '';

    private $hostname = '';

    private $host = '';

    private $port = '';

    private $hash = '';

    private $search = '';

    private $auth = '';
    
    public function __construct($str) {
        if (!is_string($str)) {
            throw new Exception\DaraException(['code' => 'InvalidURL', 'message' => 'URL must be a string']);
        }
        $parts = parse_url($str);
        if ($parts === false) {
            throw new Exception\DaraException(['code' => 'InvalidURL', 'message' => 'URL cannot be parsed']);
        }
        $this->url = $str;
        $this->protocol = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $this->hostname = isset($parts['host']) ? $parts['host'] : '';
        $this->port = isset($parts['port']) ? (string)$parts['port'] : '';
        $this->host = $this->hostname . ($this->port !== '' ? ':' . $this->port : '');
        $this->pathname = isset($parts['path']) ? $parts['path'] : '';
        $this->search = isset($parts['query']) ? '?' . $parts['query'] : '';
        $this->hash = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        if (isset($parts['user']) || isset($parts['pass'])) {
            $this->auth = (isset($parts['user']) ? $parts['user'] : '') . ':' . (isset($parts['pass']) ? $parts['pass'] : '');
        }
        $this->path = $this->pathname . $this->search;
    }
    
    public function path() {
        return $this->path;
    }
    
    public function pathname() {
        return $this->pathname;
    }
    
    public function protocol() {
        return $this->protocol;
    }
    
    public function hostname() {
        return $this->hostname;
    }
    
    public function host() {
        return $this->host;
    }
    
    public function port() {
        return $this->port;
    }
    
    public function hash() {
        return $this->hash;
    }
    
    public function search() {
        return $this->search;
    }
    
    public function href() {
        return $this->url;
    }
    
    public function auth() {
        return $this->auth;
    }
    
    public static function parse($url) {
        return new self($url);
    }
    
    public static function urlEncode($url) {
        if (empty($raw)) {
            throw new \InvalidArgumentException('not a valid value for parameter');
        }
        $str = urlencode($raw);
        $str = str_replace("%20", "+", $str);
        $str = str_replace("%2A", "*", $str);
        return $str;
    }
    
    public static function percentEncode($raw) {
        if($raw === null) {
            return null;
        }
        $encoded = urlencode($raw);
        $encoded = str_replace('+', '%20', $encoded);
        $encoded = str_replace('*', '%2A', $encoded);
        $encoded = str_replace('%7E', '~', $encoded);
        return $encoded;
    }
    
    public static function pathEncode($path) {
        if (empty($raw) || $raw === '/') {
            return $raw;
        }
        $arr = explode('/', $raw);
        $ret = '';
        foreach ($arr as $i => $path) {
            $str = self::percentEncode($path);
            $ret .= "$str/";
        }
        return substr($ret, 0, -1);
    }

}
