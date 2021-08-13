<?php

namespace nikolav\dblite;

use \Countable;
use \ArrayAccess;
use \Iterator;

// represents a list of database connections
class dbpool implements Countable, ArrayAccess, Iterator {
  
    static private $p_ = null;
    
    private $pool_ = array();
    private $npool_;
    
    public $db;
    
    ///////////
    //// magic
    
    private function __construct () {}
    
    public function __clone () {
      throw new Exception("not supported", 1);
    }
    
    public function __destruct () {
      $this->destroy();
    }
    
    public function __get ($bname) {
      return isset($this->pool_[$bname]) ? $this->pool_[$bname] : null;
    }
    
    public function __invoke ($action) { // , ...rest
      return call_user_func_array(array(
        $this->db,
        $action
      ), array_slice(func_get_args(), 1));
    }
    
    public function __isset ($bname) {
      return isset($this->pool_[$bname]);
    }
    
    public function __set ($bname, dblite $b) {
      
      if (isset($this->pool_[$bname])) 
        $this->pool_[$bname]->close();
      
      $this->pool_[$bname] = $b;
    }
    
    // public function __set_state (array $props = array()) {
    //   throw new Exception("not supported", 1);
    // }
    static public function __set_state (array $props = array()) {
      throw new Exception("not supported", 1);
    }
    
    public function __sleep () {
      throw new Exception("not supported", 1);
    }
    
    public function __toString () {
      
      $out = array();
      
      foreach ($this->pool_ as $n => $b)
        array_push($out, "({$n}){$b}");
      
      return implode(" ", $out);
      
    }
    
    public function __unset ($bname) {
      $this->rm($bname);
    }
    
    public function __wakeup () {
      throw new Exception("not supported", 1);
    }
    
    //////////////
    //// Countable
    
    public function count () {
      return count($this->pool_);
    }
    
    ////////////////
    //// ArrayAccess
  
    public function offsetExists ($n) {
      return array_key_exists($n, $this->pool_);
    }
    
    public function offsetGet ($n) {
      return $this->{$n};
    }
    
    public function offsetSet ($n, $b) {
      $this->{$n} = $b;
    }
    
    public function offsetUnset ($n) {
      unset($this->{$n});
    }
    
    //////////////
    //// Iterator
    
    public function current () {
      return $this->pool_[current($this->npool_)];
    }
    
    public function key () {
      return key($this->npool_);
    }
    
    public function next () {
      next($this->npool_);
    }
    
    public function rewind () {
      $this->npool_ = array_keys($this->pool_);
      reset($this->npool_);
    }
    
    public function valid () {
      return !is_null($this->key());
    }
    
    ////////////////
    //// public api
    
    // select a database for use by its name
    public function main ($bname) {
      
      $bname = (string) $bname;
      
      if (isset($this->pool_[$bname])) {
        $this->db && $this->db->close();
        $this->db = $this->pool_[$bname];
      } else {
        throw new Exception("undefined [" . $bname . "] database", 1);
      }
    }
  
    // method alias for .main()
    public function active ($bname) {
      $this->main($bname);
    }
    
    // show loaded database names
    public function ls () {
      return array_keys($this->pool_);
    }
    
    // remove db objects by names specified
    public function rm () {
      
      $bargs = func_get_args();
      
      if ($bargs) {
        foreach ($bargs as $bname) {
          if (isset($this->pool_[$bname])) {
            if ($this->db === $this->pool_[$bname]) $this->db = null;
            $this->pool_[$bname]->close();
            unset($this->pool_[$bname]);
          }
        }
      } else {
        $this->destroy();
      }
      
      return true;
    }
    
    ///////////////
    //// protected
    
    // full gc
    protected function destroy () {
      
      $this->db = null;
      
      foreach ($this->pool_ as $n => $b) {
        $b->close();
        unset($this->pool_[$n]);
      }
      
      return true;
    }
    
    ///////////////////
    //// static public
    
    // singleton
    static public function init () {
      return self::$p_ ? self::$p_ : (self::$p_ = new self);
    }
  }

//eof
