'use strict';

// ---------------------------------------------------------------------------
// AI Chat — client message → todo cards
// Prefer Chrome built-in Prompt API (LanguageModel / Gemini Nano).
// Fall back to WebLLM in the browser when Chrome AI is unavailable.
// ---------------------------------------------------------------------------

const AI_MAX_MESSAGE = 8000;
const AI_MAX_TODOS = 20;
const AI_MAX_CHECKLIST = 30;
const WEBLLM_MODEL = 'Llama-3.2-1B-Instruct-q4f16_1-MLC';
const WEBLLM_CDN = 'https://esm.run/@mlc-ai/web-llm';

/** Allowed board label keys (must match LABELS in app.js). */
const AI_LABEL_KEYS = ['red', 'orange', 'yellow', 'blue', 'green', 'grey', 'purple', 'sky'];
const AI_LABEL_COLORS = {
  green:  '#61bd4f',
  yellow: '#f2d600',
  orange: '#ff9f1a',
  red:    '#eb5a46',
  purple: '#c377e0',
  blue:   '#0079bf',
  sky:    '#00c2e0',
  grey:   '#90a4ae',
};

const AI_SYSTEM_PROMPT = [
  'You turn client messages into developer task-board cards. Return ONLY valid JSON (no markdown, no commentary).',
  'Shape: {"todos":[{"title":"card title","description":"imperative instruction with context","due":"YYYY-MM-DD or null","label":"red|orange|yellow|blue|green|grey|purple|sky|null","assignees":[],"checklist":["imperative step"],"comment":null}]}',
  'Card count — follow the client message (do not over-split or force a single card):',
  '- Prefer ONE card when the message is one ask, one topic, one paragraph, or a heading with related nested bullets/steps.',
  '- Nested bullets under a parent heading = checklist items on that SAME card. Never turn each bullet into its own card.',
  '- Create multiple cards ONLY when the client clearly gives separate unrelated workstreams (distinct headings, separate numbered topics, or clearly different features).',
  '- Example ONE card: "I enabled Stripe Test Mode via skip form — please test the payment flow"',
  '  -> {"todos":[{"title":"Test Stripe payment flow","description":"Client enabled Stripe Test Mode via the dashboard skip-form option and confirmed it is active. Test the platform payment flow end-to-end in test mode.","due":null,"label":"blue","assignees":[],"checklist":[],"comment":null}]}',
  '- Example ONE card: "Check SMTP\\n - configure plugin\\n - test reset email" -> one card with checklist.',
  '- Example TWO cards: "1) Fix checkout bug\\n2) Redesign homepage hero" -> two cards.',
  'Voice:',
  '- Description starts with what the developer must DO (imperative: Fix, Add, Configure, Test, Verify…).',
  '- If the client acknowledges something already done or prepared (e.g. "I have enabled…", "we set up…", "credentials are ready", "I uploaded…"), INCLUDE that briefly in the description so the developer has context — do not turn their completed work into a separate todo.',
  '- Prefer: "Client enabled Stripe Test Mode (skip form); test the payment flow." NOT two cards (verify mode + test flow), and NOT dropping the client-done context.',
  '- Checklist items stay imperative steps. Titles = short work names. No due dates in titles.',
  '- Stay faithful; do not invent scope. Drop greetings/filler.',
  'Fields:',
  '- description: 1–3 sentences (<280 chars): command + any client-done/prepared context needed.',
  '- checklist: imperative steps from client bullets/sub-tasks; [] if none.',
  '- due: YYYY-MM-DD from deadlines using Today in the user message; else null.',
  '- label: one of red/orange/yellow/blue/green/grey/purple/sky (default blue).',
  '- assignees: DEFAULT []. Only add a name if the client message EXPLICITLY assigns that person (e.g. "assign to Sam", "for Priya", "@Alex"). Listing project members is NOT a reason to assign. If unsure, [].',
  '- comment: optional extra context that does not fit description; else null.',
  'Limits: titles <80; descriptions <280; checklist items <120; comments <500; max 20 cards; max 30 checklist items/card; max 5 assignees/card.',
].join(' ');

const AI_RETRY_PROMPT = [
  'Your previous reply was not valid JSON.',
  'Reply with ONLY: {"todos":[{"title":"...","description":"imperative instruction with client-done context if any","due":"YYYY-MM-DD or null","label":"blue","assignees":[],"checklist":["..."],"comment":null}]}',
  'Do not over-split one client ask. Include client-already-done/prepared notes in description. assignees=[] unless explicitly assigned. No markdown fences.',
].join(' ');

let aiPendingTodos = [];
let aiBusy = false;
let webllmEngine = null;
let webllmLoading = null;

function aiTb() {
  return window.TaskBoard || null;
}

function aiEl(id) {
  return document.getElementById(id);
}

function aiSetStatus(msg, kind) {
  const node = aiEl('ai-chat-status');
  if (!node) return;
  node.textContent = msg || '';
  node.classList.toggle('is-error', kind === 'error');
  node.classList.toggle('is-ok', kind === 'ok');
}

function aiSetProgress(pct) {
  const wrap = aiEl('ai-chat-progress-wrap');
  const fill = aiEl('ai-chat-progress-fill');
  if (!wrap || !fill) return;
  if (pct == null || pct < 0) {
    wrap.hidden = true;
    fill.style.width = '0%';
    return;
  }
  wrap.hidden = false;
  const clamped = Math.max(0, Math.min(100, Number(pct) || 0));
  fill.style.width = clamped + '%';
}

function aiSetBusy(busy) {
  aiBusy = !!busy;
  const gen = aiEl('ai-chat-generate');
  const add = aiEl('ai-chat-add');
  if (gen) gen.disabled = aiBusy;
  if (add) add.disabled = aiBusy;
}

function aiPopulateLists() {
  const sel = aiEl('ai-chat-list');
  const tb = aiTb();
  if (!sel || !tb) return;
  const lists = (tb.board && tb.board.lists) || [];
  const prev = sel.value;
  sel.replaceChildren();
  if (!lists.length) {
    sel.appendChild(new Option('No lists yet', ''));
    sel.disabled = true;
    return;
  }
  sel.disabled = false;
  lists.forEach((l) => {
    sel.appendChild(new Option(l.title || 'List', l.id));
  });
  if (prev && lists.some((l) => l.id === prev)) sel.value = prev;
  else sel.value = lists[0].id;
}

function aiClearPreview() {
  aiPendingTodos = [];
  const list = aiEl('ai-chat-preview-list');
  const addBtn = aiEl('ai-chat-add');
  const side = aiEl('ai-chat-side');
  const layout = aiEl('ai-chat-layout');
  const shell = document.querySelector('#ai-chat-modal .ai-chat-modal');
  if (list) list.replaceChildren();
  if (addBtn) addBtn.hidden = true;
  if (layout) layout.classList.remove('has-preview');
  if (shell) shell.classList.remove('has-preview');
  if (side) {
    const hide = () => {
      if (!layout?.classList.contains('has-preview')) side.hidden = true;
    };
    side.addEventListener('transitionend', hide, { once: true });
    // Fallback if transition is skipped (e.g. reduced motion / first open).
    setTimeout(hide, 420);
  }
}

function aiShowPreview(todos) {
  aiPendingTodos = todos;
  const preview = aiEl('ai-chat-preview');
  const list = aiEl('ai-chat-preview-list');
  const addBtn = aiEl('ai-chat-add');
  const side = aiEl('ai-chat-side');
  const layout = aiEl('ai-chat-layout');
  const shell = document.querySelector('#ai-chat-modal .ai-chat-modal');
  if (!preview || !list || !addBtn || !side || !layout) return;

  list.replaceChildren();
  todos.forEach((t, index) => {
    const li = document.createElement('li');
    li.className = 'ai-todo-enter';
    li.style.setProperty('--ai-todo-i', String(index));
    if (t.label && AI_LABEL_COLORS[t.label]) {
      const chip = document.createElement('span');
      chip.className = 'ai-todo-label';
      chip.style.background = AI_LABEL_COLORS[t.label];
      chip.title = t.label;
      chip.textContent = t.label;
      li.appendChild(chip);
    }
    li.appendChild(document.createTextNode(t.title));
    if (t.due) {
      const due = document.createElement('span');
      due.className = 'ai-todo-due';
      due.textContent = 'Due ' + t.due;
      li.appendChild(due);
    }
    if (t.assigneeNames && t.assigneeNames.length) {
      const row = document.createElement('span');
      row.className = 'ai-todo-assignees';
      t.assigneeNames.forEach((name) => {
        const chip = document.createElement('span');
        chip.className = 'ai-todo-assignee';
        chip.textContent = name;
        row.appendChild(chip);
      });
      li.appendChild(row);
    }
    if (t.description) {
      const d = document.createElement('span');
      d.className = 'ai-todo-desc';
      d.textContent = t.description;
      li.appendChild(d);
    }
    if (t.checklist && t.checklist.length) {
      const ul = document.createElement('ul');
      ul.className = 'ai-todo-checklist';
      t.checklist.forEach((item) => {
        const cli = document.createElement('li');
        cli.textContent = item;
        ul.appendChild(cli);
      });
      li.appendChild(ul);
    }
    if (t.comment) {
      const c = document.createElement('span');
      c.className = 'ai-todo-comment';
      c.textContent = 'AI note: ' + t.comment;
      li.appendChild(c);
    }
    list.appendChild(li);
  });

  side.hidden = false;
  addBtn.hidden = false;
  addBtn.textContent = todos.length === 1 ? 'Add 1 card' : `Add ${todos.length} cards`;

  // Force layout, then open panel so CSS transitions run.
  void side.offsetWidth;
  requestAnimationFrame(() => {
    layout.classList.add('has-preview');
    if (shell) shell.classList.add('has-preview');
  });
}

function openAiChatModal() {
  if (window.TaskBoard?.shareMode) return;
  const modal = aiEl('ai-chat-modal');
  if (!modal) return;
  aiPopulateLists();
  aiClearPreview();
  aiSetProgress(null);
  aiSetStatus('');
  modal.hidden = false;
  setTimeout(() => aiEl('ai-chat-message')?.focus(), 0);
}

function closeAiChatModal() {
  const modal = aiEl('ai-chat-modal');
  if (modal) modal.hidden = true;
  if (!aiBusy) {
    aiClearPreview();
    aiSetProgress(null);
    aiSetStatus('');
  }
}

/** Extract a JSON object from model text (handles optional markdown fences). */
function aiExtractJson(text) {
  const raw = String(text || '').trim();
  if (!raw) throw new Error('Empty model response.');
  const fenced = raw.match(/```(?:json)?\s*([\s\S]*?)```/i);
  const candidate = (fenced ? fenced[1] : raw).trim();
  const start = candidate.indexOf('{');
  const end = candidate.lastIndexOf('}');
  if (start === -1 || end === -1 || end <= start) {
    throw new Error('No JSON object found in the response.');
  }
  return JSON.parse(candidate.slice(start, end + 1));
}

function aiNormalizeDue(raw) {
  if (raw == null || raw === '') return null;
  const s = String(raw).trim();
  if (!s || /^null$/i.test(s)) return null;
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) {
    const d = new Date(s + 'T00:00:00');
    return Number.isNaN(d.getTime()) ? null : s;
  }
  const parsed = new Date(s);
  if (Number.isNaN(parsed.getTime())) return null;
  const y = parsed.getFullYear();
  const m = String(parsed.getMonth() + 1).padStart(2, '0');
  const day = String(parsed.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function aiNormalizeLabel(raw) {
  if (raw == null || raw === '') return 'blue';
  if (typeof raw === 'string' && /^null$/i.test(raw.trim())) return 'blue';

  // Prefer first entry if an array was returned.
  let key = Array.isArray(raw) ? raw[0] : raw;
  if (key && typeof key === 'object') {
    key = key.key || key.id || key.name || key.color || '';
  }
  key = String(key || '').trim().toLowerCase();

  // Map common synonyms / hex leftovers to palette keys.
  const aliases = {
    urgent: 'red', asap: 'red', critical: 'red', high: 'orange',
    medium: 'yellow', med: 'yellow', normal: 'blue', standard: 'blue',
    low: 'green', hold: 'grey', waiting: 'grey', blocked: 'grey',
    info: 'sky', question: 'sky', special: 'purple',
  };
  if (aliases[key]) key = aliases[key];
  if (AI_LABEL_KEYS.includes(key)) return key;
  return 'blue';
}

function aiBoardMembers() {
  const tb = aiTb();
  return ((tb?.board?.members) || []).filter((m) => m && m.id && m.name);
}

function aiMatchMember(query, members) {
  const q = String(query || '').trim().toLowerCase().replace(/^@/, '');
  if (!q || /^null$/i.test(q) || q.length < 2) return null;

  // High confidence only: exact full name, or unique first name.
  const exact = members.filter((m) => String(m.name).toLowerCase() === q);
  if (exact.length === 1) return exact[0];

  const first = members.filter((m) => String(m.name).toLowerCase().split(/\s+/)[0] === q);
  if (first.length === 1) return first[0];

  return null;
}

function aiStripDueFromTitle(title) {
  let t = String(title || '');
  t = t.replace(/\s*[\(\[\{]?\s*(due|deadline)\s*:?\s*\d{4}-\d{2}-\d{2}\s*[\)\]\}]?/gi, '');
  t = t.replace(/\s*[-–—|:]\s*(due|deadline|by)\s*:?\s*\d{4}-\d{2}-\d{2}\b/gi, '');
  t = t.replace(/\s*[-–—|:]\s*(due|deadline)\s*:?\s*(mon|tue|wed|thu|fri|sat|sun|monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b[^,]*/gi, '');
  t = t.replace(/\s*[-–—|:]\s*(due|deadline|by)\s*:?\s*\d{1,2}([\/\-.]\d{1,2})?([\/\-.]\d{2,4})?\b/gi, '');
  t = t.replace(/\s*\(\s*(due|deadline)[^)]*\)\s*/gi, '');
  t = t.replace(/\s*[-–—|:]\s*(due|deadline)\b.*$/gi, '');
  t = t.replace(/\s{2,}/g, ' ').replace(/\s*[-–—|:]\s*$/g, '').trim();
  return t;
}

function aiHasAssignmentIntent(clientText) {
  const text = String(clientText || '');
  if (!text.trim()) return false;
  if (/@\w{2,}/.test(text)) return true;
  if (/\bassign(?:ed|s|ing)?\s+to\b/i.test(text)) return true;
  if (/\b(?:this\s+is\s+for|handed?\s+(?:this\s+)?(?:over\s+)?to|give\s+(?:this\s+)?to)\b/i.test(text)) return true;
  if (/\b(?:owner|assignee|developer|dev)\s*:\s*\S+/i.test(text)) return true;
  // "for/to <member>" only counts when it matches a project member name.
  for (const m of aiBoardMembers()) {
    const name = String(m.name || '').trim();
    if (name.length < 2) continue;
    const full = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const first = name.split(/\s+/)[0].replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    if (new RegExp('\\b(?:for|to)\\s+' + full + '\\b', 'i').test(text)) return true;
    if (first.length >= 3 && new RegExp('\\b(?:for|to)\\s+' + first + '\\b', 'i').test(text)) return true;
  }
  return false;
}

function aiNormalizeAssignees(raw, clientText) {
  // Hard rule: no clear assignment language in the client message → nobody.
  if (!aiHasAssignmentIntent(clientText)) return { ids: [], names: [] };

  const members = aiBoardMembers();
  if (!members.length) return { ids: [], names: [] };

  let names = [];
  if (Array.isArray(raw)) {
    names = raw;
  } else if (raw && typeof raw === 'object') {
    names = [raw.name || raw.id || ''];
  } else if (typeof raw === 'string' && raw.trim() && !/^null$/i.test(raw.trim())) {
    names = raw.split(/[,;&|/]+/).map((s) => s.trim()).filter(Boolean);
  }

  const msg = String(clientText || '').toLowerCase();
  const ids = [];
  const matchedNames = [];
  const seen = new Set();
  for (const entry of names) {
    const label = typeof entry === 'string'
      ? entry
      : String(entry?.name || entry?.id || '').trim();
    const hit = aiMatchMember(label, members);
    if (!hit || seen.has(hit.id)) continue;
    // Name (or first name) must appear in the client message.
    const full = String(hit.name).toLowerCase();
    const first = full.split(/\s+/)[0];
    if (!msg.includes(full) && !(first.length >= 3 && msg.includes(first))) continue;
    seen.add(hit.id);
    ids.push(hit.id);
    matchedNames.push(hit.name);
    if (ids.length >= 5) break;
  }
  return { ids, names: matchedNames };
}

function aiDecodeEntities(str) {
  let s = String(str ?? '');
  const named = {
    amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: '\u00a0',
  };
  const decodeOnce = (input) => input.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (entity, body) => {
    const key = String(body || '');
    if (key[0] === '#') {
      const code = key[1] === 'x' || key[1] === 'X'
        ? parseInt(key.slice(2), 16)
        : parseInt(key.slice(1), 10);
      if (!Number.isFinite(code) || code < 0 || code > 0x10ffff) return entity;
      try { return String.fromCodePoint(code); } catch (_) { return entity; }
    }
    const mapped = named[key.toLowerCase()];
    return mapped !== undefined ? mapped : entity;
  });
  for (let i = 0; i < 3; i++) {
    if (!/&(?:#x?[0-9a-f]+|[a-z]+);/i.test(s)) break;
    const next = decodeOnce(s);
    if (next === s) break;
    s = next;
  }
  return s;
}

function aiNormalizeTodos(payload, clientText) {
  const arr = Array.isArray(payload?.todos)
    ? payload.todos
    : (Array.isArray(payload) ? payload : null);
  if (!arr) throw new Error('JSON must include a "todos" array.');

  const out = [];
  for (const item of arr) {
    if (!item || typeof item !== 'object') continue;
    let title = aiStripDueFromTitle(
      aiDecodeEntities(String(item.title || item.name || '').trim())
    ).slice(0, 80);
    if (!title) continue;
    const description = aiDecodeEntities(String(item.description || item.detail || item.summary || ''))
      .trim()
      .replace(/\s+/g, ' ')
      .slice(0, 280);
    const due = aiNormalizeDue(item.due ?? item.deadline ?? item.due_date ?? null);
    const label = aiNormalizeLabel(item.label ?? item.urgency ?? item.priority ?? item.labels);
    const assignees = aiNormalizeAssignees(
      item.assignees ?? item.assignee ?? item.assigned_to ?? item.developers ?? item.developer,
      clientText
    );
    let comment = aiDecodeEntities(String(item.comment || item.note || '')).trim().slice(0, 500);
    if (!comment || /^null$/i.test(comment)) comment = '';

    let checklist = [];
    const rawChk = item.checklist || item.items || item.subtasks || [];
    if (Array.isArray(rawChk)) {
      for (const row of rawChk) {
        const text = typeof row === 'string'
          ? row
          : String(row?.text || row?.title || '').trim();
        const clean = aiDecodeEntities(text.replace(/^[-*•]\s*/, '').trim()).slice(0, 120);
        if (!clean) continue;
        checklist.push(clean);
        if (checklist.length >= AI_MAX_CHECKLIST) break;
      }
    }

    out.push({
      title,
      description,
      due,
      label,
      members: assignees.ids,
      assigneeNames: assignees.names,
      checklist,
      comment,
    });
    if (out.length >= AI_MAX_TODOS) break;
  }
  if (!out.length) throw new Error('No valid todos were returned.');
  return out;
}

function aiTodayIso() {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function aiUserPrompt(userText) {
  const roster = aiBoardMembers().map((m) => m.name);
  const membersLine = roster.length
    ? (
      'Project members (for assignees ONLY if the client message explicitly assigns someone by name; otherwise always use "assignees": []): '
      + roster.join(', ')
    )
    : 'Project members: none — always use "assignees": [].';
  return (
    'Today is ' + aiTodayIso() + '.\n' +
    membersLine + '\n' +
    'Default assignees to []. Do not assign anyone just because members are listed.\n\n' +
    'Client message:\n\n' + userText + '\n\n' +
    'Follow the client message for how many cards to create. Return JSON only.'
  );
}

async function aiChromeStatus() {
  if (!('LanguageModel' in self)) return 'missing';
  try {
    const status = await LanguageModel.availability();
    return status || 'unavailable';
  } catch (err) {
    return 'unavailable';
  }
}

async function aiPromptChrome(userText, onProgress) {
  aiSetStatus('Chrome built-in AI ready — starting…');
  onProgress(null);
  const userPrompt = aiUserPrompt(userText);
  const session = await LanguageModel.create({
    initialPrompts: [{ role: 'system', content: AI_SYSTEM_PROMPT }],
    monitor(m) {
      m.addEventListener('downloadprogress', (e) => {
        const pct = typeof e.loaded === 'number' ? Math.round(e.loaded * 100) : 0;
        if (pct <= 0) {
          aiSetStatus('Preparing Chrome built-in AI…');
          return;
        }
        onProgress(pct);
        aiSetStatus(`Downloading Chrome built-in AI model… ${pct}%`);
      });
    },
  });
  onProgress(null);
  aiSetStatus('Chrome built-in AI is generating todos…');
  let reply = await session.prompt(userPrompt);
  let retried = false;
  let todos;
  try {
    todos = aiNormalizeTodos(aiExtractJson(reply), userText);
  } catch (err) {
    retried = true;
    aiSetStatus('Chrome AI response was invalid JSON — retrying…');
    reply = await session.prompt(AI_RETRY_PROMPT + '\n\n' + userPrompt);
    todos = aiNormalizeTodos(aiExtractJson(reply), userText);
  }
  return {
    provider: 'chrome',
    model: 'Gemini Nano (LanguageModel / Prompt API)',
    request: {
      system: AI_SYSTEM_PROMPT,
      user: userPrompt,
      retried,
    },
    response: reply,
    todos,
  };
}

async function aiEnsureWebllm(onProgress) {
  if (webllmEngine) {
    aiSetStatus('Using cached WebLLM model…');
    onProgress(null);
    return webllmEngine;
  }
  if (webllmLoading) {
    aiSetStatus('Waiting for WebLLM model to finish loading…');
    return webllmLoading;
  }

  if (!navigator.gpu) {
    throw new Error('WebLLM needs WebGPU. Use Chrome/Edge with WebGPU, or enable Chrome built-in AI.');
  }

  webllmLoading = (async () => {
    aiSetStatus('Loading WebLLM library…');
    onProgress(0);
    const webllm = await import(WEBLLM_CDN);
    aiSetStatus('Preparing WebLLM model (first run may download)…');
    const engine = await webllm.CreateMLCEngine(WEBLLM_MODEL, {
      initProgressCallback: (p) => {
        const pct = typeof p?.progress === 'number' ? Math.round(p.progress * 100) : 0;
        const text = String(p?.text || '').toLowerCase();
        if (pct > 0) onProgress(pct);
        if (text.includes('finish') || pct >= 100) {
          aiSetStatus('WebLLM model ready…');
          onProgress(100);
          return;
        }
        if (text.includes('cache') || text.includes('fetch')) {
          aiSetStatus(pct > 0
            ? `Fetching WebLLM model… ${pct}%`
            : 'Checking WebLLM model cache…');
          return;
        }
        if (text.includes('load') || text.includes('gpu') || text.includes('shader')) {
          aiSetStatus(pct > 0
            ? `Loading WebLLM on GPU… ${pct}%`
            : 'Loading WebLLM on GPU…');
          return;
        }
        aiSetStatus(pct > 0
          ? `WebLLM progress… ${pct}%`
          : 'Preparing WebLLM…');
      },
    });
    webllmEngine = engine;
    webllmLoading = null;
    onProgress(null);
    return engine;
  })();

  try {
    return await webllmLoading;
  } catch (err) {
    webllmLoading = null;
    throw err;
  }
}

async function aiPromptWebllm(userText, onProgress) {
  const cached = !!webllmEngine;
  const engine = await aiEnsureWebllm(onProgress);
  onProgress(null);
  aiSetStatus(cached
    ? 'WebLLM (cached) is generating todos…'
    : 'WebLLM is generating todos…');

  const userPrompt = aiUserPrompt(userText);
  const messages = [
    { role: 'system', content: AI_SYSTEM_PROMPT },
    { role: 'user', content: userPrompt },
  ];

  let reply = await engine.chat.completions.create({
    messages,
    temperature: 0.2,
    max_tokens: 1024,
  });
  let content = reply?.choices?.[0]?.message?.content || '';
  let retried = false;
  let todos;

  try {
    todos = aiNormalizeTodos(aiExtractJson(content), userText);
  } catch (err) {
    retried = true;
    aiSetStatus('WebLLM response was invalid JSON — retrying…');
    reply = await engine.chat.completions.create({
      messages: [
        ...messages,
        { role: 'assistant', content },
        { role: 'user', content: AI_RETRY_PROMPT },
      ],
      temperature: 0.1,
      max_tokens: 1024,
    });
    content = reply?.choices?.[0]?.message?.content || '';
    todos = aiNormalizeTodos(aiExtractJson(content), userText);
  }

  return {
    provider: 'webllm',
    model: WEBLLM_MODEL,
    request: {
      system: AI_SYSTEM_PROMPT,
      user: userPrompt,
      retried,
    },
    response: content,
    todos,
  };
}

async function aiGenerateTodos(userText) {
  aiSetProgress(null);
  aiSetStatus('Choosing AI engine…');

  // Prefer an already-warm WebLLM session over starting a Chrome model download.
  if (webllmEngine) {
    aiSetStatus('Found cached WebLLM model — using it…');
    return aiPromptWebllm(userText, aiSetProgress);
  }

  const chromeStatus = await aiChromeStatus();

  if (chromeStatus === 'available') {
    aiSetStatus('Chrome built-in AI is available — using it…');
    try {
      return await aiPromptChrome(userText, aiSetProgress);
    } catch (err) {
      console.warn('Chrome AI failed, falling back to WebLLM:', err);
      aiSetStatus('Chrome AI failed — switching to WebLLM…');
      aiSetProgress(null);
      return aiPromptWebllm(userText, aiSetProgress);
    }
  }

  if (chromeStatus === 'downloadable' || chromeStatus === 'downloading') {
    aiSetStatus('Chrome built-in AI is not fully ready — using WebLLM instead…');
  } else if (chromeStatus === 'missing') {
    aiSetStatus('Chrome built-in AI not supported here — using WebLLM…');
  } else {
    aiSetStatus('Chrome built-in AI unavailable — using WebLLM…');
  }

  return aiPromptWebllm(userText, aiSetProgress);
}

/** Persist AI request/response to server logs/ when DEBUG is enabled. */
async function aiLogDebug(payload) {
  const tb = aiTb();
  if (!tb || !tb.debug || typeof tb.postJSON !== 'function') return;
  try {
    await tb.postJSON(`${tb.API}?op=ai_debug_log`, {
      provider: payload.provider || 'unknown',
      model: payload.model || 'unknown',
      project_id: tb.currentProjectId || null,
      request: payload.request || null,
      response: payload.response || null,
      error: payload.error || null,
    });
  } catch (err) {
    console.warn('AI debug log failed:', err);
  }
}

async function onAiGenerate() {
  const tb = aiTb();
  if (!tb) {
    aiSetStatus('Board is not ready yet.', 'error');
    return;
  }
  const msgEl = aiEl('ai-chat-message');
  const text = (msgEl?.value || '').trim();
  if (!text) {
    aiSetStatus('Paste or type a client message first.', 'error');
    return;
  }
  if (text.length > AI_MAX_MESSAGE) {
    aiSetStatus(`Message is too long (max ${AI_MAX_MESSAGE} characters).`, 'error');
    return;
  }
  if (!(tb.board.lists || []).length) {
    aiSetStatus('Create a list on the board first.', 'error');
    return;
  }

  aiClearPreview();
  aiSetBusy(true);
  aiSetProgress(0);
  try {
    const result = await aiGenerateTodos(text);
    aiSetProgress(100);
    aiShowPreview(result.todos);
    aiSetStatus(
      `Ready — ${result.todos.length} todo${result.todos.length === 1 ? '' : 's'} via ${result.model}.`,
      'ok'
    );
    await aiLogDebug({
      provider: result.provider,
      model: result.model,
      request: result.request,
      response: result.response,
    });
  } catch (err) {
    console.error(err);
    aiSetStatus(err?.message || 'Could not generate todos.', 'error');
    aiSetProgress(null);
    await aiLogDebug({
      provider: 'unknown',
      model: 'none',
      request: { user: text },
      response: null,
      error: err?.message || String(err),
    });
  } finally {
    aiSetBusy(false);
  }
}

async function onAiAddCards() {
  const tb = aiTb();
  if (!tb || !aiPendingTodos.length) return;
  const listId = aiEl('ai-chat-list')?.value;
  const list = listId ? tb.findList(listId) : null;
  if (!list) {
    aiSetStatus('Choose a valid list.', 'error');
    return;
  }
  if (!tb.currentProjectId || typeof tb.postJSON !== 'function') {
    aiSetStatus('Board is not ready to save.', 'error');
    return;
  }

  aiSetBusy(true);
  const created = [];
  try {
    aiPendingTodos.forEach((t) => {
      const card = {
        id: tb.uid('card'),
        title: t.title,
        description: t.description || '',
        due: t.due || null,
        labels: t.label ? [t.label] : [],
        members: Array.isArray(t.members) ? t.members : [],
        images: [],
        checklist: (t.checklist || []).map((text) => ({
          id: tb.uid('chk'),
          text,
          checked: false,
        })),
        comments: [],
      };
      list.cards.push(card);
      created.push({ card, comment: t.comment || '' });
    });

    tb.render();
    const saveJson = await tb.postJSON(
      `${tb.API}?op=board_save&project=${encodeURIComponent(tb.currentProjectId)}`,
      tb.board
    );
    if (!saveJson.ok) {
      throw new Error(saveJson.error || 'Could not save cards.');
    }

    for (const row of created) {
      if (!row.comment) continue;
      const cmtJson = await tb.postJSON(`${tb.API}?op=ai_comment_add`, {
        card_id: row.card.id,
        comment: row.comment,
      });
      if (cmtJson.ok && cmtJson.comment) {
        row.card.comments = row.card.comments || [];
        row.card.comments.push(cmtJson.comment);
      }
    }

    if (typeof tb.loadBoard === 'function') {
      await tb.loadBoard();
    } else {
      tb.render();
    }

    const n = created.length;
    tb.toast(n === 1 ? '1 card created' : `${n} cards created`);
    closeAiChatModal();
    aiClearPreview();
  } catch (err) {
    console.error(err);
    aiSetStatus(err?.message || 'Could not add cards.', 'error');
    tb.toast(err?.message || 'Could not add cards.');
  } finally {
    aiSetBusy(false);
  }
}

function wireAiChat() {
  const btn = aiEl('btn-ai-chat');
  if (!btn) return;
  btn.addEventListener('click', openAiChatModal);
  aiEl('ai-chat-close')?.addEventListener('click', closeAiChatModal);
  aiEl('ai-chat-cancel')?.addEventListener('click', closeAiChatModal);
  aiEl('ai-chat-generate')?.addEventListener('click', onAiGenerate);
  aiEl('ai-chat-add')?.addEventListener('click', onAiAddCards);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', wireAiChat);
} else {
  wireAiChat();
}

// ---------------------------------------------------------------------------
// WebLLM cache size / clear (profile modal)
// ---------------------------------------------------------------------------

function aiFormatBytes(bytes) {
  const n = Math.max(0, Number(bytes) || 0);
  if (n < 1024) return `${Math.round(n)} B`;
  if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
  if (n < 1024 * 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(n >= 10 * 1024 * 1024 ? 1 : 2)} MB`;
  return `${(n / (1024 * 1024 * 1024)).toFixed(2)} GB`;
}

function aiIsWebllmStorageName(name) {
  return typeof name === 'string' && /webllm|mlc-ai|tvmjs|ndarraycache/i.test(name);
}

function aiRoughByteSize(val) {
  if (val == null) return 0;
  if (typeof val === 'string') return val.length * 2;
  if (typeof val === 'number' || typeof val === 'boolean') return 8;
  if (val instanceof ArrayBuffer) return val.byteLength;
  if (ArrayBuffer.isView(val)) return val.byteLength;
  if (typeof Blob !== 'undefined' && val instanceof Blob) return val.size;
  if (Array.isArray(val)) {
    let sum = 0;
    for (const item of val) sum += aiRoughByteSize(item);
    return sum;
  }
  if (typeof val === 'object') {
    if (val.buffer instanceof ArrayBuffer) return val.byteLength || val.buffer.byteLength || 0;
    if (val.data) return aiRoughByteSize(val.data) + 32;
    try {
      return new Blob([JSON.stringify(val)]).size;
    } catch (_) {
      return 64;
    }
  }
  return 0;
}

async function aiCacheApiBytes() {
  if (typeof caches === 'undefined' || !caches.keys) return 0;
  let total = 0;
  const keys = await caches.keys();
  for (const key of keys) {
    if (!aiIsWebllmStorageName(key)) continue;
    const cache = await caches.open(key);
    const reqs = await cache.keys();
    for (const req of reqs) {
      const res = await cache.match(req);
      if (!res) continue;
      const cl = res.headers.get('content-length');
      if (cl && Number.isFinite(Number(cl))) {
        total += Number(cl);
        continue;
      }
      try {
        total += (await res.clone().arrayBuffer()).byteLength;
      } catch (_) { /* ignore */ }
    }
  }
  return total;
}

async function aiIdbDatabaseBytes(name) {
  return new Promise((resolve) => {
    let total = 0;
    let settled = false;
    const done = (n) => {
      if (settled) return;
      settled = true;
      resolve(n);
    };
    const open = indexedDB.open(name);
    open.onerror = () => done(0);
    open.onblocked = () => done(0);
    open.onsuccess = () => {
      const db = open.result;
      const stores = Array.from(db.objectStoreNames || []);
      if (!stores.length) {
        db.close();
        done(0);
        return;
      }
      let pending = stores.length;
      const finishStore = () => {
        pending -= 1;
        if (pending <= 0) {
          db.close();
          done(total);
        }
      };
      stores.forEach((storeName) => {
        try {
          const tx = db.transaction(storeName, 'readonly');
          const store = tx.objectStore(storeName);
          const req = store.openCursor();
          req.onerror = () => finishStore();
          req.onsuccess = (e) => {
            const cursor = e.target.result;
            if (!cursor) {
              finishStore();
              return;
            }
            total += aiRoughByteSize(cursor.value);
            cursor.continue();
          };
        } catch (_) {
          finishStore();
        }
      });
    };
  });
}

async function aiIndexedDbBytes() {
  if (typeof indexedDB === 'undefined') return 0;
  if (typeof indexedDB.databases !== 'function') return 0;
  let total = 0;
  try {
    const dbs = await indexedDB.databases();
    for (const info of dbs || []) {
      if (!aiIsWebllmStorageName(info?.name)) continue;
      total += await aiIdbDatabaseBytes(info.name);
    }
  } catch (_) { /* Safari / private mode */ }
  return total;
}

async function aiEstimateWebllmBytes() {
  const [cacheBytes, idbBytes] = await Promise.all([
    aiCacheApiBytes(),
    aiIndexedDbBytes(),
  ]);
  return {
    bytes: cacheBytes + idbBytes,
    cacheBytes,
    idbBytes,
    model: WEBLLM_MODEL,
    inMemory: !!webllmEngine,
  };
}

async function aiUnloadWebllmEngine() {
  const engine = webllmEngine;
  webllmEngine = null;
  webllmLoading = null;
  if (!engine) return;
  try {
    if (typeof engine.unload === 'function') await engine.unload();
  } catch (_) { /* ignore */ }
}

async function aiDeleteMatchingCaches() {
  if (typeof caches === 'undefined' || !caches.keys) return;
  const keys = await caches.keys();
  await Promise.all(
    keys.filter(aiIsWebllmStorageName).map((key) => caches.delete(key).catch(() => false))
  );
}

async function aiDeleteMatchingIdbs() {
  if (typeof indexedDB === 'undefined' || typeof indexedDB.databases !== 'function') return;
  let dbs = [];
  try {
    dbs = await indexedDB.databases();
  } catch (_) {
    return;
  }
  for (const info of dbs || []) {
    const name = info?.name;
    if (!aiIsWebllmStorageName(name)) continue;
    await new Promise((resolve) => {
      const req = indexedDB.deleteDatabase(name);
      req.onsuccess = () => resolve();
      req.onerror = () => resolve();
      req.onblocked = () => resolve();
    });
  }
}

async function aiClearWebllmCache() {
  await aiUnloadWebllmEngine();
  let viaApi = false;
  try {
    const webllm = await import(WEBLLM_CDN);
    if (typeof webllm.deleteModelAllInfoInCache === 'function') {
      await webllm.deleteModelAllInfoInCache(WEBLLM_MODEL);
      viaApi = true;
    }
  } catch (err) {
    console.warn('WebLLM deleteModelAllInfoInCache failed:', err);
  }
  await aiDeleteMatchingCaches();
  await aiDeleteMatchingIdbs();
  return { viaApi };
}

window.AiChatCache = {
  model: WEBLLM_MODEL,
  formatBytes: aiFormatBytes,
  estimate: aiEstimateWebllmBytes,
  clear: aiClearWebllmCache,
};
