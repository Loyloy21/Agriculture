// Actual API client + screen handlers; native UI mocked, never phone acceptance.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('../../frontend/mobile/node_modules/typescript');
const {registration,fillRsbsa,fillPersonal,fillAccount,press,select,field} = require('./auth_redesign.cjs');
const {walk,text,flush} = require('./mobile_redesign.cjs');
const root = path.join(__dirname,'../../frontend/mobile/src');
function client(base, fetchImpl) {
  const timers = new Map(), logs = [], config = {}, api = {};
  const compile = file => ts.transpileModule(fs.readFileSync(path.join(root,file),'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2020}}).outputText;
  vm.runInNewContext(compile('config/api.ts'),{exports:config,process:{env:{EXPO_PUBLIC_API_URL:base}},URL,Error});
  vm.runInNewContext(compile('services/authApi.ts'),{
    exports:api,Error,URL,Date,AbortController,fetch:fetchImpl,__DEV__:true,
    console:{info:(...args)=>logs.push(args),warn:(...args)=>logs.push(args)},
    setTimeout:(fn,ms)=>{timers.set(1,{fn,ms});return 1;},clearTimeout:id=>timers.delete(id),
    require:name=>({'@/config/api':config,'expo/fetch':{fetch:fetchImpl},'expo-file-system':{File:class{}},'@/services/uploadImages':{MAX_ID_IMAGE_BYTES:5242880}}[name]),
  });
  return {...api,timers,logs};
}
const response=(status,payload)=>new Response(typeof payload==='string'?payload:JSON.stringify(payload),{status,headers:{'X-AgriReach-Boundary-Validator':'local-geojson-v1'}});
const inside={success:true,data:{status:'inside',source:'controlled-server-boundary'}};
async function errors() {
  for(const context of ['residential','farm']) {
    const field=context==='residential'?'residentialPin':'farmPin';
    for(const [status,payload,expected] of [
      [401,'<html>Denied</html>','HTTP 401'],[403,{},'HTTP 403'],[404,'Not found','HTTP 404'],[405,{},'HTTP 405'],
      [422,{success:false,code:'PIN_OUTSIDE_CABADBARAN',errors:{[field]:'Move this pin inside Cabadbaran.'}},'Move this pin inside Cabadbaran.'],
      [422,{success:false,code:'INVALID_PIN',errors:{[field]:'Invalid coordinate.'}},'server validation'],
      [503,{code:'BOUNDARY_DATA_UNAVAILABLE'},'boundary data is unavailable'],[503,'<html>Unavailable</html>','server or API tunnel'],
      [530,'<html>Cloudflare tunnel unavailable</html>','HTTP 530'],[200,'<html>Wrong endpoint</html>','non-API response'],[200,null,'non-API response'],
    ]) {
      const api=client('https://controlled.example/api',async()=>response(status,payload));
      await assert.rejects(api.validateFarmPin(9.12,125.53,context),e=>e instanceof api.RegistrationApiError && e.status===status && e.fields[field].includes(expected) && !e.message.includes('API is unreachable'));
      assert.equal(api.timers.size,0);
    }
    const network=client('https://expired.example/api',async()=>{throw new TypeError('fetch failed');});
    await assert.rejects(network.validateFarmPin(9.12,125.53,context),e=>e.status===undefined && e.fields[field].includes('No HTTP response'));
    const timeout=client('https://controlled.example/api',(_url,{signal})=>new Promise((_,reject)=>signal.addEventListener('abort',()=>reject(new Error('Aborted')))));
    const pending=timeout.validateFarmPin(9.12,125.53,context);assert.equal(timeout.timers.get(1).ms,15000);timeout.timers.get(1).fn();
    await assert.rejects(pending,e=>e.fields[field].includes('15 seconds'));assert.equal(timeout.timers.size,0);
    // The 15-second budget includes reading the response, not only its headers.
    const slowBody=client('https://controlled.example/api',async(_url,{signal})=>({status:200,ok:true,headers:new Headers(),json:()=>new Promise((_,reject)=>signal.addEventListener('abort',()=>reject(new Error('Aborted'))))}));
    const bodyPending=slowBody.validateFarmPin(9.12,125.53,context);await flush();slowBody.timers.get(1).fn();
    await assert.rejects(bodyPending,e=>e.fields[field].includes('15 seconds'));assert.equal(slowBody.timers.size,0);
    for(const base of ['', 'not-a-url']) {
      const bad=client(base,async()=>{throw Error('Must not fetch');});
      await assert.rejects(bad.validateFarmPin(9.12,125.53,context),e=>e.fields[field].includes(base?'invalid':'empty')&&e.fields[field].includes('restart Metro'));
    }
  }
  console.log('PASS: both contexts: blank/invalid URL, no-response network failure, header/body timeout, HTTP 401/403/404/405/422/503/530 and invalid JSON distinctly classified.');
}
async function screen(os) {
  let mode='ok', complete;
  const calls=[];
  const api=client('https://controlled.example/CAPSTONE%20AGRICULTURE/api',async(url,options)=>{
    calls.push({url,method:options.method,body:JSON.parse(options.body)});
    if(mode==='network')throw new TypeError('fetch failed');
    if(mode==='validation')return response(422,{success:false,code:'INVALID_PIN',message:'Controlled server validation error.'});
    if(mode==='outside')return response(422,{success:false,code:'PIN_OUTSIDE_CABADBARAN',message:'Outside Cabadbaran City.'});
    if(mode==='pending')return await new Promise(resolve=>{complete=()=>resolve(response(200,inside));});
    return response(200,inside);
  });
  const t=registration(os,393,{}, {validateFarmPin:api.validateFarmPin,RegistrationApiError:api.RegistrationApiError});const m=t.model;
  // Step 1 (RSBSA ID) first; the home pin is on step 2 (personal information).
  await fillRsbsa(m);press(m,'Next');fillPersonal(m,os);const original=field(m,'First Name').props.value;
  const maps=()=>walk(m.render()).filter(n=>n.type==='RegistrationPinMap');
  const modals=()=>walk(m.render()).filter(n=>n.type==='Modal'&&n.props.presentationStyle==='fullScreen');
  const openHome=()=>walk(m.render()).find(n=>n.props?.accessibilityLabel==='Pin home location on map').props.onPress();
  openHome();assert(modals()[0].props.visible);
  maps()[0].props.onPick({latitude:9.12,longitude:125.53});
  for(mode of ['network','validation','outside']) {
    await press(m,'Confirm Home Pin');assert(modals()[0].props.visible);assert.equal(field(m,'First Name').props.value,original);
    assert(!text(m.render()).includes('Home pin confirmed — tap to review'));
    assert(text(m.render()).includes(mode==='network'?'No HTTP response':mode==='validation'?'server validation':'Outside Cabadbaran'));
  }
  mode='pending';const pending=press(m,'Confirm Home Pin');await flush();modals()[0].props.onRequestClose();complete();await pending;await flush();
  assert(!text(m.render()).includes('Home pin confirmed — tap to review'),'Cancel must invalidate pending success');
  mode='ok';openHome();maps()[0].props.onPick({latitude:9.12,longitude:125.53});await press(m,'Confirm Home Pin');
  assert(!modals()[0].props.visible);assert.equal(field(m,'First Name').props.value,original);assert(text(m.render()).includes('2 of 3'));
  press(m,'Next');field(m,'Farm Name').props.onChangeText('Controlled independent farm');select(m,'Select farm barangay',{code:'1600203005',name:'Bay-ang'});field(m,'Purok / Sitio').props.onChangeText('Controlled farm purok');
  walk(m.render()).find(n=>n.type==='Pressable'&&text(n).includes('Pin farm location on map')).props.onPress();
  maps()[1].props.onPick({latitude:9.125,longitude:125.535});
  for(mode of ['network','validation','outside']) {
    await press(m,'Confirm Farm Pin');assert(modals()[1].props.visible);assert(text(m.render()).includes(mode==='network'?'No HTTP response':mode==='validation'?'server validation':'Outside Cabadbaran'));
  }
  mode='pending';const farmPending=press(m,'Confirm Farm Pin');await flush();modals()[1].props.onRequestClose();complete();await farmPending;await flush();
  assert(!text(m.render()).includes('Farm pin confirmed'),'Cancel must invalidate pending farm success');
  walk(m.render()).find(n=>n.type==='Pressable'&&text(n).includes('Pin farm location on map')).props.onPress();
  maps()[1].props.onPick({latitude:9.125,longitude:125.535});
  mode='ok';await press(m,'Confirm Farm Pin');assert(!modals()[1].props.visible);assert(text(m.render()).includes('3 of 3'));assert.equal(field(m,'Farm Name').props.value,'Controlled independent farm');assert.equal(field(m,'Purok / Sitio').props.value,'Controlled farm purok');
  const last=calls.at(-1);assert.equal(last.body.context,'farm');assert.equal(last.body.latitude,9.125);
  assert(calls.some(c=>c.body.context==='residential'&&c.body.latitude===9.12));assert(calls.every(c=>c.method==='POST'&&c.url==='https://controlled.example/CAPSTONE%20AGRICULTURE/api/location/validate.php'));
  fillAccount(m);press(m,'Back');assert(text(m.render()).includes('Selected home point: 9.120000, 125.530000'));press(m,'Next');assert.equal(field(m,'Password').props.value,'TypedPass9!');assert.equal(field(m,'Purok / Sitio').props.value,'Controlled farm purok');
  // Manual farm mode is still usable and clears the farm pin, not the home pin.
  walk(m.render()).find(n=>n.type==='Pressable'&&n.props?.accessibilityRole==='radio'&&text(n).includes('Select farm address')).props.onPress();
  assert(!text(m.render()).includes('Selected farm point:'));press(m,'Back');assert(text(m.render()).includes('Selected home point: 9.120000, 125.530000'));
  assert(!JSON.stringify(api.logs).includes('125.535'),'Diagnostics must not log coordinates');
  // Modal-specific provider and all four safe edges. Scrollable body; footer is outside it.
  for(const modal of modals()) {
    assert.equal(modal.props.children.type,'SafeAreaProvider');const safe=modal.props.children.props.children;
    assert.deepEqual(Array.from(safe.props.edges),['top','right','bottom','left']);
    const children=safe.props.children;assert(children.some(n=>n.type==='ScrollView'));assert.equal(children.at(-1).type,'View');assert(!walk(children.find(n=>n.type==='ScrollView')).some(n=>n.type==='Text'&&text(n).startsWith('Confirm ')));
  }
  console.log(`PASS: ${os} home/farm requests use same central URL; own confirmed points, validation rejection, cancellation of pending response, correct return step, preserved values/barangay/purok, modal provider + safe edges + scroll body + separate footer.`);
}
(async()=>{await errors();await screen('ios');await screen('android');})().catch(e=>{console.error(e);process.exitCode=1;});
