<?php
// JSON feed for the admin "Live today" page (refreshed every 2 seconds)
require 'config.php';
if(empty($_SESSION['u'])||$_SESSION['u']['role']!=='admin'){http_response_code(401);echo '{"error":"login"}';exit;}
session_write_close();
header('Content-Type: application/json');
header('Cache-Control: no-store');
$today=date('Y-m-d');$nm=mins(date('H:i:s'));
$bf=mins('13:00');$bt=mins('14:00');
try{foreach(rows(q("SELECT k,v FROM settings WHERE k IN('break_from','break_to')")) as $x){if($x['k']==='break_from')$bf=mins($x['v']);else $bt=mins($x['v']);}}catch(mysqli_sql_exception $x){}
$fd=7.0;$hd=3.0;
try{foreach(rows(q("SELECT k,v FROM settings WHERE k IN('full_day_hours','half_day_hours')")) as $x){if($x['k']==='full_day_hours')$fd=(float)$x['v'];else $hd=(float)$x['v'];}}catch(mysqli_sql_exception $x){}
function hm($m){return intdiv($m,60).':'.sprintf('%02d',$m%60);}
$R=null;
foreach(["(SELECT l.half FROM leave_requests l WHERE l.user_id=u.id AND l.status='Approved' AND ? BETWEEN l.from_date AND l.to_date LIMIT 1)","NULL"] as $lh){
  try{
    $sql="SELECT u.name,a.clock_in,a.clock_out,a.in_status,a.hours,(SELECT l.type FROM leave_requests l WHERE l.user_id=u.id AND l.status='Approved' AND ? BETWEEN l.from_date AND l.to_date LIMIT 1) lv,$lh lh
      FROM users u LEFT JOIN attendance a ON a.user_id=u.id AND a.work_date=? WHERE u.role='staff' AND u.active=1 AND (u.start_date IS NULL OR u.start_date<=?) AND (u.end_date IS NULL OR u.end_date>=?) ORDER BY u.name";
    $R=$lh==='NULL'?rows(q($sql,'ssss',$today,$today,$today,$today)):rows(q($sql,'sssss',$today,$today,$today,$today,$today));
    break;
  }catch(mysqli_sql_exception $x){}
}
foreach($R as &$r){
  $r['st']=$r['clock_out']?'Clocked out':($r['in_status']?:($r['lv']?$r['lv'].' leave'.($r['lh']?' ('.$r['lh'].')':''):'Not in'));
  $r['worked']='';
  if($r['clock_in']){
    if($r['clock_out']&&$r['hours']!==null){$h=(float)$r['hours'];$r['worked']=hm((int)round($h*60)).' - '.($h>=$fd?'Full day':($h>=$hd?'Half day':'Under half'));}
    else{$in=mins($r['clock_in']);$out=$r['clock_out']?mins($r['clock_out']):$nm;
      $r['worked']=hm(max(0,$out-$in-max(0,min($out,$bt)-max($in,$bf)))).($r['clock_out']?'':' ...');}
  }
}unset($r);
$last=rows(q("SELECT u.name,a.clock_in,a.clock_out FROM attendance a JOIN users u ON u.id=a.user_id WHERE a.work_date=? ORDER BY GREATEST(a.clock_in,COALESCE(a.clock_out,'00:00:00')) DESC LIMIT 1",'s',$today))[0]??null;
$pend=(int)rows(q("SELECT COUNT(*) c FROM leave_requests WHERE status='Pending'"))[0]['c'];
echo json_encode([
  'time'=>date('H:i:s'),'rows'=>$R,'last'=>$last,'pending'=>$pend,
  'in'=>count(array_filter($R,fn($r)=>$r['clock_in']&&!$r['clock_out'])),
  'late'=>count(array_filter($R,fn($r)=>$r['in_status']==='Late')),
]);