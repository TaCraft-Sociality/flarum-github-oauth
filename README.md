# GitHub OAuth

用 GitHub 账号登录 Flarum 2 论坛。

玩家点击登录页的「GitHub 登录」按钮，完成 GitHub 授权后自动创建或进入论坛账号，不需要另外填写邮箱和密码。

需要先安装 [OAuth Core](../flarum-oauth-core)。

## 安装

1. 把 `flarum-github-oauth` 目录放到论坛的 `packages`（或你放插件的目录）下。
2. 在 `composer.json` 的 `repositories` 里确认已经配置了该目录的 path 仓库，然后执行：

```bash
composer require tacraft-sociality/flarum-github-oauth:*
```

3. 登录后台，在「扩展」里启用 **GitHub OAuth**，并填写 GitHub OAuth App 的 Client ID 与 Client Secret。
4. 在 GitHub OAuth App 里把回调地址设置为：

```
https://你的论坛域名/github-oauth/callback
```

5. 执行：

```bash
php flarum cache:clear
```

## 更新

覆盖插件目录后执行：

```bash
composer update tacraft-sociality/flarum-github-oauth
php flarum cache:clear
```

前端有改动时还需要在插件目录的 `js/` 下重新构建：

```bash
npm ci
npm run build
```

## 依赖

- Flarum `^2.0.2`
- PHP `^8.3`
- [flarum-oauth-core](../flarum-oauth-core)

## 许可

MIT