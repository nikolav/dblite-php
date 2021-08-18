<?php

namespace nikolav\dblite;

use \stdClass;
use \Exception;

/*********************************************************************

dblite.php
author  : vukovic nikola
email   : admin@nikolav.rs
github  : https://github.com/nikolav/dblite-php
license : public

deps:
  php5+
  SQLite3


about:
  sqlite3 wrapper
  Iterator<dblite>


APIs:

  dblite{}:
    
    instance:
      
      .q      // holds qstatus{} with last query stats
      .status // 'success-flag' for last query (0 'OK', other '!OK')
      
      .close(void)           // release cached result, close db connection, 0-fy dblite{} state
      .exec(string)          // SQLite3#exec()
      .ins(string, matrix2D) // insert data
      .ls(void)              // list tables
      .open(dbconn)          // load a database file
      .q(string)             // SQLite3#querySingle()
      .query(string)         // query, save result, set dblite#q{} query status
      .schema([string])      // get table description{}('s)
    
    static:
      .esc(string)           // SQLite3#escapeString
      .start(string [, int]) // factory


  dbpool{}:
    
    instance:
      
      .db // active dblite{}
      
      .ls(void)          // list database aliases
      .main(string)      // set dblite{} aliased by parameter as active
      .rm([, ...string]) // remove(all) specified dblite{}('s) by alias(es)
    
    static:
      .init(void)  // fetch dbpool singleton
  
  
  dbconn{}:
    
    instance:
      
      .mode(void) // getter
      .path(void) // getter
  
  
  qstatus{}
    
    instance: 
      
      .changes     // (int) affected rows
      .code        // (int) error code
      .exc         // (Exception or null) error thrown
      .inser_id    // (int)
      .last_query  // (string)
      .message     // (string) error message



usage example #1:


// get singleton database-list instance
// to be populated with db connection objects
$pool = dbpool::init();

// populate it with sample db objects
$pool->app_admin = dblite::start('data/app_admin.sqlite3.db');
$pool->app_forum = dblite::start('data/app_forum.sqlite3.db');
$pool->app_x     = dblite::start('data/app_x.sqlite3.db');


// load admin database, send sql, loop results
$pool->main('app_admin');
foreach ($pool->db->query('select stuff from admin_tables') as $data) 
  process($data);

// load, crud, loop, etc.
$pool->main('app_forum');

// sample post table schema:
create table 
  tbl_post (
    id       integer  primary key,
    title    text     not null,
    content  text     not null,
    post_id  integer  null
  );

$query = <<< EOQ

  select
    the_title
  from (
    select
      p.id, p.title as the_title, count(c.id) as tot
    from
      tbl_post as p
    join
      tbl_post as c
        on p.id = c.post_id
    where
      p.post_id is null
    group by
      p.id
    order by
      tot desc
    limit 1
  );

EOQ;

  printf("epic story: <a>%s</a>\n", $pool->db->q($query));



usage example #2:
  
  
  $b = dblite::start('data/app.sqlite3.db');
  foreach ($b->query("select stuff from tables") as $record) 
    process($record);


*********************************************************************/

use \Iterator;
use \SQLite3;

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

// eof
