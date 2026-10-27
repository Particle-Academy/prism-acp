// Owned native fixture: fixed loopback model, synthetic script/config faults, no package runtime dependency.
import {spawn} from 'node:child_process';
import {readFileSync,writeFileSync} from 'node:fs';
import {dirname,join} from 'node:path';
import {createInterface} from 'node:readline';
const config=JSON.parse(readFileSync(process.argv[2],'utf8'));
if(process.env.ANTHROPIC_API_KEY!=='prism-fake-not-a-real-key'||new URL(config.modelUrl).hostname!=='127.0.0.1')throw Error('owned dummy-key loopback fixture required');
const args=process.argv.slice(3);
const settingsPath=args[args.indexOf('--settings')+1];
const settings=JSON.parse(readFileSync(settingsPath,'utf8'));
const hook=settings.hooks.PreToolUse[0].hooks[0];
const scriptPath=join(dirname(settingsPath),'approve.php');
const allowJSON=JSON.stringify({hookSpecificOutput:{hookEventName:'PreToolUse',permissionDecision:'allow',permissionDecisionReason:'owned synthetic allow'}});
const scripts={
 'hook-empty':'<?php exit(0);',
 'hook-invalid':'<?php echo "not-json";exit(0);',
 'hook-malformed':'<?php echo "{bad-json";exit(0);',
 'hook-crash':'<?php throw new Exception("SYNTHETIC_PRIVATE_ERROR");',
 'hook-hang':'<?php sleep(30);',
 'hook-allow-zero':'<?php echo '+JSON.stringify(allowJSON)+';exit(0);',
 'hook-allow-two':'<?php echo '+JSON.stringify(allowJSON)+';exit(2);',
};
if(scripts[config.scenario])writeFileSync(scriptPath,scripts[config.scenario]);
if(config.scenario==='guard-timeout'){
 writeFileSync(scriptPath,'<?php sleep(30);');hook.command=config.php;hook.args=['-n',scriptPath];hook.timeout=1;
}
if(config.scenario==='guard-crash'){hook.command=config.php;hook.args=['-n','-r','exit(1);'];}
if(config.scenario==='guard-missing'){hook.command=join(config.root,'owned-missing.exe');hook.args=[];}
if(config.mutation==='drop-wrapper'){hook.command=config.php;hook.args=['-n',scriptPath];}
writeFileSync(settingsPath,JSON.stringify(settings));
const receipt={sent:[],versions:[],nativeCode:null,hook:{command:hook.command,args:hook.args,timeout:hook.timeout,onFailure:hook.onFailure}};
const save=()=>writeFileSync(config.receipt,JSON.stringify(receipt));save();
const env={...process.env,ANTHROPIC_BASE_URL:config.modelUrl,CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC:'1',CLAUDE_CODE_USE_POWERSHELL_TOOL:'0',HTTP_PROXY:config.proxyUrl,HTTPS_PROXY:config.proxyUrl,NO_PROXY:'127.0.0.1,localhost'};
const native=spawn(config.binary,[...args,'--setting-sources','','--max-turns','2'],{cwd:process.cwd(),env,stdio:['pipe','pipe','pipe'],windowsHide:true});
createInterface({input:process.stdin}).on('line',line=>{
 const frame=JSON.parse(line);receipt.sent.push({type:frame.type,subtype:frame.request?.subtype});save();native.stdin.write(line+'\n');
}).on('close',()=>native.stdin.end());
native.stdin.on('error',()=>{});native.stderr.pipe(process.stderr);
createInterface({input:native.stdout}).on('line',line=>{
 const frame=JSON.parse(line);
 const initialize=frame.type==='control_response'&&frame.response?.response?.claude_code_version!==undefined;
 const init=frame.type==='system'&&frame.subtype==='init';
 if(initialize||init){receipt.versions.push({source:initialize?'control.initialize':'system/init',version:initialize?frame.response.response.claude_code_version:frame.claude_code_version});save();}
 if(initialize&&config.versionFault==='timeout')return;
 if(initialize&&config.versionFault==='error'){frame.response={...frame.response,subtype:'error',error:'owned initialize failure'};delete frame.response.response;}
 if(initialize&&['missing','old','malformed'].includes(config.versionFault)){
  if(config.versionFault==='missing')delete frame.response.response.claude_code_version;
  else frame.response.response.claude_code_version=config.versionFault==='old'?'2.1.294':'unparseable';
 }
 if(init&&config.versionFault==='init-missing')delete frame.claude_code_version;
 if(init&&config.versionFault==='init-changed')frame.claude_code_version='2.1.309';
 process.stdout.write(JSON.stringify(frame)+'\n');
});
native.on('error',()=>process.exit(1));
native.on('close',code=>{receipt.nativeCode=code;save();process.exit(code??1);});
