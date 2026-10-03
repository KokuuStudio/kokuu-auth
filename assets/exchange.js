/* kokuu-exchange · 兑换页交互
 *
 * 只做三件事：算预览 → 提交 → 冷却倒计时。
 * 所有派生计算放前端只是为了即时反馈，**后端会重新校验一遍**，
 * 前端校验只是体验，不是防线。
 */
(function () {
  'use strict';

  var COST = parseInt(window.EX_COST, 10) || 100;
  var MIN = parseInt(window.EX_MIN, 10) || 1;
  var URL_CREATE = window.EX_CREATE_URL;
  /* ⚠️ CSRF：create 路由挂 `web` 中间件（含 VerifyCsrfToken），
   *   不带 X-XSRF-TOKEN 一律 419「CSRF token mismatch.」。
   *   模板注入的 EX_CSRF 取自 XSRF-TOKEN cookie；
   *   若为空（cookie 过期/被清），退回现场再读一次 cookie ——
   *   页面可能被浏览器长时间开着，注入时的 cookie 已经不是最新的了。 */
  var CSRF = window.EX_CSRF || readCookie('XSRF-TOKEN');
  /* ⚠️ 底仓与余额都由后端算好后注入，**不要在前端自己查 /users**：
   *   页面里这两个值一旦与后端不一致，用户会看到「能提交但必失败」。
   *   后端是唯一权威（见 kokuu_exchange_create_order 的两处 min_keep 校验）。 */
  var KEEP = parseInt(window.EX_KEEP, 10) || 0;
  var BALANCE = parseInt(window.EX_BALANCE, 10) || 0;

  var $ = function (id) { return document.getElementById(id); };

  /* 从 document.cookie 取值并解码。
   * ⚠️ 只 decodeURIComponent，**不要**剥 CookieValuePrefix ——
   *   Laravel 服务端会自己剥，前端剥了反而对不上。 */
  function readCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]+)'));
    return m ? decodeURIComponent(m[1]) : '';
  }

  var elUnits = $('ex-units');
  var elPlayer = $('ex-player');
  var elCost = $('ex-cost');
  var elAmount = $('ex-amount');
  var elPreview = $('ex-preview');
  var elPreviewText = $('ex-preview-text');
  var elSubmit = $('ex-submit');
  var elMsg = $('ex-msg');
  var elCooldown = $('ex-cooldown');

  if (!elUnits) { return; }          // 无角色时表单不渲染

  function msg(text, kind) {
    if (!elMsg) { return; }
    elMsg.textContent = text || '';
    elMsg.className = 'ex-msg' + (kind ? ' is-' + kind : '');
  }

  /* ---------- 实时算成本 ---------- */
  function refresh() {
    var units = parseInt(elUnits.value, 10) || 0;
    var cost = units * COST;

    /* 一条统一的「不能提交」判定，顺序即优先级：
     *   数量不合法 → 余额本身已低于底仓 → 本次会跌破底仓。
     *   把余额判定放在数量之后：余额不足时提示数量毫无意义。 */
    var blocked = '';

    if (units < MIN) {
      blocked = '最少兑换 ' + MIN + ' 单位';
    } else if (KEEP > 0 && BALANCE < KEEP) {
      blocked = '余额 ' + BALANCE + ' 已低于需保留的 ' + KEEP + ' 积分，无法兑换';
    } else if (KEEP > 0 && units * COST > BALANCE - KEEP) {
      blocked = '最多可兑 ' + Math.floor(Math.max(0, BALANCE - KEEP) / COST) + ' 单位（需保留 ' + KEEP + ' 积分）';
    }

    if (blocked) {
      if (elAmount) { elAmount.textContent = units < MIN ? '-' : String(units); }
      if (elCost) { elCost.textContent = units < MIN ? '-' : String(cost); }
      if (elPreview) { elPreview.classList.add('is-bad'); }
      if (elPreviewText) { elPreviewText.textContent = blocked; }
      if (elSubmit) { elSubmit.disabled = true; }
      return;
    }

    if (elAmount) { elAmount.textContent = String(units); }
    if (elCost) { elCost.textContent = String(cost); }
    if (elPreview) { elPreview.classList.remove('is-bad'); }
    if (elPreviewText) { elPreviewText.textContent = '将消耗'; }
    if (elSubmit) { elSubmit.disabled = false; }
  }

  if (elUnits) {
    elUnits.addEventListener('input', refresh);
    elUnits.addEventListener('change', refresh);
  }

  /* 快捷数量按钮 */
  Array.prototype.forEach.call(document.querySelectorAll('.ex-quick-btn'), function (b) {
    b.addEventListener('click', function () {
      elUnits.value = b.getAttribute('data-q');
      refresh();
    });
  });

  /* ---------- 提交 ---------- */
  if (elSubmit) {
    elSubmit.addEventListener('click', function () {
      var units = parseInt(elUnits.value, 10) || 0;
      var pid = elPlayer ? parseInt(elPlayer.value, 10) : 0;

      if (!pid) {
        msg('请选择要发放到的角色', 'err');
        return;
      }

      /* CSRF cookie 读不到就直接拦下并让用户刷新。
       * 不发这个请求：服务器会回 419 + HTML 错误页，
       * 前端 r.json() 兜底只能显示「服务器返回异常（HTTP 419）」，
       * 用户完全不知道要刷新页面。 */
      if (!CSRF) {
        msg('登录状态已过期，请刷新页面后重试', 'err');
        return;
      }

      elSubmit.disabled = true;
      msg('提交中…');

      var body = new URLSearchParams();
      body.set('units', String(units));
      body.set('player_id', String(pid));

      fetch(URL_CREATE, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest',
          /* 提交时再读一次 cookie，不复用加载时的快照 ——
           * 页面开着不动也会让 session/token 轮换，用旧值必 419。 */
          'X-XSRF-TOKEN': readCookie('XSRF-TOKEN') || CSRF
        },
        body: body.toString()
      }).then(function (r) {
        /* 419 = CSRF 校验失败，响应体是 HTML 错误页不是 JSON。
         * 必须单独翻译：否则用户只会看到「服务器返回异常（HTTP 419）」，
         * 完全不知道要刷新页面 —— 这个坑真实发生过（线上 24 次 419）。 */
        if (r.status === 419) {
          return { code: 1, message: '登录状态已过期，请刷新页面后重试', needReload: true };
        }
        return r.json().catch(function () {
          return { code: 1, message: '服务器返回异常（HTTP ' + r.status + '）' };
        });
      }).then(function (j) {
        if (j && j.code === 0) {
          msg('兑换成功！正在发放到 ' + (j.order && j.order.player_name) + '，稍等几秒刷新查看。', 'ok');
          elSubmit.disabled = true;
          setTimeout(function () { location.reload(); }, 1800);
        } else {
          msg((j && j.message) || '兑换失败', 'err');
          elSubmit.disabled = false;
          /* 失败后重算：余额可能已被并发请求扣掉，
           * 不重算的话按钮会一直停在可点状态，用户反复点全失败。 */
          refresh();
          if (j && j.needReload) {
            elSubmit.disabled = true;   // token 已失效，再点也是 419，别让用户空转
          }
        }
      }).catch(function (e) {
        msg('网络错误：' + e.message, 'err');
        elSubmit.disabled = false;
      });
    });
  }

  /* ---------- 冷却倒计时 ---------- */
  if (elCooldown) {
    var left = parseInt(elCooldown.textContent, 10) || 0;
    var tick = setInterval(function () {
      left -= 1;
      if (left <= 0) {
        clearInterval(tick);
        location.reload();
        return;
      }
      elCooldown.textContent = String(left);
    }, 1000);
  }

  refresh();
})();
