/* kokuu-coupon —— 幸运抽奖（独立页 plugin/kokuu-coupon/draw）
 *
 * 用法：<script src=".../draw.js"></script>（模板 before_foot 块，DOM 已就绪）
 *
 * 📍 跟着功能搬了两次家：dash.js（仪表盘 widget）→ draw.js（独立页）→ v1.3 重做。
 *
 * 🔴 v1.3 视觉重做时改了 DOM 结构，**类名全换了**
 *    （.kcpDash-* → .kcpPool/.kcpNum/.kcpGo/.kcpResult/.kcpHistory）。
 *    旧名没有保留 —— 页面已整体重写，留着两套类名只会让人以为
 *    哪个还在用。以后改结构记得同步 CSS、模板、守卫三处。
 *
 * ⚠️ 本页的「最近抽奖」是**服务端渲染**的，这个 JS 不碰它
 *   （只更新钱包数字和结果区）。要加「抽完自动刷新列表」再说。
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
 *  6. 奖品 label 是管理员填的，innerHTML 前**必须转义**。
 *  7. 🎬 动画层是 position:fixed;inset:0，播放期间吃掉整页点击。
 *     **必须在 ANIM_MS 准时收层**，否则页面会一直点不动 ——
 *     这是「JS 定时器」和「CSS 动画时长」必须对齐的唯一理由，
 *     改 CSS 的时长就要回来改这个常量。
 *     防重复触发不能指望 overlay（它不阻止定时器），要靠 animBusy。
 */
(function () {
  'use strict';

  var btn = document.getElementById('kcp-draw-btn');
  var btnText = document.getElementById('kcp-draw-btn-text');
  var result = document.getElementById('kcp-draw-result');
  if (!btn || !btnText || !result) { return; }

  var pool = document.querySelector('.kcpPool');
  var elDraws = document.getElementById('kcp-draws');
  var elSpendable = document.getElementById('kcp-spendable');

  /* 🎬 抽卡动画层（v1.4）
   * 这三个可能不存在 —— 别让整个抽奖脚本因为「模板还没更新」而哑掉，
   * 那样症状是「点按钮完全没反应」，比直接报错难查得多。
   * 有 / 没有都走同一条逻辑，只是少了仪式感。 */
  var anim = document.getElementById('kcp-anim');
  var animName = document.getElementById('kcp-anim-name');
  var animAmt = document.getElementById('kcp-anim-amt');

  /* 🔴 必须与 draw.css 里 .kcpAnim-sky 的动画总时长一致。
   *   CSS 是 2.9s，这里多给 80ms 余量（宁可晚收，不可早收 ——
   *   早收会出现「文字还没浮现就没了」）。 */
  var ANIM_MS = 2980;
  var animBusy = false;

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
    result.className = 'kcpResult' + (kind ? ' is-' + kind : '');
    result.innerHTML = html;
  }

  function setBtn(text, disabled) {
    btnText.textContent = text;
    btn.disabled = !!disabled;
    /* 可抽状态跟着按钮走：按钮能点时卡池才有金色描边与呼吸光。
       用 class 不用 style —— 动画与视觉都在 CSS 里。 */
    if (pool) { pool.classList.toggle('is-ready', !disabled); }
  }

  /* 🎬 播放抽卡动画。
   *
   * 只做三件事：填文案 → 摘 hidden 加 .is-on → 定时收层。
   * 全部动画时序都在 CSS 里，JS 一个 animationend 都不监听 ——
   * 理由：CSS 时序是设计的一部分（谁先谁后、错峰多少），
   * 让 JS 去编排就等于把设计拆成两半，改 CSS 时必漏。
   *
   * @param {string} label  奖品名（管理员填的，textContent 天然免疫注入）
   * @param {number} amount 积分
   */
  function playAnim(label, amount) {
    /* 卡池光爆同步做，这样动画结束后回到页面还能看到一次余韵 */
    flash();

    if (!anim || !animName || !animAmt) { return; }
    /* 系统开了「减少动态效果」→ 不播，直接给结果。
       CSS 里也做了 reduced-motion 分支（不播动画、结果直接可见），
       这里再判一次是为了**根本不显示覆盖层**，省掉 2.9s 的白等。 */
    var mq = window.matchMedia
      ? window.matchMedia('(prefers-reduced-motion: reduce)')
      : null;
    if (mq && mq.matches) { return; }

    if (animBusy) { return; }
    animBusy = true;

    var amt = Number(amount) || 0;
    /* 🔴 未中奖（amount === 0）走 .is-miss：灭掉光柱/星芒/核心，
       换冷调灰字。判据是**数字**，不是「文案里有没有谢谢」——
       「谢谢参与」「未中奖」「再接再厉」都是管理员自填的，
       靠字符串猜等于把文案约定写死，管理员一改字就失效。 */
    anim.classList.toggle('is-miss', amt <= 0);

    var name = label || '奖品';
    animName.textContent = name;
    /* 🔴 奖品池的 label 默认就是「N 积分」这种自动命名，
       于是大字行和金额行会显示同一件事（「5 积分」+「+5 积分」）。
       这里判的是**精确的默认格式**（label === amount + ' 积分'），
       不是模糊的「包含积分」——
       管理员把 label 改成「限定皮肤」时，两行都正常显示；
       改成一模一样的「+5 积分」时也确实冗余，屏蔽掉不算错。 */
    var autoNamed = amt > 0 && name.replace(/\s+/g, '') === (amt + '积分');
    animAmt.textContent = (amt > 0 && !autoNamed) ? '+' + amt + ' 积分' : '';

    /* 摘 hidden 前先清一次 .is-on：上一轮如果被中途打断（比如用户切走了），
       残留的 .is-on 会让淡入动画不重放。 */
    anim.classList.remove('is-on');
    anim.hidden = false;
    /* 强制回流后再加 .is-on —— 同 flash() 的理由：
       连续抽奖时元素已带 class 的话，animation 不会重放。 */
    void anim.offsetWidth;
    anim.classList.add('is-on');

    window.setTimeout(function () {
      anim.classList.remove('is-on');
      anim.classList.remove('is-miss');   /* 别把状态留给下一轮 */
      anim.hidden = true;
      animName.textContent = '';
      animAmt.textContent = '';
      animBusy = false;
    }, ANIM_MS);
  }

  /* 抽中时给卡池一次光爆。动画 0.72s（CSS 里 kcpFlash），
     结束后摘掉 class —— 免得下次触发时元素已带 class 导致动画不重放。 */
  function flash() {
    if (!pool) { return; }
    pool.classList.remove('is-hit');
    /* 强制回流：确保连续中奖时动画也会重放 */
    void pool.offsetWidth;
    pool.classList.add('is-hit');
    window.setTimeout(function () { pool.classList.remove('is-hit'); }, 760);
  }

  /* 冷却倒计时：到点自动恢复（配置变了也无所谓，用的是服务端给的秒数） */
  function cooldown(seconds, label) {
    if (timer) { clearInterval(timer); timer = null; }
    var left = Math.max(0, parseInt(seconds, 10) || 0);
    if (left <= 0) { setBtn(label || '抽 一 次', false); return; }
    setBtn('冷却中（' + left + 's）', true);
    timer = setInterval(function () {
      left -= 1;
      if (left <= 0) {
        clearInterval(timer);
        timer = null;
        setBtn(label || '抽 一 次', false);
      } else {
        setBtn('冷却中（' + left + 's）', true);
      }
    }, 1000);
  }

  /* 只信服务端回传的 spendable（余额 - 底仓）。
     🔴 不能用 balance 填「可用积分」格 —— 那会显示成总余额，
     数字大得离谱、看着像抽奖没扣钱（底仓只有服务端知道，前端减不了）。 */
  function refreshWallet(spendable, draws) {
    if (draws !== null && draws !== undefined && elDraws) {
      elDraws.textContent = draws;
    }
    if (spendable !== null && spendable !== undefined && elSpendable) {
      elSpendable.textContent = spendable;
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
          '抽中 <span class="kcpResult-name">' + escapeHtml(j.label || '奖品') + '</span>，' +
          '获得 <span class="kcpResult-amount">+' + (j.amount || 0) + '</span> 积分',
          'win'
        );
        refreshWallet(j.spendable, j.draws);
        /* 🎬 先播动画、再起冷却 —— 顺序反了会出现「冷却已结束但动画还在播」，
           用户以为按钮坏了。反过来（动画压着冷却）才是对的：
           反正覆盖层吃掉点击，按钮可不可点无所谓。 */
        playAnim(j.label || '奖品', j.amount || 0);
        /* 冷却秒数由服务端给，不用猜 —— 猜错了按钮会提前可点，
           连点就绕过冷却。 */
        cooldown(j.cooldown !== undefined ? j.cooldown : 3, '抽 一 次');
        return;
      }

      /* 业务失败（422 = 已穿过 CSRF，说明功能本身是通的） */
      say(escapeHtml(j.message || '抽奖失败'), 'warn');
      refreshWallet(j.spendable, j.draws);
      /* 「次数与积分均不足」时按钮保持禁用，其余给 3 秒冷却 */
      if (/不足|没有|不足次数/.test(j.message || '')) {
        setBtn('次数与积分均不足', true);
      } else {
        cooldown(j.cooldown !== undefined ? j.cooldown : 3, '抽 一 次');
      }
    }).catch(function (e) {
      say('网络错误：' + escapeHtml(e.message), 'warn');
      setBtn('抽 一 次', false);
    }).then(function () {
      busy = false;
      btn.classList.remove('is-busy');
    });
  });

  /* 奖品 label 是管理员填的，innerHTML 前必须转义（第 6 条） */
  function escapeHtml(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
})();
