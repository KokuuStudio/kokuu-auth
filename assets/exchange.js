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
  /* ⚠️ 底仓与余额都由后端算好后注入，**不要在前端自己查 /users**：
   *   页面里这两个值一旦与后端不一致，用户会看到「能提交但必失败」。
   *   后端是唯一权威（见 kokuu_exchange_create_order 的两处 min_keep 校验）。 */
  var KEEP = parseInt(window.EX_KEEP, 10) || 0;
  var BALANCE = parseInt(window.EX_BALANCE, 10) || 0;

  var $ = function (id) { return document.getElementById(id); };

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
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
      }).then(function (r) {
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
