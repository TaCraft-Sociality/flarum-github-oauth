import app from 'flarum/forum/app';
import { extend as extendMethod, override } from 'flarum/common/extend';
import LogInButtons from 'flarum/forum/components/LogInButtons';

import { AUTH_PATH, ENABLED_ATTRIBUTE } from './src/common/constants';
import githubLogo from './src/forum/components/GithubIcon';
import { handleAuthRedirect } from './src/forum/handleAuthRedirect';

const ACCOUNT_ATTRIBUTE = 'isGithubOAuthAccount';
const SIGNUP_MARKER = 'github';

/**
 * True for the sign up modal opened by this extension: the account always gets
 * a server generated virtual address, so the email field is never shown.
 */
function emailManagedByProvider(attrs) {
  return attrs?.oauthProvider === SIGNUP_MARKER;
}

function logInUrl() {
  const baseUrl = String(app.forum.attribute('baseUrl') ?? '').replace(/\/+$/, '');
  const current = `${window.location.pathname}${window.location.search}`;
  const returnTo = current.startsWith(AUTH_PATH) ? '/' : current;

  return `${baseUrl}${AUTH_PATH}?returnTo=${encodeURIComponent(returnTo)}`;
}

function logInButton() {
  return (
    <a className="Button LogInButton LogInButton--github" href={logInUrl()}>
      {githubLogo('Button-icon GithubOAuth-icon')}
      <span className="Button-label">
        <span className="Button-labelText">{app.translator.trans('tacraft-sociality-github-oauth.forum.log_in_with')}</span>
      </span>
    </a>
  );
}

export const extend = [
  {
    extend() {
      extendMethod(LogInButtons.prototype, 'items', function (items) {
        if (!app.forum.attribute(ENABLED_ATTRIBUTE)) {
          return;
        }

        items.add('github-oauth', logInButton());
      });
      // Explain why the change-email / change-password controls are gone.
      extendMethod('flarum/forum/components/UserSecurityPage', 'settingsItems', function (items) {
        const user = this.user;

        if (!user || user.id() !== app.session.user?.id() || !app.forum.attribute(ENABLED_ATTRIBUTE)) {
          return;
        }
        if (user.attribute(ACCOUNT_ATTRIBUTE) === true) {
          items.add(
            'oauthAccountNotice',
            (
              <div className="Alert Alert--warning" style={{ marginBottom: '20px' }}>
                {'本账号的登录方式由注册时使用的第三方平台（QQ、GitHub、微软、Tcsskin）决定，系统不支持换绑或解绑。如需更换登录方式，请联系管理员。'}
              </div>
            ),
            101
          );
        }
      });

      // The email GitHub returned is already verified and locked: remove the
      // (read-only) email field from the sign up modal.
      extendMethod('flarum/forum/components/SignUpModal', 'fields', function (items) {
        if (emailManagedByProvider(this.attrs)) {
          items.remove('email');
        }
      });

      // ... and stop the empty value from being submitted with the form.
      override('flarum/forum/components/SignUpModal', 'submitData', function (original) {
        const data = original();

        if (emailManagedByProvider(this.attrs)) {
          delete data.email;
        }

        return data;
      });

      // Accounts created through GitHub keep the email and password managed
      // by the provider, so hide the self-service change controls.
      extendMethod('flarum/forum/components/SettingsPage', 'accountItems', function (items) {
        const user = app.session.user;

        if (user && user.attribute(ACCOUNT_ATTRIBUTE) === true) {
          items.remove('changePassword');
          items.remove('changeEmail');
        }
      });

      app.beforeMount(() => {
        void handleAuthRedirect();
      });
    },
  },
];
