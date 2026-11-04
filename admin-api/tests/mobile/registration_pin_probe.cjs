// Read-only live probes: no applicant/photo/location record is created.
const fs=require('node:fs');const path=require('node:path');const assert=require('node:assert/strict');
const env=fs.readFileSync(path.join(__dirname,'../../frontend/mobile/.env.local'),'utf8');
const publicBase=env.match(/^EXPO_PUBLIC_API_URL=(.+)$/m)?.[1].trim();
assert(publicBase&&/^https:\/\/[a-z0-9-]+\.trycloudflare\.com\/CAPSTONE%20AGRICULTURE\/api$/.test(publicBase),'No current public API URL');
(async()=>{
  for(const base of ['http://127.0.0.1/CAPSTONE%20AGRICULTURE/api','http://127.0.0.1:8090/CAPSTONE%20AGRICULTURE/api',publicBase]) {
    for(const [context,latitude,longitude,status,code] of [['residential',9.12,125.53,200,null],['farm',9.125,125.535,200,null],['residential',14.59,120.98,422,'PIN_OUTSIDE_CABADBARAN'],['farm','invalid',125.53,422,'INVALID_PIN']]) {
      const url=base+'/location/validate.php';const start=Date.now();
      const r=await fetch(url,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json'},body:JSON.stringify({context,latitude,longitude}),signal:AbortSignal.timeout(15000)});
      const body=await r.text();console.log(JSON.stringify({method:'POST',url,context,status:r.status,elapsedMs:Date.now()-start,timeoutMs:15000,validator:r.headers.get('X-AgriReach-Boundary-Validator'),gateway:r.headers.get('X-AgriReach-Gateway'),body}));
      assert.equal(r.status,status);assert.equal(r.headers.get('X-AgriReach-Boundary-Validator'),'local-geojson-v1');const p=JSON.parse(body);if(code)assert.equal(p.code,code);else assert.equal(p.data.status,'inside');
      if(base===publicBase)assert.equal(r.headers.get('X-AgriReach-Gateway'),'api-only');
    }
  }
  console.log('PASS: localhost, restricted gateway and ordinary public DNS/HTTPS: home/farm inside 200; outside and invalid 422. Actual PHP header/body; no records written.');
})().catch(e=>{console.error(e);process.exitCode=1;});
