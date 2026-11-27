<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_OFF);
$c = new mysqli('localhost', 'root', '', 'attendance_db');
echo "Tables: ";
$r = $c->query('SHOW TABLES');
while ($t = $r->fetch_row()) echo $t[0] . ' ';
echo '<br>Users: ';
$r = $c->query('SELECT COUNT(*) FROM users');
echo $r ? $r->fetch_row()[0] : 'ERROR ' . $c->error;
echo '<br>Now loading config.php...<br>';
require 'config.php';
echo 'config.php OK';