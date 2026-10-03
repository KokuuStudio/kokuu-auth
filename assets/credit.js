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
    var uidEl = document.getElementById('kc-uid');
    if (uidEl) {
      uidEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') loadLedger();
      });
    }
    loadLedger();
  }

  function loadLedger() {
    var el = document.getElementById('kc-list');
    if (!el) return;
    var uid = (document.getElementById('kc-uid') || {}).value || '';
    var base = el.getAttribute('data-url') || '/plugin/kokuu-credit/api/ledger';
    var url = base + '?limit=50';
    if (uid) url += '&uid=' + encodeURIComponent(uid);

    el.innerHTML = '<div class="kc-empty">加载中…</div>';
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        el.innerHTML = renderTable(d.items || []);
        if (d.stats) {
          setText('kc-stat-total', d.stats.total);
          setText('kc-stat-today', d.stats.today);
          setText('kc-stat-events', d.stats.events);
        }
      })
      .catch(function () { el.innerHTML = '<div class="kc-empty">加载失败，请检查网络或权限</div>'; });
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

  function deltaClass(n) {
    return n > 0 ? 'kc-delta-plus' : (n < 0 ? 'kc-delta-minus' : 'kc-delta-zero');
  }

  function deltaText(n) {
    return n > 0 ? '+' + n : String(n);
  }

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
