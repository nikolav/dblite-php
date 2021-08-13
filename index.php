<?php

use nikolav\dblite\dblite;
use nikolav\dblite\dbpool;
use nikolav\dblite\dbconn;
use nikolav\dblite\qstatus;


require __DIR__ . './vendor/autoload.php';

$bp         = dbpool::init();
$bp->maindb = dblite::start('./main.db');

$bp->active('maindb');


$q = <<<EOQ_
select count(*) as tot from main
EOQ_;
// $bp->db->exec($q);

printf("[# of records]: %s", $bp->db->q($q));

exit;
//eof
