<?php

declare(strict_types=1);

namespace TacraftSociality\GithubOAuth\Github;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

class GithubClient
{
    public const AUTHORIZE_ENDPOINT = 'https://github.com/login/oauth/authorize';

    public const TOKEN_ENDPOINT = 'https://github.com/login/oauth/access_token';

    public const API_ENDPOINT = 'https://api.github.com';

    private const USER_AGENT = 'flarum-github-oauth';

    private const TIMEOUT = 10;

    private readonly ClientInterface $http;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        ?ClientInterface $http = null
    ) {
        $this->http = $http ?? new Client([
            'timeout' => self::TIMEOUT,
            'connect_timeout' => 5,
            'http_errors' => false,
        ]);
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'read:user user:email',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return self::AUTHORIZE_ENDPOINT.'?'.$query;
    }

    public function accessToken(string $code, string $redirectUri): string
    {
        try {
            $response = $this->http->request('POST', self::TOKEN_ENDPOINT, [
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new GithubOAuthException('network_error', $e->getMessage(), $e);
        }

        $data = $this->decode((string) $response->getBody());

        if (isset($data['error'])) {
            $message = isset($data['error_description']) && is_scalar($data['error_description'])
                ? (string) $data['error_description']
                : (is_scalar($data['error']) ? (string) $data['error'] : 'GitHub rejected the OAuth request.');

            throw new GithubOAuthException('provider_error', $message);
        }

        $token = $data['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new GithubOAuthException('token_error', 'GitHub did not return an access token.');
        }

        return $token;
    }

    public function user(string $token): array
    {
        return $this->api('/user', $token, 'profile_error');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function emails(string $token): array
    {
        $data = $this->api('/user/emails', $token, 'email_error');

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * The verified, primary address from `/user/emails`. This is the only
     * GitHub email that can be trusted; the public profile email cannot.
     */
    public function primaryVerifiedEmail(array $emails): ?string
    {
        foreach ($emails as $email) {
            if (! is_array($email)) {
                continue;
            }

            if (
                ($email['primary'] ?? false) === true
                && ($email['verified'] ?? false) === true
                && isset($email['email'])
                && is_string($email['email'])
                && filter_var($email['email'], FILTER_VALIDATE_EMAIL)
            ) {
                return $email['email'];
            }
        }

        return null;
    }

    private function api(string $path, string $token, string $reason): array
    {
        try {
            $response = $this->http->request('GET', self::API_ENDPOINT.$path, [
                'headers' => [
                    'Authorization' => 'Bearer '.$token,
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => self::USER_AGENT,
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new GithubOAuthException('network_error', $e->getMessage(), $e);
        }

        $data = $this->decode((string) $response->getBody());

        if ($response->getStatusCode() >= 400) {
            $message = isset($data['message']) && is_scalar($data['message'])
                ? (string) $data['message']
                : 'GitHub API returned HTTP '.$response->getStatusCode();

            throw new GithubOAuthException($reason, $message);
        }

        return $data;
    }

    private function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new GithubOAuthException('invalid_response', 'GitHub returned invalid JSON.', $e);
        }

        if (! is_array($decoded)) {
            throw new GithubOAuthException('invalid_response', 'GitHub returned an unexpected response.');
        }

        return $decoded;
    }
}
