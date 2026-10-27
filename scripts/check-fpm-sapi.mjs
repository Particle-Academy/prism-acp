// Actual Linux FPM measurement over a private Unix socket, not a simulated PHP_SAPI value.
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {existsSync,mkdtempSync,readFileSync,readdirSync,realpathSync,rmSync,writeFileSync} from 'node:fs';
import {createConnection} from 'node:net';
import {tmpdir,userInfo} from 'node:os';
import {dirname,join,resolve} from 'node:path';
if(process.platform!=='linux')throw Error('FPM SAPI measurement requires Linux');
const [binary,evidencePath]=process.argv.slice(2);
const parent=realpathSync(tmpdir()),root=mkdtempSync(join(parent,'prism-fpm-sapi-'));
const repo=process.cwd(),socketPath=join(root,'owned.sock');
const config=join(root,'fpm.conf');
writeFileSync(config,`[global]\npid=${root}/fpm.pid\nerror_log=${root}/error.log\ndaemonize=no\n[owned]\nuser=${userInfo().username}\nlisten=${socketPath}\nlisten.mode=0600\npm=static\npm.max_children=1\npm.max_requests=1\nclear_env=yes\nchdir=${repo}\nsecurity.limit_extensions=.php\n`);
function record(type,body=Buffer.alloc(0)){
 const header=Buffer.alloc(8);header[0]=1;header[1]=type;header.writeUInt16BE(1,2);header.writeUInt16BE(body.length,4);return Buffer.concat([header,body]);
}
function length(n){if(n<128)return Buffer.from([n]);const b=Buffer.alloc(4);b.writeUInt32BE((n+0x80000000)>>>0);return b;}
const params={SCRIPT_FILENAME:join(repo,'tests/fixtures/php-sapi-probe.php'),SCRIPT_NAME:'/php-sapi-probe.php',REQUEST_METHOD:'GET',GATEWAY_INTERFACE:'CGI/1.1',SERVER_PROTOCOL:'HTTP/1.1',CONTENT_LENGTH:'0',SERVER_NAME:'localhost',REMOTE_ADDR:'127.0.0.1',SERVER_PORT:'80'};
const packed=Buffer.concat(Object.entries(params).flatMap(([k,v])=>{const a=Buffer.from(k),b=Buffer.from(v);return[length(a.length),length(b.length),a,b];}));
const begin=Buffer.alloc(8);begin.writeUInt16BE(1);
const child=spawn(resolve(binary),['-n','-F','-y',config],{cwd:root,env:{PATH:process.env.PATH,HOME:root,TMPDIR:root},stdio:['ignore','pipe','pipe'],detached:true});
let spawnError;child.on('error',error=>{spawnError=error;});
let diagnostic='';child.stdout.on('data',b=>{diagnostic=(diagnostic+b).slice(-16000);});child.stderr.on('data',b=>{diagnostic=(diagnostic+b).slice(-16000);});
const closed=new Promise(r=>child.once('close',r));
function members(){
 if(!child.pid)return[];
 return readdirSync('/proc').filter(s=>/^\d+$/.test(s)).filter(pid=>{try{const stat=readFileSync('/proc/'+pid+'/stat','utf8');return Number(stat.slice(stat.lastIndexOf(')')+2).split(' ')[2])===child.pid;}catch{return false;}});
}
function signalOwnedGroup(signal){
 // The detached child established this private group. Never signal a missing/reused leader after the group empties.
 if(child.pid&&((child.exitCode===null&&child.signalCode===null&&!spawnError)||members().length)){
  try{process.kill(-child.pid,signal);}catch(error){if(error.code!=='ESRCH')throw error;}
 }
}
async function waitClosed(milliseconds){
 let timer;
 try{return await Promise.race([closed.then(()=>true),new Promise(r=>{timer=setTimeout(()=>r(false),milliseconds);})]);}
 finally{clearTimeout(timer);}
}
let report;
try{
 const deadline=Date.now()+5000;
 while(!existsSync(socketPath)){
  if(spawnError||child.exitCode!==null||Date.now()>deadline)throw Error('Owned FPM did not become ready: '+(spawnError?.message??diagnostic));
  await new Promise(r=>setTimeout(r,20));
 }
 const text=await new Promise((resolveText,reject)=>{
  const socket=createConnection(socketPath);let buffered=Buffer.alloc(0),stdout='',stderr='',complete=false;
  const timer=setTimeout(()=>{socket.destroy();reject(Error('Owned FastCGI response deadline'));},5000);
  socket.on('connect',()=>socket.write(Buffer.concat([record(1,begin),record(4,packed),record(4),record(5)])));
  socket.on('error',reject);
  socket.on('close',()=>{clearTimeout(timer);if(!complete)reject(Error('Incomplete owned FastCGI response: '+stderr));});
  socket.on('data',chunk=>{
   buffered=Buffer.concat([buffered,chunk]);if(buffered.length+stdout.length+stderr.length>1000000){socket.destroy();reject(Error('Owned FastCGI response bound'));return;}
   while(buffered.length>=8){
    const type=buffered[1],size=buffered.readUInt16BE(4),total=8+size+buffered[6];if(buffered.length<total)return;
    const body=buffered.subarray(8,8+size);buffered=buffered.subarray(total);
    if(type===6)stdout+=body.toString('utf8');if(type===7)stderr+=body.toString('utf8');
    if(type===3){complete=true;clearTimeout(timer);socket.end();if(body.length<8||body.readUInt32BE(0)!==0||body[4]!==0)reject(Error('Owned FPM request failed: '+stderr));else resolveText(stdout);}
   }
  });
 });
 const body=text.includes('\r\n\r\n')?text.split('\r\n\r\n').slice(1).join('\r\n\r\n'):text;
 const measured=JSON.parse(body.trim());
 assert.equal(measured.sapi,'fpm-fcgi');assert.equal(measured.explicit,false);assert.equal(measured.refused,true);
 assert.match(measured.reason,/non-CLI SAPI require an explicit trusted phpBinary/);
 assert.match(measured.binary,/php-fpm/);
 report={actualFpm:true,...measured,scope:'implicit runtime selection only; Linux provider containment remains unsupported'};
}finally{
 signalOwnedGroup('SIGTERM');
 if(!await waitClosed(5000)||members().length){signalOwnedGroup('SIGKILL');assert.equal(await waitClosed(5000),true,'owned FPM shutdown deadline');}
 assert.equal(members().length,0,'owned FPM process group not empty');
 if(dirname(realpathSync(root))!==parent)throw Error('Owned FPM cleanup escaped temporary parent');
 rmSync(root,{recursive:true,force:true});
}
if(evidencePath)writeFileSync(evidencePath,JSON.stringify(report,null,2)+'\n');
console.log(JSON.stringify(report));
