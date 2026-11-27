<?php
require 'config.php';need('admin');
$msg='';$today=date('Y-m-d');$me=(int)$_SESSION['u']['id'];
$dt=fn($v)=>preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$v)?$v:'';
if($_SERVER['REQUEST_METHOD']==='POST'){chk();
  $a=$_POST['a']??'';$id=(int)($_POST['id']??0);
  try{
    if($a==='save'){
      $un=trim($_POST['u']??'');$nm=trim($_POST['n']??'');$pw=$_POST['pw']??'';
      $role=($_POST['r']??'')==='admin'?'admin':'staff';
      $ty=($_POST['ty']??'')==='Intern'?'Intern':'Staff';
      $sd=$dt($_POST['sd']??'');$ed=$dt($_POST['ed']??'');$card=trim($_POST['c']??'');
      if($nm==='')$msg='Enter the name';
      elseif($sd!==''&&$ed!==''&&$ed<$sd)$msg='End date is before the start date';
      elseif($id){
        q("UPDATE users SET name=?,role=?,staff_type=?,start_date=NULLIF(?,''),end_date=NULLIF(?,'') WHERE id=?",'sssssi',$nm,$role,$ty,$sd,$ed,$id);
        if($pw!==''){if(strlen($pw)<6)$msg='Password must be 6 or more characters';else q('UPDATE users SET password_hash=? WHERE id=?','si',password_hash($pw,PASSWORD_DEFAULT),$id);}
        $msg=$msg?:'Saved';
      }elseif($un===''||strlen($pw)<6)$msg='Enter a username and a password of 6 or more characters';
      else{
        q("INSERT INTO users(username,password_hash,role,name,card_id,staff_type,start_date,end_date) VALUES(?,?,?,?,NULLIF(?,''),?,NULLIF(?,''),NULLIF(?,''))",'ssssssss',$un,password_hash($pw,PASSWORD_DEFAULT),$role,$nm,$card,$ty,$sd,$ed);
        $msg='Added '.$nm;
      }
    }
    if($a==='toggle'&&$id!==$me){
      $U=rows(q('SELECT active FROM users WHERE id=?','i',$id))[0]??null;
      if($U&&$U['active']){q('UPDATE users SET active=0,end_date=COALESCE(end_date,?) WHERE id=?','si',$today,$id);$msg='Deactivated. This person can no longer clock in or log in.';}
      elseif($U){q('UPDATE users SET active=1,end_date=IF(end_date<?,NULL,end_date) WHERE id=?','si',$today,$id);$msg='Activated';}
    }
    if($a==='delete'&&$id!==$me){
      q('DELETE FROM leave_requests WHERE user_id=?','i',$id);q('DELETE FROM users WHERE id=?','i',$id);$msg='User deleted (attendance records are kept)';
    }
  }catch(mysqli_sql_exception $x){$msg='That username or Card ID already exists';}
}
$E=isset($_GET['edit'])?(rows(q('SELECT * FROM users WHERE id=?','i',(int)$_GET['edit']))[0]??null):null;
$U=rows(q('SELECT * FROM users ORDER BY role,name'));
function st($u,$t){if(!$u['active'])return ['Inactive','bad'];if($u['start_date']&&$u['start_date']>$t)return ['Not started','warn'];if($u['end_date']&&$u['end_date']<$t)return ['Ended','mut'];return ['Active','ok'];}
head('Manage staff','admin.php',ADMIN_TABS+['settings.php'=>'Settings'],'manage_staff.php');
if($msg)echo '<div class="msg">'.e($msg).'</div>';?>
<div class="card"><h2><?=$E?'Edit '.e($E['name']):'Add New Staff or Intern'?></h2>
<form method="post"><?=csrf()?><input type="hidden" name="a" value="save"><input type="hidden" name="id" value="<?=$E?(int)$E['id']:0?>"><div class="row">
<div><label for="n">Full Name</label><input id="n" name="n" required value="<?=e($E['name']??'')?>"></div>
<div><label for="u">Username (Login)</label><input id="u" name="u" <?=$E?'readonly':'required'?> value="<?=e($E['username']??'')?>"></div>
<div><label for="pw"><?=$E?'New password (empty = keep)':'Password'?></label><input id="pw" name="pw" type="password" <?=$E?'':'required'?>></div>
<div><label for="c">Card ID</label><input id="c" name="c" <?=$E?'readonly title="Change cards in Manage RFID"':''?> value="<?=e($E['card_id']??'')?>"></div></div>
<div class="row">
<div><label for="ty">Type</label><select id="ty" name="ty"><?php foreach(['Staff','Intern'] as $o)echo '<option'.(($E['staff_type']??'Staff')===$o?' selected':'').'>'.$o.'</option>';?></select></div>
<div><label for="r">Role</label><select id="r" name="r"><?php foreach(['staff'=>'Staff (normal login)','admin'=>'Admin'] as $k=>$o)echo '<option value="'.$k.'"'.(($E['role']??'staff')===$k?' selected':'').'>'.$o.'</option>';?></select></div>
<div><label for="sd">Start Date</label><input id="sd" name="sd" type="date" value="<?=e($E['start_date']??'')?>"></div>
<div><label for="ed">End Date (Interns)</label><input id="ed" name="ed" type="date" value="<?=e($E['end_date']??'')?>"></div></div>
<button class="btn"><?=$E?'Save changes':'Add'?></button> <?php if($E)echo '<a href="manage_staff.php" style="margin-left:10px">Cancel</a>';?></form></div>

<div class="card"><h2>Users/Staff</h2><div class="wrap"><table><tr><th>Name</th><th>Username</th><th>Type</th><th>Role</th><th>Card ID</th><th>Start</th><th>End</th><th>Status</th><th></th></tr>
<?php foreach($U as $u){[$l,$c]=st($u,$today);
 echo '<tr><td>'.e($u['name']).'</td><td>'.e($u['username']).'</td><td>'.e($u['staff_type']).'</td><td>'.e($u['role']).'</td><td>'.e($u['card_id']?:'-').'</td><td>'.e($u['start_date']?:'-').'</td><td>'.e($u['end_date']?:'-').'</td><td><span class="p '.$c.'">'.$l.'</span></td><td>';
 echo '<a class="btn s" style="text-decoration:none;display:inline-block" href="?edit='.$u['id'].'">Edit</a>';
 if((int)$u['id']!==$me){
  echo '<form class="i" method="post">'.csrf().'<input type="hidden" name="a" value="toggle"><input type="hidden" name="id" value="'.$u['id'].'"><button class="btn s">'.($u['active']?'Deactivate':'Activate').'</button></form>';
  echo '<form class="i" method="post" onsubmit="return confirm(&quot;Delete this person and their leave requests? Attendance records are kept.&quot;)">'.csrf().'<input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="'.$u['id'].'"><button class="btn s x">Delete</button></form>';
 }
 echo '</td></tr>';}?></table></div></div>
<?php foot();