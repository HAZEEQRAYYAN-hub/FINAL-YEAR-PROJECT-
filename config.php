<?php
// ===== SETTINGS =====
const DB_HOST='localhost', DB_USER='root', DB_PASS='', DB_NAME='attendance_db';
const TAP_KEY='12345';           // secret the NodeMCU must send
const WORK_START='09:00', WORK_END='17:00';
const OFFICE_CLOSE='18:00';                // no taps accepted from this time
const LATE_GRACE=0, EARLIEST='06:00', MIN_GAP=1;   // minutes / time
date_default_timezone_set('Asia/Kuala_Lumpur');
const ADMIN_TABS=['live'=>'Live Today','report'=>'Reports','leaves'=>'Leave Requests','manage_staff.php'=>'Manage Staff','manage_rfid.php'=>'Manage RFID'];
// ====================
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
session_start();
$db=new mysqli(DB_HOST,DB_USER,DB_PASS,DB_NAME); $db->set_charset('utf8mb4');
$refresh='';

function q($sql,$types='',...$p){global $db;$s=$db->prepare($sql);if($types)$s->bind_param($types,...$p);$s->execute();return $s;}
function rows($s){return $s->get_result()->fetch_all(MYSQLI_ASSOC);}
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES);}
function mins($t){[$h,$m]=explode(':',$t);return $h*60+$m;}
function wd($a,$b){$n=0;for($d=strtotime($a);$d<=strtotime($b);$d+=86400)if(date('N',$d)<6)$n++;return $n;}
function need($role){if(empty($_SESSION['u'])||$_SESSION['u']['role']!==$role){header('Location: index.php');exit;}}
function csrf(){if(empty($_SESSION['t']))$_SESSION['t']=bin2hex(random_bytes(16));return '<input type="hidden" name="t" value="'.$_SESSION['t'].'">';}
function chk(){if(!hash_equals($_SESSION['t']??'',$_POST['t']??''))die('Bad request');}
function is_current($u,$d){return !empty($u['active'])&&(empty($u['start_date'])||$u['start_date']<=$d)&&(empty($u['end_date'])||$u['end_date']>=$d);}
function pill($s){$c=preg_match('/On Time|Approved/',$s)?'ok':(preg_match('/Late|Pending|leave|Early/',$s)?'warn':(preg_match('/Rejected|Not in/',$s)?'bad':'mut'));return '<span class="p '.$c.'">'.e($s).'</span>';}

function head($title,$base='',$tabs=[],$cur=''){global $refresh;
echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).'</title>'.$refresh.'<style>
:root{--bg:#eef2f3;--card:#fff;--tx:#14232b;--mut:#5d6f78;--pri:#0f766e;--prx:#fff;--ln:#d9e1e4;--ok:#0f766e;--warn:#a85b00;--bad:#b3261e;--hd:#14232b}
@media(prefers-color-scheme:dark){:root{--bg:#10191e;--card:#18242b;--tx:#e6eef0;--mut:#9bb0b8;--pri:#3fb8ac;--prx:#08201d;--ln:#283840;--ok:#5fd0c3;--warn:#f0a948;--bad:#ff8a80;--hd:#0b1215}}
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,sans-serif;background:var(--bg);color:var(--tx)}
header{background:var(--hd);color:#e6eef0;padding:12px 20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}header b{font-size:17px;flex:1}header a{color:#e6eef0}
main{max-width:1000px;margin:0 auto;padding:18px 14px}nav{display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap}
nav a{border:1px solid var(--ln);color:var(--tx);padding:8px 14px;border-radius:6px;text-decoration:none}nav a.on{background:var(--pri);color:var(--prx);border-color:var(--pri);font-weight:600}
.card{background:var(--card);border:1px solid var(--ln);border-radius:8px;padding:16px;margin-bottom:14px}h2{margin:0 0 12px;font-size:18px}
label{display:block;font-size:13px;color:var(--mut);margin:10px 0 4px}input,select,textarea{width:100%;padding:9px 10px;border:1px solid var(--ln);border-radius:6px;background:var(--bg);color:var(--tx);font:inherit}
.btn{background:var(--pri);color:var(--prx);border:0;padding:9px 16px;border-radius:6px;font:inherit;font-weight:600;cursor:pointer;margin-top:12px}.btn.s{padding:4px 10px;margin:0 4px 0 0;font-size:13px}.btn.x{background:var(--bad);color:#fff}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.row>div{flex:1;min-width:130px}.wrap{overflow-x:auto}
table{border-collapse:collapse;width:100%;font-size:14px}th,td{padding:8px 10px;border-bottom:1px solid var(--ln);text-align:left;white-space:nowrap}th{color:var(--mut)}
.p{padding:2px 9px;border-radius:99px;font-size:12px;font-weight:600;border:1px solid currentColor}.ok{color:var(--ok)}.warn{color:var(--warn)}.bad{color:var(--bad)}.mut{color:var(--mut)}
.stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}.stats div{flex:1;min-width:110px;padding:10px 12px;border:1px solid var(--ln);border-radius:8px;background:var(--card)}.stats b{display:block;font-size:24px}
.msg{background:var(--hd);color:#fff;padding:10px 14px;border-radius:8px;margin-bottom:12px}form.i{display:inline}
@media print{header,nav,.noprint{display:none}body{background:#fff;color:#000}}
</style></head><body><header><b>Attendance System</b>';
if(!empty($_SESSION['u']))echo '<span>'.e($_SESSION['u']['name']).'</span><a href="index.php?out=1">Log out</a>';
echo '</header><main>';
if($tabs){echo '<nav>';foreach($tabs as $k=>$l)echo '<a href="'.(strpos($k,'.php')!==false?$k:$base.'?p='.$k).'"'.($k===$cur?' class="on"':'').'>'.e($l).'</a>';echo '</nav>';}
}
function foot(){echo '</main></body></html>';}