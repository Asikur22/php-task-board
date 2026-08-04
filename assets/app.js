'use strict';

// ---------------------------------------------------------------------------
// Task Board — frontend logic
// Talks to api.php (SQLite-backed) for all board data, authentication, and image uploads.
// User content is rendered via text nodes by default. Card descriptions are the
// exception: they support basic rich text via a strict HTML allowlist sanitizer.
// ---------------------------------------------------------------------------

const API = 'api.php';

/** Label palette. Keys are stored on cards; values are the colors. */
const LABELS = {
  green:  '#61bd4f',
  yellow: '#f2d600',
  orange: '#ff9f1a',
  red:    '#eb5a46',
  purple: '#c377e0',
  blue:   '#0079bf',
  sky:    '#00c2e0',
  grey:   '#90a4ae',
};

/** Palette cycled when creating a member, so colors rarely repeat. */
const MEMBER_COLORS = [
  '#e85d75', '#f4a261', '#2a9d8f', '#3a86ff',
  '#8338ec', '#fb5607', '#ffbe0b', '#06d6a0',
];

/** All projects (for the selector). */
let projects = [];

/** Currently selected project id. */
let currentProjectId = null;

/** In-memory board state for the current project. */
let board = { title: 'Task Board', members: [], lists: [] };

/** Card currently open in the modal: { listId, cardId, labels:[] } */
let editing = null;

// ----- DOM references -------------------------------------------------------
const boardEl   = document.getElementById('board');
const titleEl   = document.getElementById('board-title');
const projectSelect = document.getElementById('project-select');
const modal     = document.getElementById('modal');
const titleInput = document.getElementById('modal-title');
const descEditable = document.getElementById('modal-desc');
const descView = document.getElementById('desc-view');
const descViewBody = document.getElementById('desc-view-body');
const descViewPlaceholder = document.getElementById('desc-view-placeholder');
const descEditor = document.getElementById('desc-editor');
const commentEditable = document.getElementById('modal-comment-text');
const commentEditor = document.getElementById('comment-editor');
const commentToolbar = document.getElementById('comment-toolbar');
const dueInput   = document.getElementById('modal-due');

// ---------------------------------------------------------------------------
// Tiny XSS-safe DOM helper.
// User content is always passed as children -> text nodes. There is no
// innerHTML path on purpose, so injected markup can never reach the DOM.
// ---------------------------------------------------------------------------
function el(tag, props = {}, ...children) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(props)) {
    if (value == null || value === false) continue;
    if (key === 'class') node.className = value;
    else if (key === 'dataset') Object.assign(node.dataset, value);
    else if (key.startsWith('on') && typeof value === 'function') {
      node.addEventListener(key.slice(2).toLowerCase(), value);
    } else {
      node.setAttribute(key, value);
    }
  }
  for (const child of children.flat()) {
    if (child == null || child === false) continue;
    node.append(child.nodeType ? child : document.createTextNode(String(child)));
  }
  return node;
}

/**
 * Paste as plain text into single-line contenteditable titles.
 * Strips HTML/fonts/colors from Word, browsers, etc.
 */
function pastePlainText(e) {
  e.preventDefault();
  const raw = (e.clipboardData || window.clipboardData)?.getData('text/plain') ?? '';
  const text = String(raw).replace(/\s+/g, ' ');
  if (!text) return;
  if (typeof document.execCommand === 'function') {
    document.execCommand('insertText', false, text);
    return;
  }
  const sel = window.getSelection();
  if (!sel || !sel.rangeCount) return;
  sel.deleteFromDocument();
  const range = sel.getRangeAt(0);
  range.insertNode(document.createTextNode(text));
  range.collapse(false);
  sel.removeAllRanges();
  sel.addRange(range);
}

/** Flatten any leftover markup in a contenteditable title to plain text. */
function flattenEditableText(node, fallback) {
  const v = String(node.textContent || '').replace(/\s+/g, ' ').trim();
  const next = v || fallback;
  node.textContent = next;
  return next;
}

// ---------------------------------------------------------------------------
// Description rich-text helpers (strict allowlist — no raw HTML injection)
// ---------------------------------------------------------------------------
const DESC_ALLOWED = {
  P: [], BR: [], DIV: [], SPAN: [],
  B: [], STRONG: [], I: [], EM: [], U: [], S: [],
  UL: [], OL: [], LI: [],
  H1: [], H2: [], H3: [],
  BLOCKQUOTE: [], CODE: [],
  A: ['href'],
};

function looksLikeHtml(str) {
  return /<[a-z][\s\S]*>/i.test(String(str || ''));
}

/**
 * Decode HTML entities (and unwind double-encoding like &amp;apos;) without innerHTML.
 */
function decodeHtmlEntitiesDeep(str) {
  let s = String(str ?? '');
  const named = {
    amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: '\u00a0',
  };
  const decodeOnce = (input) => input
    .replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (entity, body) => {
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

/** Escape plain text and turn newlines into <br> nodes under `parent`. */
function appendPlainTextAsNodes(parent, text) {
  const parts = String(text || '').split('\n');
  parts.forEach((part, i) => {
    if (part) parent.appendChild(document.createTextNode(part));
    if (i < parts.length - 1) parent.appendChild(document.createElement('br'));
  });
}

/** Walk a DOM tree and rebuild only allowlisted nodes into `targetParent`. */
function appendSanitizedChildren(sourceParent, targetParent) {
  for (const child of [...sourceParent.childNodes]) {
    if (child.nodeType === Node.TEXT_NODE) {
      targetParent.appendChild(document.createTextNode(child.textContent));
      continue;
    }
    if (child.nodeType !== Node.ELEMENT_NODE) continue;

    const tag = child.tagName;
    if (!DESC_ALLOWED[tag]) {
      appendSanitizedChildren(child, targetParent);
      continue;
    }

    if (tag === 'A') {
      const href = (child.getAttribute('href') || '').trim();
      if (!/^(https?:|mailto:)/i.test(href)) {
        appendSanitizedChildren(child, targetParent);
        continue;
      }
      const a = document.createElement('a');
      a.setAttribute('href', href);
      a.setAttribute('target', '_blank');
      a.setAttribute('rel', 'noopener noreferrer');
      appendSanitizedChildren(child, a);
      targetParent.appendChild(a);
      continue;
    }

    const node = document.createElement(tag.toLowerCase());
    appendSanitizedChildren(child, node);
    targetParent.appendChild(node);
  }
}

/**
 * Parse untrusted description markup with DOMParser, then copy only allowlisted
 * nodes into `target`. Never assigns untrusted strings into the live DOM.
 */
function fillSanitizedDesc(target, raw) {
  target.replaceChildren();
  let src = String(raw || '');
  if (!src.trim()) return false;

  // Descriptions are often stored entity-escaped; decode first so we don't
  // turn platform&apos;s into platform&amp;apos;s on the next serialize.
  if (!looksLikeHtml(src) || /&(?:amp|apos|quot|lt|gt|#\d+|#x[0-9a-f]+);/i.test(src)) {
    src = decodeHtmlEntitiesDeep(src);
  }

  if (!looksLikeHtml(src)) {
    appendPlainTextAsNodes(target, src);
    return !!(target.textContent || '').trim() || !!target.querySelector('br');
  }

  const doc = new DOMParser().parseFromString(src, 'text/html');
  appendSanitizedChildren(doc.body, target);
  return !!(target.textContent || '').trim() || !!target.querySelector('br,ul,ol');
}

/** Serialize a description editor/view root back to sanitized HTML text. */
function serializeElementChildren(parent) {
  let out = '';
  for (const child of parent.childNodes) {
    if (child.nodeType === Node.TEXT_NODE) {
      out += String(child.textContent)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
      continue;
    }
    if (child.nodeType !== Node.ELEMENT_NODE) continue;
    const tag = child.tagName.toLowerCase();
    if (tag === 'br') { out += '<br>'; continue; }
    let attrs = '';
    if (tag === 'a') {
      const href = (child.getAttribute('href') || '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;');
      attrs = ` href="${href}" target="_blank" rel="noopener noreferrer"`;
    }
    out += `<${tag}${attrs}>${serializeElementChildren(child)}</${tag}>`;
  }
  return out;
}

function serializeDescHtml(root) {
  const wrap = document.createElement('div');
  appendSanitizedChildren(root, wrap);
  const text = (wrap.textContent || '').replace(/\u00a0/g, ' ').trim();
  if (!text) return '';
  return serializeElementChildren(wrap).trim();
}

/** Normalize stored description through the allowlist (plain text stays readable). */
function sanitizeDescString(raw) {
  const wrap = document.createElement('div');
  fillSanitizedDesc(wrap, raw);
  return serializeDescHtml(wrap);
}

function showDescView() {
  if (!editing) return;
  const has = fillSanitizedDesc(descViewBody, editing.description);
  descViewBody.hidden = !has;
  descViewPlaceholder.hidden = has;
  descView.hidden = false;
  descEditor.hidden = true;
  descView.classList.toggle('is-empty', !has);
}

function enterDescEdit() {
  if (!editing || !descEditor.hidden) return;
  fillSanitizedDesc(descEditable, editing.description);
  if (!descEditable.textContent.trim() && !descEditable.querySelector('br,ul,ol')) {
    descEditable.replaceChildren();
  }
  descView.hidden = true;
  descEditor.hidden = false;
  descEditable.focus();
}

function saveDescEdit() {
  if (!editing || descEditor.hidden) return;
  editing.description = serializeDescHtml(descEditable);
  const card = findCardAnywhere(editing.cardId);
  if (card) {
    card.description = editing.description;
    save(editing.cardId);
  }
  showDescView();
}

function cancelDescEdit() {
  if (!editing || descEditor.hidden) return;
  showDescView();
}

function runRichCommand(editable, cmd, value = null) {
  if (!editable) return;
  editable.focus();
  if (cmd === 'createLink') {
    const raw = window.prompt('Enter link URL', 'https://');
    if (raw == null) return;
    const url = raw.trim();
    if (!url) {
      document.execCommand('unlink', false, null);
      return;
    }
    const href = /^(https?:|mailto:)/i.test(url) ? url : `https://${url}`;
    document.execCommand('createLink', false, href);
    editable.querySelectorAll('a').forEach((a) => {
      a.setAttribute('target', '_blank');
      a.setAttribute('rel', 'noopener noreferrer');
    });
    return;
  }
  if (cmd === 'formatBlock' && value && !/^</.test(value)) {
    value = `<${value}>`;
  }
  document.execCommand(cmd, false, value);
}

function runDescCommand(cmd, value = null) {
  runRichCommand(descEditable, cmd, value);
}

function commentHasContent() {
  return !!(commentEditable.textContent || '').replace(/\u00a0/g, ' ').trim()
    || !!commentEditable.querySelector('ul,ol,img');
}

function expandCommentTools() {
  if (!commentEditor || !commentToolbar) return;
  commentEditor.classList.remove('is-idle');
  commentToolbar.hidden = false;
}

function collapseCommentTools() {
  if (!commentEditor || !commentToolbar) return;
  if (commentHasContent()) return;
  commentEditor.classList.add('is-idle');
  commentToolbar.hidden = true;
}

function resetCommentComposer() {
  commentEditable.replaceChildren();
  collapseCommentTools();
}

// ---------------------------------------------------------------------------
// Session, auth, and a tiny fetch wrapper (adds cookies + CSRF on writes)
// ---------------------------------------------------------------------------
let currentUser = null;     // {id,name,email} | null
let csrfToken = '';
let pendingResetToken = ''; // from a ?reset=TOKEN link

const authView = document.getElementById('auth-view');
const appView  = document.getElementById('app-view');

/** GET JSON, sending the session cookie. */
function getJSON(url) {
  return fetch(url, { credentials: 'same-origin', cache: 'no-store' }).then((r) => r.json());
}

/** POST JSON, sending the cookie and the CSRF header when we have a token. */
function postJSON(url, data) {
  const headers = { 'Content-Type': 'application/json' };
  if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
  return fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers,
    body: JSON.stringify(data ?? {}),
  }).then((r) => r.json());
}

function showAuthError(msg) {
  const node = document.getElementById('auth-error');
  node.textContent = msg || '';
  node.hidden = !msg;
}

/** Show one auth pane, hiding the others. */
function showAuthPane(pane) {
  appView.hidden = true;
  authView.hidden = false;
  ['auth-main', 'auth-forgot', 'auth-reset', 'auth-verify-pending'].forEach((id) => {
    const node = document.getElementById(id);
    if (node) node.hidden = true;
  });
  if (pane) {
    const target = document.getElementById(pane);
    if (target) target.hidden = false;
  }
  showAuthError('');
}

function showAuthReset() { showAuthPane('auth-reset'); }

let pendingVerifyEmail = '';

function showVerifyPending(email) {
  pendingVerifyEmail = email || '';
  const label = document.getElementById('verify-pending-email');
  if (label) label.textContent = pendingVerifyEmail || 'your inbox';
  const res = document.getElementById('verify-pending-result');
  if (res) { res.hidden = true; res.replaceChildren(); }
  showAuthPane('auth-verify-pending');
}

function applySession(user, csrf) {
  currentUser = user || null;
  if (csrf) csrfToken = csrf;
}

function showApp() {
  authView.hidden = true;
  appView.hidden = false;
  renderUserChip();
}

/** Header avatar + name for the signed-in user. */
function renderUserChip() {
  const chip = document.getElementById('user-chip');
  const bell = document.getElementById('btn-notifications');
  const name = document.getElementById('user-chip-name');
  const av = document.getElementById('user-chip-avatar');
  const initialsEl = document.getElementById('user-chip-initials');
  const img = document.getElementById('user-chip-img');
  if (!currentUser) {
    chip.hidden = true;
    if (bell) bell.hidden = true;
    return;
  }
  name.textContent = currentUser.name;
  if (currentUser.photo_url) {
    img.src = currentUser.photo_url;
    img.hidden = false;
    initialsEl.hidden = true;
    av.style.background = 'transparent';
  } else {
    img.removeAttribute('src');
    img.hidden = true;
    initialsEl.textContent = initials(currentUser.name);
    initialsEl.hidden = false;
    av.style.background = '#2563eb';
  }
  chip.hidden = false;
  if (bell) bell.hidden = false;
}

/** Show/hide the Sign up tab based on server registration policy. */
function applyRegistrationUi(open) {
  const tabs = document.querySelector('.auth-tabs');
  const regTab = document.querySelector('.auth-tab[data-tab="register"]');
  const loginTab = document.querySelector('.auth-tab[data-tab="login"]');
  const regForm = document.getElementById('register-form');
  const loginForm = document.getElementById('login-form');
  if (!regTab || !loginTab || !regForm || !loginForm) return;

  if (open) {
    if (tabs) tabs.hidden = false;
    regTab.hidden = false;
    return;
  }

  // Closed: force login tab, hide signup.
  if (tabs) tabs.hidden = true;
  regTab.hidden = true;
  regForm.hidden = true;
  loginForm.hidden = false;
  loginTab.classList.add('active');
  regTab.classList.remove('active');
}

// ---- Auth form handlers ----------------------------------------------------
function wireAuth() {
  // Tab switching (Log in / Sign up)
  document.querySelectorAll('.auth-tab').forEach((tab) => {
    tab.addEventListener('click', () => {
      const which = tab.dataset.tab;
      document.querySelectorAll('.auth-tab').forEach((t) => t.classList.toggle('active', t === tab));
      document.getElementById('login-form').hidden = which !== 'login';
      document.getElementById('register-form').hidden = which !== 'register';
      showAuthError('');
    });
  });

  document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const email = document.getElementById('login-email').value.trim();
    const password = document.getElementById('login-password').value;
    const json = await postJSON(`${API}?op=login`, { email, password });
    if (json.ok) {
      await afterAuth(json);
      return;
    }
    const err = json.error || 'Could not log in.';
    if (/verify your email/i.test(err)) {
      showVerifyPending(email);
      showAuthError(err);
      return;
    }
    showAuthError(err);
  });

  document.getElementById('register-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = document.getElementById('reg-name').value.trim();
    const email = document.getElementById('reg-email').value.trim();
    const password = document.getElementById('reg-password').value;
    const json = await postJSON(`${API}?op=register`, { name, email, password });
    if (json.registration_open === false) applyRegistrationUi(false);
    if (!json.ok) {
      showAuthError(json.error || 'Could not register.');
      return;
    }
    if (json.needs_verification) {
      showVerifyPending(email);
      return;
    }
    await afterAuth(json);
  });

  document.getElementById('go-forgot').addEventListener('click', () => showAuthPane('auth-forgot'));
  document.querySelectorAll('[data-back]').forEach((b) => b.addEventListener('click', () => showAuthPane('auth-main')));

  document.getElementById('resend-verification').addEventListener('click', async () => {
    const email = pendingVerifyEmail || document.getElementById('reg-email')?.value.trim() || '';
    const res = document.getElementById('verify-pending-result');
    const json = await postJSON(`${API}?op=resend_verification`, { email });
    res.hidden = false;
    res.replaceChildren(el('p', { class: 'modal-hint' }, json.message || 'Done.'));
  });

  document.getElementById('forgot-submit').addEventListener('click', async () => {
    const email = document.getElementById('forgot-email').value.trim();
    const res = document.getElementById('forgot-result');
    const json = await postJSON(`${API}?op=request_reset`, { email });
    res.hidden = false;
    res.replaceChildren(el('p', { class: 'modal-hint' }, json.message || 'Done.'));
  });

  document.getElementById('reset-submit').addEventListener('click', async () => {
    if (!pendingResetToken) { showAuthError('This reset link is missing its token.'); return; }
    const password = document.getElementById('reset-password').value;
    const json = await postJSON(`${API}?op=reset_password`, { token: pendingResetToken, password });
    if (json.ok) { pendingResetToken = ''; await afterAuth(json); }
    else { showAuthError(json.error || 'Could not reset password.'); }
  });

  document.getElementById('btn-logout').addEventListener('click', async () => {
    closeProfileModal();
    closeNotifDrawer();
    stopNotificationPolling();
    await postJSON(`${API}?op=logout`, {});
    currentUser = null;
    csrfToken = '';
    notificationsCache = [];
    updateNotifBadge(0);
    const bell = document.getElementById('btn-notifications');
    if (bell) bell.hidden = true;
    showAuthPane('auth-main');
  });
}

/** After a successful login/register/reset/verify: store session, clean URL, boot the board. */
async function afterAuth(json) {
  applySession(json.user, json.csrf);
  // Drop ?reset= / ?verify= from the URL so a refresh doesn't reopen those panes.
  if (location.search.includes('reset=') || location.search.includes('verify=')) {
    history.replaceState(null, '', location.pathname);
  }
  showApp();
  try {
    const cfg = await getJSON(`${API}?op=me`);
    if (cfg && cfg.ok) {
      applyNotificationPollConfig(cfg.notification_poll_seconds);
      applyDebugConfig(cfg.debug);
    }
  } catch (err) { /* keep default */ }
  startNotificationPolling();
  await loadProjects();
  const saved = localStorage.getItem('tb_project');
  currentProjectId = (saved && projects.some((p) => p.id === saved)) ? saved : (projects[0]?.id || null);
  if (!currentProjectId) { toast('Welcome! Create your first project with ＋.'); renderProjectSelect(); return; }
  renderProjectSelect();
  await loadBoard();
  if (json.message) toast(json.message);
}

/** Generate a unique id with a crypto fallback. */
function uid(prefix) {
  const rand = (crypto?.randomUUID?.()
    ?? (Math.random().toString(36).slice(2) + Date.now().toString(36)));
  return `${prefix}_${rand}`;
}

const MAX_IMG_DIM = 1280;
const JPEG_QUALITY = 0.82;

/**
 * Read an image File, downscale it on a canvas, and return a JPEG Blob.
 * The Blob is uploaded to upload.php (see uploadImage) rather than embedded.
 */
function resizeImageToBlob(file) {
  return new Promise((resolve, reject) => {
    if (!file.type.startsWith('image/')) {
      reject(new Error('not an image file'));
      return;
    }
    const reader = new FileReader();
    reader.onerror = () => reject(new Error('could not read file'));
    reader.onload = () => {
      const img = new Image();
      img.onerror = () => reject(new Error('could not decode image'));
      img.onload = () => {
        let { width, height } = img;
        if (width > MAX_IMG_DIM || height > MAX_IMG_DIM) {
          const scale = MAX_IMG_DIM / Math.max(width, height);
          width = Math.round(width * scale);
          height = Math.round(height * scale);
        }
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        canvas.getContext('2d').drawImage(img, 0, 0, width, height);
        canvas.toBlob(
          (blob) => (blob ? resolve(blob) : reject(new Error('could not encode image'))),
          'image/jpeg',
          JPEG_QUALITY
        );
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(file);
  });
}

/**
 * Downscale and center-crop a profile photo File to a square 300x300 JPEG Blob.
 */
function resizeProfilePhotoToBlob(file) {
  return new Promise((resolve, reject) => {
    if (!file.type.startsWith('image/')) {
      reject(new Error('not an image file'));
      return;
    }
    const reader = new FileReader();
    reader.onerror = () => reject(new Error('could not read file'));
    reader.onload = () => {
      const img = new Image();
      img.onerror = () => reject(new Error('could not decode image'));
      img.onload = () => {
        const size = 300;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');
        const minDim = Math.min(img.width, img.height);
        const sx = (img.width - minDim) / 2;
        const sy = (img.height - minDim) / 2;
        ctx.drawImage(img, sx, sy, minDim, minDim, 0, 0, size, size);
        canvas.toBlob(
          (blob) => (blob ? resolve(blob) : reject(new Error('could not encode image'))),
          'image/jpeg',
          0.88
        );
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(file);
  });
}

/** Upload an image Blob to api.php?op=upload; resolve with its public url. */
async function uploadImage(blob, name) {
  const form = new FormData();
  form.append('file', blob, name || 'image.jpg');
  const headers = {};
  if (csrfToken) headers['X-CSRF-Token'] = csrfToken;
  const res = await fetch(`${API}?op=upload`, { method: 'POST', credentials: 'same-origin', headers, body: form });
  const json = await res.json();
  if (!json.ok) throw new Error(json.error || 'upload failed');
  return json.url;
}

/** Resolve an image object to a usable src: file url, or legacy base64 data. */
function imgSrc(img) {
  return (typeof img.url === 'string' && img.url)
    || (typeof img.data === 'string' && img.data)
    || '';
}

// ---------------------------------------------------------------------------
// Projects + persistence
// ---------------------------------------------------------------------------
async function loadProjects() {
  try {
    const json = await getJSON(`${API}?op=projects`);
    if (json.ok) projects = json.projects || [];
  } catch (err) {
    toast('Could not reach api.php — serve via PHP (php -S localhost:8000).');
  }
}

function renderProjectSelect() {
  projectSelect.replaceChildren();
  projects.forEach((p) => projectSelect.appendChild(el('option', { value: p.id }, p.name)));
  if (currentProjectId) projectSelect.value = currentProjectId;
}

async function loadBoard() {
  if (!currentProjectId) return;
  try {
    const json = await getJSON(`${API}?op=board&project=${encodeURIComponent(currentProjectId)}`);
    if (json.ok && json.data) {
      board = normalize(json.data);
      board.currentMemberId = json.data.current_member_id || null;
      board.currentRole = json.data.current_role || null;
      boardUpdatedAt = json.data.updated_at || null;
    }
  } catch (err) {
    toast('Could not load this project.');
  }
  render();
}

async function switchProject(id) {
  if (id === currentProjectId) {
    // Keep the dropdown in sync even when already on this project.
    if (projectSelect && projectSelect.value !== id) projectSelect.value = id;
    return;
  }
  if (!modal.hidden) closeModal();
  if (!membersModal.hidden) closeMembers();
  currentProjectId = id;
  localStorage.setItem('tb_project', id);
  renderProjectSelect();
  await loadBoard();
}

async function createProject(name) {
  try {
    const json = await postJSON(`${API}?op=project_create`, { name });
    if (!json.ok) { toast(json.error || 'Could not create project.'); return; }
    await loadProjects();
    await switchProject(json.id);
  } catch (err) {
    toast('Could not create project.');
  }
}

async function deleteProject() {
  if (!currentProjectId) return;
  if (projects.length <= 1) { toast('You need at least one project.'); return; }
  const cur = projects.find((p) => p.id === currentProjectId);
  if (!confirm(`Delete project "${cur?.name || ''}" and all its lists, cards, and images?`)) return;
  try {
    const res = await postJSON(`${API}?op=project_delete`, { id: currentProjectId });
    const json = res;
    if (!json.ok) { toast(json.error || 'Could not delete project.'); return; }
    await loadProjects();
    currentProjectId = projects[0]?.id || null;
    if (currentProjectId) localStorage.setItem('tb_project', currentProjectId);
    renderProjectSelect();
    await loadBoard();
  } catch (err) {
    toast('Could not delete project.');
  }
}

// Debounce writes so bursts of edits (e.g. typing) don't hammer the file.
let saveTimer = null;
/** True while a local save is queued or in flight (skip remote board sync). */
let localSavePending = false;
/** Server stamp for the open project's board (live sync). */
let boardUpdatedAt = null;
let boardSyncInFlight = false;
/** Card ids with unsaved local edits — remote sync must not overwrite these. */
const dirtyCardIds = new Set();
/** Remote updates waiting because the card is locked locally. */
const pendingRemoteCards = new Map();

function markCardDirty(cardId) {
  if (cardId) dirtyCardIds.add(cardId);
}

function isCardSyncLocked(cardId) {
  if (!cardId) return false;
  if (dirtyCardIds.has(cardId)) return true;
  if (modal && !modal.hidden && editing && editing.cardId === cardId) return true;
  return false;
}

function save(cardId) {
  if (cardId) markCardDirty(cardId);
  localSavePending = true;
  clearTimeout(saveTimer);
  saveTimer = setTimeout(doSave, 200);
}

async function doSave() {
  if (!currentProjectId) return;
  saveTimer = null;
  // Dirty cards claim a fresh stamp so server merge keeps our edits over remote.
  const stamp = new Date().toISOString().slice(0, 19).replace('T', ' ');
  if (editing && editing.cardId) dirtyCardIds.add(editing.cardId);
  dirtyCardIds.forEach((id) => {
    const card = findCardAnywhere(id);
    if (card) card.updated_at = stamp;
  });
  try {
    const json = await postJSON(`${API}?op=board_save&project=${encodeURIComponent(currentProjectId)}`, board);
    if (!json.ok) {
      toast('Save failed: ' + (json.error || 'unknown error'));
      return;
    }
    if (json.updated_at) boardUpdatedAt = json.updated_at;
    dirtyCardIds.clear();
    // Server kept newer remote versions for some cards — apply those locally.
    if (Array.isArray(json.protected_cards) && json.protected_cards.length) {
      let applied = 0;
      json.protected_cards.forEach((remote) => {
        if (!remote || !remote.id) return;
        if (isCardSyncLocked(remote.id)) {
          pendingRemoteCards.set(remote.id, remote);
          return;
        }
        upsertSyncedCard(remote);
        applied += 1;
      });
      if (applied) render();
      if (json.protected_cards.length) {
        toast('Kept newer remote edits on ' + json.protected_cards.length + ' card(s)');
      }
    }
    // Flush any pending remotes that are no longer locked.
    flushPendingRemoteCards();
  } catch (err) {
    toast('Save failed — is the server running?');
  } finally {
    localSavePending = false;
  }
}

// ---------------------------------------------------------------------------
// Defensive normalization (hand-edited or imported JSON may be malformed)
// ---------------------------------------------------------------------------
function normalize(data) {
  const out = { title: String(data?.title ?? 'Task Board'), members: [], lists: [] };

  out.members = Array.isArray(data?.members)
    ? data.members
        .filter((m) => m && m.name)
        .map((m, i) => ({
          id: m.id || uid('mem'),
          name: String(m.name),
          color: m.color || MEMBER_COLORS[i % MEMBER_COLORS.length],
          role: m.role || 'member',
          email: m.email || '',
          user_id: m.user_id || null,
          photo_url: m.photo_url || null,
          is_you: !!m.is_you,
        }))
    : [];

  // Only keep assignee ids that still point at a real member.
  const memberIds = new Set(out.members.map((m) => m.id));

  if (Array.isArray(data?.lists)) {
    out.lists = data.lists.map((l) => ({
      id: l.id || uid('list'),
      title: String(l.title ?? 'List'),
      color: (typeof l.color === 'string' && /^#[0-9A-Fa-f]{6}$/.test(l.color)) ? l.color : null,
      cards: Array.isArray(l.cards) ? l.cards.map((c) => ({
        id: c.id || uid('card'),
        title: String(c.title ?? ''),
        description: sanitizeDescString(c.description ?? ''),
        due: c.due ? String(c.due) : null,
        updated_at: c.updated_at ? String(c.updated_at) : null,
        labels: Array.isArray(c.labels) ? c.labels.filter((x) => LABELS[x]) : [],
        members: Array.isArray(c.members) ? c.members.filter((x) => memberIds.has(x)) : [],
        images: Array.isArray(c.images) ? c.images
          .filter((i) => i && (typeof i.url === 'string' || typeof i.data === 'string'))
          .map((i) => {
            const o = { id: i.id || uid('img'), name: String(i.name || '') };
            if (typeof i.url === 'string') o.url = i.url;
            if (typeof i.data === 'string') o.data = i.data;   // legacy base64
            return o;
          })
          : [],
        checklist: Array.isArray(c.checklist) ? c.checklist.map((item) => ({
          id: item.id || uid('chk'),
          text: String(item.text ?? ''),
          checked: !!item.checked,
        })) : [],
        comments: Array.isArray(c.comments) ? c.comments.map((cmt) => ({
          id: cmt.id || uid('cmt'),
          user_id: String(cmt.user_id || ''),
          author_name: String(cmt.author_name || 'User'),
          photo_url: cmt.photo_url || null,
          comment: sanitizeDescString(cmt.comment || ''),
          created_at: cmt.created_at || new Date().toISOString(),
        })) : [],
      })) : [],
    }));
  }
  return out;
}

function findList(id) {
  return board.lists.find((l) => l.id === id) || null;
}

/** Find a card anywhere in the board (needed when cards move between lists). */
function findCardAnywhere(cardId) {
  for (const l of board.lists) {
    const c = l.cards.find((x) => x.id === cardId);
    if (c) return c;
  }
  return null;
}

// ----- Member helpers -------------------------------------------------------
/** Initials from a name: first letter of first name + first letter of last name (or 1 letter if single name). */
function initials(name) {
  const parts = String(name).trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  if (parts.length === 1) return parts[0][0].toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function memberById(id) {
  return (board.members || []).find((m) => m.id === id) || null;
}

/** Circular avatar (supports photo or colored initials fallback). */
function renderAvatar(member) {
  if (member.photo_url) {
    return el('span', {
      class: 'avatar',
      title: member.name,
      'aria-label': member.name,
    }, el('img', { src: member.photo_url, alt: member.name }));
  }
  return el('span', {
    class: 'avatar',
    style: `background:${member.color}`,
    title: member.name,
    'aria-label': member.name,
  }, initials(member.name));
}

/** Small role/identity chips shown next to a member's name. */
function memberBadges(member) {
  const badges = [];
  if (member.role === 'owner') badges.push(el('span', { class: 'member-badge badge-owner' }, 'Owner'));
  if (member.is_you) badges.push(el('span', { class: 'member-badge badge-you' }, 'You'));
  return badges;
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------
function render() {
  titleEl.textContent = board.title;
  boardEl.replaceChildren();

  board.lists.forEach((list) => boardEl.appendChild(renderList(list)));

  // Non-dragable "add list" tile at the end.
  boardEl.appendChild(
    el('button', { class: 'add-list-tile', onclick: addList }, '+ Add a list')
  );

  initSortable();
}

function renderList(list) {
  const header = el('div', { class: 'list-header' },
    el('span', { class: 'list-handle', title: 'Drag to reorder list', 'aria-hidden': 'true' }, '⋮⋮'),
    el('span', {
      class: 'list-title',
      contenteditable: 'true',
      spellcheck: 'false',
      onpaste: pastePlainText,
      onkeydown: (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          e.target.blur();
        }
      },
      onblur: (e) => {
        list.title = flattenEditableText(e.target, 'List');
        save();
      },
    }, list.title),
    el('button', {
      class: 'list-delete',
      title: 'Delete list',
      'aria-label': 'Delete list',
      onclick: () => deleteList(list.id),
    }, '×')
  );

  const props = {
    class: 'list' + (list.color ? ' has-color' : ''),
    dataset: { listId: list.id },
  };
  if (list.color) props.style = `--list-accent:${list.color}`;

  return el('section', props,
    header,
    el('div', { class: 'card-list' }, ...list.cards.map((c) => renderCard(c))),
    el('button', { class: 'add-card-btn', onclick: () => addCard(list.id) }, '+ Add a card')
  );
}

function renderCard(card) {
  const cover = card.images?.length
    ? el('div', { class: 'card-cover-wrap' },
        el('img', { class: 'card-cover', src: imgSrc(card.images[0]), alt: card.images[0].name || '', loading: 'lazy' }),
        card.images.length > 1 ? el('span', { class: 'img-count' }, '🖼 ' + card.images.length) : null
      )
    : null;

  const labelRow = card.labels.length
    ? el('div', { class: 'card-labels' },
        ...card.labels.map((key) =>
          el('span', { class: 'label-chip', style: `background:${LABELS[key]}`, title: key })
        )
      )
    : null;

  const assignees = (card.members || []).map(memberById).filter(Boolean);
  const dueBadge = card.due
    ? el('span', { class: 'due-badge ' + dueClass(card.due) }, fmtDate(card.due))
    : null;

  const chkTotal = card.checklist?.length || 0;
  const chkDone = card.checklist ? card.checklist.filter((i) => i.checked).length : 0;
  const chkBadge = chkTotal > 0
    ? el('span', {
        class: 'checklist-badge ' + (chkDone === chkTotal ? 'checklist-done' : ''),
        title: `${chkDone} of ${chkTotal} checklist items completed`,
      }, `☑ ${chkDone}/${chkTotal}`)
    : null;

  const cmtCount = card.comments?.length || 0;
  const cmtBadge = cmtCount > 0
    ? el('span', {
        class: 'comment-badge',
        title: `${cmtCount} comment${cmtCount > 1 ? 's' : ''}`,
      }, `💬 ${cmtCount}`)
    : null;

  const footer = (assignees.length || dueBadge || chkBadge || cmtBadge)
    ? el('div', { class: 'card-footer' },
        assignees.length
          ? el('div', { class: 'card-assignees' }, ...assignees.map((m) => renderAvatar(m)))
          : null,
        el('div', { class: 'card-badges' }, chkBadge, dueBadge, cmtBadge)
      )
    : null;

  return el('article', {
    class: 'card',
    dataset: { cardId: card.id },
    onclick: () => openModal(card.id),
  },
    cover,
    labelRow,
    el('p', { class: 'card-title' }, card.title || '(untitled)'),
    footer
  );
}

// ---------------------------------------------------------------------------
// Drag-and-drop (SortableJS)
// ---------------------------------------------------------------------------
let sortables = [];

function initSortable() {
  sortables.forEach((s) => s.destroy());
  sortables = [];

  // Cards inside each list share group "cards" -> move between lists.
  document.querySelectorAll('.card-list').forEach((container) => {
    sortables.push(new Sortable(container, {
      group: 'cards',
      animation: 150,
      ghostClass: 'sortable-ghost',
      onEnd: syncFromDom,
    }));
  });

  // Whole-list reordering via the grip handle.
  sortables.push(new Sortable(boardEl, {
    animation: 150,
    draggable: '.list',
    handle: '.list-handle',
    ghostClass: 'sortable-ghost',
    onEnd: syncFromDom,
  }));
}

/**
 * After a drag, rebuild the board data to match the DOM order.
 * Cards are looked up GLOBALLY so a card moved into another list is not lost.
 */
function syncFromDom() {
  const newLists = [];
  document.querySelectorAll('#board > .list').forEach((listEl) => {
    const list = findList(listEl.dataset.listId);
    if (!list) return;
    const cards = [];
    listEl.querySelectorAll('.card-list > .card').forEach((cardEl) => {
      const card = findCardAnywhere(cardEl.dataset.cardId);
      if (card) {
        markCardDirty(card.id);
        cards.push(card);
      }
    });
    newLists.push({ ...list, cards });
  });
  board.lists = newLists;
  save();
}

// ---------------------------------------------------------------------------
// Click-vs-drag guard: a tiny pointermove threshold so dragging a card does
// not also open its editor modal on mouse-up.
// ---------------------------------------------------------------------------
const clickGuard = { moved: false };

document.addEventListener('pointerdown', (e) => {
  if (!e.target.closest('.card')) return;
  let sx = e.clientX, sy = e.clientY;
  clickGuard.moved = false;
  const move = (ev) => {
    if (Math.hypot(ev.clientX - sx, ev.clientY - sy) > 5) clickGuard.moved = true;
  };
  const up = () => {
    window.removeEventListener('pointermove', move);
    window.removeEventListener('pointerup', up);
  };
  window.addEventListener('pointermove', move);
  window.addEventListener('pointerup', up);
});

// ---------------------------------------------------------------------------
// CRUD
// ---------------------------------------------------------------------------
function addList() {
  const list = { id: uid('list'), title: 'New list', color: null, cards: [] };
  board.lists.push(list);
  render();
  save();
  const node = boardEl.querySelector(`[data-list-id="${list.id}"] .list-title`);
  if (node) {
    node.focus();
    const range = document.createRange();
    range.selectNodeContents(node);
    getSelection().removeAllRanges();
    getSelection().addRange(range);
  }
}

function deleteList(id) {
  const list = findList(id);
  if (!list) return;
  const n = list.cards.length;
  if (!confirm(`Delete "${list.title}"${n ? ` and its ${n} card${n > 1 ? 's' : ''}` : ''}?`)) return;
  board.lists = board.lists.filter((l) => l.id !== id);
  render();
  save();
}

function addCard(listId) {
  const list = findList(listId);
  if (!list) return;
  const card = {
    id: uid('card'),
    title: 'New card',
    description: '',
    due: null,
    updated_at: null,
    labels: [],
    members: [],
    images: [],
    checklist: [],
    comments: [],
  };
  list.cards.push(card);
  render();
  save(card.id);
  openModal(card.id);
}

// ---------------------------------------------------------------------------
// Card editor modal
// ---------------------------------------------------------------------------
function openModal(cardId) {
  if (clickGuard.moved) return;
  const card = findCardAnywhere(cardId);
  if (!card) return;

  // Locate the owning list id for delete.
  const owner = board.lists.find((l) => l.cards.includes(card));
  editing = {
    listId: owner?.id,
    cardId,
    labels: card.labels.length ? [card.labels[0]] : [],
    members: [...(card.members || [])],
    images: (card.images || []).map((i) => ({ ...i })),
    checklist: (card.checklist || []).map((item) => ({ ...item })),
    comments: (card.comments || []).map((cmt) => ({ ...cmt })),
    description: card.description || '',
  };

  titleInput.textContent = card.title || '';
  showDescView();
  resetCommentComposer();
  const clearBtn = document.getElementById('btn-clear-due');
  if (fpDue) {
    fpDue.setDate(card.due || '', false);
    if (clearBtn) clearBtn.hidden = !card.due;
  } else {
    dueInput.value = card.due || '';
    if (clearBtn) clearBtn.hidden = !card.due;
  }
  renderModalLabels();
  renderModalAssignees();
  renderModalChecklist();
  renderModalComments();
  renderModalImages();
  modal.hidden = false;
}

function closeModal() {
  modal.hidden = true;
  editing = null;
  descEditor.hidden = true;
  descView.hidden = false;
  flushPendingRemoteCards();
}

function saveCardFromModal() {
  if (!editing) return;
  // Commit any open description draft before saving the card.
  if (!descEditor.hidden) saveDescEdit();
  const card = findCardAnywhere(editing.cardId);
  if (!card) return closeModal();
  const cardId = editing.cardId;
  card.title = flattenEditableText(titleInput, '(untitled)');
  card.description = editing.description || '';
  card.due = dueInput.value || null;
  card.labels = [...editing.labels];
  card.members = [...editing.members];
  card.images = editing.images;
  card.checklist = editing.checklist;
  card.comments = editing.comments;
  closeModal();
  render();
  save(cardId);
}

function deleteCardFromModal() {
  if (!editing) return;
  const list = findList(editing.listId);
  if (list) list.cards = list.cards.filter((c) => c.id !== editing.cardId);
  closeModal();
  render();
  save();
}

function renderModalLabels() {
  const wrap = document.getElementById('modal-labels');
  wrap.replaceChildren();
  for (const [key, color] of Object.entries(LABELS)) {
    const on = editing.labels.includes(key);
    wrap.appendChild(el('button', {
      type: 'button',
      class: 'label-toggle' + (on ? ' active' : ''),
      style: `background:${color}`,
      title: key,
      'aria-pressed': on ? 'true' : 'false',
      'aria-label': `Label ${key}`,
      onclick: () => {
        // Single-select: only one label color at a time (click again to clear).
        editing.labels = on ? [] : [key];
        renderModalLabels();
      },
    }, on ? '✓' : ''));
  }
}

function renderModalImages() {
  const wrap = document.getElementById('modal-images');
  wrap.replaceChildren();
  if (!editing) return;
  editing.images.forEach((img) => {
    const src = imgSrc(img);
    wrap.appendChild(el('div', { class: 'image-thumb' },
      src
        ? el('img', {
            src,
            alt: img.name || '',
            onclick: () => openLightbox(src, img.name),
          })
        : el('div', { class: 'image-uploading' }, 'Uploading…'),
      el('button', {
        type: 'button',
        class: 'image-remove',
        'aria-label': 'Remove image',
        title: 'Remove image',
        onclick: (e) => {
          e.stopPropagation();
          editing.images = editing.images.filter((x) => x.id !== img.id);
          renderModalImages();
        },
      }, '×')
    ));
  });
}

function renderModalAssignees() {
  const wrap = document.getElementById('modal-assignees');
  wrap.replaceChildren();
  if (!editing) return;
  if (!board.members.length) {
    wrap.appendChild(el('p', { class: 'empty-hint' }, 'No members yet — invite some via the Members button in the header.'));
    return;
  }
  board.members.forEach((m) => {
    const on = editing.members.includes(m.id);
    wrap.appendChild(el('button', {
      type: 'button',
      class: 'assignee-toggle' + (on ? ' active' : ''),
      title: m.email ? `${m.name} (${m.email})` : m.name,
      'aria-pressed': on ? 'true' : 'false',
      onclick: () => {
        const i = editing.members.indexOf(m.id);
        if (i >= 0) editing.members.splice(i, 1);
        else editing.members.push(m.id);
        renderModalAssignees();
      },
    }, renderAvatar(m),
      el('span', { class: 'assignee-name' }, m.name),
      ...memberBadges(m)));
  });
}

function renderModalChecklist() {
  const wrap = document.getElementById('modal-checklist-items');
  const progressText = document.getElementById('modal-checklist-progress-text');
  const progressFill = document.getElementById('modal-checklist-progress-fill');
  if (!wrap || !editing) return;
  wrap.replaceChildren();

  const items = editing.checklist || [];
  const total = items.length;
  const done = items.filter((i) => i.checked).length;
  const pct = total === 0 ? 0 : Math.round((done / total) * 100);

  if (progressText) progressText.textContent = `${pct}% (${done}/${total})`;
  if (progressFill) {
    progressFill.style.width = `${pct}%`;
    progressFill.classList.toggle('all-done', total > 0 && done === total);
  }

  items.forEach((item, idx) => {
    wrap.appendChild(el('div', { class: 'checklist-item-row' + (item.checked ? ' is-checked' : '') },
      el('input', {
        type: 'checkbox',
        class: 'checklist-checkbox',
        checked: item.checked,
        onchange: (e) => {
          item.checked = e.target.checked;
          renderModalChecklist();
          // Persist promptly so checklist completions create notifications.
          const card = findCardAnywhere(editing.cardId);
          if (card) card.checklist = editing.checklist;
          save();
        },
      }),
      el('span', {
        class: 'checklist-item-text',
        contenteditable: 'true',
        spellcheck: 'false',
        onblur: (e) => {
          const v = e.target.textContent.trim();
          item.text = v || 'Checklist item';
          e.target.textContent = item.text;
        },
        onkeydown: (e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            e.target.blur();
          }
        },
      }, item.text),
      el('button', {
        type: 'button',
        class: 'checklist-item-delete',
        'aria-label': 'Delete checklist item',
        title: 'Delete item',
        onclick: () => {
          editing.checklist.splice(idx, 1);
          renderModalChecklist();
        },
      }, '×')
    ));
  });
}

function addChecklistItem() {
  if (!editing) return;
  const input = document.getElementById('modal-checklist-input');
  const text = input.value.trim();
  if (!text) return;
  editing.checklist = editing.checklist || [];
  editing.checklist.push({ id: uid('chk'), text, checked: false });
  input.value = '';
  renderModalChecklist();
  input.focus();
}

// ---------------------------------------------------------------------------
// Card Comments & Activity Manager
// ---------------------------------------------------------------------------
function renderModalComments() {
  const listWrap = document.getElementById('modal-comments-list');
  if (!listWrap || !editing) return;

  listWrap.replaceChildren();
  const comments = editing.comments || [];
  if (!comments.length) {
    listWrap.appendChild(el('p', { class: 'empty-hint' }, 'No comments or activity yet. Be the first to comment!'));
    return;
  }

  comments.forEach((cmt) => {
    const isMine = currentUser && (cmt.user_id === currentUser.id);
    const isOwner = board.currentRole === 'owner';
    const canDelete = isMine || isOwner;
    const textEl = el('div', { class: 'comment-text desc-content' });
    fillSanitizedDesc(textEl, cmt.comment);

    listWrap.appendChild(el('div', { class: 'comment-item' },
      renderAvatar({ name: cmt.author_name, photo_url: cmt.photo_url, color: '#2563eb' }),
      el('div', { class: 'comment-body' },
        el('div', { class: 'comment-header' },
          el('div', { class: 'comment-meta' },
            el('span', { class: 'comment-author' }, cmt.author_name),
            ' ',
            el('span', { class: 'comment-time' }, fmtDateTime(cmt.created_at))
          ),
          canDelete ? el('button', {
            type: 'button',
            class: 'comment-delete',
            'aria-label': 'Delete comment',
            title: 'Delete comment',
            onclick: () => deleteComment(cmt.id),
          }, '×') : null
        ),
        textEl
      )
    ));
  });
}

async function addComment() {
  if (!editing) return;
  const commentText = serializeDescHtml(commentEditable);
  if (!commentText) return;

  try {
    const json = await postJSON(`${API}?op=comment_add`, { card_id: editing.cardId, comment: commentText });
    if (!json.ok) {
      toast('Could not post comment: ' + (json.error || 'unknown error'));
      return;
    }
    // Ensure returned markup is allowlisted before showing.
    if (json.comment) json.comment.comment = sanitizeDescString(json.comment.comment || '');
    if (json.updated_at) boardUpdatedAt = json.updated_at;
    editing.comments = editing.comments || [];
    editing.comments.push(json.comment);
    const card = findCardAnywhere(editing.cardId);
    if (card) {
      card.comments = editing.comments;
    }
    resetCommentComposer();
    renderModalComments();
    render();
  } catch (err) {
    toast('Could not post comment.');
  }
}

async function deleteComment(commentId) {
  if (!editing) return;
  if (!confirm('Delete this comment?')) return;
  try {
    const json = await postJSON(`${API}?op=comment_delete`, { comment_id: commentId });
    if (!json.ok) {
      toast(json.error || 'Could not delete comment.');
      return;
    }
    if (json.updated_at) boardUpdatedAt = json.updated_at;
    editing.comments = (editing.comments || []).filter((c) => c.id !== commentId);
    const card = findCardAnywhere(editing.cardId);
    if (card) {
      card.comments = editing.comments;
    }
    renderModalComments();
    render();
  } catch (err) {
    toast('Could not delete comment.');
  }
}

// ---------------------------------------------------------------------------
// Member roster manager
// ---------------------------------------------------------------------------
const membersModal = document.getElementById('members-modal');

function openMembers() {
  board.members = board.members || [];
  renderMembersList();
  membersModal.hidden = false;
  setTimeout(() => document.getElementById('member-name').focus(), 0);
}

function closeMembers() {
  membersModal.hidden = true;
}

// ----- New project modal (replaces the browser's native prompt()) -----------
const newProjectModal = document.getElementById('new-project-modal');
const newProjectInput = document.getElementById('new-project-name');

function openNewProject() {
  newProjectInput.value = '';
  newProjectModal.hidden = false;
  setTimeout(() => newProjectInput.focus(), 0);
}

function closeNewProject() {
  newProjectModal.hidden = true;
}

/** Form submit: create the project (if named) and dismiss the modal. */
async function submitNewProject(e) {
  e.preventDefault();
  const name = newProjectInput.value.trim();
  if (!name) return;
  closeNewProject();
  await createProject(name);
}

function renderMembersList() {
  const wrap = document.getElementById('members-list');
  wrap.replaceChildren();
  if (!board.members.length) {
    wrap.appendChild(el('p', { class: 'empty-hint' }, 'No members yet. Invite a teammate above.'));
  }
  board.members.forEach((m) => {
    // Only owners may remove others; never remove the owner or yourself here.
    const removable = board.currentRole === 'owner' && m.role !== 'owner' && !m.is_you;
    wrap.appendChild(el('div', { class: 'member-row' },
      renderAvatar(m),
      el('div', { class: 'member-info' },
        el('span', {
          class: 'member-name',
          contenteditable: 'true',
          spellcheck: 'false',
          onblur: (e) => {
            const v = e.target.textContent.trim();
            if (v) { m.name = v; save(); render(); }
            else { e.target.textContent = m.name; }
          },
        }, m.name),
        el('div', { class: 'member-sub' },
          ...memberBadges(m),
          m.email ? el('span', { class: 'member-email' }, m.email) : null,
        ),
      ),
      removable ? el('button', {
        type: 'button',
        class: 'member-remove',
        'aria-label': 'Remove member',
        title: 'Remove member',
        onclick: () => removeMember(m.id),
      }, '×') : null
    ));
  });

  // A non-owner can leave the project entirely.
  if (board.currentRole && board.currentRole !== 'owner') {
    wrap.appendChild(el('button', {
      class: 'btn btn-soft leave-btn',
      type: 'button',
      onclick: leaveProject,
    }, 'Leave this project'));
  }
}

/** Invite a registered user to the current project by email. */
async function inviteMember() {
  if (!currentProjectId) return;
  const input = document.getElementById('invite-email');
  const email = input.value.trim();
  if (!email) return;
  const json = await postJSON(`${API}?op=invite`, { project: currentProjectId, email });
  if (!json.ok) { toast(json.error || 'Invite failed.'); return; }
  if (!board.members.some((m) => m.id === json.member.id)) {
    board.members.push(json.member);   // server already persisted it
  }
  input.value = '';
  renderMembersList();
  renderModalAssignees();
  toast(`Invited ${json.member.name}.`);
}

/** Remove the current user's own access to the current project. */
async function leaveProject() {
  if (!currentProjectId) return;
  if (!confirm('Leave this project? You will no longer see it.')) return;
  const json = await postJSON(`${API}?op=leave_project`, { project: currentProjectId });
  if (!json.ok) { toast(json.error || 'Could not leave project.'); return; }
  closeMembers();
  await loadProjects();
  currentProjectId = projects[0]?.id || null;
  if (currentProjectId) {
    localStorage.setItem('tb_project', currentProjectId);
    renderProjectSelect();
    await loadBoard();
  } else {
    board = { title: '', members: [], lists: [] };
    renderProjectSelect();
    render();
  }
  toast('You left the project.');
}

function addMember() {
  const input = document.getElementById('member-name');
  const name = input.value.trim();
  if (!name) return;
  const color = MEMBER_COLORS[board.members.length % MEMBER_COLORS.length];
  board.members.push({ id: uid('mem'), name, color });
  input.value = '';
  renderMembersList();
  renderModalAssignees();
  save();
}

function removeMember(id) {
  if (board.currentRole !== 'owner') {
    toast('Only the project owner can remove members.');
    return;
  }
  const m = memberById(id);
  if (!m) return;
  if (m.role === 'owner' || m.is_you) {
    toast('You cannot remove the project owner.');
    return;
  }
  const count = board.lists.reduce(
    (n, l) => n + l.cards.filter((c) => c.members.includes(id)).length, 0
  );
  const msg = count
    ? `Remove "${m.name}" and unassign them from ${count} card${count > 1 ? 's' : ''}?`
    : `Remove "${m.name}"?`;
  if (!confirm(msg)) return;
  board.members = board.members.filter((x) => x.id !== id);
  board.lists.forEach((l) => l.cards.forEach((c) => {
    c.members = c.members.filter((x) => x !== id);
  }));
  renderMembersList();
  renderModalAssignees();
  render();
  save();
}

// ---------------------------------------------------------------------------
// Board title editing
// ---------------------------------------------------------------------------
titleEl.addEventListener('blur', () => {
  board.title = flattenEditableText(titleEl, 'Untitled');
  // keep the selector label in sync (saving renames the project server-side)
  const p = projects.find((x) => x.id === currentProjectId);
  if (p) { p.name = board.title; renderProjectSelect(); }
  save();
});
titleEl.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); titleEl.blur(); }
});
titleEl.addEventListener('paste', pastePlainText);

// ---------------------------------------------------------------------------
// Export / Import JSON
// ---------------------------------------------------------------------------
function exportJson() {
  const blob = new Blob([JSON.stringify(board, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const stamp = new Date().toISOString().slice(0, 10);
  const a = el('a', { href: url, download: `task-board-${stamp}.json` });
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  toast('Exported board JSON.');
}

async function importJson(e) {
  const file = e.target.files?.[0];
  if (!file) return;
  try {
    const data = JSON.parse(await file.text());
    board = normalize(data);
    render();
    save();
    closeProfileModal();
    toast(`Imported ${board.lists.length} list${board.lists.length === 1 ? '' : 's'}.`);
  } catch {
    toast('Import failed: that file is not valid JSON.');
  }
  e.target.value = '';
}

// ---------------------------------------------------------------------------
// Modal events & keyboard
// ---------------------------------------------------------------------------
document.getElementById('modal-close').onclick = closeModal;
document.getElementById('modal-save').onclick = saveCardFromModal;
document.getElementById('modal-delete').onclick = deleteCardFromModal;

document.getElementById('modal-checklist-add-btn').onclick = addChecklistItem;
document.getElementById('modal-checklist-input').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); addChecklistItem(); }
});

document.getElementById('modal-comment-text').addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    addComment();
  }
});
commentEditable.addEventListener('focus', () => {
  expandCommentTools();
});
commentEditable.addEventListener('blur', () => {
  // Delay so toolbar button clicks still register before collapse.
  setTimeout(() => {
    if (commentToolbar?.contains(document.activeElement)) return;
    if (document.activeElement === commentEditable) return;
    collapseCommentTools();
  }, 150);
});

document.querySelectorAll('.comment-tool[data-cmd]').forEach((btn) => {
  btn.addEventListener('mousedown', (e) => e.preventDefault());
  btn.addEventListener('click', () => {
    expandCommentTools();
    runRichCommand(commentEditable, btn.dataset.cmd);
  });
});

const commentStyle = document.getElementById('comment-style');
commentStyle.addEventListener('mousedown', (e) => e.stopPropagation());
commentStyle.addEventListener('change', () => {
  expandCommentTools();
  runRichCommand(commentEditable, 'formatBlock', commentStyle.value || 'p');
  commentEditable.focus();
});

// Image picker: paste-in-description, the Attach button, and the Upload
// button all funnel through one hidden file input and one attachFiles().
const imageInput = document.getElementById('image-input');

/** Resize, upload, and attach a set of image files to the card being edited. */
async function attachFiles(files) {
  if (!editing) return;
  for (const file of files) {
    // Show a placeholder immediately so the user sees progress.
    const placeholder = { id: uid('img'), url: null, name: file.name || 'image.jpg', uploading: true };
    editing.images.push(placeholder);
    renderModalImages();
    try {
      const blob = await resizeImageToBlob(file);
      placeholder.url = await uploadImage(blob, file.name);
      delete placeholder.uploading;
      toast(`Attached "${file.name || 'image'}".`);
    } catch (err) {
      editing.images = editing.images.filter((x) => x.id !== placeholder.id);
      toast(`Skipped "${file.name || 'image'}": ${err.message}`);
    }
    renderModalImages();
  }
}

document.getElementById('modal-add-image').onclick = () => imageInput.click();

imageInput.onchange = async (e) => {
  const files = [...(e.target.files || [])];
  e.target.value = '';                // reset so the same file can be re-picked
  if (files.length) await attachFiles(files);
};

// Paste an image into the description -> attach it instead of inserting text.
descEditable.addEventListener('paste', (e) => {
  const items = e.clipboardData?.items;
  if (!items) return;
  const files = [];
  for (const it of items) {
    if (it.kind === 'file' && it.type.startsWith('image/')) {
      const f = it.getAsFile();
      if (f) files.push(f);
    }
  }
  if (!files.length) return;          // no image -> let normal text paste run
  e.preventDefault();
  attachFiles(files);
});

// Description view / edit / formatting
descView.addEventListener('click', (e) => {
  if (e.target.closest('a')) return; // allow opening links without entering edit
  enterDescEdit();
});
descView.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' || e.key === ' ') {
    e.preventDefault();
    enterDescEdit();
  }
});
document.getElementById('desc-save').onclick = saveDescEdit;
document.getElementById('desc-cancel').onclick = cancelDescEdit;
document.getElementById('desc-attach-btn').onclick = () => imageInput.click();

document.querySelectorAll('.desc-tool[data-cmd]').forEach((btn) => {
  btn.addEventListener('mousedown', (e) => e.preventDefault()); // keep selection
  btn.addEventListener('click', () => runDescCommand(btn.dataset.cmd));
});

const descStyle = document.getElementById('desc-style');
descStyle.addEventListener('mousedown', (e) => e.stopPropagation());
descStyle.addEventListener('change', () => {
  const tag = descStyle.value || 'p';
  runDescCommand('formatBlock', tag);
  descEditable.focus();
});

modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

// Header buttons
document.getElementById('btn-export').onclick = exportJson;
document.getElementById('btn-import').onclick = () => document.getElementById('import-file').click();
document.getElementById('import-file').onchange = importJson;

// Project switcher
projectSelect.onchange = () => switchProject(projectSelect.value);
document.getElementById('btn-new-project').onclick = openNewProject;
document.getElementById('btn-del-project').onclick = deleteProject;

// Member roster manager
document.getElementById('btn-members').onclick = openMembers;
document.getElementById('members-close').onclick = closeMembers;
document.getElementById('member-add').onclick = addMember;
document.getElementById('member-name').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); addMember(); }
});
document.getElementById('invite-send').onclick = inviteMember;
document.getElementById('invite-email').addEventListener('keydown', (e) => {
  if (e.key === 'Enter') { e.preventDefault(); inviteMember(); }
});
membersModal.addEventListener('click', (e) => { if (e.target === membersModal) closeMembers(); });

// New project modal
document.getElementById('new-project-close').onclick = closeNewProject;
document.getElementById('new-project-cancel').onclick = closeNewProject;
document.getElementById('new-project-form').onsubmit = submitNewProject;
newProjectModal.addEventListener('click', (e) => { if (e.target === newProjectModal) closeNewProject(); });

// ---------------------------------------------------------------------------
// Profile modal manager
// ---------------------------------------------------------------------------
const profileModal = document.getElementById('profile-modal');
const profilePhotoInput = document.getElementById('profile-photo-input');
let profilePhotoUrl = null;

function openProfileModal() {
  if (!currentUser) return;
  profilePhotoUrl = currentUser.photo_url || null;

  document.getElementById('profile-name').value = currentUser.name;
  document.getElementById('profile-email').value = currentUser.email;
  document.getElementById('profile-cur-pass').value = '';
  document.getElementById('profile-new-pass').value = '';

  showProfileMsg('', false);
  renderProfileAvatarPreview();
  refreshAiCacheStatus();
  profileModal.hidden = false;
  setTimeout(() => document.getElementById('profile-name').focus(), 0);
}

async function refreshAiCacheStatus() {
  const status = document.getElementById('profile-ai-cache-status');
  const btn = document.getElementById('btn-clear-ai-cache');
  if (!status || !btn) return;
  status.classList.remove('is-error');
  status.textContent = 'Checking cache size…';
  btn.disabled = true;
  const api = window.AiChatCache;
  if (!api || typeof api.estimate !== 'function') {
    status.textContent = 'AI cache tools unavailable.';
    return;
  }
  try {
    const info = await api.estimate();
    const size = api.formatBytes(info.bytes);
    if (info.bytes <= 0 && !info.inMemory) {
      status.textContent = `No WebLLM model cached yet (${api.model}).`;
      btn.disabled = true;
      return;
    }
    const mem = info.inMemory ? ' · loaded in memory' : '';
    status.textContent = `Using about ${size} for WebLLM (${api.model})${mem}.`;
    btn.disabled = false;
  } catch (err) {
    console.warn(err);
    status.classList.add('is-error');
    status.textContent = 'Could not measure AI cache size.';
    btn.disabled = false;
  }
}

async function clearAiCache() {
  const api = window.AiChatCache;
  const status = document.getElementById('profile-ai-cache-status');
  const btn = document.getElementById('btn-clear-ai-cache');
  if (!api || typeof api.clear !== 'function') return;
  const ok = window.confirm(
    'Clear the downloaded WebLLM AI model from this browser?\n\n' +
    'The next AI Chat generate may need to download it again.'
  );
  if (!ok) return;
  if (status) {
    status.classList.remove('is-error');
    status.textContent = 'Clearing AI cache…';
  }
  if (btn) btn.disabled = true;
  try {
    await api.clear();
    toast('AI model cache cleared');
    await refreshAiCacheStatus();
  } catch (err) {
    console.error(err);
    if (status) {
      status.classList.add('is-error');
      status.textContent = err?.message || 'Could not clear AI cache.';
    }
    if (btn) btn.disabled = false;
    toast(err?.message || 'Could not clear AI cache.');
  }
}

function closeProfileModal() {
  profileModal.hidden = true;
  showProfileMsg('', false);
}

function renderProfileAvatarPreview() {
  const preview = document.getElementById('profile-avatar-preview');
  const fallback = document.getElementById('profile-avatar-fallback');
  const img = document.getElementById('profile-avatar-img');
  const removeBtn = document.getElementById('btn-remove-photo');
  const currentName = document.getElementById('profile-name').value || currentUser?.name || '';

  if (profilePhotoUrl) {
    img.src = profilePhotoUrl;
    img.hidden = false;
    fallback.hidden = true;
    preview.style.background = 'transparent';
    removeBtn.hidden = false;
  } else {
    img.removeAttribute('src');
    img.hidden = true;
    fallback.textContent = initials(currentName);
    fallback.hidden = false;
    preview.style.background = '#2563eb';
    removeBtn.hidden = true;
  }
}

function showProfileMsg(msg, isSuccess = false) {
  const errNode = document.getElementById('profile-error');
  const succNode = document.getElementById('profile-success');
  if (isSuccess) {
    succNode.textContent = msg || '';
    succNode.hidden = !msg;
    errNode.hidden = true;
  } else {
    errNode.textContent = msg || '';
    errNode.hidden = !msg;
    succNode.hidden = true;
  }
}

async function saveProfile(e) {
  e.preventDefault();
  if (!currentUser) return;
  const name = document.getElementById('profile-name').value.trim();
  const curPass = document.getElementById('profile-cur-pass').value;
  const newPass = document.getElementById('profile-new-pass').value;

  if (!name) {
    showProfileMsg('Please enter your name.', false);
    return;
  }
  if (newPass && newPass.length < 8) {
    showProfileMsg('New password must be at least 8 characters.', false);
    return;
  }
  if (newPass && !curPass) {
    showProfileMsg('Please enter your current password to set a new password.', false);
    return;
  }

  const payload = {
    name,
    photo_url: profilePhotoUrl,
    current_password: curPass,
    new_password: newPass,
  };

  try {
    const json = await postJSON(`${API}?op=profile_update`, payload);
    if (!json.ok) {
      showProfileMsg(json.error || 'Could not update profile.', false);
      return;
    }
    currentUser = json.user;
    renderUserChip();
    // Keep in-memory member avatars in sync even before board reload finishes.
    if (board.members?.length && currentUser) {
      board.members.forEach((m) => {
        if (m.is_you || m.user_id === currentUser.id) {
          m.name = currentUser.name;
          m.photo_url = currentUser.photo_url || null;
        }
      });
      render();
      if (!modal.hidden) renderModalAssignees();
    }
    if (currentProjectId) {
      await loadBoard();
      if (!modal.hidden) renderModalAssignees();
    }
    closeProfileModal();
    toast(json.message || 'Profile updated!');
  } catch (err) {
    showProfileMsg('Server error while updating profile.', false);
  }
}

// Wire profile listeners
document.getElementById('user-chip').onclick = openProfileModal;
document.getElementById('profile-close').onclick = closeProfileModal;
document.getElementById('profile-cancel').onclick = closeProfileModal;
document.getElementById('profile-form').onsubmit = saveProfile;
document.getElementById('btn-clear-ai-cache')?.addEventListener('click', clearAiCache);

document.getElementById('btn-upload-photo').onclick = () => profilePhotoInput.click();
document.getElementById('btn-remove-photo').onclick = () => {
  profilePhotoUrl = null;
  renderProfileAvatarPreview();
};

document.getElementById('profile-name').addEventListener('input', () => {
  renderProfileAvatarPreview();
});

profilePhotoInput.onchange = async (e) => {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  try {
    const blob = await resizeProfilePhotoToBlob(file);
    profilePhotoUrl = await uploadImage(blob, 'avatar.jpg');
    renderProfileAvatarPreview();
    toast('Photo selected. Click "Save Profile" to keep changes.');
  } catch (err) {
    showProfileMsg('Failed to process image: ' + err.message, false);
  }
};

profileModal.addEventListener('click', (e) => { if (e.target === profileModal) closeProfileModal(); });

// ---------------------------------------------------------------------------
// Background theme manager
// ---------------------------------------------------------------------------
function applyBgTheme(bg) {
  if (!bg) {
    document.body.style.background = '';
    localStorage.removeItem('tb_bg_theme');
    updateBgSwatchUI('');
    return;
  }
  document.body.style.background = bg;
  localStorage.setItem('tb_bg_theme', bg);
  updateBgSwatchUI(bg);
}

function updateBgSwatchUI(bg) {
  document.querySelectorAll('.bg-swatch').forEach((sw) => {
    sw.classList.toggle('active', sw.dataset.bg === bg);
  });
  const customPicker = document.getElementById('bg-custom-picker');
  if (customPicker && bg && bg.startsWith('#')) {
    customPicker.value = bg;
  }
}

function initBgTheme() {
  const savedBg = localStorage.getItem('tb_bg_theme');
  if (savedBg) applyBgTheme(savedBg);

  document.querySelectorAll('.bg-swatch').forEach((sw) => {
    sw.onclick = () => applyBgTheme(sw.dataset.bg);
  });

  const bgPicker = document.getElementById('bg-custom-picker');
  if (bgPicker) {
    bgPicker.oninput = (e) => applyBgTheme(e.target.value);
  }
}

// ---------------------------------------------------------------------------
// Image Lightbox Popup
// ---------------------------------------------------------------------------
function openLightbox(src, name) {
  if (!src) return;
  const lbModal = document.getElementById('image-lightbox-modal');
  const imgEl = document.getElementById('lightbox-img');
  const titleEl = document.getElementById('lightbox-title');
  const downloadEl = document.getElementById('lightbox-download');
  if (!lbModal || !imgEl) return;

  imgEl.src = src;
  if (titleEl) titleEl.textContent = name || 'Attachment Image';
  if (downloadEl) {
    downloadEl.href = src;
    downloadEl.download = name || 'attachment.jpg';
  }
  lbModal.hidden = false;
}

function closeLightbox() {
  const lbModal = document.getElementById('image-lightbox-modal');
  if (lbModal) lbModal.hidden = true;
}

document.getElementById('lightbox-close').onclick = closeLightbox;
document.getElementById('image-lightbox-modal').onclick = (e) => {
  if (e.target.id === 'image-lightbox-modal') closeLightbox();
};

if (titleInput) {
  titleInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      titleInput.blur();
    }
  });
  titleInput.addEventListener('paste', pastePlainText);
  titleInput.addEventListener('blur', () => {
    flattenEditableText(titleInput, '(untitled)');
  });
}

// Update global keydown handler to close profileModal on Escape
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    const lbModal = document.getElementById('image-lightbox-modal');
    if (lbModal && !lbModal.hidden) closeLightbox();
    else if (!descEditor.hidden) cancelDescEdit();
    else if (!profileModal.hidden) closeProfileModal();
    else if (!membersModal.hidden) closeMembers();
    else if (!newProjectModal.hidden) closeNewProject();
    else if (!modal.hidden) closeModal();
  }
  if (e.key === 'Enter' && (e.metaKey || e.ctrlKey) && !modal.hidden) {
    if (!descEditor.hidden) { e.preventDefault(); saveDescEdit(); return; }
    saveCardFromModal();
  }
});

// ---------------------------------------------------------------------------
// Date helpers + toast
// ---------------------------------------------------------------------------
function fmtDate(s) {
  const d = new Date(s + 'T00:00:00');
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function fmtDateTime(s) {
  if (!s) return '';
  const d = new Date(s.includes('Z') || s.includes('+') ? s : s.replace(' ', 'T') + 'Z');
  if (isNaN(d.getTime())) return s;
  return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function dueClass(s) {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const d = new Date(s + 'T00:00:00');
  if (d < today) return 'due-overdue';
  if (d.getTime() === today.getTime()) return 'due-soon';
  return '';
}

let toastTimer = null;
function toast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.hidden = true; }, 3200);
}

/** Eye toggles on every .password-field (auth + profile). */
function initPasswordToggles() {
  document.querySelectorAll('.password-field').forEach((wrap) => {
    const input = wrap.querySelector('input[type="password"], input[type="text"]');
    const btn = wrap.querySelector('.password-toggle');
    if (!input || !btn || btn.dataset.bound) return;
    btn.dataset.bound = '1';
    const eye = btn.querySelector('.icon-eye');
    const eyeOff = btn.querySelector('.icon-eye-off');
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      if (eye) eye.hidden = show;
      if (eyeOff) eyeOff.hidden = !show;
    });
  });
}

// ---------------------------------------------------------------------------
// Boot: show the auth view or the board depending on session state.
// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------
// Notifications (bell + right drawer)
// ---------------------------------------------------------------------------
let notificationsCache = [];
let notifPollTimer = null;
let notifUnread = 0;
/** Poll interval from server (.env NOTIFICATION_POLL_SECONDS), default 2s. */
let notifPollMs = 2000;
/** Debug mode from server (.env DEBUG). Off in production. */
let debugEnabled = false;
let notifFetchInFlight = false;

function actorAvatarColor(name) {
  const colors = MEMBER_COLORS;
  let h = 0;
  const s = String(name || '');
  for (let i = 0; i < s.length; i++) h = ((h * 31) + s.charCodeAt(i)) >>> 0;
  return colors[h % colors.length];
}

function formatNotifTime(iso) {
  if (!iso) return '';
  // Server stores UTC as "YYYY-MM-DD HH:MM:SS"
  const d = new Date(String(iso).includes('T') ? iso : (iso.replace(' ', 'T') + 'Z'));
  if (Number.isNaN(d.getTime())) return '';
  const diff = Date.now() - d.getTime();
  const sec = Math.round(diff / 1000);
  if (sec < 60) return 'just now';
  const min = Math.round(sec / 60);
  if (min < 60) return min + 'm ago';
  const hr = Math.round(min / 60);
  if (hr < 24) return hr + 'h ago';
  const day = Math.round(hr / 24);
  if (day < 7) return day + 'd ago';
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function updateNotifBadge(count) {
  notifUnread = Math.max(0, Number(count) || 0);
  const badge = document.getElementById('notif-badge');
  const bell = document.getElementById('btn-notifications');
  if (!badge || !bell) return;
  if (notifUnread > 0) {
    badge.hidden = false;
    badge.textContent = notifUnread > 99 ? '99+' : String(notifUnread);
    bell.classList.add('has-unread');
  } else {
    badge.hidden = true;
    badge.textContent = '0';
    bell.classList.remove('has-unread');
  }
}

function renderNotificationsList() {
  const list = document.getElementById('notif-drawer-list');
  const empty = document.getElementById('notif-drawer-empty');
  if (!list || !empty) return;
  list.replaceChildren();

  if (!notificationsCache.length) {
    empty.hidden = false;
    return;
  }
  empty.hidden = true;

  notificationsCache.forEach((n) => {
    const typeClass = n.type === 'checklist' ? 'is-checklist' : '';
    const typeLabel = n.type === 'checklist' ? 'Checklist' : 'Comment';
    const metaText = n.type === 'checklist'
      ? (n.meta?.item_text || '')
      : (n.meta?.excerpt || '');

    const avInner = n.actor_photo
      ? el('img', { src: n.actor_photo, alt: '' })
      : initials(n.actor_name || '?');

    const avatar = el('div', {
      class: 'notif-item-avatar',
      style: n.actor_photo ? '' : `background:${actorAvatarColor(n.actor_name)}`,
    }, avInner);

    list.appendChild(el('button', {
      type: 'button',
      class: 'notif-item' + (n.is_read ? '' : ' is-unread'),
      onclick: () => onNotificationClick(n),
    },
      avatar,
      el('div', { class: 'notif-item-body' },
        el('div', { class: 'notif-item-top' },
          el('span', { class: 'notif-item-type ' + typeClass }, typeLabel),
          el('span', { class: 'notif-item-time' }, formatNotifTime(n.created_at))
        ),
        el('p', { class: 'notif-item-msg' }, n.message || ''),
        metaText ? el('p', { class: 'notif-item-meta' }, metaText) : null,
        n.project_name ? el('div', { class: 'notif-item-project' }, n.project_name) : null
      )
    ));
  });
}

async function loadNotifications({ silent } = {}) {
  if (!currentUser) return;
  if (notifFetchInFlight) return;
  notifFetchInFlight = true;
  try {
    let url = `${API}?op=notifications&limit=50`;
    if (currentProjectId) {
      url += `&project=${encodeURIComponent(currentProjectId)}`;
    }
    const json = await getJSON(url);
    if (!json.ok) {
      if (!silent) toast(json.error || 'Could not load notifications.');
      return;
    }
    notificationsCache = Array.isArray(json.notifications) ? json.notifications : [];
    updateNotifBadge(json.unread ?? notificationsCache.filter((n) => !n.is_read).length);
    const overlay = document.getElementById('notif-drawer-overlay');
    if (overlay && !overlay.hidden) renderNotificationsList();

    if (json.board_updated_at && json.project_id === currentProjectId) {
      await maybeSyncBoard(json.board_updated_at);
    }
  } catch (err) {
    if (!silent) toast('Could not load notifications.');
  } finally {
    notifFetchInFlight = false;
  }
}

/** Pull only changed cards/lists when another member saves (same poll as notifications). */
async function maybeSyncBoard(remoteUpdatedAt) {
  if (!currentProjectId || !remoteUpdatedAt) return;
  if (boardUpdatedAt && String(remoteUpdatedAt) === String(boardUpdatedAt)) return;
  if (localSavePending || saveTimer || boardSyncInFlight) return;

  boardSyncInFlight = true;
  try {
    const since = boardUpdatedAt || '';
    const json = await getJSON(
      `${API}?op=board_sync&project=${encodeURIComponent(currentProjectId)}&since=${encodeURIComponent(since)}`
    );
    if (!json.ok || !json.data) return;
    applyBoardSync(json.data);
  } catch (err) {
    console.warn('Board sync failed:', err);
  } finally {
    boardSyncInFlight = false;
  }
}

function normalizeSyncedCard(raw) {
  const memberIds = new Set((board.members || []).map((m) => m.id));
  return {
    id: raw.id || uid('card'),
    title: String(raw.title ?? ''),
    description: sanitizeDescString(raw.description ?? ''),
    due: raw.due ? String(raw.due) : null,
    updated_at: raw.updated_at ? String(raw.updated_at) : null,
    labels: Array.isArray(raw.labels) ? raw.labels.filter((x) => LABELS[x]) : [],
    members: Array.isArray(raw.members) ? raw.members.filter((x) => memberIds.has(x)) : [],
    images: Array.isArray(raw.images) ? raw.images
      .filter((i) => i && typeof i.url === 'string')
      .map((i) => ({ id: i.id || uid('img'), name: String(i.name || ''), url: i.url }))
      : [],
    checklist: Array.isArray(raw.checklist) ? raw.checklist.map((item) => ({
      id: item.id || uid('chk'),
      text: String(item.text ?? ''),
      checked: !!item.checked,
    })) : [],
    comments: Array.isArray(raw.comments) ? raw.comments.map((cmt) => ({
      id: cmt.id || uid('cmt'),
      user_id: String(cmt.user_id || ''),
      author_name: String(cmt.author_name || 'User'),
      photo_url: cmt.photo_url || null,
      comment: sanitizeDescString(cmt.comment || ''),
      created_at: cmt.created_at || new Date().toISOString(),
    })) : [],
    list_id: raw.list_id || null,
    position: Number.isFinite(Number(raw.position)) ? Number(raw.position) : null,
  };
}

function removeCardFromBoard(cardId) {
  (board.lists || []).forEach((l) => {
    l.cards = (l.cards || []).filter((c) => c.id !== cardId);
  });
}

function upsertSyncedCard(remote) {
  const card = normalizeSyncedCard(remote);
  const listId = card.list_id;
  const list = listId ? findList(listId) : null;
  if (!list) return false;

  removeCardFromBoard(card.id);
  const pos = card.position;
  delete card.list_id;
  delete card.position;
  if (pos == null || pos < 0 || pos >= list.cards.length) list.cards.push(card);
  else list.cards.splice(pos, 0, card);
  pendingRemoteCards.delete(card.id);
  return true;
}

function flushPendingRemoteCards() {
  let changed = false;
  for (const [id, remote] of [...pendingRemoteCards.entries()]) {
    if (isCardSyncLocked(id)) continue;
    if (upsertSyncedCard(remote)) changed = true;
  }
  if (changed) render();
}

function applyBoardSync(data) {
  if (!data) return;
  let changed = false;

  if (typeof data.title === 'string' && data.title && data.title !== board.title) {
    board.title = data.title;
    changed = true;
  }

  if (Array.isArray(data.members)) {
    board.members = data.members.map((m, i) => ({
      id: m.id || uid('mem'),
      name: String(m.name || ''),
      color: m.color || MEMBER_COLORS[i % MEMBER_COLORS.length],
      role: m.role || 'member',
      email: m.email || '',
      user_id: m.user_id || null,
      photo_url: m.photo_url || null,
      is_you: !!m.is_you,
    }));
    board.currentMemberId = data.current_member_id || board.currentMemberId || null;
    board.currentRole = data.current_role || board.currentRole || null;
    changed = true;
  }

  // Sync list shell (id/title/color/order) without wiping locked cards carelessly.
  if (Array.isArray(data.lists) && data.lists.length) {
    const byId = new Map((board.lists || []).map((l) => [l.id, l]));
    const nextLists = [];
    data.lists.forEach((meta) => {
      const existing = byId.get(meta.id);
      if (existing) {
        existing.title = String(meta.title ?? existing.title);
        existing.color = (typeof meta.color === 'string' && /^#[0-9A-Fa-f]{6}$/.test(meta.color))
          ? meta.color
          : existing.color;
        nextLists.push(existing);
        byId.delete(meta.id);
      } else {
        nextLists.push({
          id: meta.id || uid('list'),
          title: String(meta.title ?? 'List'),
          color: (typeof meta.color === 'string' && /^#[0-9A-Fa-f]{6}$/.test(meta.color)) ? meta.color : null,
          cards: [],
        });
      }
    });
    // Keep unknown local-only lists (rare) at the end with their cards.
    byId.forEach((l) => nextLists.push(l));
    board.lists = nextLists;
    changed = true;
  }

  const remoteIds = new Set(Array.isArray(data.card_ids) ? data.card_ids : []);
  (data.cards || []).forEach((remote) => {
    if (!remote || !remote.id) return;
    if (isCardSyncLocked(remote.id)) {
      pendingRemoteCards.set(remote.id, remote);
      return;
    }
    if (upsertSyncedCard(remote)) changed = true;
  });

  // Removals: drop cards deleted remotely unless locked locally.
  if (remoteIds.size) {
    (board.lists || []).forEach((l) => {
      const keep = [];
      (l.cards || []).forEach((c) => {
        if (remoteIds.has(c.id) || isCardSyncLocked(c.id)) keep.push(c);
        else changed = true;
      });
      l.cards = keep;
    });
  }

  if (data.updated_at) boardUpdatedAt = data.updated_at;
  if (changed) {
    render();
    renderProjectSelect();
  }
}

function openNotifDrawer() {
  const overlay = document.getElementById('notif-drawer-overlay');
  if (!overlay) return;
  overlay.hidden = false;
  renderNotificationsList();
  loadNotifications();
}

function closeNotifDrawer() {
  const overlay = document.getElementById('notif-drawer-overlay');
  if (overlay) overlay.hidden = true;
}

async function markAllNotificationsRead() {
  try {
    const json = await postJSON(`${API}?op=notifications_read`, {});
    if (!json.ok) {
      toast(json.error || 'Could not mark as read.');
      return;
    }
    notificationsCache = notificationsCache.map((n) => ({ ...n, is_read: true }));
    updateNotifBadge(json.unread ?? 0);
    renderNotificationsList();
  } catch (err) {
    toast('Could not mark as read.');
  }
}

async function onNotificationClick(n) {
  if (!n.is_read) {
    try {
      const json = await postJSON(`${API}?op=notifications_read`, { id: n.id });
      if (json.ok) {
        n.is_read = true;
        updateNotifBadge(json.unread ?? Math.max(0, notifUnread - 1));
        renderNotificationsList();
      }
    } catch (err) { /* ignore */ }
  }

  closeNotifDrawer();

  if (n.project_id && n.project_id !== currentProjectId) {
    await switchProject(n.project_id);
  }
  if (n.card_id) {
    // Allow DOM to settle after project switch/render.
    setTimeout(() => openModal(n.card_id), 50);
  }
}

function applyNotificationPollConfig(seconds) {
  const sec = Math.max(1, Math.min(300, Number(seconds) || 2));
  notifPollMs = sec * 1000;
}

function applyDebugConfig(enabled) {
  debugEnabled = !!enabled;
}

function startNotificationPolling() {
  stopNotificationPolling();
  loadNotifications({ silent: true });
  notifPollTimer = setInterval(() => {
    // Skip while the tab is hidden to avoid useless load.
    if (document.hidden) return;
    loadNotifications({ silent: true });
  }, notifPollMs);
}

function stopNotificationPolling() {
  if (notifPollTimer) {
    clearInterval(notifPollTimer);
    notifPollTimer = null;
  }
}

function wireNotifications() {
  const bell = document.getElementById('btn-notifications');
  const overlay = document.getElementById('notif-drawer-overlay');
  const closeBtn = document.getElementById('notif-drawer-close');
  const markAll = document.getElementById('notif-mark-all');
  if (bell) bell.addEventListener('click', (e) => {
    e.stopPropagation();
    openNotifDrawer();
  });
  if (closeBtn) closeBtn.addEventListener('click', closeNotifDrawer);
  if (markAll) markAll.addEventListener('click', markAllNotificationsRead);
  if (overlay) {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) closeNotifDrawer();
    });
  }
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      const ov = document.getElementById('notif-drawer-overlay');
      if (ov && !ov.hidden) closeNotifDrawer();
    }
  });
}

// ---------------------------------------------------------------------------
async function init() {
  wireAuth();
  wireNotifications();
  initPasswordToggles();
  initBgTheme();

  const resetToken = new URLSearchParams(location.search).get('reset');
  const verifyToken = new URLSearchParams(location.search).get('verify');
  let me;
  try {
    me = await getJSON(`${API}?op=me`);
    applyRegistrationUi(!!me.registration_open);
    applyNotificationPollConfig(me.notification_poll_seconds);
    applyDebugConfig(me.debug);
  } catch (err) {
    toast('Could not reach api.php — serve via PHP (php -S localhost:8000).');
    showAuthPane('auth-main');
    return;
  }

  // Email verification link: confirm token then sign in.
  if (verifyToken) {
    showAuthPane('auth-main');
    try {
      const json = await postJSON(`${API}?op=verify_email`, { token: verifyToken });
      if (json.ok) {
        await afterAuth(json);
        return;
      }
      showAuthError(json.error || 'Could not verify email.');
    } catch (err) {
      showAuthError('Could not verify email.');
    }
    return;
  }

  // A reset link takes precedence: open the reset pane (it logs in on success).
  if (resetToken) {
    pendingResetToken = resetToken;
    showAuthReset();
    return;
  }

  if (me.ok && me.user) {
    applySession(me.user, me.csrf);
    showApp();
    startNotificationPolling();
    initFlatpickr();
    await loadProjects();
    const saved = localStorage.getItem('tb_project');
    currentProjectId = (saved && projects.some((p) => p.id === saved))
      ? saved
      : (projects[0]?.id || null);
    if (!currentProjectId) {
      renderProjectSelect();
      toast('Welcome! Create your first project with ＋.');
      return;
    }
    renderProjectSelect();
    await loadBoard();
  } else {
    showAuthPane('auth-main');
  }
}

// ---------------------------------------------------------------------------
// Flatpickr date picker helper
// ---------------------------------------------------------------------------
let fpDue = null;

function initFlatpickr() {
  const input = document.getElementById('modal-due');
  const clearBtn = document.getElementById('btn-clear-due');
  if (!input || typeof flatpickr !== 'function') return;

  fpDue = flatpickr(input, {
    dateFormat: 'Y-m-d',
    altInput: true,
    altFormat: 'M j, Y',
    allowInput: false,
    onChange: function(selectedDates, dateStr) {
      if (clearBtn) clearBtn.hidden = !dateStr;
    }
  });

  if (clearBtn) {
    clearBtn.onclick = () => {
      if (fpDue) fpDue.clear();
      else input.value = '';
      clearBtn.hidden = true;
    };
  }
}

/** Bridge for assets/ai-chat.js (Chrome AI / WebLLM → todo cards). */
window.TaskBoard = {
  get board() { return board; },
  get currentProjectId() { return currentProjectId; },
  get debug() { return debugEnabled; },
  API,
  postJSON,
  uid,
  render,
  save,
  loadBoard,
  toast,
  findList,
};

init();
