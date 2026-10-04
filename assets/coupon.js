/* KokuuSkin 兑换码前端
 *
 * ⚠️ 本文件**只管兑换码**。抽奖已移到独立页 plugin/kokuu-coupon/draw，
 *    它的交互在 assets/draw.js 里 —— 别把抽奖逻辑再搬回来。
 *    （用户原话：「普通用户的兑换码页面里面不需要一套抽奖了，
 *      只需要有个输入兑换码的功能和兑换记录功能就可以了」。之前这里是
 *      页面内抽奖 + 仪表盘 widget 抽奖两套 UI 打同一个接口，纯重复。）
 *
 * ============================ 踩过的坑（写之前必读） ============================
 * ① 🔴 **CSRF 必须带**：路由挂了 `web` 中间件（含 VerifyCsrfToken），
 *    fetch 不带 X-XSRF-TOKEN → 100% 报 419「CSRF token mismatch.」。
 *    这个 bug 真实发生过：headers 块里只有 Content-Type 和 X-Requested-With，
 *    源码读起来完全正常，线上却是 24 次 419。
 *    值取自 XSRF-TOKEN cookie，**只 decodeURIComponent，不要剥 CookieValuePrefix**。
 *
 * ② 🔴 **419 与 422 语义相反**：
 *    419 = CSRF 层拦截（请求没到业务）；422 = 业务失败（已穿过 CSRF）。
 *    → 验「通不通」看非 419；前端也要把 419 单独翻译成「请刷新页面」，
 *      否则只会显示「服务器返回异常（HTTP 419）」，用户不知道要刷新。
 *
 * ③ 🔴 **提交时实时读 cookie**，不用页面加载时的快照：
 *    session/token 会随请求轮换，用旧值必 419。
 *
 * ④ 余额由后端算好后注入，前端只做展示，不重复推导。
 */
'use strict';

var URL_REDEEM = window.CPN_URL_REDEEM;
var CSRF       = window.CPN_CSRF || readCookie('XSRF-TOKEN');

var $ = function (id) { return document.getElementById(id); };

/* 从 document.cookie 取值并解码。
 * ⚠️ 只 decodeURIComponent，**不要**剥 CookieValuePrefix ——
 *   Laravel 服务端会自己剥，前端剥了反而对不上。 */
function readCookie(name) {
  var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]+)'));
  return m ? decodeURIComponent(m[1]) : '';
}

/* 统一的提示条 */
var elResult = $('cp-result');
function say(text, kind) {
  if (!elResult) { return; }
  elResult.hidden = false;
  elResult.className = 'cp-result ' + (kind || 'info');
  elResult.innerHTML = '';
  elResult.appendChild(document.createTextNode(text));
}

var elCode  = $('cp-code');
var elRedeem = $('cp-redeem');

/* 统一 fetch：带 CSRF、把 419 翻译成人话 */
function post(url, fields) {
  var body = new URLSearchParams();
  Object.keys(fields || {}).forEach(function (k) {
    body.set(k, String(fields[k]));
  });

  return fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      'X-Requested-With': 'XMLHttpRequest',
      /* 提交时再读一次 cookie，不复用加载时的快照 */
      'X-XSRF-TOKEN': readCookie('XSRF-TOKEN') || CSRF
    },
    body: body.toString()
  }).then(function (r) {
    /* 419 = CSRF 校验失败，响应体是 HTML 错误页不是 JSON，必须单独翻译 */
    if (r.status === 419) {
      return { code: 1, status: 419, message: '登录状态已过期，请刷新页面后重试' };
    }
    return r.json().catch(function () {
      return { code: 1, status: r.status, message: '服务器返回异常（HTTP ' + r.status + '）' };
    }).then(function (j) {
      j.status = r.status;
      return j;
    });
  });
}

/* CSRF cookie 读不到就别发那个必失败的请求 */
function csrfMissing() {
  if (!CSRF && !readCookie('XSRF-TOKEN')) {
    say('登录状态已过期，请刷新页面后重试', 'err');
    return true;
  }
  return false;
}

/* ---------- 兑换 ---------- */
if (elRedeem) {
  elRedeem.addEventListener('click', function () {
    var code = elCode ? elCode.value.trim() : '';
    if (!code) {
      say('请输入兑换码', 'err');
      return;
    }
    if (csrfMissing()) { return; }

    elRedeem.disabled = true;
    say('兑换中…', 'info');

    post(URL_REDEEM, { code: code }).then(function (j) {
      elRedeem.disabled = false;
      if (j && j.code === 0) {
        say(j.message + (j.balance !== null && j.balance !== undefined ? '（当前 ' + j.balance + ' 积分）' : ''), 'ok');
        if (elCode) { elCode.value = ''; }
        /* 结果变了就刷新拿最新数据，别让页面停在旧数字上 */
        setTimeout(function () { location.reload(); }, 1500);
      } else {
        say((j && j.message) || '兑换失败', 'err');
        if (j && j.status === 419) { elRedeem.disabled = true; }
      }
    }).catch(function (e) {
      elRedeem.disabled = false;
      say('网络错误：' + e.message, 'err');
    });
  });

  /* 回车直接兑换 */
  if (elCode) {
    elCode.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { elRedeem.click(); }
    });
  }
}
