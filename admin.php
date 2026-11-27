<?php
require 'config.php';need('admin');
$p=$_GET['p']??'live';$today=date('Y-m-d');$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){chk();
  $a=$_POST['a']??'';
  if($a==='decide'&&in_array($_POST['s']??'',['Approved','Rejected'],true)){
    q('UPDATE leave_requests SET status=? WHERE id=?','si',$_POST['s'],(int)$_POST['id']);$msg='Leave '.strtolower($_POST['s']);}
  if($a==='lt_add'){
    $c=strtoupper(trim($_POST['code']??''));$nm=trim($_POST['lname']??'');
    if(!preg_match('/^[A-Z0-9]{2,10}$/',$c)||$nm==='')$msg='Enter a short code (2 to 10 letters or numbers) and a name';
    else try{
      $mx=(int)rows(q('SELECT COALESCE(MAX(sort),0) m FROM leave_types'))[0]['m'];
      q('INSERT INTO leave_types(code,name,sort) VALUES(?,?,?)','ssi',$c,$nm,$mx+1);$msg='Leave type added';
    }catch(mysqli_sql_exception $x){$msg='That code already exists';}
  }
  if($a==='lt_toggle'){q('UPDATE leave_types SET active=1-active WHERE code=?','s',(string)($_POST['code']??''));$msg='Leave type updated';}
  if($a==='delete'){
    $id=(int)($_POST['id']??0);
    if($id===(int)$_SESSION['u']['id'])$msg='You cannot delete your own account';
    elseif(!rows(q('SELECT id FROM users WHERE id=?','i',$id)))$msg='User not found';
    else{q('DELETE FROM leave_requests WHERE user_id=?','i',$id);q('DELETE FROM users WHERE id=?','i',$id);$msg='User deleted';}
  }
  if($a==='user'){
    $u=trim($_POST['u']);$n=trim($_POST['n']);$pw=$_POST['pw'];
    if($u===''||$n===''||strlen($pw)<6)$msg='Enter username, name and a password of 6 or more characters';
    else try{
      q("INSERT INTO users(username,password_hash,role,name,card_id) VALUES(?,?,?,?,NULLIF(?,'')) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash),role=VALUES(role),name=VALUES(name),card_id=VALUES(card_id)",
        'sssss',$u,password_hash($pw,PASSWORD_DEFAULT),$_POST['r']==='admin'?'admin':'staff',$n,trim($_POST['c']));
      $msg='User saved';
    }catch(mysqli_sql_exception $x){$msg='That Card ID is already used by another user';}
  }
}
head('Admin','admin.php',ADMIN_TABS+['settings.php'=>'Settings'],$p);
if($msg)echo '<div class="msg">'.e($msg).'</div>';

if($p==='live'){?>
<style>@keyframes fl{from{background:var(--pri);color:var(--prx)}to{background:var(--card)}}#last.flash{animation:fl 2s}</style>
<div class="card" id="last" style="display:none"><h2>Latest scan</h2><div id="lastTxt" style="font-size:20px;font-weight:600"></div></div>
<div class="stats" id="stats"></div>
<div class="card"><h2>Today <span class="mut" id="lt" style="font-size:13px"></span></h2><div class="wrap"><table id="tb"></table></div></div>
<script>
const esc=s=>String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const cls=s=>/On Time|Approved/.test(s)?'ok':/Late|Pending|leave|Early/.test(s)?'warn':/Rejected|Not in/.test(s)?'bad':'mut';
let prev='';
async function go(){
  try{
    const res=await fetch('live.php',{cache:'no-store'});
    if(res.status===401){location='index.php';return}
    const r=await res.json();
    document.getElementById('stats').innerHTML=[['Staff',r.rows.length],['In Office',r.in],['Late',r.late],['Pending Leave',r.pending]]
      .map(x=>'<div><b>'+x[1]+'</b>'+x[0]+'</div>').join('');
    document.getElementById('lt').textContent='Updated '+r.time+' (live, every 2 seconds)';
    document.getElementById('tb').innerHTML='<tr><th>Name</th><th>Clock in</th><th>Clock out</th><th>Worked</th><th>Status</th></tr>'+
      (r.rows.length?r.rows.map(x=>'<tr><td>'+esc(x.name)+'</td><td>'+(x.clock_in||'-')+'</td><td>'+(x.clock_out||'-')+'</td><td>'+(x.worked||'-')+'</td><td><span class="p '+cls(x.st)+'">'+esc(x.st)+'</span></td></tr>').join(''):'<tr><td colspan="5" class="mut">No staff yet.</td></tr>');
    if(r.last){
      const out=r.last.clock_out&&r.last.clock_out>=r.last.clock_in;
      const t=out?r.last.clock_out:r.last.clock_in;
      const box=document.getElementById('last');
      document.getElementById('lastTxt').textContent=r.last.name+(out?' clocked out at ':' clocked in at ')+t;
      box.style.display='block';
      const key=r.last.name+t;
      if(prev&&prev!==key){box.classList.remove('flash');void box.offsetWidth;box.classList.add('flash')}
      prev=key;
    }
  }catch(e){document.getElementById('lt').textContent='Connection lost, retrying...'}
}
go();setInterval(go,2000);
</script>
<?php }

if($p==='report'){
  function wdl($a,$b){$o=[];for($d=strtotime($a);$d<=strtotime($b);$d+=86400)if(date('N',$d)<6)$o[]=date('Y-m-d',$d);return $o;}
  $per=in_array($_GET['t']??'',['daily','weekly','monthly'],true)?$_GET['t']:'daily';
  $ref=preg_match('/^\d{4}-\d{2}-\d{2}$/',$_GET['d']??'')?$_GET['d']:$today;
  $d=new DateTime($ref);
  if($per==='daily'){$f=clone $d;$t=clone $d;}
  elseif($per==='weekly'){$f=(clone $d)->modify('monday this week');$t=(clone $f)->modify('+6 days');}
  else{$f=(clone $d)->modify('first day of this month');$t=(clone $d)->modify('last day of this month');}
  $from=$f->format('Y-m-d');$to=$t->format('Y-m-d');$end=min($to,$today);
  $exp=$from<=$end?wd($from,$end):0;
  $fd=7.0;$hd=3.0;
  try{foreach(rows(q("SELECT k,v FROM settings WHERE k IN('full_day_hours','half_day_hours')")) as $x){if($x['k']==='full_day_hours')$fd=(float)$x['v'];else $hd=(float)$x['v'];}}catch(mysqli_sql_exception $x){}
  $fds=sprintf('%.2F',$fd);$hds=sprintf('%.2F',$hd);
  $S=[];
  foreach(['a.overtime','0'] as $otc){
    try{$S=rows(q("SELECT u.id,u.name,u.start_date,u.end_date,COUNT(a.id) present,COALESCE(SUM(a.in_status='Late'),0) late,COALESCE(SUM(a.out_status='Early Leave'),0) early,COALESCE(SUM(a.hours),0) hrs,COALESCE(SUM($otc),0) ot,COALESCE(SUM(a.clock_out IS NOT NULL AND a.hours>=$fds),0) fulld,COALESCE(SUM(a.clock_out IS NOT NULL AND a.hours>=$hds AND a.hours<$fds),0) halfd,COALESCE(SUM(a.clock_out IS NOT NULL AND a.hours<$hds),0) shortd,COALESCE(SUM(a.clock_out IS NULL AND a.work_date<'$today'),0) noout
      FROM users u LEFT JOIN attendance a ON a.user_id=u.id AND a.work_date BETWEEN ? AND ? WHERE u.role='staff' AND (u.start_date IS NULL OR u.start_date<=?) AND (u.end_date IS NULL OR u.end_date>=?) GROUP BY u.id,u.name,u.start_date,u.end_date ORDER BY u.name",'ssss',$from,$to,$to,$from));break;}catch(mysqli_sql_exception $x){}
  }
  $AD=[];foreach(rows(q('SELECT user_id,work_date FROM attendance WHERE work_date BETWEEN ? AND ?','ss',$from,$to)) as $x)$AD[$x['user_id']][$x['work_date']]=1;
  $L=[];$P=[];$LD=[];$LR=[];
  foreach(['half','NULL'] as $hc){
    try{$LR=rows(q("SELECT user_id,type,status,from_date,to_date,$hc half FROM leave_requests WHERE status IN('Approved','Pending') AND from_date<=? AND to_date>=?",'ss',$to,$from));break;}catch(mysqli_sql_exception $x){}
  }
  foreach($LR as $l){
    $fr=$l['half']?0.5:1;$uid=$l['user_id'];
    $a=max($l['from_date'],$from);$b=min($l['to_date'],$to);
    if($l['status']==='Approved'){
      foreach(wdl($a,min($b,$end)) as $dd){$L[$uid][$l['type']]=($L[$uid][$l['type']]??0)+$fr;$LD[$uid][$dd]=min(1,($LD[$uid][$dd]??0)+$fr);}
    }else $P[$uid]=($P[$uid]??0)+$fr*wd($a,$b);
  }
  $LT=[];foreach(rows(q('SELECT code,name,active FROM leave_types ORDER BY sort,code')) as $t)$LT[$t['code']]=$t;
  $cols=[];foreach($LT as $c=>$t){$used=false;foreach($L as $lu)if(($lu[$c]??0)>0)$used=true;if($t['active']||$used)$cols[]=$c;}
  ?>
<div class="card"><h2>Reports</h2><form class="row noprint" method="get"><input type="hidden" name="p" value="report">
<div><label for="t">Period</label><select id="t" name="t"><?php foreach(['daily'=>'Daily','weekly'=>'Weekly (Mon to Sun)','monthly'=>'Monthly'] as $k=>$v)echo '<option value="'.$k.'"'.($k===$per?' selected':'').'>'.$v.'</option>';?></select></div>
<div><label for="d">Date</label><input id="d" type="date" name="d" value="<?=e($ref)?>"></div>
<div><button class="btn">Generate</button> <button class="btn" type="button" onclick="print()">Print</button></div></form>
<div class="wrap"><table><tr><th>Name</th><th>Present</th><th>Late</th><th>Early leave</th><th>Hours</th><th>Avg/day</th><th>Overtime</th><th>Full days</th><th>Half days</th><th>Under half</th><th>No clock out</th><?php foreach($cols as $c)echo '<th title="'.e($LT[$c]['name']).'">'.e($c).'</th>';?><th>Pending leave</th><th>Absent</th></tr>
<?php foreach($S as $s){$i=$s['id'];$s0=max($from,$s['start_date']?:$from);$s1=min($end,$s['end_date']?:$end);
 $ab=0;if($s0<=$s1)foreach(wdl($s0,$s1) as $dd){if(empty($AD[$i][$dd]))$ab+=max(0,1-($LD[$i][$dd]??0));}
 $lc='';foreach($cols as $c)$lc.='<td>'.round($L[$i][$c]??0,1).'</td>';
 echo '<tr><td>'.e($s['name']).'</td><td>'.$s['present'].'</td><td>'.$s['late'].'</td><td>'.$s['early'].'</td><td>'.round($s['hrs'],2).'</td><td>'.($s['present']?round($s['hrs']/$s['present'],2):0).'</td><td>'.round($s['ot'],2).'</td><td>'.$s['fulld'].'</td><td>'.$s['halfd'].'</td><td>'.$s['shortd'].'</td><td>'.$s['noout'].'</td>'.$lc.'<td>'.round($P[$i]??0,1).'</td><td>'.round($ab,1).'</td></tr>';}
if(!$S)echo '<tr><td colspan="'.(13+count($cols)).'" class="mut">No staff yet.</td></tr>';?></table></div></div>
<?php }

if($p==='leaves'){
  $R=rows(q("SELECT l.*,u.name FROM leave_requests l JOIN users u ON u.id=l.user_id ORDER BY l.id DESC"));?>
<div class="card"><h2>Leave Requests</h2><div class="wrap"><table><tr><th>Staff</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Status</th><th></th></tr>
<?php foreach($R as $l){echo '<tr><td>'.e($l['name']).'</td><td>'.$l['type'].'</td><td>'.$l['from_date'].'</td><td>'.$l['to_date'].'</td><td>'.($l['days']+0).(!empty($l['half'])?' ('.$l['half'].')':'').'</td><td>'.e($l['reason']).'</td><td>'.pill($l['status']).'</td><td>';
 if($l['status']==='Pending')foreach(['Approved'=>'Approve','Rejected'=>'Reject'] as $s=>$b)echo '<form class="i" method="post">'.csrf().'<input type="hidden" name="a" value="decide"><input type="hidden" name="id" value="'.$l['id'].'"><input type="hidden" name="s" value="'.$s.'"><button class="btn s'.($s==='Rejected'?' x':'').'">'.$b.'</button></form>';
 echo '</td></tr>';}
if(!$R)echo '<tr><td colspan="8" class="mut">No leave requests yet.</td></tr>';?></table></div></div>
<?php $LT=rows(q('SELECT * FROM leave_types ORDER BY sort,code'));?>
<div class="card"><h2>Leave Types</h2>
<div class="wrap"><table><tr><th>Code</th><th>Name</th><th>Status</th><th></th></tr>
<?php foreach($LT as $t)echo '<tr><td>'.e($t['code']).'</td><td>'.e($t['name']).'</td><td>'.($t['active']?'<span class="p ok">Available</span>':'<span class="p mut">Hidden</span>').'</td><td><form class="i" method="post">'.csrf().'<input type="hidden" name="a" value="lt_toggle"><input type="hidden" name="code" value="'.e($t['code']).'"><button class="btn s">'.($t['active']?'Hide':'Show').'</button></form></td></tr>';?></table></div>
<form method="post"><?=csrf()?><input type="hidden" name="a" value="lt_add"><div class="row">
<div><label for="lc">Code (for example SL)</label><input id="lc" name="code" maxlength="10" required></div>
<div><label for="ln">Name</label><input id="ln" name="lname" required></div></div>
<button class="btn">Add Leave Type</button></form></div>
<?php }

if($p==='staff'){
  $U=rows(q('SELECT id,username,name,role,card_id FROM users ORDER BY role,name'));?>
<div class="card"><h2>Add or update a user</h2><p class="mut">Using an existing username updates that user, including a new password. Card ID is the text written on the card.</p>
<form method="post"><?=csrf()?><input type="hidden" name="a" value="user"><div class="row">
<div><label for="u">Username</label><input id="u" name="u" required></div><div><label for="n">Name</label><input id="n" name="n" required></div>
<div><label for="pw">Password</label><input id="pw" name="pw" type="password" required></div>
<div><label for="c">Card ID</label><input id="c" name="c"></div>
<div><label for="r">Role</label><select id="r" name="r"><option value="staff">Staff</option><option value="admin">Admin</option></select></div></div>
<button class="btn">Save user</button></form></div>
<div class="card"><h2>Users</h2><div class="wrap"><table><tr><th>Username</th><th>Name</th><th>Role</th><th>Card ID</th><th></th></tr>
<?php $cf='return confirm(&quot;Delete this user and their leave requests? Attendance records are kept.&quot;)';
foreach($U as $u){echo '<tr><td>'.e($u['username']).'</td><td>'.e($u['name']).'</td><td>'.e($u['role']).'</td><td>'.e($u['card_id']?:'-').'</td><td>';
 if((int)$u['id']!==(int)$_SESSION['u']['id'])echo '<form class="i" method="post" onsubmit="'.$cf.'">'.csrf().'<input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="'.$u['id'].'"><button class="btn s x">Delete</button></form>';
 echo '</td></tr>';}?></table></div></div>
<?php }
foot();