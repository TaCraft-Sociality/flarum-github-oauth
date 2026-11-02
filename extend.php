<?php

declare(strict_types=1);

namespace TacraftSociality\GithubOAuth;

use Flarum\Api\Context;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema\Boolean;
use Flarum\Extend;
use Flarum\Foundation\ValidationException;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\Saving;
use Flarum\User\LoginProvider;
use Flarum\User\RegistrationToken;
use Flarum\User\User;
use TacraftSociality\GithubOAuth\Forum\Controllers\GithubAuthController;
use TacraftSociality\OauthCore\Support\FakeEmail;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('forum'))
        ->get('/github-oauth/callback', 'github-oauth.callback', GithubAuthController::class),


    // Lets the forum JS know that this account was created through GitHub, so
    // the self-service email/password controls can be hidden for it.
    (new Extend\ApiResource(UserResource::class))
        ->fields(fn (): array => [
            Boolean::make('isGithubOAuthAccount')
                ->visible(fn (User $user, Context $context): bool => $context->getActor()->id === $user->id)
                ->get(fn (User $user): bool => $user->exists && LoginProvider::query()
                    ->where('user_id', $user->id)
                    ->where('provider', 'github')
                    ->exists()),
        ]),

    (new Extend\Settings())
        ->default(Settings::CLIENT_ID, '')
        ->default(Settings::CLIENT_SECRET, '')
        ->serializeToForum('githubOAuthEnabled', Settings::CLIENT_ID, function ($value): bool {
            if (! is_string($value) || trim($value) === '') {
                return false;
            }

            $secret = resolve(SettingsRepositoryInterface::class)->get(Settings::CLIENT_SECRET, '');

            return is_string($secret) && trim($secret) !== '';
        }),

    (new Extend\Event())
        ->listen(Saving::class, function (Saving $event) {
            $user = $event->user;

            if ($user->exists) {
                return;
            }

            $token = null;
            $tokenString = $event->data['attributes']['token'] ?? null;

            if (is_string($tokenString) && $tokenString !== '') {
                try {
                    $token = RegistrationToken::validOrFail($tokenString);
                } catch (\Throwable) {
                    $token = null;
                }
            }

            if ($token === null || $token->provider !== 'github') {
                return;
            }

            // GitHub signups never carry a usable email: the address is
            // derived from the immutable GitHub user id here, on the server,
            // and never leaves it. The same derivation runs on the skin site,
            // so one GitHub player maps to exactly one account everywhere.
            $identifier = (string) $token->identifier;

            if ($identifier === '') {
                throw new ValidationException([
                    'email' => '当前 GitHub 账号没有返回可用的身份标识，无法完成登录。',
                ]);
            }

            // The address suffix comes from OAuth Core (admin setting), and
            // defaults to the RFC 2606 reserved `.invalid` TLD, which never
            // resolves and can never receive mail.
            $domain = FakeEmail::domain();

            $user->email = FakeEmail::onDomain(
                FakeEmail::assignUnique(FakeEmail::forIdentity('github', $identifier, $domain), 'github'),
                $domain
            );

            $user->activate();
        })
        // Accounts that signed up through GitHub keep the email and password
        // managed by the provider: block self-service changes server-side, so
        // the UI being hidden cannot be bypassed.
        ->listen(Saving::class, function (Saving $event) {
            $user = $event->user;

            if (! $user->exists) {
                return;
            }

            if ($event->actor->id !== $user->id) {
                return;
            }

            $isGithubAccount = LoginProvider::query()
                ->where('user_id', $user->id)
                ->where('provider', 'github')
                ->exists();

            if (! $isGithubAccount) {
                return;
            }

            $attributes = (array) ($event->data['attributes'] ?? []);
            $requestedEmail = $attributes['email'] ?? null;
            $currentEmail = (string) $user->getOriginal('email');

            if (is_string($requestedEmail) && $requestedEmail !== '' && strcasecmp($requestedEmail, $currentEmail) !== 0) {
                throw new ValidationException([
                    'email' => '当前账号通过 GitHub 登录，暂不支持自助修改邮箱。',
                ]);
            }

            if (array_key_exists('password', $attributes) || $user->isDirty('password')) {
                throw new ValidationException([
                    'password' => '当前账号通过 GitHub 登录，暂不支持设置登录密码。',
                ]);
            }
        }),
];
