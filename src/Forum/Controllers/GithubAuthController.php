<?php

declare(strict_types=1);

namespace TacraftSociality\GithubOAuth\Forum\Controllers;

use Flarum\Forum\Auth\Registration;
use Flarum\Forum\Auth\ResponseFactory;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\LoginProvider;
use Flarum\User\User;
use Illuminate\Session\Store;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TacraftSociality\GithubOAuth\Github\GithubClient;
use TacraftSociality\GithubOAuth\Github\GithubOAuthException;
use TacraftSociality\GithubOAuth\Settings;

class GithubAuthController implements RequestHandlerInterface
{
    private const PROVIDER = 'github';

    private const SESSION_STATE = 'github_oauth_state';

    private const SESSION_RETURN_TO = 'github_oauth_return_to';

    private const SESSION_LINK = 'github_oauth_link';

    private const SESSION_LINK_USER = 'github_oauth_link_user';

    public const SESSION_VERIFIED_EMAIL = 'github_oauth_verified_email';

    public const ERROR_PARAM = '_flarum_github_error';

    public const MARKER_PARAM = '_flarum_github';

    public const LINKED_PARAM = '_flarum_github_linked';

    public function __construct(
        protected ResponseFactory $response,
        protected SettingsRepositoryInterface $settings,
        protected UrlGenerator $url,
        protected LoggerInterface $logger
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Store $session */
        $session = $request->getAttribute('session');
        $query = $request->getQueryParams();
        $actor = RequestUtil::getActor($request);

        $returnTo = $this->resolveReturnTo($request, $session);
        $linking = $this->isLinking($query);
        $linkInProgress = $session->get(self::SESSION_LINK) === true;
        $linkUserId = $session->get(self::SESSION_LINK_USER);

        // Accounts are bound to the provider they signed up with: linking and
        // unlinking are no longer supported, so a signed in user has nothing
        // to do here.
        if ($actor->exists) {
            $this->forgetFlow($session);

            return new RedirectResponse($returnTo);
        }

        if ($linking && ! $actor->exists) {
            $this->forgetFlow($session);

            return $this->errorResponse($returnTo, 'login_required');
        }

        if (isset($query['error'])) {
            $this->forgetFlow($session);

            return $this->errorResponse($returnTo, 'cancelled');
        }

        $clientId = trim((string) $this->settings->get(Settings::CLIENT_ID, ''));
        $clientSecret = trim((string) $this->settings->get(Settings::CLIENT_SECRET, ''));

        if ($clientId === '' || $clientSecret === '') {
            return $this->errorResponse($returnTo, 'not_configured');
        }

        $client = new GithubClient($clientId, $clientSecret);
        $redirectUri = $this->url->to('forum')->route('github-oauth.callback');
        $code = $query['code'] ?? null;

        if (! is_string($code) || $code === '') {
            if (isset($query['state']) || isset($query['usercancel'])) {
                $this->forgetFlow($session);

                return $this->errorResponse($returnTo, 'cancelled');
            }

            $state = bin2hex(random_bytes(32));

            $session->forget(self::SESSION_LINK);
            $session->forget(self::SESSION_LINK_USER);
            $session->forget(self::SESSION_VERIFIED_EMAIL);

            if ($linking) {
                $session->put(self::SESSION_LINK, true);
                $session->put(self::SESSION_LINK_USER, (int) $actor->id);
            }

            $session->put(self::SESSION_STATE, $state);
            $session->put(self::SESSION_RETURN_TO, $returnTo);

            return new RedirectResponse($client->authorizationUrl($redirectUri, $state));
        }

        $expectedState = $session->get(self::SESSION_STATE);
        $state = $query['state'] ?? null;

        $this->forgetFlow($session);

        if (
            ! is_string($expectedState)
            || $expectedState === ''
            || ! is_string($state)
            || ! hash_equals($expectedState, $state)
        ) {
            return $this->errorResponse($returnTo, 'invalid_state');
        }

        try {
            $token = $client->accessToken($code, $redirectUri);
            $user = $client->user($token);
        } catch (GithubOAuthException $e) {
            $this->logger->warning('[flarum-github-oauth] GitHub login failed: '.$e->reason(), [
                'exception' => $e,
            ]);

            return $this->errorResponse($returnTo, $e->reason());
        }

        $identifier = isset($user['id']) && is_scalar($user['id']) ? (string) $user['id'] : '';

        if ($identifier === '') {
            return $this->errorResponse($returnTo, 'invalid_response');
        }

        if ($linkInProgress) {
            if (! $actor->exists) {
                return $this->errorResponse($returnTo, 'login_required');
            }

            if ($linkUserId === null || (int) $linkUserId === 0 || (int) $linkUserId !== (int) $actor->id) {
                return $this->errorResponse($returnTo, 'invalid_state');
            }

            return $this->linkAccount($actor, $identifier, $returnTo, $session);
        }

        $login = isset($user['login']) && is_string($user['login']) ? trim($user['login']) : '';
        $avatar = isset($user['avatar_url']) && is_string($user['avatar_url']) ? trim($user['avatar_url']) : '';

        $response = $this->response->make(
            self::PROVIDER,
            $identifier,
            function (Registration $registration) use ($login, $avatar): void {
                // No email is provided on purpose: the account gets a virtual
                // address generated on the server (see extend.php), so the
                // address never travels to the browser through the
                // registration token.
                if ($login !== '') {
                    $registration->suggestUsername($login);
                }

                if ($avatar !== '') {
                    $registration->provideAvatar($avatar);
                }
            },
            $this->markReturnTo($returnTo)
        );

        $session->regenerate(true);

        return $response;
    }

    private function linkAccount(User $actor, string $identifier, string $returnTo, Store $session): RedirectResponse
    {
        $existing = LoginProvider::query()
            ->where('provider', self::PROVIDER)
            ->where('identifier', $identifier)
            ->first();

        if ($existing !== null && (int) $existing->user_id !== (int) $actor->id) {
            return $this->errorResponse($returnTo, 'already_linked');
        }

        LoginProvider::query()
            ->where('provider', self::PROVIDER)
            ->where('user_id', $actor->id)
            ->where('identifier', '!=', $identifier)
            ->delete();

        $actor->loginProviders()->firstOrCreate([
            'provider' => self::PROVIDER,
            'identifier' => $identifier,
        ]);

        $session->regenerate(true);

        return new RedirectResponse($this->appendQuery($returnTo, self::LINKED_PARAM, '1'));
    }

    private function isLinking(array $query): bool
    {
        foreach (['link', 'linkTo'] as $key) {
            $value = $query[$key] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $value = (string) $value;

            if ($value === '' || $value === '0') {
                continue;
            }

            return true;
        }

        return false;
    }

    private function resolveReturnTo(ServerRequestInterface $request, Store $session): string
    {
        $query = $request->getQueryParams();
        $candidate = $query['returnTo'] ?? null;

        if (! is_string($candidate) || $candidate === '') {
            $candidate = $session->get(self::SESSION_RETURN_TO);
        }

        if (is_string($candidate) && self::isSafeReturnPath($candidate)) {
            return $candidate;
        }

        $referer = $request->getHeaderLine('Referer');

        if ($referer !== '') {
            $path = parse_url($referer, PHP_URL_PATH);

            if (is_string($path) && self::isSafeReturnPath($path)) {
                return $path;
            }
        }

        return '/';
    }

    private static function isSafeReturnPath(string $path): bool
    {
        return str_starts_with($path, '/')
            && ! str_starts_with($path, '//')
            && ! str_contains($path, '://')
            && ! str_contains($path, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1;
    }

    private function markReturnTo(string $returnTo): string
    {
        return $this->appendQuery($returnTo, self::MARKER_PARAM, '1');
    }

    private function appendQuery(string $url, string $key, string $value): string
    {
        [$path, $hash] = array_pad(explode('#', $url, 2), 2, null);

        $separator = str_contains($path, '?') ? '&' : '?';
        $marked = $path.$separator.rawurlencode($key).'='.rawurlencode($value);

        return $hash === null ? $marked : $marked.'#'.$hash;
    }

    private function errorResponse(string $returnTo, string $reason): RedirectResponse
    {
        return new RedirectResponse($this->appendQuery($returnTo, self::ERROR_PARAM, $reason));
    }

    private function forgetFlow(Store $session): void
    {
        $session->forget(self::SESSION_STATE);
        $session->forget(self::SESSION_RETURN_TO);
        $session->forget(self::SESSION_LINK);
        $session->forget(self::SESSION_LINK_USER);
    }
}