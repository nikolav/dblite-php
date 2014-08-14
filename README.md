dblite-php
==========

lightweight wrapper for [php sqlite3](http://php.net/SQLite3) database
----------------------------------------------------------------------


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
        post_id  integer  null index
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

      printf("epic story: %s\n", $pool->db->q($query));



    usage example #2:

      $b = dblite::start('data/app.sqlite3.db');
      foreach ($b->query("select stuff from tables") as $record)
        process($record);

