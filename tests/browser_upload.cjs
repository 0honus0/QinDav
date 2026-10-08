const {chromium}=require('playwright');
const fs=require('node:fs'),assert=require('node:assert/strict'),crypto=require('node:crypto');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try{
 const page=await browser.newPage();await page.context().grantPermissions(['clipboard-read','clipboard-write']);const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(process.env.BENCH_URL);
 await page.locator('#username').fill('admin');await page.locator('#password').fill('browser-test-password-123');await page.locator('#confirm').fill('browser-test-password-123');
 await page.getByRole('button',{name:'创建账号',exact:true}).click();await page.locator('#upload-button').waitFor();
 let active=0,peak=0,failedPart=false,lostFinish=false,parts=0,direct=0;
 await page.route('**/*',async route=>{
  const req=route.request(),u=new URL(req.url());
  if(u.searchParams.get('api')==='upload-part'){
   parts++;active++;peak=Math.max(peak,active);
   try{
    if(!failedPart){failedPart=true;return await route.fulfill({status:503,contentType:'application/json',body:'{"error":"temporary test failure"}'});}
    await new Promise(r=>setTimeout(r,200));const response=await route.fetch();await route.fulfill({response});
   }finally{active--;}
  }else if(u.searchParams.get('api')==='upload-finish'&&!lostFinish){
   lostFinish=true;const response=await route.fetch();assert.equal(response.status(),200);await route.fulfill({status:503,contentType:'application/json',body:'{"error":"simulated lost acknowledgement"}'});
  }else{if(req.method()==='PUT'&&u.pathname.startsWith('/index.php/'))direct++;await route.continue();}
 });
 const paths=['big-one.bin','big-two.bin','small.txt'].map(n=>process.env.FIXTURE_DIR+'/'+n);
 await page.locator('#files').setInputFiles(paths);
 await page.waitForFunction(()=>document.querySelector('#status').textContent==='已上传 3 个文件',{},{timeout:60000});
 const hash=p=>crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex');
 for(const name of ['big-one.bin','big-two.bin','small.txt'])assert.equal(hash(process.env.FIXTURE_DIR+'/'+name),hash(process.env.STATE_DIR+'/files/'+name));
 assert.equal(errors.length,0,errors.join('\n'));assert.ok(peak>1&&peak<=4,'global concurrency '+peak);assert.equal(direct,1);assert.ok(parts>=11);assert.ok(failedPart&&lostFinish);
 assert.equal(fs.readdirSync(process.env.STATE_DIR+'/files').filter(n=>n.startsWith('.dav-upload-')).length,0);
 await page.locator('#files').setInputFiles(paths[0]);await page.waitForFunction(()=>document.querySelector('#status').textContent.includes('失败 1 个'),{},{timeout:10000});
 assert.equal(hash(paths[0]),hash(process.env.STATE_DIR+'/files/big-one.bin'));
 await page.locator('#filter').fill('small');assert.equal(await page.locator('#rows .filename').count(),1);
 await page.locator('#filter').fill('nothing-matches');assert.equal(await page.locator('#rows .filename').count(),0);
 await page.locator('#filter').fill('');await page.locator('#sort-size').click();assert.equal(await page.locator('#rows .filename').first().textContent(),'small.txt');
 await page.locator('#settings-button').click();assert.equal(await page.locator('#settings').evaluate(el=>el.open),true);
 assert.equal(await page.locator('#settings').evaluate(el=>el.matches(':modal')),true);
 page.on('dialog',dialog=>dialog.accept());
 await page.waitForFunction(()=>document.querySelector('#update-backups').textContent.includes('暂无程序备份'));
 assert.equal(await page.locator('#update-current').textContent(),process.env.APP_VERSION);
 await page.locator('#backup-keep').fill('3');await page.locator('#backup-save').click();
 await page.waitForFunction(()=>document.querySelector('#update-result').textContent.includes('保留最近 3 份'));
 assert.equal((await (await page.request.post(process.env.BENCH_URL+'/?api=update-info',{headers:{'X-CSRF-Token':await page.evaluate(()=>csrf)},data:{}})).json()).keep,3);
 let available=true,installedVersion=null,restoredId=null;
 await page.route('**/?api=update-check',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({current:'1.1.0',latest:available?'1.2.0':'1.1.0',available,can_update:true,requirements:[]})}));
 await page.route('**/?api=update-install',route=>{installedVersion=route.request().postDataJSON().version;return route.fulfill({status:423,contentType:'application/json',body:JSON.stringify({error:'仍有文件传输正在执行，请稍后重试'})});});
 await page.locator('#update-check').click();await page.waitForFunction(()=>!document.querySelector('#update-install').disabled);
 await page.locator('#update-install').click();await page.waitForFunction(()=>document.querySelector('#update-result').textContent.includes('仍有文件传输'));
 assert.equal(installedVersion,'1.2.0');
 available=false;await page.locator('#update-check').click();await page.waitForFunction(()=>document.querySelector('#update-result').textContent==='当前已是最新版本');
 assert.equal(await page.locator('#update-install').isDisabled(),true);
 const backupId='job-'+'0'.repeat(24);
 await page.route('**/?api=update-info',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({current:'1.1.0',can_update:true,requirements:[],keep:3,backups:[{id:backupId,version:'1.0.0',created_at:1700000000}]})}));
 await page.route('**/?api=update-restore',route=>{restoredId=route.request().postDataJSON().id;return route.fulfill({status:400,contentType:'application/json',body:JSON.stringify({error:'备份内容校验失败，不能回退'})});});
 await page.getByRole('button',{name:'关闭设置'}).click();await page.locator('#settings-button').click();
 await page.getByRole('button',{name:'回退此版本'}).click();await page.waitForFunction(()=>document.querySelector('#update-result').textContent.includes('备份内容校验失败'));
 assert.equal(restoredId,backupId);
 await page.unroute('**/?api=update-info');

 await page.locator('#app-password').click();await page.waitForFunction(()=>document.querySelector('#app-secret').value.length===48);
 const appPassword=await page.locator('#app-secret').inputValue();
 await page.locator('#copy-app').click();await page.waitForFunction(()=>document.querySelector('#app-result').textContent==='应用密码已复制');
 assert.equal(await page.evaluate(()=>navigator.clipboard.readText()),appPassword);
 await page.locator('#view-app').click();assert.equal(await page.locator('#app-secret').inputValue(),'');
 await page.locator('#view-app').click();await page.waitForFunction(()=>!document.querySelector('#app-secret').hidden);
 assert.equal(await page.locator('#app-secret').inputValue(),appPassword);
 await page.getByRole('button',{name:'关闭设置'}).click();assert.equal(await page.locator('#app-secret').inputValue(),'');
 await page.locator('#settings-button').click();await page.locator('#view-app').click();
 await page.waitForFunction(()=>!document.querySelector('#app-secret').hidden);assert.equal(await page.locator('#app-secret').inputValue(),appPassword);
 await page.locator('#revoke-app').click();await page.waitForFunction(()=>document.querySelector('#app-result').textContent==='应用密码已撤销');
 assert.equal(await page.locator('#app-secret').inputValue(),'');
 await page.locator('#view-app').click();await page.waitForFunction(()=>document.querySelector('#app-result').textContent==='尚未生成应用密码');
 await page.waitForFunction(()=>document.querySelector('#storage-used').textContent!=='—');
 await page.locator('#quota-limit').fill('0.25');await page.locator('#quota-unit').selectOption('1073741824');await page.locator('#quota-save').click();
 await page.waitForFunction(()=>document.querySelector('#storage-result').textContent==='容量上限已保存');
 let quota=await (await page.request.get(process.env.BENCH_URL+'/?api=storage')).json();assert.equal(quota.limit_bytes,268435456);assert.equal(quota.files,3);
 await page.locator('#quota-limit').fill('1');await page.locator('#quota-unit').selectOption('1048576');await page.locator('#quota-save').click();
 await page.waitForFunction(()=>document.querySelector('#storage-result').textContent.includes('不能小于'));
 await page.getByRole('button',{name:'关闭设置'}).click();assert.equal(await page.locator('#settings').evaluate(el=>el.open),false);
 assert.equal(await page.locator('#settings-button').evaluate(el=>el===document.activeElement),true);
 await page.locator('#settings-button').click();await page.keyboard.press('Escape');assert.equal(await page.locator('#settings').evaluate(el=>el.open),false);
 await page.locator('#settings-button').click();await page.mouse.click(5,5);assert.equal(await page.locator('#settings').evaluate(el=>el.open),false);
 const root=process.env.STATE_DIR+'/files';
 for(const name of ['Documents','Photos','backups'])fs.mkdirSync(root+'/'+name);
 for(const [name,content] of [['README.md','# File space\n'],['config.json','{"example":true}'],['project.zip','archive fixture'],['notes.txt','Notes\n'],['mount.sh','#!/bin/sh\n'],['travel.jpg','image fixture']])fs.writeFileSync(root+'/'+name,content);
 await page.locator('#refresh').click();await page.waitForFunction(()=>document.querySelectorAll('#rows .filename').length===12);
 await page.locator('#sort-name').click();await page.evaluate(()=>document.querySelector('#status').textContent='');
 await page.locator('#rows .filename').filter({hasText:'Documents'}).click();await page.getByRole('button',{name:'返回上级目录'}).waitFor();
 await page.getByRole('button',{name:'返回上级目录'}).click();await page.waitForFunction(()=>document.querySelectorAll('#rows .filename').length===12);
 await page.locator('#settings-button').click();await page.locator('#storage-rescan').click();await page.waitForFunction(()=>document.querySelector('#storage-result').textContent==='用量统计已更新');
 quota=await (await page.request.get(process.env.BENCH_URL+'/?api=storage')).json();assert.equal(quota.files,9);assert.equal(quota.reserved_bytes,0);
 await page.getByRole('button',{name:'关闭设置'}).click();

 await page.setViewportSize({width:1280,height:800});
 let listRect=await page.locator('#file-list').boundingBox();assert.ok(listRect.height/800>.78,'file list owns desktop viewport');
 assert.ok(listRect.y<125,'compact desktop toolbar');
 const large=root+'/Large directory';fs.mkdirSync(large);
 for(let i=0;i<420;i++)fs.writeFileSync(large+'/'+String(i).padStart(4,'0')+'-document.txt','fixture');
 await page.locator('#refresh').click();await page.waitForFunction(()=>document.querySelectorAll('#rows .filename').length===13);
 await page.locator('#rows .filename').filter({hasText:'Large directory'}).click();await page.waitForFunction(()=>document.querySelectorAll('#rows .filename').length===200);
 await page.locator('#rows .filename').first().evaluate(el=>el.dataset.marker='kept');
 await page.locator('#refresh').click();await page.waitForFunction(()=>document.querySelector('#file-list').getAttribute('aria-busy')==='false');
 assert.equal(await page.locator('#rows .filename').first().getAttribute('data-marker'),'kept','unchanged refresh preserves DOM');
 const changedName=await page.locator('#rows .filename').first().textContent();fs.writeFileSync(large+'/'+changedName,'changed file contents');
 await page.locator('#refresh').click();await page.waitForFunction(()=>!document.querySelector('#rows .filename[data-marker]'));
 assert.equal(await page.locator('#rows .filename').first().textContent(),changedName);
 assert.ok((await page.locator('#rows tr').filter({has:page.locator('.filename').filter({hasText:changedName})}).textContent()).includes('21 B'),'changed metadata re-renders');
 await page.locator('#file-list').evaluate(el=>el.scrollTop=600);
 const sticky=await page.locator('thead').boundingBox();listRect=await page.locator('#file-list').boundingBox();assert.ok(Math.abs(sticky.y-listRect.y)<2,'table header stays visible while scrolling');
 await page.locator('#next').click();await page.waitForFunction(()=>document.querySelector('#capacity').textContent.startsWith('第 2 页'));
 assert.equal(await page.locator('#rows .filename').count(),200);assert.equal(await page.locator('#file-list').evaluate(el=>el.scrollTop),0);
 await page.locator('#next').click();await page.waitForFunction(()=>document.querySelector('#capacity').textContent.startsWith('第 3 页'));
 assert.equal(await page.locator('#rows .filename').count(),20);assert.equal(await page.locator('#next').isDisabled(),true);
 await page.getByRole('button',{name:'返回上级目录'}).click();await page.waitForFunction(()=>document.querySelectorAll('#rows .filename').length===13);
 // A slow prior navigation must not replace the latest directory or its action paths.
 await page.route('**/?api=list&path=Slow&offset=0',async route=>{await new Promise(resolve=>setTimeout(resolve,250));await route.fulfill({contentType:'application/json',body:JSON.stringify({items:[{name:'stale.txt',directory:false,size:1,modified:1700000000}],more:false,offset:0,free:100000})}).catch(()=>{});});
 await page.evaluate(()=>{navigate('Slow');});await page.evaluate(()=>navigate('Photos'));await page.waitForFunction(()=>path==='Photos'&&!loading);
 await page.waitForTimeout(300);assert.equal(await page.locator('#rows .filename').count(),0);assert.equal(await page.evaluate(()=>path),'Photos');
 await page.evaluate(()=>navigate('Missing directory'));assert.equal(await page.evaluate(()=>path),'Photos','failed navigation preserves current path');
 await page.evaluate(()=>status(''));await page.evaluate(()=>navigate(''));await page.waitForFunction(()=>document.querySelectorAll('#rows .filename').length===13);
 // Capacity service can be slow: files must render without waiting for that response.
 let releaseStorage,storageStartedResolve;const storageHold=new Promise(resolve=>releaseStorage=resolve),storageStarted=new Promise(resolve=>storageStartedResolve=resolve);
 await page.route('**/?api=storage',async route=>{storageStartedResolve();await storageHold;await route.continue();});
 await page.locator('#refresh').click();await storageStarted;await page.waitForFunction(()=>document.querySelector('#file-list').getAttribute('aria-busy')==='false');
 assert.equal(await page.locator('#rows .filename').count(),13);releaseStorage();await page.unroute('**/?api=storage');
 await page.screenshot({path:'/tmp/qingdav-h5ai-desktop.png',fullPage:true});
 await page.setViewportSize({width:390,height:844});await page.screenshot({path:'/tmp/qingdav-h5ai-mobile.png',fullPage:true});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'mobile layout does not overflow');
 listRect=await page.locator('#file-list').boundingBox();assert.ok(listRect.height/844>.7,'file list owns mobile viewport');
 await page.setViewportSize({width:320,height:640});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'narrow mobile does not overflow');
 await page.setViewportSize({width:390,height:844});
 await page.locator('#settings-button').click();await page.screenshot({path:'/tmp/qingdav-settings-mobile.png'});
 assert.equal(await page.locator('#settings').evaluate(el=>el.getBoundingClientRect().left>=0&&el.getBoundingClientRect().right<=innerWidth),true,'settings fit on mobile');
 await page.getByRole('button',{name:'关闭设置'}).click();await page.setViewportSize({width:1280,height:800});await page.locator('#settings-button').click();await page.screenshot({path:'/tmp/qingdav-settings-desktop.png'});
 console.log(JSON.stringify({browser:'chromium',global_peak_concurrency:peak,chunk_requests:parts,small_direct_uploads:direct,chunk_retry_passed:failedPart,completion_retry_passed:lostFinish,all_hashes_verified:true,existing_file_preserved:true,filter_sort_navigation_settings_passed:true,quota_save_and_rescan_passed:true,update_and_backup_controls_passed:true,list_viewport_pagination_sticky_and_async_usage_passed:true,mobile_overflow:false,page_errors:errors}));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
