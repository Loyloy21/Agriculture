// Static, offline captures of actual JSX/styles. No API requests, real identities,
// native maps, keyboard, permissions, or physical-phone acceptance are simulated.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');
const { harness, walk } = require('./mobile_redesign.cjs');
const { registration, fillRsbsa, fillPersonal, fillFarm, fillAccount, press } = require('./auth_redesign.cjs');
const modules = path.resolve(__dirname, '../../frontend/mobile/node_modules');
const React = require(path.join(modules,'react'));
const DOM = require(path.join(modules,'react-dom/server'));
const RN = require(path.join(modules,'react-native-web'));
const ts = require(path.join(modules,'typescript'));
const src = path.resolve(__dirname,'../../frontend/mobile/src');
const out = path.resolve(__dirname,'../.artifacts/auth-redesign');
const fixture = name => 'file:///' + path.resolve(__dirname,'../../frontend/mobile',name).replaceAll('\\','/').replaceAll(' ','%20');
const jsx = (type, props) => ({ type, props });
const icons = { SymbolView: ({ name, size=22, tintColor }) => React.createElement('span',{ style:{ fontFamily:'Material',fontSize:size,color:tintColor,lineHeight:1,display:'inline-block',flexShrink:0 },'aria-hidden':true },name.web) };
function authComponents() {
  const exports = {}, file = path.join(src,'components/auth-ui.tsx');
  if (!fs.existsSync(file)) return {};
  vm.runInNewContext(ts.transpileModule(fs.readFileSync(file,'utf8'),{compilerOptions:{module:ts.ModuleKind.CommonJS,jsx:ts.JsxEmit.ReactJSX}}).outputText,{
    exports, require: name => {
      if (name.startsWith('@/assets/')) return fixture(name.slice(2));
      const mocks = { 'react/jsx-runtime':{jsx,jsxs:jsx}, 'react-native':{...RN,Image:'Image',View:'View',Text:'Text'}, 'expo-symbols':{SymbolView:'SymbolView'}, 'expo-image':{Image:'ExpoImage'} };
      if (!(name in mocks)) throw Error('Unexpected auth component import '+name); return mocks[name];
    },
  });
  return exports;
}
const auth = authComponents();
function convert(node) {
  if (node == null || typeof node !== 'object') return node;
  if (Array.isArray(node)) return node.map((child,index) => { const result=convert(child);return React.isValidElement(result)?React.cloneElement(result,{key:child?.key??index}):result; });
  const {type,props={}} = node;
  if (props.visible === false) return null;
  if (['AuthBrand','AuthIcon','AuthScenery','AuthLandscape'].includes(type)) return convert(auth[type](props));
  if (typeof type === 'function') return convert(type(props));
  const children = convert(props.children), cleaned = {...props,children};
  if (!type) return React.createElement(React.Fragment,null,children);
  if (type === 'SymbolView') return icons.SymbolView(props);
  if (type === 'StatusBar') return null;
  if (type === 'ExpoImage') return React.createElement('img',{src:typeof props.source==='string'?props.source:props.source.uri,alt:'',style:{...RN.StyleSheet.flatten(props.style),objectFit:'cover',objectPosition:'65% 50%'}});
  if (type === 'MapView' || type === 'DateTimePicker') return React.createElement(RN.View,{style:{padding:16,backgroundColor:'#E8EFDF'}},React.createElement(RN.Text,null, 'Native '+type+' — physical test pending'));
  if (type === 'Marker') return null;
  if (type === 'SafeAreaView') return React.createElement(RN.View,{...cleaned,style:[props.style,{paddingTop:44,paddingBottom:24}]});
  if (type === 'Image' && typeof props.source === 'string') return React.createElement('img',{src:props.source,alt:'',style:{...RN.StyleSheet.flatten(props.style),objectFit:'cover'}});
  const component = RN[type]; if (!component) throw Error('Unexpected renderer '+type);
  return React.createElement(component,cleaned);
}
const names = [];
function save(name,tree,width=393,height=852,position='top') {
  names.push(name);
  const html = DOM.renderToString(React.createElement(RN.View,{style:{width,height}},convert(tree)));
  const sheet = RN.StyleSheet.getSheet();
  const scroll = position==='top'?'':`<script>document.fonts.ready.then(()=>{for(const el of document.querySelectorAll('div')){if(['auto','scroll'].includes(getComputedStyle(el).overflowY)&&el.scrollHeight>el.clientHeight+50){el.scrollTop=${position==='bottom'?'el.scrollHeight':position};break;}}});</script>`;
  fs.writeFileSync(path.join(out,name+'.html'),`<!doctype html><html><head><meta charset="utf-8"><style>${sheet.textContent}</style><style>@font-face{font-family:Material;src:url('${fixture('node_modules/@expo-google-fonts/material-symbols/400Regular/MaterialSymbols_400Regular.ttf')}')}html,body{margin:0;background:#F7F5EE;width:100%;height:100%}*{box-sizing:border-box}</style></head><body>${html}${scroll}</body></html>`);
  const profile = fs.mkdtempSync(path.join(out,'chrome-render-'));
  const result = spawnSync('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless','--disable-gpu','--no-first-run','--no-default-browser-check','--hide-scrollbars',`--user-data-dir=${profile}`,'--allow-file-access-from-files','--force-device-scale-factor=2',`--window-size=${width},${height}`,'--virtual-time-budget=2500',`--screenshot=${path.join(out,name+'.png')}`,'file:///'+path.join(out,name+'.html').replaceAll('\\','/').replaceAll(' ','%20')],{windowsHide:true,timeout:30000,stdio:'pipe'});
  if (result.status!==0 || !fs.existsSync(path.join(out,name+'.png'))) throw Error('Static screenshot failed: '+name+' '+(result.error?.message||'exit '+result.status)+' '+String(result.stderr).slice(-1600));
  console.log('Rendered '+name);
}
(async()=>{
  fs.mkdirSync(out,{recursive:true});
  for(const [name,file] of [['welcome','index'],['login','login'],['forgot-password','forgot-password']]) {
    const mocks = { '@/components/auth-ui':{AuthBrand:'AuthBrand',AuthIcon:'AuthIcon',AuthScenery:'AuthScenery',AuthLandscape:'AuthLandscape',authColors:auth.authColors,authStyles:auth.authStyles}, 'expo-image':{Image:'ExpoImage'}, '@/assets/images/redesign/home-hero.jpg':fixture('assets/images/redesign/home-hero.jpg'), 'expo-symbols':{SymbolView:'SymbolView'},'expo-status-bar':{StatusBar:'StatusBar'},'@/services/farmerAuthApi':{FarmerAuthApiError:class extends Error{}},'@/services/passwordResetApi':{PasswordResetApiError:class extends Error{},requestPasswordResetCode:async()=>{},verifyPasswordResetCode:async()=>{},completePasswordReset:async()=>{}},'@/state/farmerAuthContext':{useFarmerAuth:()=>({login:async()=>{}})} };
    save(name,harness('app/'+file+'.tsx',mocks,'ios',393).render());
    if(name!=='welcome') save(name+'-bottom',harness('app/'+file+'.tsx',mocks,'ios',393).render(),393,852,'bottom');
    save(name+'-compact',harness('app/'+file+'.tsx',mocks,'android',320).render(),320,640);
  }
  // Synthetic placeholder cards stand in for ID photos; no real identity image is used.
  const card = (label) => 'data:image/svg+xml;utf8,' + encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="640" height="404" viewBox="0 0 640 404"><rect width="640" height="404" rx="28" fill="#E8EFDF"/><rect x="24" y="24" width="592" height="356" rx="20" fill="none" stroke="#174D36" stroke-width="4" stroke-dasharray="14 10"/><text x="320" y="190" text-anchor="middle" font-family="Arial" font-size="34" fill="#174D36">Controlled test card</text><text x="320" y="240" text-anchor="middle" font-family="Arial" font-size="26" fill="#637068">${label} (not a real ID)</text></svg>`);
  const uploads = { MAX_ID_IMAGE_BYTES: 5242880, UploadImagePreparationError: class extends Error {}, preparePickedImage: async (_asset, side) => ({ uri: card(side.endsWith('front') ? 'front side' : 'back side'), fileName: side + '.png', mimeType: 'image/png', size: 120 }) };
  const model = registration('ios',393,{ authColors:auth.authColors },{},uploads).model;
  save('registration-1',model.render());
  await fillRsbsa(model); save('registration-1-filled',model.render()); save('registration-1-bottom',model.render(),393,852,'bottom');
  press(model,'Next'); save('registration-2',model.render()); fillPersonal(model,'ios'); save('registration-2-bottom',model.render(),393,852,'bottom');
  press(model,'Next'); fillFarm(model); fillAccount(model);
  save('registration-3',model.render());save('registration-3-bottom',model.render(),393,852,'bottom');
  save('registration-1-compact',registration('android',320,{authColors:auth.authColors},{},uploads).model.render(),320,640);
  const compact = registration('android',320,{authColors:auth.authColors},{},uploads).model; await fillRsbsa(compact,'000102003000045'); save('registration-1-compact-bottom',compact.render(),320,640,'bottom');
  press(compact,'Next'); save('registration-2-compact',compact.render(),320,640);
  fs.writeFileSync(path.join(out,'index.html'),`<!doctype html><html><head><meta charset="utf-8"><title>AgriReach auth implementation captures</title><style>body{font:16px system-ui;background:#F7F5EE;color:#172B24;padding:24px}main{display:flex;gap:20px;flex-wrap:wrap}figure{margin:0;width:280px}img{width:100%;border-radius:12px}</style></head><body><h1>Actual auth-screen JSX/styles</h1><p>Offline React Native Web layout captures with controlled test data. Not screenshots of a physical phone. Native keyboard, map and picker checks remain pending.</p><main>${names.map(name=>`<figure><a href="${name}.png">${name}<img src="${name}.png" alt="${name}"></a></figure>`).join('')}</main></body></html>`);
})().catch(error=>{console.error(error);process.exitCode=1;});
