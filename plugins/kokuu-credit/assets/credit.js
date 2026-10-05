/* KokuuCredit —— 后台配置页的保存/自检，流水页的列表渲染 */
(function () {
  'use strict';

  /* ---------- 后台配置页 ---------- */
  var root = document.getElementById('kc-root');
  if (root) {
    var cfg = JSON.parse(root.getAttribute('data-config') || '{}');
    var urlSettings = root.getAttribute('data-url-settings');
    var urlLedger = root.getAttribute('data-url-ledger');
    var urlSelftest = root.getAttribute('data-url-selftest');

    function csrf() {
      var m = document.querySelector('meta[name="csrf-token"]');
      return m ? m.getAttribute('content') : '';
    }

    function toast(msg, ok) {
      var el = document.createElement('div');
      el.className = 'kc-toast ' + (ok ? 'ok' : 'err');
      el.textContent = msg;
      document.body.appendChild(el);
      setTimeout(function () { el.remove(); }, 3200);
    }

    var saveBtn = document.getElementById('kc-save');
    if (saveBtn) {
      saveBtn.addEventListener('click', function () {
        var fd = new FormData(document.getElementById('kc-form'));
        fd.append('_token', csrf());

        saveBtn.disabled = true;
        fetch(urlSettings, {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() }
        })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            toast(d.message || (d.code === 0 ? '已保存' : '保存失败'), d.code === 0);
          })
          .catch(function () { toast('请求失败', false); })
          .then(function () { saveBtn.disabled = false; });
      });
    }

    var stBtn = document.getElementById('kc-selftest');
    if (stBtn) {
      stBtn.addEventListener('click', function () {
        var email = (document.getElementById('kc-email').value || '').trim();
        if (!email) { toast('请填写皮肤站账号邮箱', false); return; }

        stBtn.disabled = true;
        fetch(urlSelftest, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf()
          },
          body: JSON.stringify({ email: email })
        })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            toast(d.message || (d.code === 0 ? '自检通过' : '自检失败'), d.code === 0);
            if (d.code === 0) loadLedger();
          })
          .catch(function () { toast('请求失败', false); })
          .then(function () { stBtn.disabled = false; });
      });
    }

    /* 配置页里也顺手刷一下流水，方便管理员确认加分是否真的到账 */
    var box = document.getElementById('kc-ledger');
    if (box && urlLedger) {
      fetch(urlLedger + '?limit=10', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (r) { return r.json(); })
        .then(function (d) { box.innerHTML = renderTable(d.items || []); })
        .catch(function () { box.innerHTML = '<div class="kc-empty">流水加载失败</div>'; });
    }

    /* ---------- 账号互通（SSO） ---------- */
    var urlSso = root.getAttribute('data-url-sso');
    var urlSsoStatus = root.getAttribute('data-url-sso-status');
    var urlSsoLinks = root.getAttribute('data-url-sso-links');

    if (urlSso) {
      var ssoSaveBtn = document.getElementById('kc-sso-save');
      if (ssoSaveBtn) {
        ssoSaveBtn.addEventListener('click', function () {
          var fd = new FormData(document.getElementById('kc-sso-form'));
          // checkbox 未勾选时 FormData 里不会有这个 key，显式补 0，
          // 否则后端 !empty(null) 判定下永远存 0，勾了也保存不上
          fd.append('enabled', document.getElementById('kc-sso-enabled').checked ? '1' : '0');
          fd.append('auto_register', document.getElementById('kc-sso-autoreg').checked ? '1' : '0');
          fd.append('forum_callback', document.querySelector('#kc-sso-form input[name="forum_callback"]').value || '');
          fd.append('_token', csrf());

          ssoSaveBtn.disabled = true;
          fetch(urlSso, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() }
          })
            .then(function (r) { return r.json(); })
            .then(function (d) {
              toast(d.message || (d.code === 0 ? '已保存' : '保存失败'), d.code === 0);
              if (d.code === 0) loadSsoStatus();
            })
            .catch(function () { toast('请求失败', false); })
            .then(function () { ssoSaveBtn.disabled = false; });
        });
      }

      loadSsoStatus();
      loadSsoLinks();
    }

    function loadSsoStatus() {
      var stateEl = document.getElementById('kc-sso-state');
      var detailEl = document.getElementById('kc-sso-detail');
      if (!stateEl || !urlSsoStatus) return;

      fetch(urlSsoStatus, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.enabled) {
            stateEl.innerHTML = '<span style="color:#c0392b">未启用</span>';
          } else if (!d.client_ready) {
            stateEl.innerHTML = '<span style="color:#c0392b">OAuth 客户端未就绪，请检查回调地址后重新保存</span>';
          } else {
            stateEl.innerHTML = '<span style="color:#27ae60">已启用 · 客户端 #' + esc(d.client_id) + '</span>';
          }

          if (!detailEl) return;

          var autoText = d.auto_register ? '是' : '否';
          var rows = [
            ['论坛回调', d.callback || '（未设置）'],
            ['自动建号', autoText],
            ['授权端点', d.authorize_url || ''],
            ['令牌端点', d.token_url || ''],
            ['用户信息端点', d.userinfo_url || ''],
            ['论坛登录入口', (d.callback || '').replace(/\/auth\/kokuu\/callback$/, '/auth/kokuu')]
          ];

          detailEl.innerHTML = '<table class="kc-table"><tbody>' +
            rows.map(function (r) {
              return '<tr><td style="width:160px;color:#888">' + esc(r[0]) + '</td><td>' + esc(r[1]) + '</td></tr>';
            }).join('') +
            '</tbody></table>' +
            '<p class="help-block" style="margin-top:8px;">' +
            '用户授权后，论坛按「已绑定 skin_uid → 同邮箱 → 自动建号」的顺序对齐账号；' +
            '自动建的账号密码为空，无法在论坛独立登录。' +
            '</p>';
        })
        .catch(function () { stateEl.innerHTML = '<span style="color:#c0392b">状态查询失败</span>'; });
    }

    function loadSsoLinks() {
      var el = document.getElementById('kc-sso-links');
      if (!el || !urlSsoLinks) return;

      fetch(urlSsoLinks, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var items = d.items || [];
          if (!items.length) {
            el.innerHTML = '<div class="kc-empty">还没有任何账号通过互通登录过</div>';
            return;
          }

          var rows = items.map(function (it) {
            return '<tr>' +
              '<td>' + esc(it.nickname) + ' <span class="kc-event-id">uid ' + esc(it.uid) + '</span></td>' +
              '<td>' + esc(it.last_email) + '</td>' +
              '<td>论坛 #' + esc(it.forum_user_id) + '</td>' +
              '<td>' + esc(it.last_ip) + '</td>' +
              '<td>' + esc(it.last_seen) + '</td>' +
              '</tr>';
          }).join('');

          el.innerHTML = '<table class="kc-table"><thead><tr>' +
            '<th>皮肤站用户</th><th>邮箱</th><th>论坛用户</th><th>IP</th><th>最近登录</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table>';
        })
        .catch(function () { el.innerHTML = '<div class="kc-empty">绑定记录加载失败</div>'; });
    }
  }

  /* ---------- 流水页 ---------- */
  var listEl = document.getElementById('kc-list');
  if (listEl) {
    var refreshBtn = document.getElementById('kc-refresh');
    if (refreshBtn) refreshBtn.addEventListener('click', loadLedger);

    // 筛选：按钮 + 回车两种触发方式
    var uidEl = document.getElementById('kc-uid');
    var uidGo = document.getElementById('kc-uid-go');
    if (uidGo) uidGo.addEventListener('click', loadLedger);
    if (uidEl) {
      uidEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') loadLedger();
      });
    }
    loadLedger();
  }

  /* ---------- 管理员调分面板 ---------- */
  var adjBox = document.getElementById('kc-adj');
  if (adjBox) {
    var page = adjBox.closest('.kcPage') || document;
    var urlUsers = page.getAttribute('data-users-url');
    var urlAdjust = page.getAttribute('data-adjust-url');

    var adjQ = document.getElementById('kc-adj-q');
    var adjSearch = document.getElementById('kc-adj-search');
    var adjResults = document.getElementById('kc-adj-results');
    var adjPicked = document.getElementById('kc-adj-picked');
    var adjForm = document.getElementById('kc-adj-form');
    var adjAmount = document.getElementById('kc-adj-amount');
    var adjAmountLabel = document.getElementById('kc-adj-amount-label');
    var adjNote = document.getElementById('kc-adj-note');
    var adjSubmit = document.getElementById('kc-adj-submit');
    var adjMsg = document.getElementById('kc-adj-msg');
    var adjPreview = document.getElementById('kc-adj-preview');

    /* 当前选中的用户；null = 还没选。提交时用它做「前端有 uid」的本地短路，
     * 但**真正的校验在服务端**（前端只是省一次请求，不是安全边界）。 */
    var adjUid = null;
    var adjScore = 0;
    var adjNickname = '';

    function csrfToken() {
      var m = document.querySelector('meta[name="csrf-token"]');
      return m ? m.getAttribute('content') : '';
    }

    function xsrfToken() {
      var m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
      return m ? decodeURIComponent(m[1]) : '';
    }

    function adjSay(text, kind) {
      if (!adjMsg) return;
      adjMsg.textContent = text || '';
      adjMsg.className = 'kcAdj-msg' + (kind ? ' is-' + kind : '');
    }

    function adjMode() {
      var r = document.querySelector('input[name="kc-adj-mode"]:checked');
      return r ? r.value : 'add';
    }

    /* 数额字段在set 模式下要能填 0（清零是合法操作），
     * 在 add/sub 模式下 0 无意义但也不拦（服务端会拒并给明确文案）。 */
    function syncAmountMode() {
      var m = adjMode();
      if (!adjAmount) return;
      if (m === 'set') {
        adjAmount.min = '0';
        adjAmount.placeholder = '目标余额';
        if (adjAmountLabel) adjAmountLabel.textContent = '设为';
      } else if (m === 'sub') {
        adjAmount.min = '1';
        adjAmount.placeholder = '扣掉多少';
        if (adjAmountLabel) adjAmountLabel.textContent = '扣减';
      } else {
        adjAmount.min = '1';
        adjAmount.placeholder = '加上多少';
        if (adjAmountLabel) adjAmountLabel.textContent = '增加';
      }
      renderPreview();
    }

    /* 实时预览「调完会变成多少」——
     * 让管理员在点确认之前就看到结果，避免手滑输错数字。 */
    function renderPreview() {
      if (!adjPreview || adjUid === null) return;
      var v = parseInt(adjAmount ? adjAmount.value : '0', 10);
      if (isNaN(v)) { adjPreview.textContent = ''; return; }

      var m = adjMode();
      var next = m === 'set' ? v : (m === 'add' ? adjScore + v : adjScore - v);
      adjPreview.textContent = '调整后 ' + next;
      adjPreview.className = 'kcAdj-preview' + (next < 0 ? ' is-bad' : '');
    }

    function selectUser(u) {
      adjUid = u.uid;
      adjScore = u.score;
      adjNickname = u.nickname || '';

      if (adjResults) adjResults.hidden = true;
      if (adjPicked) adjPicked.hidden = false;
      if (adjForm) adjForm.hidden = false;

      var n = document.getElementById('kc-adj-picked-nickname');
      var id = document.getElementById('kc-adj-picked-uid');
      var mail = document.getElementById('kc-adj-picked-email');
      var sc = document.getElementById('kc-adj-picked-score');
      if (n) n.textContent = adjNickname;
      if (id) id.textContent = 'uid ' + u.uid;
      if (mail) mail.textContent = u.email || '';
      if (sc) sc.textContent = adjScore;

      adjSay('');
      syncAmountMode();
    }

    function doSearch() {
      if (!urlUsers) return;
      var q = (adjQ ? adjQ.value : '').trim();
      if (!adjResults) return;

      adjResults.hidden = false;
      adjResults.innerHTML = '<div class="kcPage-empty">搜索中…</div>';

      fetch(urlUsers + '?q=' + encodeURIComponent(q), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var items = d.items || [];
          if (!items.length) {
            adjResults.innerHTML = '<div class="kcPage-empty">没找到匹配的用户</div>';
            return;
          }
          adjResults.innerHTML = items.map(function (u) {
            return '<button type="button" class="kcAdj-hit" data-uid="' + esc(u.uid) + '">' +
              '<span class="kcAdj-hit-name">' + esc(u.nickname || '(无昵称)') + '</span>' +
              '<span class="kcRec-uid">uid ' + esc(u.uid) + '</span>' +
              '<span class="kcAdj-hit-mail">' + esc(u.email || '') + '</span>' +
              '<span class="kcAdj-hit-score">' + esc(u.score) + ' 分</span>' +
              '</button>';
          }).join('');

          Array.prototype.forEach.call(
            adjResults.querySelectorAll('.kcAdj-hit'),
            function (btn) {
              btn.addEventListener('click', function () {
                var uid = parseInt(btn.getAttribute('data-uid'), 10);
                var hit = items.filter(function (x) { return x.uid === uid; })[0];
                if (hit) selectUser(hit);
              });
            }
          );
        })
        .catch(function () {
          adjResults.innerHTML = '<div class="kcPage-empty">搜索失败，请检查网络或权限</div>';
        });
    }

    if (adjSearch) adjSearch.addEventListener('click', doSearch);
    if (adjQ) {
      adjQ.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
      });
    }

    /*
     * ⚠️ 监听器**直接绑在每个 radio 上**，不绑外层容器。
     *   绑容器就得依赖 change 事件冒泡，一旦将来有人给某处加
     *   stopPropagation，或者用 `new Event('change')` 这种默认不冒泡的
     *   方式派发（测试脚本、某些框架的合成事件），标签与 min 就悄悄不再更新
     *   —— 界面还显示「增加」，实际发的是 sub，是很难发现的错账隐患。
     */
    Array.prototype.forEach.call(
      document.querySelectorAll('input[name="kc-adj-mode"]'),
      function (r) { r.addEventListener('change', syncAmountMode); }
    );
    if (adjAmount) {
      adjAmount.addEventListener('input', renderPreview);
    }

    if (adjSubmit) {
      adjSubmit.addEventListener('click', function () {
        if (adjUid === null) { adjSay('请先选一个用户', 'err'); return; }

        var amountRaw = adjAmount ? adjAmount.value : '';
        var note = (adjNote ? adjNote.value : '').trim();

        /* 本地只拦两件最蠢的事：空数额、没写原因。
         * 其余（数额是否超范围、是否非数字、扣成负数）都交给服务端，
         * 因为本地复刻一遍校验逻辑 = 两处实现迟早漂移。 */
        if (amountRaw === '') { adjSay('请填写数额', 'err'); return; }
        if (!note) { adjSay('请填写原因', 'err'); return; }

        adjSubmit.disabled = true;
        adjSay('提交中…');

        fetch(urlAdjust, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            /* 页面侧 POST 必须带 CSRF。两个头都给：
             * X-CSRF-TOKEN 取 meta（csrf_token()），
             * X-XSRF-TOKEN 取 cookie。
             * ⚠️ 值必须在**点击这一刻**现读 —— session/token 会轮转，
             *    用页面加载时的快照必 419。 */
            'X-CSRF-TOKEN': csrfToken(),
            'X-XSRF-TOKEN': xsrfToken()
          },
          /* 每次点击生成一个 nonce：双击是两次独立操作（符合直觉），
           * 而网络重试/回放同一个 nonce 会被 event_id 唯一索引挡掉。 */
          body: JSON.stringify({
            uid: adjUid,
            mode: adjMode(),
            amount: amountRaw,
            note: note,
            nonce: Math.random().toString(36).slice(2) + Date.now().toString(36)
          })
        })
          .then(function (r) {
            return r.json().then(function (d) {
              return { status: r.status, data: d };
            });
          })
          .then(function (res) {
            var d = res.data || {};
            adjSay(d.message || '操作失败', d.code === 0 ? 'ok' : 'err');

            if (d.code === 0 && typeof d.score === 'number') {
              /* 用**服务端回传**的余额覆盖，而不是自己算 ——
               * 真实余额只有服务端知道（可能有并发改动）。 */
              adjScore = d.score;
              var sc = document.getElementById('kc-adj-picked-score');
              if (sc) sc.textContent = adjScore;
              if (adjNote) adjNote.value = '';
              renderPreview();
              /* 立刻刷流水，让管理员看到刚写进去的那条 manual 记录 */
              loadLedger();
              if (adjQ) doSearch();
            }
          })
          .catch(function () { adjSay('请求失败，请检查网络', 'err'); })
          .then(function () { adjSubmit.disabled = false; });
      });
    }

    syncAmountMode();
  }

  function loadLedger() {
    var el = document.getElementById('kc-list');
    if (!el) return;
    var uid = (document.getElementById('kc-uid') || {}).value || '';
    var base = el.getAttribute('data-url') || '/plugin/kokuu-credit/api/ledger';
    var url = base + '?limit=50';
    if (uid) url += '&uid=' + encodeURIComponent(uid);

    el.innerHTML = '<div class="kcPage-empty">加载中…</div>';
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        el.innerHTML = renderRecords(d.items || []);
        if (d.stats) {
          setText('kc-stat-total', d.stats.total);
          setText('kc-stat-today', d.stats.today);
          setText('kc-stat-events', d.stats.events);
        }
      })
      .catch(function () { el.innerHTML = '<div class="kcPage-empty">加载失败，请检查网络或权限</div>'; });
  }

  function setText(id, v) {
    var el = document.getElementById(id);
    if (el) el.textContent = v;
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /*
   * ⚠️ 变动颜色的 class 名**分两套**，别图省事共用一个函数：
   *   · 流水页卡片 → .is-plus / .is-minus / .is-zero（Apple 风，无前缀）
   *   · 后台配置页表格 → .kc-delta-plus / .kc-delta-minus / .kc-delta-zero（虚空深色）
   * 两边 CSS 变量与配色体系完全不同，共用一个函数会导致
   * 后台表格里的数字变成无色。
   */
  function deltaClass(n) {
    return n > 0 ? 'kc-delta-plus' : (n < 0 ? 'kc-delta-minus' : 'kc-delta-zero');
  }

  function recDeltaClass(n) {
    return n > 0 ? 'is-plus' : (n < 0 ? 'is-minus' : 'is-zero');
  }

  function deltaText(n) {
    return n > 0 ? '+' + n : String(n);
  }

  /*
   * 每种来源配一个图标，让卡片扫起来有区分度。
   * ⚠️ 图标名必须落在项目已引入的 Font Awesome 版本里，
   *    写不存在的名字会渲染成一个空方块。
   */
  var SOURCE_ICONS = {
    thread: 'fa-comment-dots',
    reply: 'fa-reply',
    liked: 'fa-heart',
    manual: 'fa-sliders-h',
    legacy: 'fa-clock'
  };

  function sourceIcon(src) {
    return SOURCE_ICONS[src] || 'fa-coins';
  }

  /*
   * 流水列表渲染。
   *
   * 重写前的版本是一张 7 列表格（时间/用户/来源/变动/变动后余额/
   * 事件 ID/备注），用户反馈「不需要那么复杂的记录」。
   * 现在：主行只给「图标 + 来源 + 时间 + 变动」，
   * 其余（用户 / 余额 / 事件 ID / 备注）收进每条记录自己的
   * <details>，默认收起。
   *
   * 另一个改动是**从表格换成卡片**：
   * 7 列在 390px 移动端必须横向滚动，而积分变动天然是
   * 一条一条的事，纵向卡片不用滚动就能读完。
   */
  function renderRecords(items) {
    if (!items.length) {
      return '<div class="kcPage-empty">还没有任何积分流水</div>';
    }

    return items.map(function (it) {
      var src = it.source || '';

      var facts = [];
      if (it.nickname) {
        facts.push(['用户', esc(it.nickname) + (it.uid ? ' <span class="kcRec-uid">uid ' + esc(it.uid) + '</span>' : '')]);
      }
      if (it.balance_after !== undefined && it.balance_after !== null) {
        facts.push(['变动后余额', esc(it.balance_after)]);
      }
      if (it.event_id) {
        facts.push(['事件 ID', '<code class="kcRec-code">' + esc(it.event_id) + '</code>']);
      }
      if (it.note) {
        facts.push(['备注', esc(it.note)]);
      }

      var more = facts.length
        ? '<details class="kcRec-more">' +
            '<summary>技术详情</summary>' +
            '<dl class="kcRec-facts">' +
            facts.map(function (f) {
              return '<dt>' + f[0] + '</dt><dd>' + f[1] + '</dd>';
            }).join('') +
            '</dl>' +
          '</details>'
        : '';

      return '<article class="kcRec kcRec--' + esc(src) + '">' +
        '<div class="kcRec-main">' +
          '<span class="kcRec-ico"><i class="fas ' + sourceIcon(src) + '"></i></span>' +
          '<span class="kcRec-body">' +
            '<span class="kcRec-label">' + esc(it.source_label || src) + '</span>' +
            '<span class="kcRec-time">' + esc(it.created_at) + '</span>' +
          '</span>' +
          '<span class="kcRec-delta ' + recDeltaClass(it.delta) + '">' + deltaText(it.delta) + '</span>' +
        '</div>' +
        more +
      '</article>';
    }).join('');
  }

  /*
   * 后台配置页里那 10 行流水仍然用**表格**渲染
   * （那边是管理员核对到账用，信息密度优先）。
   * 与流水页的卡片渲染并存，各自服务自己的场景。
   */
  function renderTable(items) {
    if (!items.length) {
      return '<div class="kc-empty">还没有任何积分流水</div>';
    }

    var rows = items.map(function (it) {
      return '<tr>' +
        '<td>' + esc(it.created_at) + '</td>' +
        '<td>' + esc(it.nickname) + (it.uid ? ' <span class="kc-event-id">#' + it.uid + '</span>' : '') + '</td>' +
        '<td><span class="kc-tag">' + esc(it.source_label || it.source) + '</span></td>' +
        '<td class="' + deltaClass(it.delta) + '">' + deltaText(it.delta) + '</td>' +
        '<td>' + esc(it.balance_after) + '</td>' +
        '<td class="kc-event-id">' + esc(it.event_id) + '</td>' +
        '<td class="kc-note">' + esc(it.note || '') + '</td>' +
        '</tr>';
    }).join('');

    return '<table class="kc-table"><thead><tr>' +
      '<th>时间</th><th>用户</th><th>来源</th><th>变动</th><th>变动后余额</th><th>事件 ID</th><th>备注</th>' +
      '</tr></thead><tbody>' + rows + '</tbody></table>';
  }
})();
