<?php
require 'config.php';need('staff');
$me=$_SESSION['u'];$p=$_GET['p']??'apply';$msg='';
$LT=rows(q('SELECT code,name FROM leave_types WHERE active=1 ORDER BY sort,code'));$codes=array_column($LT,'code');
if($_SERVER['REQUEST_METHOD']==='POST'){chk();
  $ty=$_POST['ty']??'';$f=$_POST['f']??'';$t=$_POST['to']??'';
  $half=in_array($_POST['du']??'',['AM','PM'],true)?$_POST['du']:'';
  if($half)$t=$f;                                   // half day = one day only
  $ok=preg_match('/^\d{4}-\d{2}-\d{2}$/',$f)&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$t);
  if(!in_array($ty,$codes,true)||!$ok||$t<$f)$msg='Check the leave type and dates';
  elseif(($d=wd($f,$t))<1)$msg='Selected dates have no working days';
  else{
    $days=$half?0.5:$d;
    try{
      q("INSERT INTO leave_requests(user_id,type,from_date,to_date,days,reason,half) VALUES(?,?,?,?,?,?,NULLIF(?,''))",'isssdss',$me['id'],$ty,$f,$t,$days,substr(trim($_POST['r']??''),0,200),$half);
      $msg=$half?'Half-day leave submitted ('.($half==='AM'?'morning off':'afternoon off').')':"Leave submitted ($d working days)";
    }catch(mysqli_sql_exception $x){$msg='Could not save. Ask the admin to run update6.sql.';}
  }
}
head('Staff','staff.php',['apply'=>'Apply for Leave','mine'=>'My Records'],$p);
if($msg)echo '<div class="msg">'.e($msg).'</div>';
if($p==='apply'){?>
<div class="card"><h2>Apply for Leave</h2><form method="post"><?=csrf()?>
<label for="ty">Type</label><select id="ty" name="ty"><?php foreach($LT as $t)echo '<option value="'.e($t['code']).'">'.e($t['code'].' ('.$t['name'].')').'</option>';?></select>
<label for="du">Duration</label><select id="du" name="du" onchange="document.getElementById('tw').style.display=this.value==='full'?'block':'none'">
<option value="full">Full day(s)</option>
<option value="AM">Half day: morning off (I come in for the afternoon)</option>
<option value="PM">Half day: afternoon off (I leave at lunchtime)</option></select>
<div class="row"><div><label for="f">From</label><input id="f" type="date" name="f" value="<?=date('Y-m-d')?>" required></div>
<div id="tw"><label for="to">To</label><input id="to" type="date" name="to" value="<?=date('Y-m-d')?>" required></div></div>
<label for="r">Reason</label><textarea id="r" name="r" rows="3" maxlength="200"></textarea>
<button class="btn">Submit Leave</button></form></div>
<?php }else{
 $L=rows(q('SELECT * FROM leave_requests WHERE user_id=? ORDER BY id DESC','i',$me['id']));
 $A=rows(q('SELECT * FROM attendance WHERE user_id=? AND work_date>=? ORDER BY work_date DESC','is',$me['id'],date('Y-m-01')));
 $th=0;$to=0;foreach($A as $a){$th+=(float)$a['hours'];$to+=(float)($a['overtime']??0);}
 $fd=7.0;$hd=3.0;
 try{foreach(rows(q("SELECT k,v FROM settings WHERE k IN('full_day_hours','half_day_hours')")) as $x){if($x['k']==='full_day_hours')$fd=(float)$x['v'];else $hd=(float)$x['v'];}}catch(mysqli_sql_exception $x){}
 $dt=function($a)use($fd,$hd){if(!$a['clock_out'])return '-';$h=(float)$a['hours'];return $h>=$fd?'Full day':($h>=$hd?'Half day':'Under half');};
 $nf=$nh=0;foreach($A as $a){$k=$dt($a);if($k==='Full day')$nf++;elseif($k==='Half day')$nh++;}?>
<div class="card"><h2>My Leave</h2><div class="wrap"><table><tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Status</th></tr>
<?php foreach($L as $l)echo '<tr><td>'.e($l['type']).'</td><td>'.$l['from_date'].'</td><td>'.$l['to_date'].'</td><td>'.e($l['days']+0).(!empty($l['half'])?' ('.($l['half']==='AM'?'morning off':'afternoon off').')':'').'</td><td>'.pill($l['status']).'</td></tr>';
if(!$L)echo '<tr><td colspan="5" class="mut">No leave submitted yet.</td></tr>';?></table></div></div>
<div class="card"><h2>My Attendance This Month</h2>
<p class="mut">Total worked: <b><?=round($th,2)?></b> hours (lunch break taken out). Overtime: <b><?=round($to,2)?></b> hours. Full days: <b><?=$nf?></b>, half days: <b><?=$nh?></b>.</p>
<div class="wrap"><table><tr><th>Date</th><th>In</th><th>Out</th><th>In status</th><th>Out status</th><th>Hours</th><th>Overtime</th><th>Day</th></tr>
<?php foreach($A as $a)echo '<tr><td>'.$a['work_date'].'</td><td>'.$a['clock_in'].'</td><td>'.($a['clock_out']?:'-').'</td><td>'.pill((string)$a['in_status']).'</td><td>'.($a['out_status']?pill($a['out_status']):'-').'</td><td>'.($a['hours']??'-').'</td><td>'.($a['clock_out']?e($a['overtime']??0):'-').'</td><td>'.$dt($a).'</td></tr>';
if(!$A)echo '<tr><td colspan="8" class="mut">No attendance recorded this month.</td></tr>';?></table></div></div>
<?php }foot();