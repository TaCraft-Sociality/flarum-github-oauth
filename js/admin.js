import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';

import { CLIENT_ID_SETTING, CLIENT_SECRET_SETTING } from './src/common/constants';

function trans(key, params) {
  const id = `tacraft-sociality-github-oauth.admin.settings.${key}`;

  return params === undefined ? app.translator.trans(id) : app.translator.trans(id, params);
}

function callbackUrl() {
  const baseUrl = String(app.forum.attribute('baseUrl') ?? '').replace(/\/+$/, '');

  return `${baseUrl}/github-oauth/callback`;
}

export const extend = [
  new Extend.Admin()
    .setting(() => ({
      setting: CLIENT_ID_SETTING,
      type: 'text',
      label: trans('client_id_label'),
      help: trans('client_id_help'),
      placeholder: 'Iv1.0123456789abcdef',
    }))
    .setting(() => ({
      setting: CLIENT_SECRET_SETTING,
      type: 'text',
      label: trans('client_secret_label'),
      help: trans('callback_help', { url: callbackUrl() }),
    })),
];
