<?php
require 'config.php';
if(isset($_GET['out'])){session_destroy();header('Location: index.php');exit;}
$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){chk();
  $u=rows(q('SELECT * FROM users WHERE username=?','s',trim($_POST['u']??'')))[0]??null;
  if($u&&$u['active']&&password_verify($_POST['p']??'',$u['password_hash'])){
    session_regenerate_id(true);
    $_SESSION['u']=['id'=>$u['id'],'role'=>$u['role'],'name'=>$u['name'],'card'=>$u['card_id']];
    header('Location: '.($u['role']==='admin'?'admin.php':'staff.php'));exit;
  }
  $err='Wrong username or password';
}
head('Log in');?>
<div class="card" style="max-width:340px;margin:50px auto"><h2>Log in</h2>
<form method="post"><?=csrf()?>
<label for="u">Username</label><input id="u" name="u" required autofocus>
<label for="p">Password</label><input id="p" name="p" type="password" required>
<button class="btn">Log in</button></form>
<p class="bad"><?=e($err)?></p></div>
<?php foot();