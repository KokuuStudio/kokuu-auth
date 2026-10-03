@extends('admin._base')

{{--
  配置页的所有数据都**直接调函数取实时值**，不用 View::share 传进来的快照。

  原因：Blessing Skin 的 Plugin::getConfigView() 是 view()->file($path)，
  不传参；share 是插件启动时的快照，管理员改完配置再刷新页面会看到旧值。
  函数调用每次都走 options 实时读取（options 有文件缓存，管理员保存时已经
  @unlink 掉 storage/options.php），拿到的一定是最新值。
--}}
@php
  $cfg = kokuu_credit_config();
  $stats = kokuu_credit_stats();
  $sso = kokuu_sso_config();
@endphp

@section('content')
<div class="box box-solid">
  <div class="box-header with-border">
    <h3 class="box-title">KokuuSkin 论坛积分 · 配置</h3>
  </div>
  <div class="box-body">
    <div id="kc-root"
         data-config='@json($cfg, JSON_UNESCAPED_UNICODE)'
         data-stats='@json($stats)'
         data-sso='@json($sso, JSON_UNESCAPED_UNICODE)'
         data-url-settings="{{ url('/plugin/kokuu-credit/settings') }}"
         data-url-ledger="{{ url('/plugin/kokuu-credit/api/ledger') }}"
         data-url-selftest="{{ url('/plugin/kokuu-credit/api/selftest') }}"
         data-url-sso="{{ url('/plugin/kokuu-credit/sso') }}"
         data-url-sso-status="{{ url('/plugin/kokuu-credit/api/sso-status') }}"
         data-url-sso-links="{{ url('/plugin/kokuu-credit/api/sso-links') }}">

      <form id="kc-form">
        <div class="form-group">
          <label class="col-sm-3 control-label">论坛地址</label>
          <div class="col-sm-8">
            <input class="form-control" name="forum_url" value="{{ $cfg['forum_url'] }}">
            <p class="help-block">Flarum 的 url（config.php 里的 url），用于自检时反查论坛接口。</p>
          </div>
        </div>

        <div class="form-group">
          <label class="col-sm-3 control-label">HMAC 密钥</label>
          <div class="col-sm-8">
            <input class="form-control" name="secret" type="password" value="" autocomplete="off"
                   placeholder="{{ $cfg['secret'] === '' ? '未设置' : '已设置（留空则不修改）' }}">
            <p class="help-block">
              与论坛端扩展配置的密钥必须完全一致。留空保存不会覆盖已设置的密钥。
            </p>
          </div>
        </div>

        <div class="form-group">
          <label class="col-sm-3 control-label">发起主题得分</label>
          <div class="col-sm-3">
            <input class="form-control" name="score_thread" type="number" min="0" value="{{ $cfg['score_thread'] }}">
          </div>
          <label class="col-sm-3 control-label">回复帖子得分</label>
          <div class="col-sm-3">
            <input class="form-control" name="score_reply" type="number" min="0" value="{{ $cfg['score_reply'] }}">
          </div>
        </div>

        <div class="form-group">
          <label class="col-sm-3 control-label">帖子被赞得分</label>
          <div class="col-sm-3">
            <input class="form-control" name="score_liked" type="number" min="0" value="{{ $cfg['score_liked'] }}">
          </div>
          <label class="col-sm-3 control-label">单次加分上限</label>
          <div class="col-sm-3">
            <input class="form-control" name="max_per_event" type="number" min="1" value="{{ $cfg['max_per_event'] }}">
          </div>
        </div>

        <div class="form-group">
          <label class="col-sm-3 control-label">每日加分上限</label>
          <div class="col-sm-3">
            <input class="form-control" name="max_per_day" type="number" min="0" value="{{ $cfg['max_per_day'] }}">
          </div>
          <label class="col-sm-3 control-label">签名有效期（秒）</label>
          <div class="col-sm-3">
            <input class="form-control" name="ttl" type="number" min="30" max="3600" value="{{ $cfg['ttl'] }}">
          </div>
        </div>
      </form>

      <div class="row" style="margin-top: 12px;">
        <div class="col-sm-3">
          <button class="btn btn-primary btn-block" id="kc-save">
            <i class="fa fa-save"></i> 保存配置
          </button>
        </div>
        <div class="col-sm-6">
          <div class="input-group">
            <input class="form-control" id="kc-email" type="email"
                   placeholder="用皮肤站账号邮箱做全链路自检">
            <span class="input-group-btn">
              <button class="btn btn-default" id="kc-selftest">
                <i class="fa fa-plug"></i> 自检
              </button>
            </span>
          </div>
        </div>
      </div>
    </div>

    <hr>
    <h4>最近发放</h4>
    <div id="kc-ledger">加载中…</div>

    <hr>

    {{-- ============ 账号互通（SSO） ============ --}}
    <h4>账号互通（OAuth2）</h4>
    <p class="help-block">
      皮肤站作为授权服务器，Flarum 作为客户端。用户点论坛登录页的「使用皮肤站账号登录」
      后会被引导到这里，授权通过即自动登录论坛 —— 密码始终只由皮肤站掌握，
      论坛侧不保存任何密码。
    </p>

    <form id="kc-sso-form">
      <div class="form-group">
        <div class="col-sm-offset-3 col-sm-8">
          <label>
            <input type="checkbox" name="enabled" id="kc-sso-enabled"
                   {{ !empty($sso['enabled']) ? 'checked' : '' }}>
            启用账号互通
          </label>
        </div>
      </div>

      <div class="form-group">
        <div class="col-sm-offset-3 col-sm-8">
          <label>
            <input type="checkbox" name="auto_register" id="kc-sso-autoreg"
                   {{ !empty($sso['auto_register']) ? 'checked' : '' }}>
            首次登录自动在论坛建号
          </label>
          <p class="help-block">
            皮肤站用户在论坛还没有同邮箱账号时，是否自动创建一个。
            关闭后只有「论坛已存在同邮箱账号」的人能登录。
            自动建的账号密码为空，无法用论坛原生登录表单独立登录。
          </p>
        </div>
      </div>

      <div class="form-group">
        <label class="col-sm-3 control-label">论坛回调地址</label>
        <div class="col-sm-8">
          <input class="form-control" name="forum_callback"
                 value="{{ $sso['forum_callback'] ?? '' }}"
                 placeholder="https://chat.kokuu.org/auth/kokuu/callback">
          <p class="help-block">
            必须与 Flarum 扩展里的回调地址**逐字一致**（差一个斜杠都会报 redirect_uri mismatch）。
            改这个地址后，OAuth 客户端的 redirect 也会同步更新。
          </p>
        </div>
      </div>
    </form>

    <div class="row" style="margin-top: 8px;">
      <div class="col-sm-3">
        <button class="btn btn-primary btn-block" id="kc-sso-save">
          <i class="fa fa-save"></i> 保存互通配置
        </button>
      </div>
      <div class="col-sm-6">
        <span id="kc-sso-state" class="help-block" style="display:inline-block;padding-top:8px;">
          客户端状态加载中…
        </span>
      </div>
    </div>

    <div id="kc-sso-detail" style="margin-top: 14px;"></div>

    <h4 style="margin-top: 20px;">最近绑定</h4>
    <div id="kc-sso-links">加载中…</div>
  </div>
</div>
@endsection
