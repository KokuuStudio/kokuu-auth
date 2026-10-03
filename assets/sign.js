/* KokuuCredit —— 签到按钮
 *
 * 为什么本插件要自己实现签到按钮：
 *   内核的签到 UI 是 React 组件（SignButton.tsx），它被挂在用户中心
 *   原生的 usage widget 里。我们用 grid:user.index / grid:admin.index
 *   接管了整块仪表盘后，那个原生 widget 被整体替换掉了，
 *   签到按钮就跟着消失了 —— 只剩我们自己渲染的状态文字。
 *   用户反馈「签到的按钮没了，现在只显示上次什么时候签到的」正是此因。
 *
 * 怎么做：
 *   不重新发明轮子，直接复用内核**已有**的接口：
 *     GET  /user/score-info   → { user:{score,lastSignAt}, signAfterZero, signGapTime }
 *     POST /user/sign         → code 0 成功（带新 score），1 表示还不能签到
 *   判定「能否签到」的逻辑与内核 scoreUtils.ts 保持一致：
 *     - sign_after_zero=true ：上次签到早于今天 0 点 → 可签；否则等到明天 0 点
 *     - sign_after_zero=false：lastSign + signGap 小时
 *
 * ⚠️ 接口返回的 `code` 是**业务码不是 HTTP 状态**：
 *   失败也返回 HTTP 200，必须判 body.code。
 *
 * ⚠️ 不用 element.click() 那套测试思路来验证这里 —— 真实交互才行。
 *
 * ⚠️ 首屏 HTML 里已经渲染了按钮与 disabled 态（见 credit.twig），
 *   本脚本只做「校正 + 接管点击」：
 *   即使 JS 因任何原因没跑，用户看到的也是一个静态但正确的状态，
 *   而不是一片空白。
 */
(function () {
  'use strict';

  var ONE_MINUTE = 60 * 1000;
  var ONE_HOUR = 60 * ONE_MINUTE;
  var ONE_DAY = 24 * ONE_HOUR;

  var SEL = '.kcDash-sign';

  /* ---------- 工具 ---------- */

  function toast(type, msg) {
    try {
      var n = window.blessing && window.blessing.notify;
      if (n && n.toast && typeof n.toast[type] === 'function') {
        n.toast[type](msg);
        return;
      }
    } catch (e) {
      /* toast 不可用时静默降级，绝不因为提示组件缺失让签到本身失败 */
    }
  }

  /* 内核 json() 返回体永远是 { code, message, data } 形状，
   * 但为稳妥起见，两种形状都接受。 */
  function readCode(body) {
    if (body == null) {
      return -1;
    }
    if (typeof body.code === 'number') {
      return body.code;
    }
    if (typeof body.code === 'string' && body.code !== '') {
      return parseInt(body.code, 10);
    }
    return -1;
  }

  function readMessage(body) {
    if (body && typeof body.message === 'string' && body.message) {
      return body.message;
    }
    return '';
  }

  /* ---------- 时间计算（对齐 scoreUtils.ts） ---------- */

  function remainingMs(lastSignMs, signGapHours, afterZero) {
    if (!isFinite(lastSignMs) || lastSignMs <= 0) {
      /* 没有签到记录 → 随时可签 */
      return 0;
    }

    if (afterZero) {
      var today = new Date().setHours(0, 0, 0, 0);
      var tomorrow = today + ONE_DAY;
      /* 上次签到早于今天 0 点 → 已经跨天，可签 */
      return lastSignMs < today ? 0 : tomorrow - Date.now();
    }

    return lastSignMs + signGapHours * ONE_HOUR - Date.now();
  }

  function remainText(ms) {
    var mins = ms / ONE_MINUTE;
    if (mins < 1) {
      return '不足 1 分钟';
    }
    if (mins < 60) {
      return Math.floor(mins) + ' 分钟';
    }
    return Math.floor(mins / 60) + ' 小时';
  }

  function parseTime(str) {
    if (!str) {
      return 0;
    }
    var t = Date.parse(str);
    return isFinite(t) ? t : 0;
  }

  /* ---------- 渲染 ---------- */

  /**
   * 把整条签到区刷成目标状态。
   * 三种状态：可签到 / 已签到（倒计时）/ 提交中。
   */
  function render(box, state) {
    var btn = box.querySelector('.kcDash-sign-btn');
    var btnText = box.querySelector('.kcDash-sign-btn-text');
    var icon = box.querySelector('.kcDash-sign-icon i');
    var text = box.querySelector('.kcDash-sign-text');
    if (!btn || !btnText) {
      return;
    }

    box.classList.toggle('is-done', !!state.done);
    box.classList.toggle('is-todo', !state.done);
    box.dataset.kcSign = state.done ? 'done' : 'todo';

    if (state.done) {
      if (icon) {
        icon.className = 'fas fa-check';
      }
      if (text && state.statusText) {
        text.textContent = state.statusText;
      }
      btnText.textContent = state.remain && state.remain > 0
        ? remainText(state.remain) + '后可签'
        : '已签到';
      btn.setAttribute('disabled', 'disabled');
    } else {
      if (icon) {
        icon.className = 'fas fa-calendar-check';
      }
      if (text && state.statusText) {
        text.textContent = state.statusText;
      }
      btnText.textContent = '签到';
      btn.removeAttribute('disabled');
    }

    btn.classList.toggle('is-loading', !!state.loading);
  }

  /* ---------- 状态拉取 ---------- */

  function fetchInfo() {
    return fetch('/user/score-info', {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(function (r) {
      if (!r.ok) {
        throw new Error('score-info HTTP ' + r.status);
      }
      return r.json();
    });
  }

  /**
   * 依据 score-info 的权威数据判定状态并渲染。
   * 页面里 twig 渲染的初值可能因页面缓存而过期，
   * 所以拿到真实数据后一律以它为准。
   */
  function applyInfo(box, info) {
    var user = (info && info.user) || {};
    var lastMs = parseTime(user.lastSignAt);
    var afterZero = !!info.signAfterZero;
    var gap = parseInt(info.signGapTime, 10);
    if (!isFinite(gap)) {
      gap = 24;
    }

    var remain = remainingMs(lastMs, gap, afterZero);
    var canSign = remain <= 0;

    box.__kcSignGap = gap;
    box.__kcSignAfterZero = afterZero;
    box.__kcSignLast = lastMs;

    if (canSign) {
      render(box, { done: false, statusText: '还没签到，点右边领取今日积分' });
      scheduleCountdown(box);
      return;
    }

    var at = lastMs ? new Date(lastMs) : null;
    var hhmm = at
      ? ('0' + at.getHours()).slice(-2) + ':' + ('0' + at.getMinutes()).slice(-2)
      : '';
    render(box, {
      done: true,
      remain: remain,
      statusText: hhmm ? '今日已签到 · ' + hhmm : '今日已签到',
    });
    scheduleCountdown(box);
  }

  /* 倒计时到期后自动把按钮恢复成可点 —— 用户挂着页面跨过
   * 可签到时刻时不需要手动刷新。 */
  function scheduleCountdown(box) {
    if (box.__kcTimer) {
      clearTimeout(box.__kcTimer);
      box.__kcTimer = null;
    }
    if (!box.classList.contains('is-done')) {
      return;
    }
    var gap = box.__kcSignGap;
    var afterZero = box.__kcSignAfterZero;
    var last = box.__kcSignLast;
    var remain = remainingMs(last, gap, afterZero);
    if (remain <= 0) {
      return;
    }
    /* 上限 60s 轮一次：跨天用 after_zero 时直接等到明天的毫秒数会很大，
     * setTimeout 超过约 24.8 天会溢出，分片刷新更稳且能顺带兜住时间漂移。 */
    var delay = Math.min(remain + 1200, 60000);
    box.__kcTimer = setTimeout(function () {
      applyInfo(box, {
        user: { lastSignAt: last ? new Date(last).toISOString() : null },
        signAfterZero: afterZero,
        signGapTime: gap,
      });
    }, delay);
  }

  /* ---------- 签到动作 ---------- */

  function doSign(box) {
    var btn = box.querySelector('.kcDash-sign-btn');
    if (!btn || btn.hasAttribute('disabled') || btn.classList.contains('is-loading')) {
      return;
    }
    btn.classList.add('is-loading');
    btn.setAttribute('disabled', 'disabled');

    fetch('/user/sign', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
      },
    })
      .then(function (r) {
        if (!r.ok) {
          throw new Error('HTTP ' + r.status);
        }
        return r.json();
      })
      .then(function (body) {
        var code = readCode(body);
        var msg = readMessage(body);
        if (code === 0) {
          toast('success', msg || '签到成功');
          /* 签到成功 → 重新拉权威状态（新 score、新 lastSignAt） */
          refresh(box);
          refreshScoreDisplay(box);
          return;
        }
        /* code 1：还没到可签时间；code 2：被插件拒绝（can_sign filter） */
        if (msg) {
          toast('info', msg);
        } else {
          toast('warning', '暂时还不能签到，请稍后再试');
        }
        refresh(box);
      })
      .catch(function (err) {
        console.warn('[kokuu-credit] 签到失败', err);
        toast('error', '签到失败，请刷新页面后重试');
        refresh(box);
      });
  }

  function refreshScoreDisplay(box) {
    /* 余额数字就在同一张卡里，签到后 +N，顺手刷一下，
     * 免得用户看到「签到成功」但余额没动而困惑。
     * 这里只读 score-info，不改账本。 */
    fetchInfo()
      .then(function (info) {
        var num = document.querySelector('.kcDash-balance-num');
        if (num && info && info.user && typeof info.user.score !== 'undefined') {
          num.textContent = info.user.score;
        }
      })
      .catch(function () {
        /* 余额刷新失败不影响签到结果本身，静默 */
      });
  }

  function refresh(box) {
    fetchInfo()
      .then(function (info) {
        applyInfo(box, info);
      })
      .catch(function (err) {
        console.warn('[kokuu-credit] 签到状态刷新失败', err);
        /* 拉不到就退回页面初值，至少不是坏状态 */
        var btn = box.querySelector('.kcDash-sign-btn');
        if (btn) {
          btn.classList.remove('is-loading');
          if (box.dataset.kcSign === 'done') {
            btn.setAttribute('disabled', 'disabled');
          } else {
            btn.removeAttribute('disabled');
          }
        }
      });
  }

  /* ---------- CSRF ---------- */

  function csrfToken() {
    /* 内核是 Laravel，CSRF 走 meta 或 XSRF-TOKEN cookie。
     * 两者都没有时返回空串，让服务端自己决定（419 会被 catch 到）。 */
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.getAttribute('content')) {
      return meta.getAttribute('content');
    }
    var m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    if (m) {
      try {
        return decodeURIComponent(m[1]);
      } catch (e) {
        return m[1];
      }
    }
    return '';
  }

  /* ---------- 绑定 ---------- */

  function init() {
    var boxes = document.querySelectorAll(SEL);
    if (!boxes.length) {
      return;
    }

    Array.prototype.forEach.call(boxes, function (box) {
      if (box.__kcSignBound) {
        return;
      }
      box.__kcSignBound = true;

      /* 事件委托：按钮由我们自己渲染，直接挂在容器上更稳 */
      box.addEventListener('click', function (ev) {
        var btn = ev.target.closest && ev.target.closest('.kcDash-sign-btn');
        if (btn && box.contains(btn)) {
          ev.preventDefault();
          doSign(box);
        }
      });

      /* 拉到权威状态校正首屏渲染 */
      refresh(box);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
