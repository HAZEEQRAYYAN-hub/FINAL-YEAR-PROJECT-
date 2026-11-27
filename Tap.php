<?php
// NodeMCU calls: http://PC-IP/attendance/tap.php?key=SECRET&uid=CARDUID&name=TEXTONCARD[&ts=UNIXTIME]
require 'config.php';
header('Content-Type: text/plain');
$uid=strtoupper(trim($_GET['uid']??''));$txt=trim($_GET['name']??'');
if(!hash_equals((string)TAP_KEY,(string)($_GET['key']??''))||($uid===''&&$txt===''))die('Invalid request');

// office times: set by the admin on the Settings page (config.php values are only the fallback)
$S=['clock_in_from'=>EARLIEST,'work_start'=>WORK_START,'grace'=>LATE_GRACE,'clock_out_from'=>WORK_END,'work_end'=>WORK_END,'office_close'=>OFFICE_CLOSE,'break_from'=>'13:00','break_to'=>'14:00'];
try{foreach(rows(q('SELECT k,v FROM settings')) as $r)$S[$r['k']]=$r['v'];}catch(mysqli_sql_exception $x){}

// time of the tap: now, or the saved time of a tap made while Wi-Fi was down
$ts=time();$saved=isset($_GET['ts'])&&$_GET['ts']!=='';
if($saved){$t=(int)$_GET['ts'];if($t<time()-7*86400||$t>time()+300)die('Invalid time');$ts=min($t,time());}
$today=date('Y-m-d',$ts);$now=date('H:i:s',$ts);$nm=mins($now);

// find the person: card UID first, then the old text-on-card ID
$U=null;
foreach(array_unique(array_filter([$uid,$txt],'strlen')) as $c){
  $U=rows(q('SELECT id,name,active,start_date,end_date FROM users WHERE card_id=?','s',$c))[0]??null;
  if($U)break;
}

if(!$U){                                         // unknown card: maybe the admin is enrolling a new card
  $P=($uid!==''&&!$saved)?(rows(q('SELECT * FROM pending_enroll WHERE id=1 AND expires_at>?','s',date('Y-m-d H:i:s')))[0]??null):null;
  if(!$P)die('Unknown card');
  if(rows(q('SELECT id FROM card_history WHERE old_card=?','s',$uid)))die('Card retired');
  if($P['user_id']===null){                         // brand-new person typed on the website
    try{q('INSERT INTO users(username,password_hash,role,name,card_id,staff_type,start_date,end_date) VALUES(?,?,?,?,?,?,?,?)','ssssssss',$P['new_username'],$P['new_hash'],'staff',$P['new_name'],$uid,$P['new_type'],$P['new_start'],$P['new_end']);}
    catch(mysqli_sql_exception $x){die('Username taken');}
    q("INSERT INTO card_history(user_id,old_card,new_card,reason) VALUES(?,NULL,?,'New card')",'is',$db->insert_id,$uid);
    q('DELETE FROM pending_enroll WHERE id=1');
    die('CARD ADDED '.$P['new_name']);
  }
  $T=rows(q('SELECT name,card_id FROM users WHERE id=?','i',$P['user_id']))[0]??null;
  if(!$T)die('Unknown card');
  q('UPDATE users SET card_id=? WHERE id=?','si',$uid,$P['user_id']);
  q('INSERT INTO card_history(user_id,old_card,new_card,reason) VALUES(?,?,?,?)','isss',$P['user_id'],$T['card_id'],$uid,$P['reason']);
  q('DELETE FROM pending_enroll WHERE id=1');
  die('CARD ADDED '.$T['name']);
}

$u=$U['name'];
if(!is_current($U,$today))die('Account inactive '.$u);
if($nm>=mins($S['office_close']))die('Office closed '.$u);

// approved half-day leave for this person today: AM = morning off, PM = afternoon off
$half=null;
try{$half=rows(q("SELECT half FROM leave_requests WHERE user_id=? AND status='Approved' AND half IS NOT NULL AND from_date<=? AND to_date>=? LIMIT 1",'iss',$U['id'],$today,$today))[0]['half']??null;}catch(mysqli_sql_exception $x){}
$bf=mins($S['break_from']);$bt=mins($S['break_to']);

$r=rows(q('SELECT * FROM attendance WHERE work_date=? AND user_id=?','si',$today,$U['id']))[0]??null;
if(!$r){                                                   // CLOCK IN (first tap of the day)
  if($nm<mins($S['clock_in_from']))die('Too early to clock in '.$u);
  $lateAfter=($half==='AM'?$bt:mins($S['work_start']))+(int)$S['grace'];   // morning off: on time until the afternoon starts
  $st=$nm<=$lateAfter?'On Time':'Late';
  q('INSERT INTO attendance(work_date,card_id,user_id,clock_in,in_status) VALUES(?,?,?,?,?)','ssiss',$today,$uid!==''?$uid:$txt,$U['id'],$now,$st);
  die("CLOCK IN OK $u ($st) $now");
}
if($r['clock_out'])die("Already clocked out today $u");
$outFrom=mins($S['clock_out_from']);$endT=mins($S['work_end']);
if($half==='PM'){$outFrom=min($outFrom,$bf);$endT=min($endT,$bf);}   // afternoon off: may leave at the break
if($nm<$outFrom||$nm-mins($r['clock_in'])<MIN_GAP)die("Already clocked in $u (out from ".sprintf('%02d:%02d',intdiv($outFrom,60),$outFrom%60).")");
$os=$nm<$endT?'Early Leave':'Normal';                        // CLOCK OUT (second tap, only after the clock-out time)
$in=mins($r['clock_in']);
$worked=max(0,$nm-$in-max(0,min($nm,$bt)-max($in,$bf)));     // time at work, minus the lunch break
$h=round($worked/60,2);
$ot=round(max(0,$nm-max(mins($S['work_end']),$in))/60,2);    // time after the end of work
try{q('UPDATE attendance SET clock_out=?,out_status=?,hours=?,overtime=? WHERE id=?','ssddi',$now,$os,$h,$ot,$r['id']);}
catch(mysqli_sql_exception $x){q('UPDATE attendance SET clock_out=?,out_status=?,hours=? WHERE id=?','ssdi',$now,$os,$h,$r['id']);}
echo "CLOCK OUT OK $u ($os) $now";