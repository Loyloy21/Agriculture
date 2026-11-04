// Standalone, isolated local Chrome QA. No user browser/profile/session access.
// Uses the normal login form and only records owned by the parent test fixture.
const {spawn} = require('node:child_process'), fs=require('node:fs'), path=require('node:path'), assert=require('node:assert/strict');
const out=path.resolve('tests/.artifacts/admin-redesign'), base=(process.env.AGRIREACH_TEST_BASE_URL || 'http://127.0.0.1')+'/CAPSTONE%20AGRICULTURE/admin/';
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
async function main(fixture) {
  fs.mkdirSync(out,{recursive:true}); const profile=fs.mkdtempSync(path.join(out,'chrome-qa-'));
  const chrome=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0','--remote-debugging-address=127.0.0.1','--user-data-dir='+profile,'about:blank'],{windowsHide:true,stdio:'ignore'});
  let socket, sequence=0; const pending=new Map(), errors=[], mapFailures=[], networkRequests=new Map();
  try {
    const portFile=path.join(profile,'DevToolsActivePort');
    for(let i=0;i<100&&!fs.existsSync(portFile);i++) await sleep(100);
    assert(fs.existsSync(portFile),'Isolated Chrome started'); const port=fs.readFileSync(portFile,'utf8').split('\n')[0];
    const tabs=await (await fetch('http://127.0.0.1:'+port+'/json/list')).json(); const tab=tabs.find(t=>t.type==='page');
    socket=new WebSocket(tab.webSocketDebuggerUrl); await new Promise((resolve,reject)=>{socket.onopen=resolve;socket.onerror=reject;});
    socket.onmessage=event=>{const m=JSON.parse(event.data);if(m.id){const p=pending.get(m.id);pending.delete(m.id);if(m.error)p.reject(Error(m.error.message));else p.resolve(m.result);}else if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text);else if(m.method==='Network.requestWillBeSent')networkRequests.set(m.params.requestId,m.params.request.url);else if(m.method==='Network.loadingFailed'){const url=networkRequests.get(m.params.requestId)||'';if(/maps\.(google|gstatic)|maps\.googleapis/.test(url)&&!m.params.canceled)mapFailures.push({origin:new URL(url).origin,error:m.params.errorText});}};
    const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++sequence;pending.set(id,{resolve,reject});socket.send(JSON.stringify({id,method,params}));});
    const evaluate=async expression=>{const result=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);return result.result.value;};
    const waitFor=async expr=>{for(let i=0;i<150;i++){if(await evaluate(expr))return;await sleep(100);}throw Error('Timed out: '+expr);};
    const navigate=async url=>{await send('Page.navigate',{url:base+url});await waitFor('document.readyState === "complete"');await sleep(150);};
    const viewport=async(width,height)=>send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
    const screenshot=async name=>{await sleep(180);const r=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(out,name+'.png'),Buffer.from(r.data,'base64'));};
    const click=async selector=>evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
    const close=async id=>{await click(id+' [data-bs-dismiss="modal"]');await waitFor(`!document.querySelector(${JSON.stringify(id)}).classList.contains('show')`);await sleep(220);};
    const modalCheck=async id=>{await waitFor(`document.querySelector(${JSON.stringify(id)}).classList.contains('show')`);await sleep(220);const metrics=await evaluate(`(()=>{const m=document.querySelector(${JSON.stringify(id)}),b=m.querySelector('.modal-body'),h=m.querySelector('.modal-header');const before=h.getBoundingClientRect().top;b.scrollTop=b.scrollHeight;return {client:b.clientHeight,scroll:b.scrollHeight,header:Math.abs(h.getBoundingClientRect().top-before),bottom:m.querySelector('.modal-content').getBoundingClientRect().bottom,width:document.documentElement.scrollWidth,inner:innerWidth};})()`);assert(metrics.client>100,'Modal has a usable body');assert(metrics.header<1,'Header stays fixed while body scrolls');assert(metrics.bottom<=1000,'Modal stays in viewport');assert(metrics.width<=metrics.inner+1,'No page-level horizontal clipping');return metrics;};
    await send('Runtime.enable');await send('Page.enable');await send('Network.enable');await viewport(1440,1000);
    await navigate('login.php');await evaluate(`document.querySelector('[name=username]').value=${JSON.stringify(fixture.marker)};document.querySelector('[name=password]').value=${JSON.stringify(fixture.password)};document.querySelector('form').requestSubmit();`);
    await waitFor('location.pathname.endsWith("dashboard.php") && document.readyState === "complete"');
    const routes=['dashboard','farmer-verification','manage-users','damage-reports','weather-advisory','email-settings','notifications','settings'];
    for(const width of [1440,820]){await viewport(width,1000);for(const route of routes){await navigate(route+'.php');await waitFor('typeof bootstrap !== "undefined"');const metrics=await evaluate('({width:document.documentElement.scrollWidth,inner:innerWidth,bg:getComputedStyle(document.body).backgroundColor})');assert(metrics.width<=width+1,route+' fits '+width);assert.equal(metrics.bg,'rgb(247, 245, 238)');await screenshot(route+'-'+width);console.log('PASS: '+route+' '+width+'px layout and loaded interactive framework');}}
    await viewport(1440,1000);await navigate('damage-reports.php?search='+encodeURIComponent(fixture.code));await click('[data-report-key="'+fixture.reportId+'"]');await modalCheck('#reportDetailsModal');await click('[data-report-tab="photos"]');await sleep(300);
    for(let i=0;i<3;i++){await click('[data-select-photo-id="'+fixture.images[i]+'"]');const state=await evaluate('({src:document.querySelector("#reportMap iframe")?.src || null,label:document.getElementById("selectedPhotoMapLabel").textContent,link:document.getElementById("mapExternalLink").getAttribute("href")})');assert(state.label.includes(String(fixture.images[i])));if(i===2){assert.equal(state.src,null);assert.equal(state.link,null);}else assert(state.src.includes(i===0?'9.1172%2C125.5296':'9.1128%2C125.5352'));}
    await click('[data-select-photo-id="'+fixture.images[0]+'"]');await evaluate('document.querySelector("#reportDetailsModal .modal-body").scrollTop=document.querySelector(".report-evidence-grid").offsetTop - 150');await sleep(8000);await screenshot('report-evidence-desktop');
    fs.writeFileSync(path.join(out,'google-map-network.json'),JSON.stringify(mapFailures,null,2));console.log('Google map resource diagnostics: '+JSON.stringify(mapFailures));
    await click('#satelliteMapButton');await sleep(5000);await screenshot('report-satellite-desktop');
    await click('#reportSelectedPhotoPreview');await waitFor('!document.getElementById("reportImageViewer").hidden');await waitFor('document.getElementById("reportImageViewerImage").naturalWidth > 0');
    await click('#nextReportImage');await waitFor(`document.getElementById('reportImageLocationText').textContent.includes(${JSON.stringify(String(fixture.images[1]))})`);
    await click('#nextReportImage');assert.equal(await evaluate('document.querySelector("#reportImageLocationMap iframe")'),null);await click('#closeReportImageViewer');
    await click('[data-select-photo-id="'+fixture.images[0]+'"]');
    await evaluate('document.querySelector("#reportDetailsModal .modal-body").scrollTop=0');await screenshot('report-modal-desktop');
    await viewport(820,1000);await modalCheck('#reportDetailsModal');await screenshot('report-modal-tablet');
    await close('#reportDetailsModal');await viewport(1440,1000);
    await navigate('manage-users.php?search='+encodeURIComponent(fixture.marker));await click('.view-user-btn[data-user-id="farmer:'+fixture.farmerId+'"]');await modalCheck('#userDetailsModal');assert.equal(await evaluate('document.querySelectorAll("#userDetailsModal .linked-farm").length'),3);await screenshot('user-details');await close('#userDetailsModal');
    await click('[data-bs-target="#addUserModal"]');const add=await modalCheck('#addUserModal');assert(add.scroll>add.client,'Long registration form scrolls');await screenshot('user-add-scroll');await close('#addUserModal');
    await navigate('farmer-verification.php?search=Controlled+Edited+Farmer&status=Approved');await click('[data-farmer-id="'+fixture.farmerId+'"]');await modalCheck('#farmerDetailsModal');assert.equal(await evaluate('document.querySelectorAll("#farmerDetailsModal .linked-farm").length'),3);await screenshot('verification-approved');await close('#farmerDetailsModal');
    await navigate('weather-advisory.php?advisory_id='+fixture.advisoryId);await waitFor('document.querySelector(".modal.show") !== null');await screenshot('advisory-details');
    await navigate('email-settings.php');await click('[data-bs-target="#senderModal"]');const sender=await modalCheck('#senderModal');assert(sender.scroll>sender.client,'Sender setup scrolls');await screenshot('email-sender-scroll');await close('#senderModal');
    assert.deepEqual(errors,[],'No uncaught JavaScript exceptions on tested pages/modals');
    console.log('PASS: Real browser photo-ID map switching/no-GPS clearing, linked farms, fixed modal headers, scrollable forms, desktop/tablet screenshots; no native-phone test implied.');
  } finally {socket?.close();chrome.kill();}
}
let input='';process.stdin.setEncoding('utf8');process.stdin.on('data',s=>input+=s);process.stdin.on('end',()=>main(JSON.parse(input)).catch(e=>{console.error(e.stack);process.exitCode=1;}));
