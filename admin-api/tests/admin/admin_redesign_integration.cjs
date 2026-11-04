const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm'), { spawnSync } = require('node:child_process');
const php = 'C:/xampp/php/php.exe', base = (process.env.AGRIREACH_TEST_BASE_URL || 'http://127.0.0.1') + '/CAPSTONE%20AGRICULTURE/';
const fixtureScript = path.resolve('tests/admin/admin_redesign_fixture.php');
const runPHP = (mode, fixture) => { const r = spawnSync(php,['-d','extension=gd',fixtureScript,mode],{input:fixture ? JSON.stringify(fixture):undefined,encoding:'utf8',windowsHide:true}); if(r.status!==0)throw Error(r.stderr || r.stdout);return r.stdout; };
let fixture, cookie = '';
async function admin(url, body) {
  const response = await fetch(base+'admin/'+url,{method:body?'POST':'GET',headers:{...(cookie?{Cookie:cookie}:{}),...(body?{'Content-Type':'application/x-www-form-urlencoded'}:{})},body:body?new URLSearchParams(body):undefined,redirect:'manual'});
  for (const value of response.headers.getSetCookie()) if(value.startsWith('AGRIREACH_ADMIN=')) cookie=value.split(';')[0];
  const text=await response.text();return {status:response.status,text,location:response.headers.get('location')};
}
async function api(url,token,body){const r=await fetch(base+'api/'+url,{method:body?'POST':'GET',headers:{Accept:'application/json',...(token?{Authorization:'Bearer '+token}:{}),...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify(body):undefined});return {status:r.status,data:await r.json()};}
function csrf(html){const value=html.match(/name="csrf_token" value="([^"]+)"/);assert(value,'CSRF field present');return value[1];}
const pass=text=>console.log('PASS: '+text);
(async()=>{
  try {
    fixture=JSON.parse(runPHP('setup'));
    let response=await admin('login.php',{username:fixture.marker,password:fixture.password}); assert.equal(response.status,302); pass('Normal admin login using this run’s controlled account');
    const pages=['dashboard','farmer-verification','manage-users','damage-reports','weather-advisory','email-settings','notifications','settings'];
    for(const page of pages){response=await admin(page+'.php');assert.equal(response.status,200);assert.match(response.text,/admin-redesign\.css/);assert.doesNotMatch(response.text,/Fatal error|Warning:|data is not available|data is unavailable|dashboard data is unavailable/);for(const script of response.text.matchAll(/<script([^>]*)>([\s\S]*?)<\/script>/g))if(!/application\/json|\bsrc=/.test(script[1]))new vm.Script(script[2],{filename:page+'.php inline'});pass(page+' authenticated live page and inline JavaScript parse');}
    response=await admin('manage-users.php?search='+fixture.marker);let match=response.text.match(new RegExp('<script type="application/json" id="user-data-farmer:'+fixture.farmerId+'">([^<]+)</script>'));assert(match);assert.equal(JSON.parse(match[1]).linked_farms.length,3);pass('User details contain every linked farm, including a labelled archive');
    let token=csrf(response.text);
    response=await admin('manage-users.php',{csrf_token:token,action:'edit',user_type:'farmer',user_id:fixture.farmerId,full_name:'Controlled Edited Farmer',contact_number:fixture.contact,email:fixture.email,address:'Controlled edited address'});assert.equal(response.status,302);
    let login=await api('auth/login.php',null,{email:fixture.email,password:fixture.password});assert.equal(login.status,200);let farmerToken=login.data.token;
    let me=await api('auth/me.php',farmerToken);assert.match(JSON.stringify(me.data),/Controlled Edited Farmer/);pass('Admin user edit appears in that farmer’s authenticated profile');
    response=await admin('manage-users.php',{csrf_token:token,action:'block',user_type:'farmer',user_id:fixture.farmerId});assert.equal(response.status,302);assert.equal((await api('auth/me.php',farmerToken)).status,401);
    await admin('manage-users.php',{csrf_token:token,action:'block',user_type:'farmer',user_id:fixture.farmerId});login=await api('auth/login.php',null,{email:fixture.email,password:fixture.password});assert.equal(login.status,200);farmerToken=login.data.token;pass('Block denies farmer API access; unblock permits normal login');
    response=await admin('notifications.php?search=Controlled+QA');token=csrf(response.text);
    await admin('notifications.php',{csrf_token:token,action:'mark_read',notification_id:fixture.notificationId});
    let notices=(await api('notifications/index.php',farmerToken)).data.data;let own=notices.find(n=>n.id===String(fixture.notificationId));assert.equal(own.isRead,false);
    await admin('notifications.php',{csrf_token:token,action:'delete',notification_id:fixture.notificationId});notices=(await api('notifications/index.php',farmerToken)).data.data;assert(notices.find(n=>n.id===String(fixture.notificationId)));pass('Admin read/archive does not change or delete farmer read status/notice');
    await admin('notifications.php',{csrf_token:token,action:'send',recipient_type:'individual',farmer_id:fixture.farmerId,send_type:'General',title:'Controlled sent QA',message:'Controlled notification action check'});notices=(await api('notifications/index.php',farmerToken)).data.data;assert(notices.some(n=>n.title==='Controlled sent QA'));pass('Individual send reaches the intended farmer’s Notifications');
    response=await admin('damage-reports.php?search='+fixture.code);token=csrf(response.text);
    response=await admin('damage-reports.php',{csrf_token:'invalid',action:'save_assessment',report_id:fixture.reportId,final_assessed_cost:'11000',assessment_remarks:'Controlled findings'});assert.equal(response.status,403);
    response=await admin('damage-reports.php',{csrf_token:token,action:'save_assessment',report_id:fixture.reportId,final_assessed_cost:'11000',assessment_remarks:'Controlled findings'});assert.equal(response.status,200);assert.equal(JSON.parse(response.text).report.status,'Under Review');
    let draft=(await api('reports/show.php?id='+fixture.code,farmerToken)).data.data;assert.equal(draft.finalAssessedCost,null);assert.equal(draft.assessmentRemarks,null);
    response=await admin('damage-reports.php',{csrf_token:token,action:'resolve_report',report_id:fixture.reportId,final_assessed_cost:'11000',assessment_remarks:'Controlled final findings'});assert.equal(response.status,200);
    let report=(await api('reports/show.php?id='+fixture.code,farmerToken)).data.data;assert.equal(report.status,'Resolved');assert.equal(report.estimatedCost,12500.5);assert.equal(report.finalAssessedCost,11000);assert.match(report.adminResponse,/PHP 11,000\.00\nControlled final findings/);assert.equal(report.photos.length,3);assert.equal(report.photos[2].latitude,null);
    let listed=(await api('reports/index.php',farmerToken)).data.data.find(r=>r.id===fixture.code);assert.equal(listed.finalAssessedCost,11000);assert.match(listed.adminResponse,/Controlled final findings/);
    notices=(await api('notifications/index.php',farmerToken)).data.data;assert.equal(notices.filter(n=>n.title==='Damage report resolved'&&n.relatedReportId===fixture.code).length,1);assert.match(notices.find(n=>n.title==='Damage report resolved'&&n.relatedReportId===fixture.code).fullMessage,/PHP 11,000\.00/);
    response=await admin('damage-reports.php',{csrf_token:token,action:'resolve_report',report_id:fixture.reportId,final_assessed_cost:'11000',assessment_remarks:'Controlled final findings'});assert.equal(response.status,409);pass('CSRF guard, draft save, resolve, read-only duplicate guard; correct farmer My Reports/Notifications and all photo IDs preserved');
    const pdfResponse=await fetch(base+'admin/damage-reports-pdf.php?mode=single&report_id='+fixture.reportId,{headers:{Cookie:cookie}});assert.equal(pdfResponse.status,200);const pdf=Buffer.from(await pdfResponse.arrayBuffer());assert.equal(pdf.subarray(0,5).toString(),'%PDF-');
    fs.mkdirSync('tests/.artifacts/admin-redesign',{recursive:true});fs.writeFileSync('tests/.artifacts/admin-redesign/controlled-final.pdf',pdf);pass('Authenticated final PDF generated from the saved controlled assessment');
    response=await admin('weather-advisory.php?advisory_id='+fixture.advisoryId);token=csrf(response.text);
    await admin('weather-advisory.php',{csrf_token:token,action:'publish',advisory_id:fixture.advisoryId});let advice=(await api('weather/advisories.php',farmerToken)).data.data.items;assert(advice.some(a=>String(a.id)===String(fixture.advisoryId)));
    await admin('weather-advisory.php',{csrf_token:token,action:'publish',advisory_id:fixture.advisoryId});notices=(await api('notifications/index.php',farmerToken)).data.data;assert.equal(notices.filter(n=>String(n.relatedAdvisoryId)===String(fixture.advisoryId)).length,1);
    await admin('weather-advisory.php',{csrf_token:token,action:'archive',advisory_id:fixture.advisoryId});advice=(await api('weather/advisories.php',farmerToken)).data.data.items;assert(!advice.some(a=>String(a.id)===String(fixture.advisoryId)));pass('Publish/archive use farmer Weather Advice rules, with one notification on repeated publish');
    response=await admin('settings.php');token=csrf(response.text);
    await admin('settings.php',{csrf_token:token,action:'save_profile',full_name:'Controlled Updated Reviewer',username:fixture.marker,email:fixture.marker+'@example.test',contact_number:'+639000000677'});
    await admin('settings.php',{csrf_token:token,action:'save_preferences',new_farmer_registration:'on',new_damage_reports:'on'});response=await admin('settings.php');assert.match(response.text,/Controlled Updated Reviewer/);assert.match(response.text,/name="new_damage_reports" checked/);assert.doesNotMatch(response.text,/name="weather_advisory_updates" checked/);
    await admin('settings.php',{csrf_token:token,action:'change_password',current_password:fixture.password,new_password:fixture.password+'New',confirm_password:fixture.password+'New'});await admin('logout.php');fixture.password+='New';response=await admin('login.php',{username:fixture.marker,password:fixture.password});assert.equal(response.status,302);pass('Profile/preferences/password saved only for the controlled admin');
    if(process.argv.includes('--browser')) { const browserRun=spawnSync(process.execPath,['tests/admin/admin_browser_review.cjs'],{input:JSON.stringify(fixture),encoding:'utf8',windowsHide:true,timeout:300000});process.stdout.write(browserRun.stdout || '');if(browserRun.status!==0)throw Error(browserRun.stderr || 'Browser checks failed'); }
  } finally { if(fixture) process.stdout.write(runPHP('cleanup',fixture)); }
})().catch(e=>{console.error(e.message);process.exitCode=1;});
