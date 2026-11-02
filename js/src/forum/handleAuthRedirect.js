import app from 'flarum/forum/app';

import { AUTH_ERROR_PARAM, AUTH_MARKER_PARAM, AUTH_TOKEN_PARAM, REGISTRATION_TOKEN_ENDPOINT } from '../common/constants';

const ERROR_CODES = [
  'not_configured',
  'cancelled',
  'invalid_state',
  'login_required',
  'already_linked',
  'token_error',
  'profile_error',
  'email_error',
  'provider_error',
  'network_error',
  'invalid_response',
  'unknown',
];

export async function handleAuthRedirect() {
  const params = new URLSearchParams(window.location.search);
  const token = params.get(AUTH_TOKEN_PARAM);
  const error = params.get(AUTH_ERROR_PARAM);
  const marker = params.get(AUTH_MARKER_PARAM);

  if (error) {
    params.delete(AUTH_ERROR_PARAM);
    stripQueryFromUrl(params);
    showError(error);

    return;
  }

  if (token && marker) {
    params.delete(AUTH_TOKEN_PARAM);
    params.delete(AUTH_MARKER_PARAM);
    stripQueryFromUrl(params);

    await openSignUpModal(token);

    return;
  }

  if (marker) {
    params.delete(AUTH_MARKER_PARAM);
    stripQueryFromUrl(params);
  }
}

async function openSignUpModal(token) {
  let attrs = { token };

  try {
    const data = await app.request({
      method: 'POST',
      url: `${app.forum.attribute('apiUrl')}${REGISTRATION_TOKEN_ENDPOINT}`,
      body: { token },
      errorHandler: () => undefined,
    });

    attrs = {
      token,
      // Marker so forum.js only touches the sign up modal opened by this
      // extension when several OAuth extensions are installed.
      oauthProvider: 'github',
      username: data?.username ?? '',
      provided: Array.isArray(data?.provided) ? data.provided : [],
    };
  } catch {
    showError('invalid_state');

    return;
  }

  setTimeout(() => {
    app.modal.show(() => import('flarum/forum/components/SignUpModal'), attrs);
  }, 0);
}

function stripQueryFromUrl(params) {
  const search = params.toString();
  const url = `${window.location.pathname}${search ? `?${search}` : ''}${window.location.hash}`;

  window.history.replaceState(window.history.state, '', url);
}

function showError(code) {
  const key = ERROR_CODES.includes(code) ? code : 'unknown';

  setTimeout(() => {
    app.alerts.show({ type: 'error' }, app.translator.trans(`tacraft-sociality-github-oauth.forum.errors.${key}`));
  }, 0);
}

