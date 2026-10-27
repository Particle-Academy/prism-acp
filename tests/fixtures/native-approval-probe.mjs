import assert from 'node:assert/strict';
import {execFileSync,spawn} from 'node:child_process';
import {createServer} from 'node:http';
import {existsSync,mkdtempSync,readFileSync,realpathSync,rmSync,writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname,join} from 'node:path';
import {fileURLToPath} from 'node:url';
const [php,binary,scenario,mutation,versionFault,wildcard]=process.argv.slice(2);
const root=mkdtempSync(join(tmpdir(),'prism-php-native-approval-'));
const allowed=new Set(['SYSTEMROOT','WINDIR','COMSPEC','PATH','PATHEXT','TEMP','TMP','TMPDIR','LANG']);
const env={...Object.fromEntries(Object.entries(process.env).filter(([k])=>allowed.has(k.toUpperCase()))),HOME:root,USERPROFILE:root,XDG_CONFIG_HOME:root};
assert.match(execFileSync(binary,['--version'],{encoding:'utf8',timeout:5000,windowsHide:true,env}).trim(),/^2\.1\.310 \(Claude Code\)$/);
const requests=[],blocked=[];
const model=createServer(async(req,res)=>{
 let raw='';for await(const c of req)raw+=c;
 if(!req.url?.includes('/messages')){res.writeHead(404);res.end('{}');return;}
 const body=JSON.parse(raw);requests.push(body);assert(requests.length<=3,'owned request budget exceeded');
 const done=body.messages.some(m=>Array.isArray(m.content)&&m.content.some(c=>c.type==='tool_result'));
 const content=done?[{type:'text',text:'Owned probe complete.'}]:[{type:'tool_use',id:'toolu_owned',name:'Bash',input:{command:'node -e "console.log(\'PRISM_PHP_HOOK_EXECUTED\')"',description:'Owned PHP approval probe'}}];
 const message={id:'msg_owned',type:'message',role:'assistant',model:body.model,content,stop_reason:done?'end_turn':'tool_use',stop_sequence:null,usage:{input_tokens:5,output_tokens:5}};
 if(!body.stream){res.writeHead(200,{'content-type':'application/json'});res.end(JSON.stringify(message));return;}
 res.writeHead(200,{'content-type':'text/event-stream'});
 const event=(type,data)=>res.write(`event: ${type}\ndata: ${JSON.stringify({type,...data})}\n\n`);
 event('message_start',{message:{...message,content:[],stop_reason:null}});
 for(const[index,c]of content.entries()){
  event('content_block_start',{index,content_block:c.type==='tool_use'?{...c,input:{}}:{...c,text:''}});
  event('content_block_delta',{index,delta:c.type==='tool_use'?{type:'input_json_delta',partial_json:JSON.stringify(c.input)}:{type:'text_delta',text:c.text}});
  event('content_block_stop',{index});
 }
 event('message_delta',{delta:{stop_reason:message.stop_reason,stop_sequence:null},usage:{output_tokens:5}});event('message_stop',{});res.end();
});
const proxy=createServer((req,res)=>{blocked.push(req.url??'unknown');res.writeHead(502);res.end();});
proxy.on('connect',(req,socket)=>{blocked.push(req.url??'CONNECT');socket.end('HTTP/1.1 502 Blocked\r\n\r\n');});
await new Promise(r=>model.listen(0,'127.0.0.1',r));await new Promise(r=>proxy.listen(0,'127.0.0.1',r));
const here=dirname(fileURLToPath(import.meta.url));
const config={php,binary,scenario,mutation:mutation||null,versionFault:versionFault||null,wildcard:wildcard==='1',root,home:join(root,'home'),node:process.execPath,bridge:join(here,'native-approval-bridge.mjs'),receipt:join(root,'native.json'),modelUrl:'http://127.0.0.1:'+model.address().port,proxyUrl:'http://127.0.0.1:'+proxy.address().port};
const configPath=join(root,'config.json');writeFileSync(configPath,JSON.stringify(config));
let host;
try{
 const row=await new Promise((resolve,reject)=>{
  host=spawn(php,['-d','display_errors=stderr','-d','log_errors=0',join(here,'native-approval-host.php'),configPath],{cwd:root,env,stdio:['ignore','pipe','pipe'],windowsHide:true});
  let out='',err='';const timer=setTimeout(()=>reject(Error('owned PHP native probe deadline')),55000);
  host.stdout.on('data',d=>{out+=d;if(out.length>1000000)reject(Error('owned host stdout bound'));});
  host.stderr.on('data',d=>{err+=d;if(err.length>1000000)reject(Error('owned host stderr bound'));});
  host.on('error',reject);host.on('close',code=>{clearTimeout(timer);try{assert.equal(code,0,'PHP host failed: '+err.slice(0,1000));resolve(JSON.parse(out.trim()));}catch(e){reject(e);}});
 });
 const receipt=existsSync(config.receipt)?JSON.parse(readFileSync(config.receipt,'utf8')):{sent:[],versions:[],nativeCode:null};
 const results=requests.flatMap(r=>r.messages.flatMap(m=>Array.isArray(m.content)?m.content.filter(c=>c.type==='tool_result'):[]));
 console.log(JSON.stringify({...row,scenario,mutation:mutation||null,versionFault:versionFault||null,requests:requests.length,executed:JSON.stringify(results).includes('PRISM_PHP_HOOK_EXECUTED'),sentUsers:receipt.sent.filter(f=>f.type==='user').length,reportedVersions:receipt.versions,nativeCode:receipt.nativeCode,hook:receipt.hook??null,toolResults:results,blockedConnections:blocked}));
}finally{
 if(host&&host.exitCode===null&&host.signalCode===null){
  const closed=new Promise(r=>host.once('close',r));host.kill();
  let timer;try{await Promise.race([closed,new Promise((_,reject)=>{timer=setTimeout(()=>reject(Error('owned PHP host cleanup deadline')),5000);})]);}finally{clearTimeout(timer);}
 }
 model.closeAllConnections();model.close();proxy.closeAllConnections();proxy.close();
 if(dirname(realpathSync(root))!==realpathSync(tmpdir()))throw Error('owned native fixture cleanup escaped temporary parent');
 rmSync(root,{recursive:true,force:true,maxRetries:20,retryDelay:100});
}
