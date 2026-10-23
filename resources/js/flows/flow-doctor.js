/**
 * Flow Doctor — a pure, static analyzer for the flow-builder graph.
 *
 * Runs entirely in the browser over the in-memory { nodes, edges } the canvas
 * already holds. No network, no runtime, no DB. It is deliberately decoupled
 * from the builder: the builder passes in `handlesOf(node)` (the node's output
 * ports) and `labelOf(node)` (its display label) so this file never has to
 * know the node-type table. That keeps it unit-testable in isolation.
 *
 * It finds, BEFORE publish, the quiet ways a flow breaks a real conversation:
 *   - a branch (button / condition / list option) wired to nothing, so the
 *     contact who takes it silently dead-ends — the #1 "flow stops midway" cause
 *   - a node nothing can reach from the trigger
 *   - a {{variable}} no step ever sets, so it renders blank
 *   - a required field left empty (message with no text, webhook with no URL…)
 *   - a loop with no wait step (a tight infinite loop)
 *   - no trigger, or more than one
 *
 * Returns { errors, warnings, info, errorNodeIds, warnNodeIds, ok }.
 * Each issue: { nodeId, severity: 'error'|'warning'|'info', code, msg }.
 * Errors genuinely break the flow; warnings are very likely bugs but may be
 * intentional (an option that deliberately ends the chat), so they never block.
 */

// Vars the runtime always has, so referencing them is never "undefined".
const BUILTIN_VARS = new Set([
  'name', 'first_name', 'last_name', 'full_name', 'contact_name', 'display_name',
  'phone', 'mobile', 'number', 'email', 'wa_id', 'channel',
  'timestamp', 'date', 'time', 'now', 'today', 'datetime',
]);

// Node types that pause the flow (wait for the contact or the clock). A loop is
// only dangerous when it contains none of these — then it spins with no brake.
const WAIT_TYPES = new Set([
  'ask', 'buttons', 'list', 'poll', 'delay', 'wa_form', 'google_form',
  'ig_ask', 'ig_quick', 'cf_listen', 'cf_menu', 'book_appointment',
]);

/** Pull the variable(s) a node WRITES, so we know what later steps can read. */
function producedVars(node) {
  const t = node.type, d = node.data || {};
  const out = [];
  const push = (v) => { const s = String(v || '').trim(); if (s) out.push(s); };
  switch (t) {
    case 'ask': case 'buttons': case 'list': push(d.var); break;
    case 'ai': case 'webhook': case 'code': case 'mysql':
    case 'cf_listen': case 'cf_ai': case 'cf_assistant': case 'cf_search':
    case 'ig_ai': push(d.save); break;
    case 'ig_ask': push(d.save); break;
    case 'wa_form': push(d.flowVariable); break;
    case 'google_sheets': case 'google_docs': case 'google_form': case 'deal':
      push(d.saveAs); break;
    case 'book_appointment': push(d.dateVar); break;
    case 'ig_lead': case 'fb_lead':
      push(d.nameVar); push(d.emailVar); push(d.phoneVar); push(d.notesVar); break;
  }
  // An AI node with "extract fields" declares extra output vars (comma/space list).
  if ((t === 'ai' || t === 'cf_ai') && d.extract && d.fields) {
    String(d.fields).split(/[,\s]+/).forEach(push);
  }
  return out;
}

/** Every {{token}} a node READS — just the root (so {{order.id}} → "order"). */
function referencedVars(node) {
  let blob = '';
  try { blob = JSON.stringify(node.data || {}); } catch (e) { blob = ''; }
  const found = new Set();
  const re = /\{\{\s*([a-zA-Z_][\w]*)/g;
  let m;
  while ((m = re.exec(blob)) !== null) found.add(m[1]);
  return Array.from(found);
}

/** Per-type required-field checks. Returns an array of issue partials. */
function requiredFieldIssues(node, labelOf) {
  const t = node.type, d = node.data || {};
  const label = labelOf(node);
  const bad = [];
  const err  = (msg) => bad.push({ severity: 'error',   msg });
  const warn = (msg) => bad.push({ severity: 'warning', msg });
  const empty = (v) => String(v == null ? '' : v).trim() === '';
  const noOpts = (arr) => !Array.isArray(arr) || arr.length === 0;

  switch (t) {
    case 'message':
      if (empty(d.text)) warn(`"${label}" has no message text.`);
      break;
    case 'cf_say':
      if (empty(d.text)) warn(`"${label}" has nothing to say.`);
      break;
    case 'ig_send_dm': case 'fb_reply_comment': case 'ig_reply_comment':
      if (empty(d.text) && empty(d.message)) warn(`"${label}" has no text.`);
      break;
    case 'ask':
      if (empty(d.var)) err(`"${label}" does not store the answer in a variable — the reply is lost.`);
      if (empty(d.prompt)) warn(`"${label}" has no question to ask.`);
      break;
    case 'buttons':
      if (noOpts(d.options) || d.options.every(empty)) warn(`"${label}" has no buttons set.`);
      if (empty(d.var)) warn(`"${label}" does not save the choice in a variable.`);
      break;
    case 'list':
      if (noOpts(d.options)) warn(`"${label}" has no items.`);
      break;
    case 'poll':
      if (noOpts(d.options) || d.options.every(empty)) warn(`"${label}" has no options.`);
      break;
    case 'condition': {
      const rules = Array.isArray(d.conditions) ? d.conditions : [];
      if (rules.length === 0) warn(`"${label}" has no rule set — it always takes the same branch.`);
      else if (rules.some(r => empty(r && r.variable))) warn(`"${label}" has a rule with no variable chosen.`);
      break;
    }
    case 'webhook':
      if (empty(d.url)) err(`"${label}" has no URL to call.`);
      break;
    case 'template':
      if (empty(d.tpl)) warn(`"${label}" has no template selected.`);
      break;
    case 'subflow':
      if (empty(d.flow)) err(`"${label}" has no flow selected to run.`);
      break;
    case 'ai': case 'cf_ai':
      if (empty(d.prompt) && empty(d.assistant)) warn(`"${label}" has neither a prompt nor an assistant.`);
      break;
    case 'book_appointment':
      if (empty(d.bookingTypeId)) warn(`"${label}" has no booking type selected.`);
      break;
    case 'cross_channel':
      if (empty(d.to)) warn(`"${label}" has no recipient set.`);
      break;
    case 'media':
      if (empty(d.url) && empty(d.filename)) warn(`"${label}" has no file selected.`);
      break;
  }
  return bad.map(b => ({ ...b, nodeId: node.id }));
}

/** Find back-edges (cycles) via DFS; report any cycle with no wait step in it. */
function loopIssues(nodes, outByNode, labelOf) {
  const state = new Map(); // id -> 0 unvisited, 1 on-stack, 2 done
  const stack = [];
  const issues = [];
  const reported = new Set();

  const visit = (id) => {
    state.set(id, 1);
    stack.push(id);
    for (const e of (outByNode.get(id) || [])) {
      const nx = e.target;
      const st = state.get(nx) || 0;
      if (st === 0) { visit(nx); }
      else if (st === 1) {
        // Back-edge → cycle is stack[from nx .. top].
        const from = stack.indexOf(nx);
        if (from < 0) continue;
        const cycle = stack.slice(from);
        const key = [...cycle].sort().join('|');
        if (reported.has(key)) continue;
        reported.add(key);
        const hasWait = cycle.some(cid => {
          const n = nodes.find(x => x.id === cid);
          return n && WAIT_TYPES.has(n.type);
        });
        if (!hasWait) {
          const anchor = nodes.find(x => x.id === cycle[0]);
          issues.push({
            nodeId: cycle[0],
            severity: 'warning',
            code: 'tight_loop',
            msg: `"${labelOf(anchor)}" is part of a loop with no wait step — it can run forever. Add a Delay or a question inside the loop.`,
          });
        }
      }
    }
    stack.pop();
    state.set(id, 2);
  };

  nodes.forEach(n => { if ((state.get(n.id) || 0) === 0) visit(n.id); });
  return issues;
}

/**
 * Analyze a flow graph.
 * @param {Array} nodes  builder nodes: { id, type, data, isStart }
 * @param {Array} edges  builder edges: { source, sourceHandle, target }
 * @param {Object} opts  { handlesOf(node)->[{id,label}], labelOf(node)->string, declaredVars?:string[] }
 */
export function diagnoseFlow(nodes, edges, opts = {}) {
  const list = Array.isArray(nodes) ? nodes.filter(Boolean) : [];
  const links = Array.isArray(edges) ? edges.filter(Boolean) : [];
  const handlesOf = opts.handlesOf || (() => []);
  const labelOf   = opts.labelOf   || ((n) => (n && n.type) || 'node');
  const declared  = new Set((opts.declaredVars || []).map(v => String(v || '').trim()).filter(Boolean));

  const issues = [];
  const add = (nodeId, severity, code, msg) => issues.push({ nodeId, severity, code, msg });

  if (!list.length) {
    return { errors: [], warnings: [], info: [], errorNodeIds: [], warnNodeIds: [], ok: true, empty: true };
  }

  const byId = new Map(list.map(n => [n.id, n]));
  const incoming = new Set();
  const outByNode = new Map();
  const handlesUsed = new Map(); // nodeId -> Set(sourceHandle)
  links.forEach(e => {
    if (!byId.has(e.source) || !byId.has(e.target)) return; // dangling edge — ignore
    incoming.add(e.target);
    if (!outByNode.has(e.source)) outByNode.set(e.source, []);
    outByNode.get(e.source).push(e);
    if (!handlesUsed.has(e.source)) handlesUsed.set(e.source, new Set());
    handlesUsed.get(e.source).add(e.sourceHandle || 'out');
  });

  // --- Structure: trigger presence + reachability ------------------------
  const triggers = list.filter(n => n.type === 'trigger');
  const start = list.find(n => n.isStart) || triggers[0] || list[0];
  if (triggers.length === 0) {
    add(start.id, 'error', 'no_trigger', 'This flow has no Trigger node — nothing will ever start it.');
  } else if (triggers.length > 1) {
    triggers.slice(1).forEach(t =>
      add(t.id, 'warning', 'multi_trigger', `"${labelOf(t)}" is a second Trigger — a flow runs from one entry point; extra triggers are ignored.`));
  }

  // BFS from the start node over outgoing edges.
  const reachable = new Set([start.id]);
  const queue = [start.id];
  while (queue.length) {
    const cur = queue.shift();
    for (const e of (outByNode.get(cur) || [])) {
      if (!reachable.has(e.target)) { reachable.add(e.target); queue.push(e.target); }
    }
  }
  list.forEach(n => {
    if (n.id === start.id || n.type === 'trigger') return;
    if (!reachable.has(n.id)) {
      add(n.id, 'error', 'unreachable',
        incoming.has(n.id)
          ? `"${labelOf(n)}" can't be reached from the Trigger — the steps leading to it are themselves disconnected.`
          : `"${labelOf(n)}" is not connected to anything — nothing leads into it.`);
    }
  });

  // --- Dead-end branches: the headliner ----------------------------------
  // A node with ONE output and no edge is a valid leaf (a flow can end on a
  // "Send message"). But a node with SEVERAL outputs (buttons, condition,
  // list, commerce, booking…) where one port is empty silently drops every
  // contact who takes that path. Flag each empty branch.
  list.forEach(n => {
    const handles = handlesOf(n) || [];
    if (handles.length < 2) return;
    const used = handlesUsed.get(n.id) || new Set();
    handles.forEach(h => {
      if (!used.has(h.id)) {
        add(n.id, 'warning', 'dead_branch',
          `On "${labelOf(n)}", the "${h.label || h.id}" path goes nowhere — contacts taking it stop here.`);
      }
    });
  });

  // --- Required fields ----------------------------------------------------
  list.forEach(n => {
    requiredFieldIssues(n, labelOf).forEach(p => add(p.nodeId, p.severity, 'required', p.msg));
  });

  // --- Undefined variables (conservative) --------------------------------
  // Collect every var any step ever sets, plus declared + built-ins. We flag a
  // {{var}} only when NOTHING anywhere produces it — not based on ordering — so
  // a value captured "later" on a loop-back never trips a false positive.
  const known = new Set(BUILTIN_VARS);
  declared.forEach(v => known.add(v));
  list.forEach(n => producedVars(n).forEach(v => known.add(v)));
  list.forEach(n => {
    referencedVars(n).forEach(v => {
      if (!known.has(v)) {
        add(n.id, 'warning', 'undef_var',
          `"${labelOf(n)}" uses {{${v}}}, but no earlier step sets it — it will send blank.`);
      }
    });
  });

  // --- Loops with no wait -------------------------------------------------
  loopIssues(list, outByNode, labelOf).forEach(i => add(i.nodeId, i.severity, i.code, i.msg));

  const errors   = issues.filter(i => i.severity === 'error');
  const warnings = issues.filter(i => i.severity === 'warning');
  const info     = issues.filter(i => i.severity === 'info');
  const uniqIds  = (arr) => Array.from(new Set(arr.map(i => i.nodeId)));

  return {
    errors, warnings, info,
    errorNodeIds: uniqIds(errors),
    warnNodeIds:  uniqIds(warnings),
    ok: errors.length === 0 && warnings.length === 0,
    empty: false,
  };
}

export default diagnoseFlow;
