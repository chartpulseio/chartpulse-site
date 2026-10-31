/* ChartPulse Assistant panel. Vanilla, no dependencies.
   Loaded on first launcher click. Conversation lives in sessionStorage only. */
(function () {
  'use strict';
  if (window.CPChat) return;

  var API = '/chat-api.php';
  var KEY = 'cp_chat_v1';
  var MAXT = 12;
  var openedAt = Date.now();
  var turns = [];
  var busy = false;
  var panel, log, ta, send, chipsBox;

  try {
    var s = sessionStorage.getItem(KEY);
    if (s) turns = JSON.parse(s) || [];
  } catch (e) { turns = []; }

  function save() {
    try { sessionStorage.setItem(KEY, JSON.stringify(turns.slice(-MAXT))); } catch (e) {}
  }

  function esc(t) {
    return String(t).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* Safe, tiny markdown: bold, bullets, same-site links. Never raw HTML. */
  function md(t) {
    var out = esc(t);
    out = out.replace(/\[([^\]]{1,80})\]\((\/[^)\s]{0,120}|https:\/\/chartpulse\.io[^)\s]{0,140})\)/g,
      function (m, label, href) { return '<a href="' + esc(href) + '">' + label + '</a>'; });
    out = out.replace(/\*\*([^*]{1,160})\*\*/g, '<strong>$1</strong>');
    var lines = out.split('\n'), html = '', inUl = false;
    for (var i = 0; i < lines.length; i++) {
      var m = lines[i].match(/^\s*[-*]\s+(.*)$/);
      if (m) {
        if (!inUl) { html += '<ul>'; inUl = true; }
        html += '<li>' + m[1] + '</li>';
      } else {
        if (inUl) { html += '</ul>'; inUl = false; }
        html += lines[i] + '\n';
      }
    }
    if (inUl) html += '</ul>';
    return html.trim();
  }

  function bubble(role, text) {
    var d = document.createElement('div');
    d.className = 'cp-msg ' + (role === 'me' ? 'me' : 'bot');
    d.innerHTML = role === 'me' ? esc(text) : md(text);
    log.appendChild(d);
    log.scrollTop = log.scrollHeight;
    return d;
  }

  function chipsFor() {
    var p = location.pathname.toLowerCase();
    if (p.indexOf('scanner') > -1) {
      return ['How do I read the queue?', 'What is Watching?', 'Explain Pulse Sync', 'Which markets are covered?'];
    }
    if (p.indexOf('dashboard') > -1) {
      return ['What is a gate event?', 'What does ENTRY mean?', 'What is Ground score?', 'What is cancel below?'];
    }
    if (p.indexOf('pricing') > -1) {
      return ['What do the plans cost?', 'How does the free trial work?', 'How do payments work?', 'What is the refund policy?'];
    }
    return ['How does ChartPulse work?', 'Explain Pulse Sync', 'What do the plans cost?', 'Is the market open now?'];
  }

  function renderChips() {
    chipsBox.innerHTML = '';
    if (turns.length) { chipsBox.style.display = 'none'; return; }
    chipsBox.style.display = 'flex';
    chipsFor().forEach(function (c) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'cp-chip';
      b.textContent = c;
      b.addEventListener('click', function () { ask(c); });
      chipsBox.appendChild(b);
    });
  }

  function ask(text) {
    text = String(text || '').trim();
    if (!text || busy) return;
    if (text.length > 800) text = text.slice(0, 800);

    busy = true;
    send.disabled = true;
    ta.value = '';
    ta.style.height = 'auto';
    bubble('me', text);
    turns.push({ role: 'me', text: text });
    renderChips();

    var wait = document.createElement('div');
    wait.className = 'cp-msg bot';
    wait.innerHTML = '<span class="cp-dots"><i></i><i></i><i></i></span>';
    wait.setAttribute('aria-label', 'Assistant is typing');
    log.appendChild(wait);
    log.scrollTop = log.scrollHeight;

    fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        q: text,
        page: location.pathname,
        t: openedAt,
        website: '',
        turns: turns.slice(-4)
      })
    })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .then(function (j) {
        wait.remove();
        var reply = (j && j.reply) || 'I could not reach the assistant just now. Please try again in a moment.';
        var d = bubble('bot', reply);
        turns.push({ role: 'bot', text: reply });
        save();
        addVote(d);
      })
      .catch(function () {
        wait.remove();
        bubble('bot', 'I could not reach the assistant just now. Please try again in a moment.');
      })
      .then(function () {
        busy = false;
        send.disabled = false;
        ta.focus();
      });
  }

  function addVote(d) {
    var v = document.createElement('div');
    v.className = 'cp-vote';
    ['Helpful', 'Not helpful'].forEach(function (lbl) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = lbl;
      b.setAttribute('aria-pressed', 'false');
      b.addEventListener('click', function () {
        var all = v.querySelectorAll('button');
        for (var i = 0; i < all.length; i++) { all[i].setAttribute('aria-pressed', 'false'); }
        b.setAttribute('aria-pressed', 'true');
      });
      v.appendChild(b);
    });
    d.appendChild(v);
  }

  function build() {
    panel = document.createElement('section');
    panel.className = 'cp-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'ChartPulse Assistant');
    panel.innerHTML =
      '<div class="cp-head">' +
        '<b>CHART<i>PULSE</i></b><span class="cp-sp"></span>' +
        '<button class="cp-icon" type="button" data-new title="New chat" aria-label="New chat">' +
          '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true">' +
          '<path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5" stroke="currentColor" stroke-width="1.8" ' +
          'stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
        '<button class="cp-icon" type="button" data-close title="Close" aria-label="Close assistant">' +
          '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true">' +
          '<path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="1.9" ' +
          'stroke-linecap="round"/></svg></button>' +
      '</div>' +
      '<div class="cp-log" id="cp-log" role="log" aria-live="polite"></div>' +
      '<div class="cp-chips" id="cp-chips"></div>' +
      '<div class="cp-foot">' +
        '<div class="cp-row">' +
          '<label class="cp-hp">Website<input class="cp-hp" tabindex="-1" autocomplete="off" data-hp></label>' +
          '<textarea rows="1" maxlength="800" placeholder="Ask about ChartPulse..." aria-label="Your question"></textarea>' +
          '<button class="cp-send" type="button">Send</button>' +
        '</div>' +
        '<div class="cp-legal">AI assistant. Information only, not investment advice.<br>' +
        'Smart answers may be generated with Google Gemini (free tier). ' +
        'Do not share personal or financial information.</div>' +
      '</div>';

    document.body.appendChild(panel);
    log = panel.querySelector('#cp-log');
    chipsBox = panel.querySelector('#cp-chips');
    ta = panel.querySelector('textarea');
    send = panel.querySelector('.cp-send');

    panel.querySelector('[data-close]').addEventListener('click', close);
    panel.querySelector('[data-new]').addEventListener('click', function () {
      turns = [];
      save();
      log.innerHTML = '';
      greet();
      renderChips();
    });
    send.addEventListener('click', function () { ask(ta.value); });
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); ask(ta.value); }
    });
    ta.addEventListener('input', function () {
      ta.style.height = 'auto';
      ta.style.height = Math.min(ta.scrollHeight, 110) + 'px';
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && panel && !panel.hidden) close();
    });

    if (turns.length) {
      turns.forEach(function (t) { bubble(t.role, t.text); });
    } else {
      greet();
    }
    renderChips();
  }

  function greet() {
    bubble('bot',
      'Hi, I am the ChartPulse Assistant. I can explain how the engine works, what the terms mean, ' +
      'and what the plans include.\n\nI cannot give trading advice.');
  }

  function open() {
    if (!panel) build();
    panel.hidden = false;
    openedAt = Date.now();
    var l = document.getElementById('cp-launch');
    if (l) l.style.display = 'none';
    setTimeout(function () { if (ta) ta.focus(); }, 40);
  }

  function close() {
    if (panel) panel.hidden = true;
    var l = document.getElementById('cp-launch');
    if (l) l.style.display = 'inline-flex';
  }

  window.CPChat = { open: open, close: close };
})();
