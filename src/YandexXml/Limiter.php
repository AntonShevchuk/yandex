<?php

namespace AntonShevchuk\YandexXml;

class Limiter {
  public $hour_limits = array();
  public $hour_requests = array();
  public $last_hour;
  public $user;
  public $key;
  public $last_request_time = 0;
  public $proxy = array();//host,port,user,pass
  const YANDEX_XML_URL = 'https://yandex.ru/search/xml';
  public function __construct($user, $key, $proxy=null) {
    $this->user = $user;
    $this->key = $key;
    if(!is_null($proxy)){
      if(!is_array($proxy)){
        throw new Exception('Proxy should be array with keys: host,port,user,pass', 1);
      }else{
        $this->proxy = $proxy;
      }
    }
    $this->loadLimits();
  }
  // Функции прокси скопированы из anton-shevchuk/yandex-xml-library
  /**
   * Set/Get proxy fo request
   *
   * @param  string $host
   * @param  integer $port
   * @param  string $user
   * @param  string $pass
   * @return Request|array
   */
  public function proxy($host = '', $port = 80, $user = null, $pass = null)
  {
      if (is_null($host)) {
          return $this->getProxy();
      } else {
          return $this->setProxy($host, $port, $user, $pass);
      }
  }

  /**
   * Set proxy for request
   *
   * @param  string $host
   * @param  integer $port
   * @param  string $user
   * @param  string $pass
   * @return Request
   */
  protected function setProxy($host = '', $port = 80, $user = null, $pass = null)
  {
      $this->proxy = array(
          'host' => $host,
          'port' => $port,
          'user' => $user,
          'pass' => $pass,
      );
      return $this;
  }
  /**
   * Apply proxy before each request
   * @param resource $ch
   */
  protected function applyProxy($ch)
  {
      curl_setopt_array(
          $ch,
          array(
              CURLOPT_PROXY => $this->proxy['host'],
              CURLOPT_PROXYPORT => $this->proxy['port'],
              CURLOPT_PROXYUSERPWD => $this->proxy['user'] . ':' . $this->proxy['pass']
          )
      );
  }
  
  public function loadLimits()
  {
    $url = self::YANDEX_XML_URL.'?'
    .http_build_query(array(
      'action' => 'limits-info',
      'user'   => $this->user,
      'key'    => $this->key
    ));
    if(function_exists('curl_version')){
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/xml"));
      curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/xml"));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
      if (!empty($this->proxy['host'])) {
          $this->applyProxy($ch);
      }
      $this->increment();
      $data = curl_exec($ch);
    }else if( ini_get('allow_url_fopen') ) {
      $data = file_get_contents($url);
    }else{
      throw new Exception("url fopen disabled. Curl not enabled. How do we get data?", 1);
    }
    $simpleXML = new \SimpleXMLElement($data);
    if(!is_object($simpleXML)){
      throw new Exception("simpleXML failed to get object", 1);
    }
    if(isset($simpleXML->response->error)&&!empty($simpleXML->response->error)){
      throw new Exception("Yandex XML error: ".$simpleXML->response->error, 1);
    }
    if(isset($simpleXML->response->limits->{'time-interval'})){
      foreach ($simpleXML->response->limits->{'time-interval'} as $limit) {
        $from = (string)$limit->attributes()->from;
        $from_unix = strtotime($from);
        $this->hour_limits[gmdate("G",$from_unix)] = (int)$limit;
      }
    }else{
      throw new Exception("Response limits not set", 1);
    }
  }
  public function getLimit($time=null) {
    if(is_null($time)){
      $time = time();
    }
    if(empty($this->hour_limits)){
      $this->loadLimits();
    }
    $hour = gmdate("G",$time);
    return $this->hour_limits[$hour];
  }
  //https://tech.yandex.ru/xml/doc/dg/concepts/restrictions-docpage/#rps-limits
  public function wait($time=null) {
    if(is_null($time)){
      $time = time();
    }
    if(empty($this->hour_limits)){
      $this->loadLimits();
    }
    $hour = gmdate("G",$time);
    $safe_wait = ceil(2000/$this->hour_limits[$hour]);
    if($this->last_request_time < time()-$safe_wait){
      return;
    }
    $sleeping = ceil(2000/$this->hour_limits[$hour]*1000)*1000;
    if($this->last_request_time-time() > 0){
      $sleeping = $sleeping - (($this->last_request_time-time()) * 1000 * 1000); 
    }
    usleep($sleeping);
    return;
  }
  public function increment($time=null) {
    if(is_null($time)){
      $time = time();
    }
    $hour = gmdate("G",$time);
    if(!isset($this->hour_requests[$hour]) || $this->last_hour !== $hour){
      $this->hour_requests[$hour] = 0;
    }
    $this->hour_requests[$hour] = $this->hour_requests[$hour] + 1;
    $this->last_hour = $hour;
    $this->last_request_time = time();
    return $this->hour_requests[$hour];
  }
  public function hourLimitExceeded($time=null) {
    if(is_null($time)){
      $time = time();
    }
    if(empty($this->hour_limits)){
      $this->loadLimits();
    }
    $hour = gmdate("G",$time);
    if(!isset($this->hour_requests[$hour])){
      $this->hour_requests[$hour] = 0;
    }
    if(!isset($this->hour_limits[$hour])){
      throw new Exception("hourLimitExceeded has no data about requests hour_requests: ".var_export($this->hour_limits,true), 1);
    }
    if($this->hour_requests[$hour] >= $this->hour_limits[$hour]){
      return true;
    }else{
      return false;
    }
  }
  public function nextHourStamp($time=null){
    if(is_null($time)){
      $time = time();
    }
    $time -= time() % 3600;
    return $time+3600;
  }
  public function waitHour($time=null) {
    if(is_null($time)){
      $time = time();
    }
    if($this->hourLimitExceeded($time)){
      return time_sleep_until($this->nextHourStamp($time));
    }else{
      return false;
    }
  }
}