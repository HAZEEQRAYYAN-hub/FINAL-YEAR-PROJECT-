<?php
require 'config.php';need('admin');
$now=date('Y-m-d H:i:s');$msg='';
$WAIT=30;                                       // seconds the system waits for the new card to be tapped
if(isset($_GET['status'])){                       // used by the page to wait for the tap
  header('Content-Type: application/json');header('Cache-Control: no-store');
  $w=rows(q('SELECT user_id FROM pending_enroll WHERE id=1 AND expires_at>?','s',$now));
  $h=rows(q('SELECT h.id,h.new_card,u.name FROM card_history h JOIN users u ON u.id=h.user_id ORDER BY h.id DESC LIMIT 1'))[0]??['id'=>0];
  echo json_encode(['waiting'=>count($w)>0,'hid'=>(int)$h['id'],'name'=>$h['name']??'','card'=>$h['new_card']??'']);exit;
}
$WHY=['New card','Lost','Damaged','Replaced','Other'];
$dt=fn($v)=>preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$v)?$v:'';
function mkuser($n){$b=strtolower(preg_replace('/[^a-z0-9]/i','',$n))?:'user';$u=$b;$i=1;while(rows(q('SELECT id FROM users WHERE username=?','s',$u)))$u=$b.(++$i);return $u;}
if($_SERVER['REQUEST_METHOD']==='POST'){chk();
  if(isset($_POST['mode']))$_POST['id']=$_POST['mode']==='new'?'new':($_POST['pid']??'');   // form 1: new person or existing person
  $a=$_POST['a']??'';$id=(int)($_POST['id']??0);
  $why=in_array($_POST['why']??'',$WHY,true)?$_POST['why']:'Other';
  $U=rows(q('SELECT id,card_id FROM users WHERE id=?','i',$id))[0]??null;
  if($a==='cancel'){q('DELETE FROM pending_enroll WHERE id=1');$msg='Cancelled';}
  elseif($a==='enroll'&&($_POST['id']??'')==='new'){               // brand-new person
    $nm=trim($_POST['n']??'');$ty=($_POST['ty']??'')==='Intern'?'Intern':'Staff';
    $sd=$dt($_POST['sd']??'');$ed=$dt($_POST['ed']??'');$un=trim($_POST['u']??'');$pw=$_POST['pw']??'';
    if($nm==='')$msg='Enter the name';
    elseif($sd!==''&&$ed!==''&&$ed<$sd)$msg='End date is before the start date';
    elseif($pw!==''&&strlen($pw)<6)$msg='Password must be 6 or more characters';
    elseif($un!==''&&rows(q('SELECT id FROM users WHERE username=?','s',$un)))$msg='That username is taken';
    else{
      if($un==='')$un=mkuser($nm);
      $gen=$pw==='';if($gen)$pw=bin2hex(random_bytes(4));
      q("REPLACE INTO pending_enroll(id,user_id,reason,expires_at,new_name,new_type,new_username,new_hash,new_start,new_end) VALUES(1,NULL,'New card',?,?,?,?,?,NULLIF(?,''),NULLIF(?,''))",'sssssss',date('Y-m-d H:i:s',time()+$WAIT),$nm,$ty,$un,password_hash($pw,PASSWORD_DEFAULT),$sd,$ed);
      $_SESSION['np']=['n'=>$nm,'u'=>$un,'p'=>$gen?$pw:null];
      $msg='Ready. Tap the new card on the reader within '.$WAIT.' seconds.';
    }
  }
  elseif($a==='enroll'){
    if(!$U)$msg='Choose a person';
    elseif($why==='New card'&&$U['card_id']!==null)$msg='This person already has a card. Use the lost, damaged or replaced section';
    elseif($why!=='New card'&&$U['card_id']===null)$msg='This person has no card yet. Use Add a new card';
    else{q('REPLACE INTO pending_enroll(id,user_id,reason,expires_at) VALUES(1,?,?,?)','iss',$id,$why,date('Y-m-d H:i:s',time()+$WAIT));
      $msg='Ready. Tap the new card on the reader within '.$WAIT.' seconds.';}
  }
}
$P=rows(q("SELECT id,name,staff_type,card_id,active FROM users WHERE role='staff' ORDER BY name"));
$H=rows(q("SELECT h.*,u.name FROM card_history h JOIN users u ON u.id=h.user_id ORDER BY h.id DESC LIMIT 30"));
$pend=rows(q('SELECT COALESCE(u.name,p.new_name) name,p.expires_at FROM pending_enroll p LEFT JOIN users u ON u.id=p.user_id WHERE p.id=1 AND p.expires_at>?','s',$now))[0]??null;
if(!$pend)unset($_SESSION['np']);
$hid0=(int)rows(q('SELECT COALESCE(MAX(id),0) m FROM card_history'))[0]['m'];
head('Manage RFID','admin.php',ADMIN_TABS+['settings.php'=>'Settings'],'manage_rfid.php');
if($msg)echo '<div class="msg">'.e($msg).'</div>';
$why='';foreach($WHY as $w)$why.='<option>'.$w.'</option>';
$optsNew='';$optsOld='<option value="">Choose...</option>';
foreach($P as $x){
  if($x['card_id']===null)$optsNew.='<option value="'.$x['id'].'">'.e($x['name']).'</option>';
  else $optsOld.='<option value="'.$x['id'].'">'.e($x['name']).' (card: '.e($x['card_id']).')</option>';
}?>
<?php if($pend){?>
<div class="card"><h2>Waiting for a card</h2>
<p id="wait" style="font-size:18px;font-weight:600">Waiting for a card for <?=e($pend['name'])?>... tap the card on the reader now.</p>
<p class="mut">Time left: <b id="left"><?=max(0,strtotime($pend['expires_at'])-time())?></b> seconds</p>
<?php $np=$_SESSION['np']??null;if($np){?><p class="mut">Login for <?=e($np['n'])?>: username <b><?=e($np['u'])?></b><?=$np['p']?', password <b>'.e($np['p']).'</b> (note it down now)':''?></p><?php }?>
<form method="post"><?=csrf()?><input type="hidden" name="a" value="cancel"><button class="btn x">Cancel</button></form>
<script>
const hid0=<?=$hid0?>;
let left=<?=max(0,strtotime($pend['expires_at'])-time())?>;
const cd=setInterval(()=>{left=Math.max(0,left-1);const e=document.getElementById('left');if(e)e.textContent=left;if(left<=0)clearInterval(cd);},1000);
const cred=<?=json_encode($np?('username '.$np['u'].($np['p']?', password '.$np['p']:'')):'')?>;
const t=setInterval(async()=>{try{
  const r=await (await fetch('manage_rfid.php?status=1',{cache:'no-store'})).json();
  if(!r.waiting){clearInterval(t);
    const ok=r.hid>hid0,w=document.getElementById('wait');
    w.textContent=ok?('Card added for '+r.name+' ('+r.card+'). It can be used now.'+(cred?' Login: '+cred+'.':'')):'Time is up. Press Start again.';
    if(!ok||!cred)setTimeout(()=>location.href='manage_rfid.php',2500);
    else{const a=document.createElement('a');a.href='manage_rfid.php';a.textContent=' Done';w.appendChild(a);}}
}catch(e){}},2000);
</script></div>
<?php }else{?>
<div class="card"><h2>1. Add a New Card</h2>
<form method="post" autocomplete="off"><?=csrf()?><input type="hidden" name="a" value="enroll"><input type="hidden" name="why" value="New card">
<label style="display:flex;gap:8px;align-items:center;font-size:15px;color:var(--tx)"><input type="radio" name="mode" value="new" checked style="width:auto" onchange="sw()"> New person</label>
<div id="nw"><div class="row">
<div><label for="n">Full Name</label><input id="n" name="n" autocomplete="off" placeholder="e.g. Nurul Izzah"></div>
<div><label for="ty">Type</label><select id="ty" name="ty" onchange="sw()"><option>Staff</option><option>Intern</option></select></div>
<div id="ew" style="display:none"><label for="ed">Last day of internship</label><input id="ed" name="ed" type="date"></div></div></div>
<?php if($optsNew!==''){?>
<label style="display:flex;gap:8px;align-items:center;font-size:15px;color:var(--tx);margin-top:14px"><input type="radio" name="mode" value="old" style="width:auto" onchange="sw()"> Someone already added without a card</label>
<div id="ow" style="display:none"><label for="pid">Person</label><select id="pid" name="pid"><option value="">Choose...</option><?=$optsNew?></select></div>
<?php }?>
<button class="btn">Start</button></form>
<script>
function sw(){const n=document.querySelector('input[name=mode]:checked').value==='new';
  document.getElementById('nw').style.display=n?'block':'none';
  const o=document.getElementById('ow');if(o)o.style.display=n?'none':'block';
  document.getElementById('ew').style.display=(n&&document.getElementById('ty').value==='Intern')?'block':'none';}
window.addEventListener('load',()=>setTimeout(()=>{const f=document.getElementById('n');if(f&&document.activeElement!==f)f.value='';},300));
</script></div>

<div class="card"><h2>2. Damaged, Lost or Replaced Card</h2>
<form method="post"><?=csrf()?><input type="hidden" name="a" value="enroll"><div class="row">
<div><label for="ido">Full Name</label><select id="ido" name="id" required><?=$optsOld?></select></div>
<div><label for="whyo">Reason</label><select id="whyo" name="why"><option>Damaged</option><option>Lost</option><option>Replaced</option></select></div></div>
<button class="btn">Start</button></form></div>
<?php }?>

<div class="card"><h2>Current Cards</h2><div class="wrap"><table><tr><th>Name</th><th>Type</th><th>Card ID</th><th>Card</th></tr>
<?php foreach($P as $x)echo '<tr><td>'.e($x['name']).'</td><td>'.e($x['staff_type']).'</td><td>'.e($x['card_id']?:'-').'</td><td>'.($x['card_id']?'<span class="p ok">Active</span>':'<span class="p bad">No card</span>').'</td></tr>';
if(!$P)echo '<tr><td colspan="4" class="mut">No staff yet.</td></tr>';?></table></div></div>

<div class="card"><h2>Card History</h2><div class="wrap"><table><tr><th>Date</th><th>Name</th><th>Old card</th><th>New card</th><th>Reason</th></tr>
<?php foreach($H as $h)echo '<tr><td>'.e($h['changed_at']).'</td><td>'.e($h['name']).'</td><td>'.e($h['old_card']?:'-').'</td><td>'.e($h['new_card']?:'-').'</td><td>'.e($h['reason']).'</td></tr>';
if(!$H)echo '<tr><td colspan="5" class="mut">No card changes yet.</td></tr>';?></table></div></div>
<?php foot();