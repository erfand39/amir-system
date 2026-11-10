<?php
declare(strict_types=1);
require dirname(__DIR__) . '/php/api.php';
putenv('DB_DRIVER=sqlite');putenv('SQLITE_PATH=:memory:');putenv('AMIR_ALLOW_BOOTSTRAP=true');
$cfg=read_config();$db=new DB($cfg);$s=new Shop($db,$cfg);$s->init();$api=new API($s);$passed=0;
function yes($v,$name){global $passed;if(!$v)throw new RuntimeException($name);$passed++;echo "PASS: $name\n";}
function fails($fn,$status=400){try{$fn();}catch(HttpError $e){yes($e->status===$status,'reject '.$status);return;}throw new RuntimeException('Unexpected acceptance');}
foreach([null,true,false,[],new stdClass(),'',' '] as $value) fails(fn()=>integer_value($value));
foreach(['javascript:alert(1)','https://user:pass@example.test/a.png','https://bad host/a.png','https://example.test/"a.png','/images/../config',[]] as $value) fails(fn()=>safe_url($value));
yes(safe_url('/images/placeholder.svg')==='/images/placeholder.svg','safe local image');
yes(safe_url('https://images.example.test/a.webp')==='https://images.example.test/a.webp','safe external image');
$guest=$s->newSession();
fails(fn()=>$api->handle('GET','admin/overview',[],[],$guest),401);
$u=$s->register(['name'=>'مشتری آزمون امنیت','phone'=>'09123456780','password'=>'Test-only-secure-7829']);
$buyer=$s->newSession($u['id']);
fails(fn()=>$api->handle('GET','admin/overview',[],[],$buyer),403);
fails(fn()=>$s->quote(['items'=>[null]]));
$p=$db->get('SELECT id FROM products WHERE active=1 AND stock>=1 LIMIT 1');
$o=$s->createOrder(['items'=>[['id'=>$p['id'],'qty'=>1]],'customer_name'=>'آزمون امنیت','mobile'=>'09123456780','province'=>'کرمان','city'=>'کرمان','address'=>'نشانی ساختگی آزمون امنیت','postal_code'=>'7611111111','payment_method'=>'cod'],$buyer,'security-order-key-001');
fails(fn()=>$s->order($o['id'],$guest),403);
fails(fn()=>$s->transition($o['id'],'cancelled',$buyer),403);
for($i=0;$i<2;$i++)$s->rate('security-quota',2,15);
fails(fn()=>$s->rate('security-quota',2,15),429);
$db->run("UPDATE sessions SET last_seen='2000-01-01 00:00:00' WHERE id=?",[$buyer['id']]);
yes($s->session($buyer['token'])===null,'idle session rejected');
yes(count($s->products(['q'=>"' OR 1=1 --"])['items'])===0,'SQL payload treated as search text');
echo "PHP security checks passed: $passed\n";
