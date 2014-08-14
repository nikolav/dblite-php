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
      
      .close(void) // release cached result, close database connection, nullify internal state
      .conn()
      .db()
      .end(void)
      .exec(string) // == .exec()
      .ins()
      .ls()
      .open(dbconn) // load a database file
      .q() // == .querySingle()
      .query(string) // run a query, save result, set dblite#q status{}
      .res()
      .schema()
    
    static:
      .esc()
      .start()


  dbpool{}:
    
    object:
      
      .db
      
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


// represents single db file
class dblite implements Iterator {

  const IMAX     = 2147483647;
  const ITER_END = false;
  
  static protected $qpool  = array(
    'get_tables' => "select group_concat(tbl_name, ',') from sqlite_master where type = 'table'",
    'p_insert'   => "insert into %s (%s) values (%s)",
    't_exists'   => "select 1 = (select count(*) from sqlite_master where type = 'table' and name = '%s')",
    't_info'     => "pragma table_info('%s')"
  );
  
  private $conn_;
  private $db_;
  private $res_;
  
  private $iter_counter_;
  private $iter_record_;
  
  protected $mode_ = SQLITE3_ASSOC;
  
  public $q;
  public $status;
  
  
  ///////////
  //// magic

  // @param dbconn c
  function __construct (dbconn $c = null) {
    $c && $this->open($c);
  }
  public function __toString () {
    return (string) $this->conn_;
  }
  public function __destruct () {
    $this->close();
    $this->db_ = null;
  }
  
  
  ///////////////
  //// public api

  public function close () {
    
    if ($this->res_) {
      $this->resfree_();
      $this->res_ = null;
    }
    
    $this->db_ && $this->db_->close();

    $this->conn_         = 
    $this->iter_counter_ = 
    $this->iter_record_  = 
    $this->q             = 
    $this->status        = null;

    return $this;
  }
  
  public function conn () {
    return clone $this->conn_;
  }
  
  public function db () {
    return $this->db_;
  }
  
  public function exec ($q) {
    
    $exc = null;
    $this->status_reset_();
    
    try {
      $this->db_->exec($q);
    } catch (Exception $e) {
      $exc = $e;
    }
    
    $this->qstat_($q, $exc);
    
    return $this;
  }
  
  public function ins ($tname, array $input = null) { // input(s)[]

    if ($input) {
      
      $tname = self::esc($tname);
      
      $colnames = $this->columns_($tname);
      $params   = array();

      foreach ($colnames as $cname) 
        $params[(':'. self::rnd_())] = $cname;
      
      $paramkeys = array_keys($params);
      $inserts   = array_flip($paramkeys);
      
      $qprep = sprintf(self::$qpool['p_insert'], 
        $tname, implode(',', $colnames), implode(',', $paramkeys));

      $qs = $this->db_->prepare($qprep);

      foreach ($params as $pname => $cname) 
        $qs->bindParam(substr($pname, 1), $inserts[$pname]);
      
      foreach ($input as $data) {
        
        foreach ($params as $pname => $cname)
          $inserts[$pname] = isset($data[$cname]) ? $data[$cname] : null;
        
        $qs->execute();
      }
      
      $qs->close();
    }
    
    return $this;
  }
  
  public function ls () {
    
    $tables = 
      $this->db_->querySingle(self::$qpool['get_tables'], false);
    
    return $tables ? explode(",", $tables) : array();
  }

  public function open (dbconn $c) {

    if ($this->close()->db_) {
      $this->db_->open($c->path(), $c->mode());
    } else {
      $this->db_ = self::connection_($c);
    }
    
    $this->conn_ = $c;
    
    return $this;
  }
  
  public function q ($q, $as_row = false) {
    
    $this->status_reset_();
    
    $qres = null;
    $exc  = null;
    
    try {
      $qres = $this->db_->querySingle($q, $as_row);
    } catch (Exception $e) {
      $exc = $e;
    }
    
    $this->qstat_($q, $exc);
    
    return $qres;
  }
  
  public function query ($q) {
    
    $this->status_reset_();
    
    $exc = null;
    
    try {
      $this->resfree_();
      $this->res_ = $this->db_->query($q);
    } catch (Exception $e) {
      $exc = $e;
    }

    $this->qstat_($q, $exc);
    
    return $this;
  }
  
  public function res () {
    return $this->res_;
  }

  public function schema ($tname = null) {
    
    $sc = new stdClass;
      
    if (!is_null($tname)) {
      
      $tname = self::esc($tname);

      $sc->schema = 
        ($sc->exists = $this->t_exists_($tname)) ? 
        $this->t_schema_($tname) : null;
      
      return $sc;
    }
    
    $tables = $this->ls();
    
    foreach ($tables as $tname)
      $sc->{$tname} = $this->t_schema_($tname);
    
    return $sc;
  }


  ///////////////
  //// protected
  
  protected function columns_ ($tname) {

    $cols   = array();
    $rtinfo = $this->db_->query(sprintf(self::$qpool['t_info'], $tname));
    
    for (
      $stop = self::ITER_END, $assoc = SQLITE3_ASSOC, $info = null;
      $stop !== ($info = $rtinfo->fetchArray($assoc));
      array_push($cols, $info['name']));
    
    $rtinfo->finalize();

    return $cols;
  }
      
  protected function resfree_ () {
    $this->res_ && $this->res_->finalize();
  }
  
  protected function status_reset_ () {
    $this->status = -1;
  }
  
  protected function qstat_ ($q, $e) {
    
    $this->q = new qstatus(
      $this->db_->lastErrorMsg(), 
      $this->db_->lastErrorCode(), 
      $q, 
      $this->db_->changes(), 
      $this->db_->lastInsertRowID(), 
      $e);
    
    $this->status = $this->q->code;
  }
  
  protected function t_exists_ ($tname) {
    return !!$this->db_->querySingle(sprintf(self::$qpool['t_exists'], self::esc($tname)), false);
  }

  protected function t_schema_ ($tname) {
    
    $tsc     = new stdClass;
    $res_tsc = $this->db_->query(sprintf(self::$qpool['t_info'], $tname));
    
    for (
      $stop = self::ITER_END, $inf = null, $rec = null, $assoc = SQLITE3_ASSOC; 
      $stop !== ($rec = $res_tsc->fetchArray($assoc));
    ) {

      $tsc->{$rec['name']} = $inf = new stdClass;
      $inf->type           = $rec['type'];
      
      $rec['pk']         && ($inf->primary_key   = true);
      $rec['notnull']    && ($inf->not_null      = true);
      $rec['dflt_value'] && ($inf->default_value = $rec['dflt_value']);
    }
    
    $res_tsc->finalize();

    return $tsc;
  }
  
  ///////////////////
  //// static public
  
  static public function esc ($s) {
    return SQLite3::escapeString((string) $s);
  }
  static public function start ($dbpath, $mode = null) {
    return new self(new dbconn($dbpath, $mode));
  }
  
  //////////////////////
  //// static protected
  
  static protected function connection_ (dbconn $c) {
    return new SQLite3($c->path(), $c->mode());
  }
  
  static protected function rnd_ ($prefix = 'b') {
    return $prefix . base_convert(mt_rand(1, self::IMAX), 10, 36);
  }
  
  //////////////
  //// Iterator
  
  public function current () {
    return $this->iter_record_;
  }
  public function key () {
    return $this->iter_counter_;
  }
  public function next () {
    $this->iter_counter_ += 1;
  }
  public function rewind () {
    $this->res_->reset();
    $this->iter_counter_ = 0;
  }
  public function valid () {
    
    $valid_ = true;
    $next   = $this->res_->fetchArray($this->mode_);
    
    if (self::ITER_END !== $next) {
      
      $this->iter_record_ = (object) $next;
    } else {
      
      $valid_              = self::ITER_END;
      $this->iter_counter_ = 
      $this->iter_record_  = null;
    }
    
    return $valid_;
  }
}

// represents a list of database connections
class dbpool implements Countable, ArrayAccess, Iterator {
  
  static private $p_ = null;
  
  private $pool_ = array();
  private $npool_;
  
  public $db;
  
  // #magic
  //
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
  
  public function __set_state (array $props = array()) {
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
  
  
  // #Countable
  //
  public function count () {
    return count($this->pool_);
  }
  
  
  // #ArrayAccess
  //
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
  
  
  // #Iterator
  //
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
  
  
  // #public api
  //
  
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
  
  // full gc
  protected function destroy () {
    
    $this->db = null;
    
    foreach ($this->pool_ as $n => $b) {
      $b->close();
      unset($this->pool_[$n]);
    }
    
    return true;
  }
  
  // singleton
  static public function init () {
    return self::$p_ ? self::$p_ : (self::$p_ = new self);
  }
}

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
