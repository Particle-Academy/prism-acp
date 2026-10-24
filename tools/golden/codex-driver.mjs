// Expected behavior comes from the pinned PUBLISHED adapter, driven through its real driver state machine.
// These are schema-aligned protocol scenarios, not a claim of native live turn captures.
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
const reference = resolve(process.argv[2] ?? '');
const version = JSON.parse(readFileSync(join(reference, 'package.json'), 'utf8')).version;
assert.equal(version, '0.25.0', 'use the reviewed published reference');
const { CodexDriver } = await import(pathToFileURL(join(reference, 'dist/index.js')).href);
const notify = (method, params) => ({ type: 'frame', frame: { jsonrpc: '2.0', method, params } });
const request = (method, params) => ({ type: 'frame', frame: { jsonrpc: '2.0', id: 'approval-fixture', method, params } });
const prompt = { type: 'prompt', content: 'fixture prompt' };
const completed = (status = 'completed', extra = {}) => notify('turn/completed', { turn: { id: 'turn-fixture', status, ...extra } });
const item = (method, value) => notify('item/'+method, { turnId:'turn-fixture', item:value });
const scenarios = [
  { name: 'subscription-start', actions: [] },
  { name: 'prompt-text-and-complete', actions: [prompt,
    notify('item/agentMessage/delta', { turnId:'turn-fixture', itemId:'message-fixture', delta:'hello ' }),
    item('completed', { type:'agentMessage', id:'message-fixture', text:'hello world' }), completed()] },
  { name: 'completed-text-without-delta', actions: [prompt, item('completed', { type:'agentMessage', id:'message-fixture', text:'complete' }), completed()] },
  { name: 'reasoning-delta-without-duplicate', actions: [prompt,
    notify('item/reasoning/summaryTextDelta', { turnId:'turn-fixture', itemId:'reason-fixture', delta:'think' }),
    item('completed', { type:'reasoning', id:'reason-fixture', summary:['think'] }), completed()] },
  { name: 'command-lifecycle-and-output', actions: [prompt,
    item('started', { type:'commandExecution', id:'command-fixture', command:'echo fixture', cwd:'/fixture' }),
    notify('item/commandExecution/outputDelta', { turnId:'turn-fixture', itemId:'command-fixture', delta:'fixture output' }),
    item('completed', { type:'commandExecution', id:'command-fixture', command:'echo fixture', exitCode:0, status:'completed', durationMs:12 }), completed()] },
  { name: 'file-lifecycle', actions: [prompt,
    item('started', {type:'fileChange',id:'file-fixture',changes:[{path:'/fixture/a',kind:{type:'add'},diff:'+a'}]}),
    item('completed', {type:'fileChange',id:'file-fixture',changes:[],status:'declined'}),completed()] },
  { name: 'mcp-and-dynamic-results', actions:[prompt,
    item('completed',{type:'mcpToolCall',id:'mcp-fixture',tool:'search',arguments:{q:'fixture'},result:{content:[{type:'text',text:'found'},{type:'image',data:'AA=='}]}}),
    item('completed',{type:'dynamicToolCall',id:'dynamic-fixture',name:'host',success:false,contentItems:[{type:'inputText',text:'refused'}]}),completed()] },
  { name: 'plan-model-and-compaction', actions:[prompt,
    notify('turn/plan/updated',{turnId:'turn-fixture',plan:[{step:'first',status:'inProgress'},{step:'second',status:'pending'}]}),
    notify('turn/plan/delta',{turnId:'turn-fixture',delta:'progress'}),
    notify('model/rerouted',{toModel:'fixture-next'}),notify('thread/compaction/started',{threadId:'thread-fixture',note:'fixture'}),completed()] },
  { name:'failed-turn', actions:[prompt,completed('failed',{error:{message:'fixture limit',codexErrorInfo:'usageLimitExceeded'}})] },
  { name:'cancel-turn-keeps-session', actions:[prompt,{type:'cancel'},completed('interrupted')] },
  { name:'unknown-notification-item-and-request',actions:[prompt,
    notify('fixture/unknown',{accountId:'private-fixture',visible:'kept'}),
    item('started',{type:'futureItem',id:'future-fixture',accountId:'private-fixture'}),
    request('fixture/request',{value:'fixture'}),completed()] },
  { name:'model-state-and-change',actions:[{type:'models'},{type:'setModel',model:'fixture-next'},prompt,completed()] },
  { name:'resume-paged-history',options:{resumeSessionId:'thread-fixture'}, replies:{
    'thread/turns/list':[{result:{data:[{id:'old-turn'}],nextCursor:'next-turn'}},{result:{data:[{id:'older-turn'}],nextCursor:null}}],
    'thread/items/list':[{result:{data:[{item:{type:'userMessage',content:[{type:'inputText',text:'old question'}]}}],nextCursor:'next-item'}},
      {result:{data:[{item:{type:'agentMessage',id:'old-answer',text:'old answer'}},{item:{type:'reasoning',summary:['old thought']}}],nextCursor:null}},
      {result:{data:[{item:{type:'commandExecution',id:'old-command',command:'pwd',exitCode:0,aggregatedOutput:'/fixture'}}],nextCursor:null}}]}, actions:[] },
  { name:'resume-repeated-cursor',options:{resumeSessionId:'thread-fixture'},replies:{'thread/turns/list':[
    {result:{data:[],nextCursor:'same'}},{result:{data:[],nextCursor:'same'}}]},actions:[] },
  { name:'startup-rpc-failure-is-bounded',replies:{'thread/start':[{error:{code:-32600,message:'fixture\n\u001b[31mproblem'}}]},actions:[] },
];
const methods = ['item/commandExecution/requestApproval','item/fileChange/requestApproval','item/permissions/requestApproval','applyPatchApproval','execCommandApproval'];
for (const method of methods) {
  for (const choice of ['cancelled','allow','unknown','throw']) {
    scenarios.push({name:'approval-'+method.replaceAll('/','-')+'-'+choice, permission:choice,
      actions:[prompt,request(method,{itemId:'tool-fixture',callId:'call-fixture',command:'echo fixture',cwd:'/fixture',permissions:{network:{enabled:true}},reason:'fixture reason',fileChanges:{'/fixture/a':{type:'add',content:'a'}}}),completed()]});
  }
}
for (const decisions of [[],['future'],['acceptForSession'],[{acceptWithExecpolicyAmendment:{execpolicy_amendment:['echo','fixture']}}],
  [{applyNetworkPolicyAmendment:{network_policy_amendment:{host:'fixture.test',action:'deny'}}}]]) {
  scenarios.push({name:'restricted-command-choices-'+scenarios.length,permission:'allow',actions:[prompt,
    request(methods[0],{itemId:'tool-fixture',command:'echo fixture',availableDecisions:decisions}),completed()]});
}
const drain = () => new Promise((yes) => setImmediate(yes));
scenarios.push(
  {name:'configured-model-sandbox-and-approval',options:{model:'fixture-option',sandbox:'workspace-write',approvalPolicy:'untrusted'},actions:[prompt,completed()]},
  {name:'resume-configured-policy',options:{resumeSessionId:'thread-fixture',model:'fixture-option',sandbox:'read-only',approvalPolicy:'never'},actions:[]},
  {name:'resume-rejects-invalid-identity',options:{resumeSessionId:'bad\nidentity'},actions:[]},
  {name:'resume-rejects-acp-minted-identity',options:{resumeSessionId:'sess_1_2'},actions:[]},
  {name:'start-rejects-missing-thread',replies:{'thread/start':[{result:{thread:{}}}]},actions:[]},
  {name:'resume-rejects-different-thread',options:{resumeSessionId:'thread-fixture'},replies:{'thread/resume':[{result:{thread:{id:'another-thread'}}}]},actions:[]},
  {name:'turn-rpc-refusal',replies:{'turn/start':[{error:{code:-32602,message:'fixture turn refusal'}}]},actions:[prompt]},
  {name:'rate-limit-read-optional',replies:{'account/rateLimits/read':[{error:{code:-32601,message:'not available'}}]},actions:[]},
  {name:'foreign-thread-and-turn-never-complete-parent',actions:[prompt,
    notify('item/agentMessage/delta',{threadId:'another-thread',turnId:'turn-fixture',itemId:'foreign',delta:'wrong thread'}),
    notify('turn/completed',{threadId:'another-thread',turn:{id:'turn-fixture',status:'completed'}}),
    notify('item/agentMessage/delta',{turnId:'another-turn',itemId:'foreign',delta:'wrong turn'}),
    request(methods[0],{threadId:'another-thread',command:'foreign'}),completed()]},
  {name:'malformed-json-and-primitive-frames',actions:[{type:'frame',frame:'{malformed secret fixture'},
    {type:'frame',frame:null},{type:'frame',frame:true},{type:'frame',frame:[]}]},
  {name:'mcp-elicitation-explicit-decline',actions:[prompt,request('mcpServer/elicitation/request',{serverName:'fixture',mode:'form',message:'fixture\u001b[31m question'}),completed()]},
  {name:'usage-is-last-context-with-thread-cost-metadata',actions:[prompt,
    notify('thread/tokenUsage/updated',{tokenUsage:{total:{inputTokens:1000,outputTokens:500,cachedInputTokens:50,reasoningOutputTokens:10},last:{totalTokens:20},modelContextWindow:100}}),completed()]},
  {name:'invalid-usage-and-plan-not-silent',actions:[prompt,
    notify('thread/tokenUsage/updated',{tokenUsage:{total:{inputTokens:true,outputTokens:2},last:{totalTokens:2},modelContextWindow:100}}),
    notify('thread/tokenUsage/updated',{tokenUsage:{total:{inputTokens:2,outputTokens:2},last:{totalTokens:2},modelContextWindow:0}}),
    notify('turn/plan/updated',{plan:[{step:'fixture',status:'future'}]}),completed('future')]},
  {name:'duplicate-completion-is-once',actions:[prompt,completed(),completed()]},
  {name:'public-fields-retain-numeric-json-names',actions:[notify('contextCompacted',{'3':'three','01':'leading','8':{accountId:'hidden',visible:'kept'}})]},
  {name:'pending-approval-native-resolution-clears-answer',permission:'pending',actions:[prompt,request(methods[0],{itemId:'pending',command:'fixture'}),
    notify('serverRequest/resolved',{requestId:'approval-fixture'}),completed()]},
  {name:'pending-approval-turn-completion-clears-answer',permission:'pending',actions:[prompt,request(methods[0],{itemId:'pending',command:'fixture'}),completed()]},
  {name:'duplicate-active-approval-answered-once',permission:'pending',actions:[prompt,request(methods[0],{itemId:'pending',command:'fixture'}),
    request(methods[0],{itemId:'pending',command:'fixture'}),completed()]},
  {name:'idle-turn-deadline-refuses',options:{turnInactivityTimeoutMs:10},actions:[prompt,{type:'wait',ms:40}]},
  {name:'active-native-command-pauses-deadline',options:{turnInactivityTimeoutMs:10},actions:[prompt,
    item('started',{type:'commandExecution',id:'active-command',command:'fixture'}),{type:'wait',ms:40},
    item('completed',{type:'commandExecution',id:'active-command',command:'fixture',status:'completed',exitCode:0}),completed()]},
  {name:'pending-permission-pauses-deadline',options:{turnInactivityTimeoutMs:10},permission:'pending',actions:[prompt,
    request(methods[0],{itemId:'pending',command:'fixture'}),{type:'wait',ms:40},notify('serverRequest/resolved',{requestId:'approval-fixture'}),completed()]},
  {name:'utf8-and-terminal-control-in-permissions',permission:'allow',actions:[prompt,
    request(methods[0],{itemId:'fixture',command:'echo \u001b[31m雪',reason:'why\u001b[0m',availableDecisions:['accept','cancel']}),completed()]}
);
const bearerServers = [{type:'http',name:'fixture-mcp',url:'http://127.0.0.1:1/mcp',headers:[
  {name:'aUtHoRiZaTiOn',value:'  Bearer fixture-explicit-token\t'},{name:'X-Fixture',value:'retained'}]}];
for(const resume of [false,true]) scenarios.push({name:'mcp-bearer-'+(resume?'resume':'start'),auditMcp:true,
  options:{mcpServers:bearerServers,mcpToolApproval:{'fixture-mcp':'approve'},...(resume?{resumeSessionId:'thread-fixture'}:{}),
    parentEnv:{OLD_SELECTOR:'old-dummy-token'},allowEnv:['OLD_SELECTOR']},
  replies:{'config/read':[{result:{config:{mcp_servers:{'fixture-mcp':{url:'http://127.0.0.1:2',bearer_token_env_var:'OLD_SELECTOR'},unrelated:{command:'/fixture/server'}},
    shell_environment_policy:{inherit:'core',exclude:['KEEP_EXCLUDE'],include_only:['KEEP_INCLUDE'],set:{VISIBLE:'yes'}}}}}]},actions:[]});
for(const [label,policy] of Object.entries({
  defaults:undefined,nullable:null,legacy:{inherit:'all',exclude:['OLD'],set:{VISIBLE:'yes'}},
  keyed:{filters:{VISIBLE:'include',HIDDEN:'exclude'},set:{VISIBLE:'yes'}},
  nullFields:{inherit:'core',exclude:null,include_only:null,set:null,filters:null},
  badScalar:'no',badArray:[],badExclude:{exclude:1},badExcludeMember:{exclude:[1]},badSet:{set:[]},
  badFilters:{filters:[]},badFilterValue:{filters:{VISIBLE:'future'}},mixedFilters:{filters:{VISIBLE:'include'},exclude:['OLD']},
  mixedInclude:{filters:{VISIBLE:'include'},include_only:['OLD']},
})) scenarios.push({name:'mcp-effective-shell-'+label,auditMcp:true,options:{mcpServers:bearerServers},
  replies:{'config/read':[{result:{config:policy===undefined?{}:{shell_environment_policy:policy}}}]},actions:[]});
for(const [label,config] of Object.entries({missing:undefined,nullConfig:null,arrayConfig:[],valid:{mcp_servers:{stdio:{command:'/fixture/server',args:['--fixture']},http:{url:'http://127.0.0.1:2'},unrelated:{command:'/fixture/keep'}}},
  nullInherited:{mcp_servers:null},arrayInherited:{mcp_servers:[]},badEntry:{mcp_servers:{stdio:null}},badTransport:{mcp_servers:{stdio:{}}},badArgs:{mcp_servers:{stdio:{command:'/fixture/server',args:[1]}}},
})) scenarios.push({name:'mcp-disabled-'+label,options:{disabledMcpServers:['stdio','http','missing']},
  replies:{'config/read':[{result:config===undefined?{}:{config}}]},actions:[]});
scenarios.push({name:'mcp-nonbearer-and-stdio-no-shell-read',options:{mcpServers:[
  {name:'stdio',command:'/fixture/server',args:[],env:[]},
  {type:'http',name:'http',url:'http://127.0.0.1:1',headers:[{name:'Authorization',value:'Basic dummy'}]}]},actions:[]});
for(const [label,text,count] of [['ascii','x',1000002],['unicode','😀',500001]]) scenarios.push({name:'command-output-bound-'+label,
  actions:[prompt,item('started',{type:'commandExecution',id:'bounded',command:'fixture'}),
    {...notify('item/commandExecution/outputDelta',{turnId:'turn-fixture',itemId:'bounded',delta:''}),repeatDelta:{text,count}},
    {type:'wait',ms:230},item('completed',{type:'commandExecution',id:'bounded',command:'fixture',exitCode:0,status:'completed'}),completed()]});
scenarios.push({name:'rpc-error-unicode-whitespace',replies:{'thread/start':[{error:{code:-32602,message:'first\u00a0second\ufeffthird\u2028fourth'}}]},actions:[]},
  {name:'rpc-error-bounded-text',replies:{'thread/start':[{error:{code:-32602,message:'x'.repeat(350)+'discarded suffix'}}]},actions:[]});
function compactLargeText(value){
  if(typeof value==='string'&&value.length>1024)return {$comparison:'long-string',utf16Length:value.length,utf8Sha256:createHash('sha256').update(value).digest('hex')};
  if(Array.isArray(value))return value.map(compactLargeText);
  if(value&&typeof value==='object')return Object.fromEntries(Object.entries(value).map(([key,entry])=>[key,compactLargeText(entry)]));
  return value;
}
const fixtures = [];
for (const scenario of scenarios) {
  const events = []; const sent = []; const indexes = {}; let handlers; let closes = 0; let childEnv;
  const defaultResult = (frame) => {
    if (frame.method === 'thread/start' || frame.method === 'thread/resume') return {thread:{id:'thread-fixture'},model:'fixture-model'};
    if (frame.method === 'turn/start') return {turn:{id:'turn-fixture'}};
    if (frame.method === 'thread/turns/list' || frame.method === 'thread/items/list') return {data:[],nextCursor:null};
    if (frame.method === 'model/list') return {data:[{id:'fixture-model',model:'fixture-model',displayName:'Fixture',isDefault:true}],nextCursor:null};
    if (frame.method === 'account/rateLimits/read') return {ordinaryUsageAllowed:true,rateLimits:{primary:{usedPercent:10,windowDurationMins:300,resetsAt:1800000000},secondary:null}};
    return {};
  };
  const driver = new CodexDriver({cwd:'/fixture',parentEnv:{},...scenario.options},{
    onUpdate:(value)=>events.push({type:'update',value}),
    onProtocolError:(problem,detail)=>events.push({type:'problem',problem,...(detail===undefined?{}:{detail})}),
    onTurnEnd:(value)=>events.push({type:'turnEnd',value}),onExit:(code,signal)=>events.push({type:'exit',code,signal}),
    onRequestPermission:async(value)=>{events.push({type:'permission',value});
      if(scenario.permission==='throw')throw new Error('fixture callback');
      if(scenario.permission==='pending')return new Promise(()=>{});
      if(scenario.permission==='unknown')return {outcome:'selected',optionId:'unoffered'};
      if(scenario.permission==='allow')return {outcome:'selected',optionId:value.options.find((option)=>option.kind==='allow_once')?.optionId ?? value.options[0]?.optionId};
      return {outcome:'cancelled'};},
  },(options,callbacks)=>{handlers=callbacks;childEnv=options.env;return {start:async()=>{},send:(frame)=>{
    sent.push(structuredClone(frame)); if(typeof frame.method!=='string'||frame.id===undefined)return;
    const index=indexes[frame.method]??0; indexes[frame.method]=index+1;
    const scripted=scenario.replies?.[frame.method]?.[index];
    queueMicrotask(()=>callbacks.onFrame({jsonrpc:'2.0',id:frame.id,...(scripted??{result:defaultResult(frame)})}));
  },close:()=>{closes++;},stop:async()=>({graceful:true,empty:true,survivors:0,verified:true})};});
  try {
    driver.start(); await driver.ready; await drain();
    for(const action of scenario.actions){
      if(action.type==='frame'){
        const frame=structuredClone(action.frame);
        if(action.repeatDelta)frame.params.delta=action.repeatDelta.text.repeat(action.repeatDelta.count);
        handlers.onFrame(frame);
      }
      else if(action.type==='prompt')driver.prompt(action.content);
      else if(action.type==='cancel')await driver.cancel();
      else if(action.type==='models')events.push({type:'models',value:await driver.modelState()});
      else if(action.type==='setModel')await driver.setModel(action.model);
      else if(action.type==='wait')await new Promise((yes)=>setTimeout(yes,action.ms));
      await drain();
    }
  } finally {driver.kill();await drain();}
  const expected={sent,events,closes};
  if(scenario.auditMcp){
    const names=Object.keys(childEnv).filter((name)=>name.startsWith('PRISM_ACP_MCP_TOKEN_'));
    assert.equal(names.length,1);assert.match(names[0],/^PRISM_ACP_MCP_TOKEN_[A-F0-9]{32}$/);
    assert.equal(childEnv[names[0]],'fixture-explicit-token');
    expected.childEnv=childEnv;
    // Normalize only identities already proven to be fresh child bindings; never values or their relationships.
    const text=JSON.stringify(expected).replaceAll(names[0],'PRISM_ACP_MCP_TOKEN_FIXTURE_0');
    fixtures.push({...scenario,expected:compactLargeText(JSON.parse(text))});
  } else fixtures.push({...scenario,expected:compactLargeText(expected)});
}
writeFileSync(new URL('../../tests/fixtures/codex-driver.json',import.meta.url),JSON.stringify({reference:{package:'@particle-academy/prism-acp',version,source:'e24c27e78b92c735a8b357265cf9df6d2bf877c7'},scenarios:fixtures},null,2)+'\n');
console.log('Generated',fixtures.length,'Codex scenarios from published',version);
