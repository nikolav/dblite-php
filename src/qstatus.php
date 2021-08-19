<?php

namespace nikolav\dblite;

// represents success status of a query
// used internaly to create `dblite#q ` query status{}
class qstatus {
  
    public $changes;
    public $code;
    public $error;
    public $insert_id;
    public $last_query;
    public $message;
    
    function __construct($msg, $msg_code = null, $q = null, 
      $numchanges = null, $insert_id = null, $exc = null) {
      
      $this->changes    = $numchanges;
      $this->code       = $msg_code;
      $this->insert_id  = $insert_id;
      $this->last_query = $q;
      $this->message    = $msg;
      $this->error      = $exc;
    }
  }

//eof
