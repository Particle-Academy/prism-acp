// Run the same provider + native approval oracle against the immutable old publisher and the current publisher.
import {spawnSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import {mkdirSync,readFileSync,writeFileSync} from 'node:fs';
import {dirname,join} from 'node:path';
import {fileURLToPath} from 'node:url';
if(process.platform!=='win32')throw Error('Windows cold-cache gate only');
const root=dirname(dirname(fileURLToPath(import.meta.url))),php=process.argv[2];
if(!php)throw Error('Pass the actual PHP CLI path');
const baseline='050d9d5f063849680497b4eac5a92f70490e1cdc';
const source=join(root,'src/Process/WindowsSupervisorBuild.php');
const original=readFileSync(source),hash=bytes=>createHash('sha256').update(bytes).digest('hex'),originalHash=hash(original);
const logs=join(root,'.approval-evidence');mkdirSync(logs,{recursive:true});
function run(binary,args,settings={}){const result=spawnSync(binary,args,{cwd:root,encoding:'utf8',windowsHide:true,maxBuffer:16*1024*1024,timeout:300000,...settings});if(result.error)throw result.error;return result;}
function measure(label){
 const result=run(php,['-n','tests/fixtures/supervisor-cold-controller.php'],{env:{...process.env,PRISM_ACP_PHP_COLD:'1'}});
 writeFileSync(join(logs,'cold-'+label+'.log'),(result.stdout??'')+(result.stderr??''));
 process.stdout.write(result.stdout??'');process.stderr.write(result.stderr??'');
 const rows=(result.stdout??'').split(/\r?\n/).filter(line=>line.startsWith('{')).map(line=>JSON.parse(line));
 const batches=rows.filter(r=>r.phpSupervisorColdBatch).map(r=>r.phpSupervisorColdBatch),summaries=rows.filter(r=>r.phpSupervisorColdSummary).map(r=>r.phpSupervisorColdSummary);
 if(batches.length!==20||summaries.length!==1||summaries[0].launches!==160||summaries[0].outerVerifiedEmpty!==160)throw Error(label+': incomplete cold-cache measurement');
 const hashRefusals=batches.flatMap(b=>b.failures).filter(e=>e.name?.endsWith('ProcessTreeException')&&/supervisor does not match.*hash/.test(e.message)).length;
 return{exit:result.status,...summaries[0],hashRefusals};
}
let old=run('git',['show',baseline+':src/Process/WindowsSupervisorBuild.php']);
if(old.status!==0){const fetched=run('git',['fetch','--no-tags','--depth=1','https://github.com/Particle-Academy/prism-acp.git',baseline]);if(fetched.status!==0)throw Error('Immutable baseline fetch failed');old=run('git',['show',baseline+':src/Process/WindowsSupervisorBuild.php']);}
if(old.status!==0||!old.stdout.includes('final class WindowsSupervisorBuild'))throw Error('Immutable cache baseline missing');
let before;
try{
 writeFileSync(source,old.stdout);before=measure('baseline');
 if(before.exit!==1||before.failed<1||before.hashRefusals<1)throw Error('Old PHP publisher did not reproduce a hash refusal');
}finally{writeFileSync(source,original);if(hash(readFileSync(source))!==originalHash)throw Error('Current PHP source restoration hash mismatch');}
const after=measure('current');
if(after.exit!==0||after.failed!==0||after.approvalVerified!==160)throw Error('Current PHP publisher failed the cold-cache oracle');
writeFileSync(join(logs,'cold-summary.json'),JSON.stringify({baselineRef:baseline,currentSourceSha256:originalHash,restored:true,before,after},null,2)+'\n');
console.log('PHP Windows cold-cache regression verified: baseline '+before.failed+'/160 refusals; current0/160;160 actual approval adapters.');
