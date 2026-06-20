// Convoro extension: Projects (forum surface).
// Shipped prebuilt — no build step. Two widgets using live theme tokens (--c-*):
//   • profile:below  → a member's "Projects" showcase (featured first)
//   • forum:sidebar  → a compact "Latest projects" card
// Both fetch public JSON endpoints; nothing renders until data arrives.

const c = window.Convoro;

function el(tag, css, text) {
  const n = document.createElement(tag);
  if (css) n.style.cssText = css;
  if (text != null) n.textContent = text;
  return n;
}

const TOK = {
  surface: 'rgb(var(--c-surface,255 255 255))',
  surface2: 'rgb(var(--c-surface-2,245 246 250))',
  border: 'rgb(var(--c-border,230 232 240))',
  text: 'rgb(var(--c-text,27 32 48))',
  text2: 'rgb(var(--c-text-2,74 81 104))',
  muted: 'rgb(var(--c-muted,138 144 166))',
  primary: 'rgb(var(--c-primary,91 91 214))',
};

function cardShell() {
  return el('div', [
    'overflow:hidden', 'border-radius:var(--c-radius,12px)',
    'border:1px solid ' + TOK.border, 'background:' + TOK.surface,
    'box-shadow:0 1px 2px rgba(0,0,0,.04)', 'margin-bottom:16px',
  ].join(';'));
}

function sectionHead(icon, label) {
  const head = el('div', 'display:flex;align-items:center;gap:8px;padding:12px 16px;background:rgb(var(--c-primary,91 91 214) / .10);border-bottom:1px solid ' + TOK.border);
  head.appendChild(el('span', null, icon));
  const b = el('b', 'font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:rgb(var(--c-primary-700,66 66 181))', label);
  head.appendChild(b);
  return head;
}

// A single project row (used in both widgets).
function projectRow(p, opts) {
  opts = opts || {};
  const row = el('a', [
    'display:flex', 'gap:10px', 'align-items:center', 'padding:10px 16px',
    'text-decoration:none', 'border-bottom:1px solid ' + TOK.border,
  ].join(';'));
  row.href = '/projects/' + p.slug;

  if (p.image) {
    const thumb = el('div', 'width:46px;height:46px;border-radius:9px;flex-shrink:0;background-size:cover;background-position:center;background-color:' + TOK.surface2);
    thumb.style.backgroundImage = "url('" + p.image + "')";
    row.appendChild(thumb);
  } else {
    const thumb = el('div', 'width:46px;height:46px;border-radius:9px;flex-shrink:0;display:grid;place-items:center;font-size:20px;background:' + TOK.surface2, '📦');
    row.appendChild(thumb);
  }

  const body = el('div', 'min-width:0;flex:1');
  const titleRow = el('div', 'display:flex;align-items:center;gap:6px;min-width:0');
  if (opts.featured) {
    titleRow.appendChild(el('span', 'color:#f5a623;flex-shrink:0', '★'));
  }
  const title = el('div', 'font-weight:700;color:' + TOK.text + ';white-space:nowrap;overflow:hidden;text-overflow:ellipsis', p.title);
  titleRow.appendChild(title);
  body.appendChild(titleRow);

  const metaBits = [];
  if (p.category && p.category.name) metaBits.push((p.category.icon ? p.category.icon + ' ' : '') + p.category.name);
  if (typeof p.likesCount === 'number' && p.likesCount > 0) metaBits.push('♥ ' + p.likesCount);
  if (metaBits.length) {
    body.appendChild(el('div', 'font-size:12px;color:' + TOK.muted + ';white-space:nowrap;overflow:hidden;text-overflow:ellipsis', metaBits.join(' · ')));
  }
  row.appendChild(body);
  return row;
}

function stripLastBorder(container) {
  const rows = container.querySelectorAll('a');
  if (rows.length) rows[rows.length - 1].style.borderBottom = '0';
}

// ---- profile:below — the member's project showcase ----
function mountProfile(host, ctx) {
  const userId = ctx && ctx.props && ctx.props.userId;
  let req;
  if (userId) {
    req = fetch('/api/ext/projects/user/' + userId + '/projects', { headers: { Accept: 'application/json' } });
  } else {
    // Fallback: derive the username from the profile URL (/u/<name> or /user/<name>).
    const m = location.pathname.match(/\/(?:u|user|users)\/([^/]+)/i);
    if (!m) return;
    req = fetch('/api/ext/projects/user/by-name/' + encodeURIComponent(decodeURIComponent(m[1])), { headers: { Accept: 'application/json' } });
  }
  req.then((r) => (r.ok ? r.json() : null))
    .then((d) => { if (d && d.projects && d.projects.length) renderProfile(host, d.projects); })
    .catch(() => { /* silent */ });
}

function renderProfile(host, projects) {
  const card = cardShell();
  card.style.marginTop = '20px';
  card.appendChild(sectionHead('📦', c.t('Projects')));
  const list = el('div', null);
  projects.forEach((p) => list.appendChild(projectRow(p, { featured: p.featured })));
  stripLastBorder(list);
  card.appendChild(list);

  const foot = el('a', 'display:block;padding:10px 16px;text-align:center;font-size:13px;font-weight:600;text-decoration:none;color:' + TOK.primary, c.t('Browse all projects'));
  foot.href = '/projects';
  card.appendChild(foot);
  host.appendChild(card);
}

// ---- forum:sidebar — latest projects ----
function mountSidebar(host) {
  fetch('/api/ext/projects/recent', { headers: { Accept: 'application/json' } })
    .then((r) => (r.ok ? r.json() : null))
    .then((d) => { if (d && d.projects && d.projects.length) renderSidebar(host, d.projects); })
    .catch(() => { /* silent */ });
}

function renderSidebar(host, projects) {
  const card = cardShell();
  card.appendChild(sectionHead('📦', c.t('Latest projects')));
  const list = el('div', null);
  projects.forEach((p) => list.appendChild(projectRow(p)));
  stripLastBorder(list);
  card.appendChild(list);

  const foot = el('a', 'display:block;padding:10px 16px;text-align:center;font-size:13px;font-weight:600;text-decoration:none;color:' + TOK.primary, c.t('See all'));
  foot.href = '/projects';
  card.appendChild(foot);
  host.appendChild(card);
}

if (c && typeof c.registerSlot === 'function') {
  c.registerSlot('profile:below', {
    ext: 'convoro-projects',
    label: 'Member projects',
    order: 10,
    mount(elm, ctx) { mountProfile(elm, ctx); },
  });

  c.registerSlot('forum:sidebar', {
    ext: 'convoro-projects',
    label: 'Latest projects',
    order: -10,
    mount(elm) { mountSidebar(elm); },
  });
}
