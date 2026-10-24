// Published redactor parity: compare every three-way split and every emitted chunk, with compact exact digests.
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {readFileSync,writeFileSync} from 'node:fs';
import {join,resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
const reference=resolve(process.argv[2]??'');
const version=JSON.parse(readFileSync(join(reference,'package.json'),'utf8')).version;
assert.equal(version,'0.25.1');
const {SecretRedactor}=await import(pathToFileURL(join(reference,'dist/redact.js')).href);
const secret='dummy-explicit-key';
const scenarios=[
 {name:'whole-and-repeated',secret,text:`error ${secret}\nretry ${secret}.`},
 {name:'dangling-long',secret,text:'cut off: dummy-ex'},
 {name:'dangling-four',secret,text:'cut off: dumm'},
 {name:'dangling-three',secret,text:'cut off: dum'},
 {name:'prefix-mismatch-released',secret,text:'dummy sky blue'},
 {name:'unicode',secret:'密钥😀-fixture',text:'前 密钥😀-fixture 后 密钥😀'},
 {name:'overlap',secret:'ababa',text:'abababababa'},
 {name:'short-secret-marker',secret:'a',text:'a a'},
 {name:'marker-as-secret',secret:'[redacted]',text:'[redacted] tail'},
 {name:'empty-refused',secret:'',text:'plain'},
];
for(const scenario of scenarios){
 try{
  const chars=[...scenario.text];const hash=createHash('sha256');let count=0;
  for(let first=0;first<=chars.length;first++)for(let second=first;second<=chars.length;second++){
   const redactor=new SecretRedactor(scenario.secret);
   const output=[redactor.push(chars.slice(0,first).join('')),redactor.push(chars.slice(first,second).join('')),
    redactor.push(chars.slice(second).join('')),redactor.end(),redactor.end()];
   hash.update(JSON.stringify({first,second,output})+'\n');count++;
  }
  scenario.expected={ok:true,splitCount:count,sha256:hash.digest('hex')};
 }catch(error){scenario.expected={ok:false,message:error.message};}
}
writeFileSync(new URL('../../tests/fixtures/redact.json',import.meta.url),JSON.stringify({reference:'@particle-academy/prism-acp '+version,
 source:'d78555a4884e60ad1d8766eead8f2a7f40eb3eb7',chunking:'decoded Unicode code points',scenarios},null,2)+'\n');
console.log('Generated',scenarios.length,'redactor cases with all three-way chunk/output split digests');
