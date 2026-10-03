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

    if (units < MIN) {
      if (elAmount) { elAmount.textContent = '-'; }
      if (elCost) { elCost.textContent = '-'; }
      if (elPreview) { elPreview.classList.add('is-bad'); }
      if (elPreviewText) { elPreviewText.textContent = '最少兑换 ' + MIN + ' 单位，需要'; }
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
        say('请选择要发放到的角色', 'err');
        return;
      }

      elSubmit.disabled = true;
      say('提交中…');

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
          say('兑换成功！正在发放到 ' + (j.order && j.order.player_name) + '，稍等几秒刷新查看。', 'ok');
          elSubmit.disabled = true;
          setTimeout(function () { location.reload(); }, 1800);
        } else {
          say((j && j.message) || '兑换失败', 'err');
          elSubmit.disabled = false;
        }
      }).catch(function (e) {
        say('网络错误：' + e.message, 'err');
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
