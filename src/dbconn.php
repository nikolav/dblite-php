<?php

namespace nikolav\dblite;

// represents database filepath
// passed to dblite ctor
class dbconn {

    const R  = SQLITE3_OPEN_READONLY;
    const RW = SQLITE3_OPEN_READWRITE;
    const X  = SQLITE3_OPEN_CREATE;
  
    private $dbpath_ = "";
    private $mode_   = null;
  
    ///////////
    //// magic
  
    // @param String, 'path/to/db/file.db'
    function __construct($path2db, $mode = null) {
      $this->dbpath_ = (string) $path2db;
      $this->mode_   = is_null($mode) ? (self::X | self::RW) : (int) $mode;
    }
    
    public function __toString() {
      return $this->path();
    }
    
    ////////////
    //// public
  
    public function path() {
      return $this->dbpath_;
    }
    public function mode() {
      return $this->mode_;
    }
  }

//eof
