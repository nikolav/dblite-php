<?php

namespace nikolav\dblite;

// represents success status of a query
// used internaly to create `dblite#q ` query status{}
class qstatus {
  
    public $changes;
    public $code;
    public $exc;
    public $inser_id;
    public $last_query;
    public $message;
    
    function __construct($msg, $msg_code = null, $q = null, 
      $numchanges = null, $insert_id = null, $exc = null) {
      
      $this->changes    = $numchanges;
      $this->code       = $msg_code;
      $this->inser_id   = $insert_id;
      $this->last_query = $q;
      $this->message    = $msg;
      $this->exc        = $exc;
    }
  }

//eof
