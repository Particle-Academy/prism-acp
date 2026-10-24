// Optional native Windows control. All model and MCP responses are local fixtures; no paid inference or user home.
// node tools/native/codex-mcp.mjs <php.exe> <codex.exe> [installed-autoload.php]
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn, execFileSync } from 'node:child_process';
import { mkdtemp, mkdir, writeFile, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const [php, binary, installedAutoload] = process.argv.slice(2);
assert(php && binary, 'pass PHP and Codex executable paths');
assert.equal(process.platform, 'win32', 'PHP currently supports Windows containment only');
const nativeVersion = execFileSync(binary, ['--version'], { encoding:'utf8', windowsHide:true }).trim();
assert.match(nativeVersion, /\b0\.163\.0\b/, 'review the native control before changing the measured version');
const root = await mkdtemp(join(tmpdir(), 'prism-acp-native-codex-'));
const home = join(root, 'home'); const project = join(root, 'project');
await mkdir(home); await mkdir(join(project, '.git'), {recursive:true});
const apiKeyMode=process.env.PRISM_NATIVE_CREDENTIALS==='apiKey';
if(apiKeyMode){await mkdir(join(root,'subscription'));await writeFile(join(root,'subscription','auth.json'),'owned subscription sentinel');}
const bearer = 'dummy-php-session-bearer';
let modelCalls = 0; let shellOutput = ''; const calls = []; const fixtureErrors = [];
function toolsOf(tools, prefix='') {
  return tools.flatMap((tool)=>Array.isArray(tool.tools)?toolsOf(tool.tools,prefix+(tool.name?tool.name+'.':''))
    :typeof tool.name==='string'?[{name:prefix+tool.name,tool}]:[]);
}
function sse(response, events) {
  response.writeHead(200, {'Content-Type':'text/event-stream'});
  response.end(events.map((event)=>`event: ${event.type}\ndata: ${JSON.stringify(event)}\n\n`).join(''));
}
const http = createServer((request,response)=>{
  void (async()=>{
    let text=''; for await(const bytes of request){text+=bytes;assert(text.length<5_000_000);}
    if(request.method!=='POST'){response.writeHead(405);response.end();return;}
    const body=JSON.parse(text);
    if(request.url==='/model/responses'){
      modelCalls++;
      assert(!toolsOf(body.tools??[]).some((entry)=>/(^|\.)view_image$/.test(entry.name)), 'unrelated feature survives');
      const completed={type:'response.completed',response:{id:`fixture-${modelCalls}`,usage:{input_tokens:1,output_tokens:1,total_tokens:2}}};
      if(modelCalls===1){
        const selected=toolsOf(body.tools??[]).find((entry)=>/(^|\.)(exec_command|shell_command|shell)$/.test(entry.name));
        assert(selected,'native model request offers a shell tool');
        const command="& 'C:/Program Files/Git/usr/bin/env.exe'";
        const args=/exec_command$/.test(selected.name)?{cmd:command,yield_time_ms:1000,max_output_tokens:8000}
          :/shell_command$/.test(selected.name)?{command,timeout_ms:10000}:{command:['env'],timeout_ms:10000};
        sse(response,[{type:'response.created',response:{id:'fixture-1'}},
          {type:'response.output_item.done',item:{type:'function_call',id:'fc-env',call_id:'call-env',name:selected.name,arguments:JSON.stringify(args)}},completed]);
      }else{
        const outputs=(body.input??[]).filter((item)=>item.type==='function_call_output');
        shellOutput=outputs.map((item)=>typeof item.output==='string'?item.output:JSON.stringify(item.output)).join('\n');
        sse(response,[{type:'response.created',response:{id:'fixture-2'}},
          {type:'response.output_item.done',item:{type:'message',id:'fixture-done',role:'assistant',content:[{type:'output_text',text:'fixture complete'}]}},completed]);
      }
      return;
    }
    calls.push({path:request.url,method:body.method,auth:request.headers.authorization});
    if(body.id===undefined){response.writeHead(202);response.end();return;}
    const result=body.method==='initialize'?{protocolVersion:body.params.protocolVersion,capabilities:{tools:{}},serverInfo:{name:'fixture',version:'1'}}
      :body.method==='tools/list'?{tools:[]}:{};
    response.writeHead(200,{'Content-Type':'application/json'});response.end(JSON.stringify({jsonrpc:'2.0',id:body.id,result}));
  })().catch((error)=>{fixtureErrors.push(error.message);response.destroy();});
});
await new Promise((yes)=>http.listen(0,'127.0.0.1',yes));
const origin=`http://127.0.0.1:${http.address().port}`;
const keyedPolicy=process.env.PRISM_NATIVE_POLICY==='keyed';
const shellPolicy=keyedPolicy?'filters={PRISM_FIXTURE_HIDDEN="exclude"}':'exclude=["PRISM_FIXTURE_HIDDEN"]';
await writeFile(join(home,'config.toml'),`model="fixture"\nmodel_provider="fixture"\n[model_providers.fixture]\nname="Fixture"\nbase_url="${origin}/model"\nwire_api="responses"\nrequires_openai_auth=false\nsupports_websockets=false\n[projects.${JSON.stringify(project)}]\ntrust_level="trusted"\n[features]\nunified_exec=false\nshell_snapshot=true\nview_image=false\n[shell_environment_policy]\ninherit="all"\nignore_default_excludes=true\n${shellPolicy}\nset={PRISM_FIXTURE_SET="visible"}\n[mcp_servers.fixture]\nurl="${origin}/mcp"\nbearer_token_env_var="OLD_SELECTOR"\n[mcp_servers.blocked]\nurl="${origin}/blocked"\n[mcp_servers.unrelated]\nurl="${origin}/unrelated"\n`);
async function run(resumeSessionId, withBearer=true){
  calls.length=0;modelCalls=0;shellOutput='';
  const options={cwd:project,binary,home,resumeSessionId,apiKeyMode,mcpServers:[{type:'http',name:'fixture',url:`${origin}/mcp`,
    headers:withBearer?[{name:'Authorization',value:`Bearer ${bearer}`}]:[]}]};
  const optionsPath=join(root,'options.json');await writeFile(optionsPath,JSON.stringify(options));
  const child=spawn(php,[fileURLToPath(new URL('./codex-host.php',import.meta.url)),optionsPath,...(installedAutoload?[installedAutoload]:[])],{windowsHide:true,stdio:['ignore','pipe','pipe']});
  let stdout='',stderr='';child.stdout.on('data',(bytes)=>{stdout+=bytes;});child.stderr.on('data',(bytes)=>{stderr+=bytes;});
  const timer=setTimeout(()=>child.kill(),45_000);
  let code;try{code=await new Promise((yes,no)=>{child.on('error',no);child.on('exit',yes);});}finally{clearTimeout(timer);}
  assert.equal(code,0,`PHP native fixture failed: ${stderr}`);
  const events=stdout.trim().split('\n').map((line)=>JSON.parse(line));
  const result=events.find((event)=>event.outcome);const stop=events.find((event)=>event.stop)?.stop;
  assert.deepEqual(result.problems,[]);assert.equal(result.outcome.isError,false);assert.equal(result.outcome.stopReason,'end_turn');
  assert.equal(stop.verified,true);assert.equal(stop.empty,true);assert.equal(stop.survivors,0);
  assert.equal(fixtureErrors.length,0,fixtureErrors.join('; '));assert.equal(modelCalls,2,'native tool/result positive control');
  assert(shellOutput.includes('PRISM_FIXTURE_VISIBLE=visible'),'shell env presence control');
  assert(shellOutput.includes('PRISM_FIXTURE_SET=visible'),'effective set survives');
  assert(!shellOutput.includes('must-be-excluded'),'user exclusion survives');
  assert(!shellOutput.includes(bearer),'MCP bearer absent from native model-run env');
  assert(!shellOutput.includes('PRISM_ACP_MCP_TOKEN_'),'binding name absent from native model-run env');
  assert(calls.some((call)=>call.path==='/mcp'&&call.method==='tools/list'&&call.auth===`Bearer ${withBearer?bearer:'dummy-old-selector'}`),'MCP uses the intended selector');
  assert(!calls.some((call)=>call.path==='/blocked'),'selective denial is effective');
  assert(calls.some((call)=>call.path==='/unrelated'&&call.method==='tools/list'),'unrelated inherited server survives');
  if(apiKeyMode)assert.equal(await readFile(join(root,'subscription','auth.json'),'utf8'),'owned subscription sentinel','API-key turns preserve the subscription home');
  console.log(JSON.stringify({passed:true,nativeVersion,keyedPolicy,apiKeyMode,resumed:resumeSessionId!==undefined,withBearer,modelCalls,stop}));
  return result.sessionId;
}
try{
  const selected=process.env.PRISM_NATIVE_CASE;
  if(selected==='baseline')await run(undefined,false);
  else if(selected==='start')await run();
  else {const session=await run();await run(session);}
}finally{
  http.closeAllConnections();await new Promise((yes)=>http.close(yes));
  // Resolve and verify this exact owned root before recursive removal; never derive it from provider output.
  assert(root.startsWith(join(tmpdir(),'prism-acp-native-codex-')));
  await rm(root,{recursive:true,force:true,maxRetries:10,retryDelay:200});
}
