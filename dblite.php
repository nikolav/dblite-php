<?php

/**********************************************************************

dblite.php
author  : vukovic nikola
email   : vukoivcnikola2014@gmail.com
github  : 
license : public domain, do what you want I don't care if it breaks something

deps:
  php5+
  SQLite3


description:
  sqlite3 wrapper
  iterable of database objects


APIs:

  dblite{}:
    
    object:
      
      .q         // holds qstatus{} with last query stats
      .status    // flag for last query runned (0 for 'OK', other for '!OK')
      
      .close()
      .conn()
      .db()
      .end()
      .exec()
      .ins()
      .ls()
      .mode()
      .open()
      .q()
      .query()
      .res()
      .schema()
    
    static:
      .esc()
      .start()


  dbpool{}:
    
    object:
      
      .db
      
      .destroy()
      .ls()
      .main()
      .rm()
    
    static:
      .init()  // fetch dbpool singleton
  
  
  dbconn{}:
    
    object:
      
      .mode(void)  // getter
      .path(void)  // getter
  
  
  qstatus{}
    
    object: 
      
      .changes     //
      .code        //
      .exc         //
      .inser_id    //
      .last_query  //
      .message     //




usage example #1:


// get singleton database-list instance
// to be populated with db connection objects
$pool = dbpool::init();

// populate it with sample db objects
$pool->admin     = dblite::start('data/admin.sqlite3.db');
$pool->app_forum = dblite::start('data/app_forum.sqlite3.db');
$pool->app_x     = dblite::start('data/app_x.sqlite3.db');

// load admin database
// send sql 
// loop results
$pool->main('admin');
foreach ($pool->db->query('select stuff from admin_tables') as $data) 
  printf("%s: [%s]\n", $, $data->name, $data->email);

// load, crud, loop
$pool->main('app_forum');

// sample table:
//   tbl_forum_post {id, title, content, user_id}

$query = <<< EOQ

select 
  title, count(id) as tot
from
  tbl_forum_post
group by
  title
order by
  tot desc
limit 1

EOQ;

  printf("story of the day: <a>%s</a>\n", $pool->db->q($query));




usage example #2:
  
  
  $b = dblite::start('data/app.sqlite3.db');
  foreach ($b->query("select stuff from tables") as $record) 
    process($record);


**********************************************************************/


// represents database filepath
// passed to dblite ctor
class dbconn {
  
  const R  = SQLITE3_OPEN_READONLY;
  const RW = SQLITE3_OPEN_READWRITE;
  const X  = SQLITE3_OPEN_CREATE;
  
  private $dbpath_ = "";
  private $mode_   = null;
  
  // @param String, 'path/to/db/file.db'
  function __construct($path2db, $mode = null) {
    $this->dbpath_ = (string) $path2db;
    $this->mode_   = is_null($mode) ? (self::X | self::RW) : (int) $mode;
  }
  
  public function path() {
    return $this->dbpath_;
  }
  public function mode() {
    return $this->mode_;
  }
  public function __toString() {
    return $this->path();
  }
}

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

// represents single db file
class dblite implements Iterator {
  
  const IMAX     = 2147483647;
  const ITER_END = false;
  
  static protected $qpool  = array(
    'get_tables' => "select group_concat(tbl_name, ',') from sqlite_master where type = 'table'"
  );
  
  private $db_   = null;
  private $res_  = null;
  private $conn_ = null;
  
  private $fetch_mode_ = SQLITE3_ASSOC;
  
  private $iter_counter_ = null;
  private $iter_record_  = null;
  
  public $q      = null;
  public $status = null;
  
  
  ///////////
  //// magic

  // @param dbconn c
  function __construct(dbconn $c = null) {
    if ($c) $this->open($c);
  }
  public function __toString() {
    return (string) $this->conn_;
  }
  public function __destruct() {
    $this->end();
  }
  
  
  ///////////////
  //// public api
  
  public function open(dbconn $c) {
    
    $this->close();
    
    if ($this->db_) {
      $this->db_->open($c->path(), $c->mode());
    } else {
      $this->db_ = self::connection($c);
    }
    
    $this->conn_ = $c;
    
    return $this;
  }
  public function close() {
    
    if ($this->res_) {
      $this->resfree_();
      $this->res_ = null;
    }
    
    if ($this->db_) {
      $this->db_->close();
    }
    
    $this->q     = null;
    $this->conn_ = null;
    
    return $this;
  }
  public function end() {
    $this->close();
    $this->db_ = null;
  }
  public function query($q) {
    
    $exc = null;
    
    $this->status_reset_();
    
    if ($this->conn_) {
      
      try {
        
        $this->resfree_();
        $this->res_ = $this->db_->query($q);
      } catch (Exception $e) {

        $exc = $e;
      }
      
    }
    
    $this->qstat_($q, $exc);
    
    return $this;
  }
  public function q ($q, $as_row = false) {
    
    $res = null;
    $exc = null;
    $this->status_reset_();
    
    if ($this->conn_) {
      try {
        $res = $this->db_->querySingle($q, $as_row);
      } catch (Exception $xc) {
        $exc = $xc;
      }
    }
    
    $this->qstat_($q, $exc);
    
    return $res;
  }
  public function exec ($q) {
    
    $exc = null;
    $this->status_reset_();
    
    if ($this->conn_) {
      try {
        $this->db_->exec($q);
      } catch (Exception $xc) {
        $exc = $xc;
      }
    }
    
    $this->qstat_($q, $exc);
    
    return $this;
  }
  public function schema ($tbl = null) {
    
    $s = new stdClass;
    
    if ($this->conn_) {
      
      if (!is_null($tbl)) {
        
        $tbl = (string) $tbl;

        $s->schema = 
          ($s->exists = $this->tbl_exists_($tbl)) ? 
          $this->tbl_schema_($tbl) : null;
        
        return $s;
      }
      
      $tables = $this->ls();
      
      if (!empty($tables)) {
        foreach ($tables as $tname)
          $s->{$tname} = $this->tbl_schema_($tname);
      }
    }
    
    return $s;
  }
  public function mode ($m = null) {
    if (is_null($m)) {
      return $this->fetch_mode_;
    } else {
      return $this->fetch_mode_ = (int) $m;
    }
  }
  public function ls () {
    
    $tables = 
      $this->db_->querySingle(self::$qpool['get_tables'], false);
    
    return $tables ? explode(",", $tables) : array();
  }
  public function ins ($tname, array $input = null) { // , ... array(s) $input(s)

    if ($input) {
      
      $tname = self::esc($tname);
      
      $colnames = $this->columns_($tname);
      $params   = array();

      foreach ($colnames as $cname) 
        $params[(':'. $this->rnd_())] = $cname;
      
      $paramskeys   = array_keys($params);
      $insert_value = array_flip($paramskeys);
      
      $qprep = 
        'insert into '. $tname .
        ' ('. implode(',', $colnames) .')'.
        ' values ('. implode(',', $paramskeys) .')';

      $qs = $this->db_->prepare($qprep);

      foreach ($params as $pname => $cname) 
        $qs->bindParam(substr($pname, 1), $insert_value[$pname]);
      
      foreach ($input as $data) {
        
        foreach ($params as $pname => $cname)
          $insert_value[$pname] = isset($data[$cname]) ? $data[$cname] : null;
        
        $qs->execute();
      }
      
      $qs->clear();
      $qs->close();
      $qs = null;
    }
    
    return $this;
  }
  public function db() {
    return $this->db_;
  }
  public function res() {
    return $this->res_;
  }
  public function conn() {
    return clone $this->conn_;
  }


  ///////////////
  //// protected
  
  protected function columns_ ($tname) {

    $cols   = array();
    $rtinfo = $this->db_->query('pragma table_info('. $tname .')');
    
    for (
      $stop = self::ITER_END, $assoc = SQLITE3_ASSOC, $info = null;
      $stop !== ($info = $rtinfo->fetchArray($assoc));
      array_push($cols, $info['name']));
    
    $rtinfo->finalize();

    return $cols;
  }
  protected function rnd_ ($prefix = 'b') {
    return $prefix . base_convert((rand() * self::IMAX), 10, 36);
  }
  protected function resfree_() {
    if ($this->res_ instanceof SQLite3Result) {
      $this->res_->finalize();
    }
  }
  protected function status_reset_() {
    $this->status = 1;
  }
  protected function qstat_($q, $e) {
    
    $this->q = new qstatus(
      $this->db_->lastErrorMsg(), 
      $this->db_->lastErrorCode(), 
      $q, 
      $this->db_->changes(), 
      $this->db_->lastInsertRowID(), 
      $e);
    
    $this->status = $this->q->code;
  }
  protected function tbl_exists_($tbl) {
    return !!$this->db_->querySingle(
      "select 1 = (select count(*) from sqlite_master where type = 'table' and name = '". 
      self::esc($tbl) . "')", false);
  }
  protected function tbl_schema_($tbl) {
    
    $s   = new stdClass;
    $inf = null; // field (inf)o

    foreach ($this->query("pragma table_info('" . self::esc($tbl) . "')") as $r) {
      
      $s->{$r->name} = $inf = new stdClass;
      
      $inf->type = $r->type;
      
      $r->pk && ($inf->primary_key   = true);
      $r->notnull && ($inf->not_null = true);
      $r->dflt_value && ($inf->default_value = $r->dflt_value);
    }

    return $s;
  }
  
  ///////////////////
  //// static public
  
  static public function esc($s) {
    return SQLite3::escapeString((string) $s);
  }
  static public function start($dbpath, $mode = null) {
    return new self(new dbconn($dbpath, $mode));
  }
  
  //////////////////////
  //// static protected
  
  static protected function connection(dbconn $c) {
    return new SQLite3($c->path(), $c->mode());
  }
  
  // #Iterator
  //
  public function current() {
    return $this->iter_record_;
  }
  public function key() {
    return $this->iter_counter_;
  }
  public function next() {
    $this->iter_counter_ += 1;
  }
  public function rewind() {
    $this->res_->reset();
    $this->iter_counter_ = 0;
  }
  public function valid() {
    
    $valid_ = true;
    $next   = $this->res_->fetchArray($this->mode());
    
    if (self::ITER_END !== $next) {
      
      $this->iter_record_ = (object) $next;
    } else {
      
      $valid_              = self::ITER_END;
      $this->iter_counter_ = null;
      $this->iter_record_  = null;
    }
    
    return $valid_;
  }
}

// represents a list of database connections
class dbpool implements Countable, ArrayAccess, Iterator {
  
  static private $p_ = null;
  
  private $pool_ = array();
  private $npool_ = null;
  
  public $db = null;
  
  
  // #magic
  //
  private function __construct() {}
  
  public function __clone() {
    throw new Exception("not supported", 1);
  }
  
  public function __destruct() {
    $this->destroy();
  }
  
  public function __get($bname) {
    return isset($this->pool_[$bname]) ? $this->pool_[$bname] : null;
  }
  
  public function __invoke($action) { // , ...rest
    return call_user_func_array(array(
      $this->db,
      $action
    ), array_slice(func_get_args(), 1));
  }
  
  public function __isset($bname) {
    return isset($this->pool_[$bname]);
  }
  
  public function __set($bname, dblite $b) {
    
    $ismain = false;
    
    if (isset($this->pool_[$bname])) {
      if ($this->db === $this->pool_[$bname])
        $ismain = true;
      $this->pool_[$bname]->end();
      //$this->db->end();
    }
    
    $this->pool_[$bname] = $b;
    
    if ($ismain)
      $this->db = $this->pool_[$bname];
  }
  
  public function __set_state(array $props = array()) {
    throw new Exception("not supported", 1);
  }
  
  public function __sleep() {
    throw new Exception("not supported", 1);
  }
  
  public function __toString() {
    
    $out = array();
    
    foreach ($this->pool_ as $n => $b)
      array_push($out, "({$n}){$b}");
    
    return implode(" ", $out);
    
  }
  
  public function __unset($bname) {
    $this->rm($bname);
  }
  
  public function __wakeup() {
    throw new Exception("not supported", 1);
  }
  
  
  // #Countable
  //
  public function count() {
    return count($this->pool_);
  }
  
  
  // #ArrayAccess
  //
  public function offsetExists($n) {
    return array_key_exists($n, $this->pool_);
  }
  
  public function offsetGet($n) {
    return $this->{$n};
  }
  
  public function offsetSet($n, $b) {
    $this->{$n} = $b;
  }
  
  public function offsetUnset($n) {
    unset($this->{$n});
  }
  
  
  // #Iterator
  //
  public function current() {
    return $this->pool_[current($this->npool_)];
  }
  
  public function key() {
    return key($this->npool_);
  }
  
  public function next() {
    next($this->npool_);
  }
  
  public function rewind() {
    $this->npool_ = array_keys($this->pool_);
    reset($this->npool_);
  }
  
  public function valid() {
    return !is_null($this->key());
  }
  
  
  
  // #public api
  //
  
  // select a database for use by its name
  public function main($bname) {
    if (isset($this->pool_[$bname])) {
      $this->db = $this->pool_[$bname];
    } else {
      throw new Exception("undefined [" . (string) $bname . "] database", 1);
    }
  }
  
  // show loaded database names
  public function ls() {
    return array_keys($this->pool_);
  }
  
  // remove db objects by names specified
  public function rm() {
    
    $bargs = func_get_args();
    
    if (count($bargs) == 0)
      return $this->destroy();
    
    foreach ($bargs as $bname) {
      if (isset($this->pool_[$bname])) {
        if ($this->db === $this->pool_[$bname])
          $this->db = null;
        $this->pool_[$bname]->end();
        unset($this->pool_[$bname]);
      }
    }
    
    return true;
  }
  
  // full gc
  protected function destroy() {
    
    $this->db = null;
    
    foreach ($this->pool_ as $n => $b) {
      $b->end();
      unset($this->pool_[$n]);
    }
    
    return true;
  }
  
  // singleton
  static public function init() {
    return self::$p_ ? self::$p_ : (self::$p_ = new self);
  }
}
