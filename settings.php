<?php
require 'config.php';need('admin');
$msg='';
$D=['clock_in_from'=>EARLIEST,'work_start'=>WORK_START,'grace'=>(string)LATE_GRACE,'clock_out_from'=>WORK_END,'work_end'=>WORK_END,'office_close'=>OFFICE_CLOSE,'break_from'=>'13:00','break_to'=>'14:00','full_day_hours'=>'7','half_day_hours'=>'3'];
$ready=true;
try{foreach(rows(q('SELECT k,v FROM settings')) as $r)$D[$r['k']]=$r['v'];}catch(mysqli_sql_exception $x){$ready=false;}
if($_SERVER['REQUEST_METHOD']==='POST'){chk();
  if(!$ready)$msg='Run update5.sql in phpMyAdmin first';
  else{
    $in=[];
    foreach(['clock_in_from','work_start','clock_out_from','work_end','office_close','break_from','break_to'] as $k){
      $v=trim($_POST[$k]??'');
      if(!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$v)){$msg='Check the times';break;}
      $in[$k]=$v;
    }
    $g=(int)($_POST['grace']??0);
    $fd=(float)($_POST['full_day_hours']??7);$hd=(float)($_POST['half_day_hours']??3);
    if(!$msg){
      if($in['clock_in_from']>=$in['work_start'])$msg='Clock in must open before the late time';
      elseif($in['work_start']>=$in['office_close'])$msg='The late time must be before the office closes';
      elseif($in['clock_out_from']>=$in['office_close'])$msg='Clock out must start before the office closes';
      elseif($in['work_end']>$in['office_close'])$msg='End of work cannot be after the office closes';
      elseif($in['break_from']>=$in['break_to'])$msg='The break must end after it starts';
      elseif($hd<=0||$hd>=$fd||$fd>16)$msg='Half day hours must be more than 0 and less than the full day hours';
      elseif($g<0||$g>120)$msg='Grace minutes must be 0 to 120';
      else{
        $in['grace']=(string)$g;
        $in['full_day_hours']=(string)$fd;$in['half_day_hours']=(string)$hd;
        foreach($in as $k=>$v)q('REPLACE INTO settings(k,v) VALUES(?,?)','ss',$k,$v);
        $D=array_merge($D,$in);$msg='Saved. New taps use these times straight away.';
      }
    }
  }
}
head('Settings','admin.php',ADMIN_TABS+['settings.php'=>'Settings'],'settings.php');
if($msg)echo '<div class="msg">'.e($msg).'</div>';
if(!$ready)echo '<div class="msg">The settings table is missing. Run update5.sql in phpMyAdmin.</div>';?>
<div class="card"><h2>Office Times</h2>
<form method="post"><?=csrf()?>
<div class="row">
<div><label for="a1">Clock in Opens</label><input id="a1" type="time" name="clock_in_from" value="<?=e($D['clock_in_from'])?>" required></div>
<div><label for="a2">On Time Until (Later = Late)</label><input id="a2" type="time" name="work_start" value="<?=e($D['work_start'])?>" required></div>
<div><label for="a3">Tolerance (Minutes)</label><input id="a3" type="number" name="grace" min="0" max="120" value="<?=e($D['grace'])?>" required></div></div>
<div class="row">
<div><label for="a4">Clock out Opens</label><input id="a4" type="time" name="clock_out_from" value="<?=e($D['clock_out_from'])?>" required></div>
<div><label for="a5">End of Work (Before = Early Leave)</label><input id="a5" type="time" name="work_end" value="<?=e($D['work_end'])?>" required></div>
<div><label for="a6">Office Closes</label><input id="a6" type="time" name="office_close" value="<?=e($D['office_close'])?>" required></div></div>
<div class="row">
<div><label for="a7">Lunch Break Starts</label><input id="a7" type="time" name="break_from" value="<?=e($D['break_from'])?>" required></div>
<div><label for="a8">Lunch Break Ends</label><input id="a8" type="time" name="break_to" value="<?=e($D['break_to'])?>" required></div></div>
<div class="row">
<div><label for="a9">Full Day = at least (Hours Worked)</label><input id="a9" type="number" step="0.25" min="1" max="16" name="full_day_hours" value="<?=e($D['full_day_hours'])?>" required></div>
<div><label for="a10">Half Day = at least (Hours Worked)</label><input id="a10" type="number" step="0.25" min="0.25" max="16" name="half_day_hours" value="<?=e($D['half_day_hours'])?>" required></div></div>
<button class="btn">Save</button></form>
<p class="mut" style="margin-top:14px">Now: clock in from <b><?=e($D['clock_in_from'])?></b>, late after <b><?=e($D['work_start'])?></b> (+<?=e($D['grace'])?> min), clock out from <b><?=e($D['clock_out_from'])?></b>, office closed from <b><?=e($D['office_close'])?></b>.</p></div>
<?php foot();