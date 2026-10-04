/* kokuu-coupon —— 用户中心「幸运抽奖」widget
 *
 * 用法：<script src=".../dash.js"></script>（页面底部加载，DOM 已就绪）
 *
 * 🔴 本文件沿用的硬事实（都是踩过的，别改回去）：
 *  1. **CSRF 值提交时实时再读一次 cookie**。页面加载时的快照会 419 ——
 *     session/token 会轮换。绝不用变量缓存。
 *  2. cookie 值**只 decodeURIComponent，不要剥 CookieValuePrefix** ——
 *     服务端会自己剥，多剥一次就无效。
 *  3. 判据是「**非 419**」，不是「等于 200」。419 = CSRF 层拦截（请求没到业务），
 *     422 = 业务失败（已穿过 CSRF）。验 CSRF 修好了要看「不是 419」。
 *  4. disabled 按钮的 click() 被浏览器**静默忽略**（一个请求都不发）——
 *     所以状态没变就 early return，不要发出去等 422。
 *  5. 抽奖成功后**冷却才刚开始**，必须按响应里的真实冷却秒数倒计时，
 *     不能假设配置值（配置可能被管理员改过）。
 */
(function () {
  'use strict';

  var btn = document.getElementById('kcp-draw-btn');
  var btnText = document.getElementById('kcp-draw-btn-text');
  var result = document.getElementById('kcp-draw-result');
  if (!btn || !btnText || !result) { return; }

  var drawUrl = btn.getAttribute('data-url') || '/plugin/kokuu-coupon/draw';
  var busy = false;
  var timer = null;

  /* 🔴 实时读 cookie，不吃缓存（第 1、2 条） */
  function csrfToken() {
    var m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
  }

  function say(html, kind) {
    result.hidden = false;
    result.className = 'kcpDash-result' + (kind ? ' is-' + kind : '');
    result.innerHTML = html;
  }

  function setBtn(text, disabled) {
    btnText.textContent = text;
    btn.disabled = !!disabled;
  }

  /* 冷却倒计时：到点自动恢复（配置变了也无所谓，用的是服务端给的秒数） */
  function cooldown(seconds, label) {
    if (timer) { clearInterval(timer); timer = null; }
    var left = Math.max(0, parseInt(seconds, 10) || 0);
    if (left <= 0) { setBtn(label || '立即抽奖', false); return; }
    setBtn('冷却中（' + left + 's）', true);
    timer = setInterval(function () {
      left -= 1;
      if (left <= 0) {
        clearInterval(timer);
        timer = null;
        setBtn(label || '立即抽奖', false);
      } else {
        setBtn('冷却中（' + left + 's）', true);
      }
    }, 1000);
  }

  function refreshWallet(spendable, draws) {
    var nums = document.querySelectorAll('.kcpDash-wallet-num');
    /* 第一个是抽奖次数、第二个是**可用积分**（余额 - 底仓）——
       与 widget.twig 的顺序一致。
       🔴 传进来的必须是 spendable 而不是 balance：
       第二个格子标的是「可用积分」，填 balance 会显示成总余额，
       数字大得离谱、看着像抽奖没扣钱（底仓只有服务端知道，前端减不了）。 */
    if (draws !== null && draws !== undefined && nums[0]) { nums[0].textContent = draws; }
    if (spendable !== null && spendable !== undefined && nums[1]) {
      nums[1].textContent = spendable;
    }
  }

  btn.addEventListener('click', function () {
    /* 🔴 disabled 会被静默忽略，这里再判一次是为了防「连点」期间状态漂移 */
    if (busy || btn.disabled) { return; }
    busy = true;
    btn.classList.add('is-busy');
    setBtn('抽奖中…', true);

    fetch(drawUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': csrfToken()   /* 🔴 实时读，不缓存 */
      },
      body: ''
    }).then(function (r) {
      return r.json().catch(function () {
        return { code: 1, message: '服务器返回异常（HTTP ' + r.status + '）' };
      }).then(function (j) {
        return { status: r.status, body: j };
      });
    }).then(function (res) {
      var j = res.body || {};

      if (res.status === 419) {
        say('CSRF 校验未通过（419）。请刷新页面后重试。', 'warn');
        setBtn('请刷新页面', true);
        return;
      }

      if (j.code === 0) {
        say(
          '抽中 <b>' + escapeHtml(j.label || '奖品') + '</b>，获得 ' +
          '<span class="kcpDash-result-amount">+' + (j.amount || 0) + '</span> 积分',
          'win'
        );
        refreshWallet(j.spendable, j.draws);
        /* 冷却秒数由服务端给（响应里没带就用配置值 3），
           不用猜 —— 猜错了按钮会提前可点，连点就绕过冷却。 */
        cooldown(j.cooldown !== undefined ? j.cooldown : 3, '立即抽奖');
        return;
      }

      /* 业务失败（422 = 已穿过 CSRF，说明功能本身是通的） */
      say(escapeHtml(j.message || '抽奖失败'), 'warn');
      refreshWallet(j.spendable, j.draws);
      /* 「次数与积分均不足」时按钮保持禁用，其余给 3 秒冷却 */
      if (/不足|没有|不足次数/.test(j.message || '')) {
        setBtn('次数与积分均不足', true);
      } else {
        cooldown(j.cooldown !== undefined ? j.cooldown : 3, '立即抽奖');
      }
    }).catch(function (e) {
      say('网络错误：' + escapeHtml(e.message), 'warn');
      setBtn('立即抽奖', false);
    }).then(function () {
      busy = false;
      btn.classList.remove('is-busy');
    });
  });

  /* 奖品 label 是管理员填的，innerHTML 前必须转义 */
  function escapeHtml(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
})();
