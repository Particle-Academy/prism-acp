// Pure credential/home behavior from the actual published reference. All keys and homes are owned fixtures.
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, writeFileSync, rmSync, mkdirSync, symlinkSync } from 'node:fs';
import { tmpdir, homedir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
const reference=resolve(process.argv[2]??'');
const version=JSON.parse(readFileSync(join(reference,'package.json'),'utf8')).version;
assert.equal(version,'0.25.1','use the reviewed published reference');
const {apiKeyHome,apiKeyEnvironment}=await import(pathToFileURL(join(reference,'dist/credentials.js')).href);
const scenarios=[
  {name:'default-subscription',provider:'claude'},
  {name:'explicit-subscription',provider:'codex',credentials:{mode:'subscription'}},
  {name:'invalid-mode',provider:'codex',credentials:{mode:'wrong'}},
  ...[null,'',' \ufeff\u00a0','dummy\nkey','dummy\0key','dummy\u007fkey'].map((apiKey,index)=>({name:'invalid-key-'+index,provider:'claude',credentials:{mode:'apiKey',apiKey,home:'@root/app'}})),
  {name:'relative-home',provider:'codex',credentials:{mode:'apiKey',apiKey:'dummy',home:'relative'}},
  {name:'file-home',provider:'claude',credentials:{mode:'apiKey',apiKey:'dummy',home:'@root/file'}},
  ...['claude','codex'].flatMap((provider)=>[
    {name:provider+'-default-home',provider,credentials:{mode:'apiKey',apiKey:'dummy',home:'@native/.'+provider}},
    {name:provider+'-moved-home',provider,credentials:{mode:'apiKey',apiKey:'dummy',home:'@root/moved/./x/../'},parentEnv:{[provider==='claude'?'claude_config_dir':'codex_home']:'@root/moved'}},
    {name:provider+'-app-home',provider,credentials:{mode:'apiKey',apiKey:'arbitrary dummy token',home:'@root/tenant/provider'}},
    {name:provider+'-linked-home',setup:'aliases',provider,credentials:{mode:'apiKey',apiKey:'dummy',home:'@root/alias'},parentEnv:{[provider==='claude'?'CLAUDE_CONFIG_DIR':'CODEX_HOME']:'@root/moved'}},
    {name:provider+'-chained-home',setup:'aliases',provider,credentials:{mode:'apiKey',apiKey:'dummy',home:'@root/chain'},parentEnv:{[provider==='claude'?'CLAUDE_CONFIG_DIR':'CODEX_HOME']:'@root/moved'}},
  ]),
];
const root=mkdtempSync(join(tmpdir(),'prism-acp-key-oracle-'));
const expand=(value)=>typeof value==='string'?value.replaceAll('@root',root).replaceAll('@native',homedir())
  :Array.isArray(value)?value.map(expand):value&&typeof value==='object'?Object.fromEntries(Object.entries(value).map(([k,v])=>[k,expand(v)])):value;
const normalize=(value)=>typeof value==='string'?value.replaceAll(root,'@root').replaceAll('\\','/')
  :Array.isArray(value)?value.map(normalize):value&&typeof value==='object'?Object.fromEntries(Object.entries(value).map(([k,v])=>[k,normalize(v)])):value;
try{
  writeFileSync(join(root,'file'),'sentinel');
  mkdirSync(join(root,'moved'));
  symlinkSync(join(root,'moved'),join(root,'alias'),process.platform==='win32'?'junction':'dir');
  symlinkSync(join(root,'alias'),join(root,'chain'),process.platform==='win32'?'junction':'dir');
  for(const scenario of scenarios){
    const input=expand(scenario);
    try{
      const checked=apiKeyHome(input.provider,input.credentials,input.parentEnv??{});
      scenario.expected=normalize({ok:true,result:checked??null,...(checked?{env:apiKeyEnvironment(input.provider,checked)}:{})});
    }catch(error){scenario.expected={ok:false,message:error.message};}
  }
}finally{
  assert(root.startsWith(join(tmpdir(),'prism-acp-key-oracle-')));
  rmSync(root,{recursive:true,force:true});
}
writeFileSync(new URL('../../tests/fixtures/credentials.json',import.meta.url),JSON.stringify({reference:'@particle-academy/prism-acp '+version,scenarios},null,2)+'\n');
console.log('Generated',scenarios.length,'credential/home scenarios from published reference');
