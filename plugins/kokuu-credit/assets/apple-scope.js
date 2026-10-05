/* KokuuCredit —— 给插件自有页面补上 kokuu-apple 作用域类
 *
 * 背景：
 *   kokuu-ui 的 apple.css 所有作用域规则都写成 `body.kokuu-apple xxx`，
 *   那个类是 rebuild.js 在运行时加到 body 上的。
 *   但 rebuild.js 只注入 user / admin / skinlib 三个前缀的页面，
 *   **不含 plugin/** —— 于是 /plugin/kokuu-credit/ledger
 *   这类插件自有页面上，apple 规则一行都不生效，
 *   页面退回内核的紫渐变背景 + 虚空深色表格。
 *
 * 做什么：
 *   只把 `kokuu-apple` 加到 body 上，让 apple.css 的作用域规则生效。
 *   bootstrap.php 里配套注入了 apple.css 本体。
 *
 * ⚠️ 刻意**不**做的事：
 *   不复制 rebuild.js 的任何行为（侧栏折叠、进场 stagger 动画）——
 *   那是 kokuu-ui 的职责。两边都往同一个 localStorage key 写状态会打架，
 *   表现为折叠状态在两类页面之间互相覆盖。
 */
(function () {
  'use strict';

  var BODY_CLASS = 'kokuu-apple';

  function mark() {
    if (document.body && !document.body.classList.contains(BODY_CLASS)) {
      document.body.classList.add(BODY_CLASS);
    }
  }

  /*
   * 加类要在样式重算之前完成，否则会闪一下紫底再跳成浅灰。
   * rebuild.js 用的是「DOMContentLoaded 或立即执行」，
   * 这里照抄同一个时机，行为一致。
   */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mark);
  } else {
    mark();
  }
})();
