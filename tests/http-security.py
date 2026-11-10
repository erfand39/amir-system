import os,json,socket,subprocess,tempfile,time,urllib.request,urllib.error,http.cookiejar,pathlib
root=pathlib.Path(__file__).resolve().parents[1]
php=json.loads(os.environ.get('AMIR_TEST_PHP_COMMAND','["php"]'))+['-d','display_errors=0','-d','log_errors=1']
with tempfile.TemporaryDirectory(prefix='amir-security-') as tmp:
 env=dict(os.environ,DB_DRIVER='sqlite',SQLITE_PATH=tmp+'/shop.sqlite',AMIR_ALLOW_BOOTSTRAP='true',NODE_ENV='test',COOKIE_SECURE='false',ZARINPAL_MERCHANT_ID='',SETUP_TOKEN='test-only-setup-token-very-long-7829')
 subprocess.run(php+['-r',"require 'php/core.php';$cfg=read_config();$s=new Shop(new DB($cfg),$cfg);$s->init();"],cwd=root,env=env,check=True)
 with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
 origin=f'http://127.0.0.1:{port}';env['APP_URL']=origin
 log=open(tmp+'/server.log','w');proc=subprocess.Popen(php+['-S',f'127.0.0.1:{port}','php/router.php'],cwd=root,env=env,stdout=log,stderr=log)
 client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 count=0
 def req(path,method='GET',data=None,headers=None,status=200):
  global count
  h={} if headers is None else dict(headers)
  raw=None if data is None else (data if isinstance(data,bytes) else json.dumps(data).encode())
  if data is not None:h.setdefault('Content-Type','application/json')
  try: res=client.open(urllib.request.Request(origin+path,data=raw,method=method,headers=h))
  except urllib.error.HTTPError as e:res=e
  payload=res.read();assert res.code==status,(path,res.code,payload[:200]);count+=1
  return payload,res.headers
 try:
  for i in range(60):
   try:
    with socket.create_connection(('127.0.0.1',port),timeout=.1):break
   except OSError:time.sleep(.05)
  raw,h=req('/api/bootstrap');csrf=json.loads(raw)['data']['csrf']
  assert h.get('Cache-Control')=='no-store' and 'object-src' in h.get('Content-Security-Policy','')
  cookie=h.get('Set-Cookie','');assert 'HttpOnly' in cookie and 'SameSite=Lax' in cookie;count+=2
  req('/api/contact','POST',{},status=419)
  req('/api/contact','POST',{}, {'X-CSRF-Token':csrf,'Origin':'https://evil.test'},403)
  req('/api/contact','POST',b'not json',{'X-CSRF-Token':csrf},400)
  req('/api/contact','POST',[],{'X-CSRF-Token':csrf},400)
  req('/api/contact','POST',b'--multipart',{'X-CSRF-Token':csrf,'Content-Type':'multipart/form-data'},415)
  req('/api/contact','POST',b'x'*262145,{'X-CSRF-Token':csrf},413)
  req('/api/admin/overview',status=401)
  req('/api/products?q='+urllib.parse.quote("' OR 1=1 --"))
  req('/api/auth//login','POST',{}, {'X-CSRF-Token':csrf},400)
  req('/api/%61uth/login','POST',{}, {'X-CSRF-Token':csrf},400)
  for path in ['/.env','/php/core.php','/database/01_schema.sql','/tests/php-security.php']:req(path,status=404)
  page,h=req('/');assert 'frame-ancestors' in h.get('Content-Security-Policy','');count+=1
  # Failed setup attempts count toward the same durable quota.
  for i in range(5):req('/api/setup','POST',{'setup_token':'wrong'}, {'X-CSRF-Token':csrf},403)
  raw,h=req('/api/setup','POST',{'setup_token':'wrong'}, {'X-CSRF-Token':csrf},429);assert h.get('Retry-After')=='60';count+=1
  print('PHP HTTP security checks passed:',count)
 finally:proc.terminate();proc.wait(timeout=5);log.close()
