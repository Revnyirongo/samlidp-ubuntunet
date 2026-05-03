<?php

declare(strict_types=1);

namespace SimpleSAML\Module\ubuntunetbroker\Auth\Source;

use SimpleSAML\Auth;
use SimpleSAML\Error;
use SimpleSAML\Module;
use SimpleSAML\Utils\HTTP;

/**
 * Minimal upstream OIDC/OAuth2 authentication source for broker use-cases.
 *
 * This keeps the integration self-contained in the repo so UbuntuNet can
 * broker Google/Microsoft sign-in centrally and issue SAML assertions
 * downstream without wiring social login into every SP.
 */
class OidcGeneric extends Auth\Source
{
    public const AUTH_ID = '\SimpleSAML\Module\ubuntunetbroker\Auth\Source\OidcGeneric.AuthId';
    public const STAGE_ID = 'ubuntunetbroker:OidcGeneric';

    private string $providerName;
    private string $issuer;
    private string $clientId;
    private string $clientSecret;
    private string $clientAuthMethod;
    private string $prompt;
    private string $loginHint;
    private array $scopes;
    private array $attributeMap;
    private array $endpoints;

    public function __construct(array $info, array $config)
    {
        parent::__construct($info, $config);

        $this->providerName = trim((string) ($config['provider_name'] ?? 'OIDC'));
        $this->issuer = rtrim(trim((string) ($config['issuer'] ?? '')), '/');
        $this->clientId = trim((string) ($config['client_id'] ?? ''));
        $this->clientSecret = trim((string) ($config['client_secret'] ?? ''));
        $this->clientAuthMethod = trim((string) ($config['client_auth_method'] ?? 'client_secret_post'));
        $this->prompt = trim((string) ($config['prompt'] ?? ''));
        $this->loginHint = trim((string) ($config['login_hint'] ?? ''));
        $this->scopes = $this->normalizeStringList($config['scopes'] ?? ['openid', 'email', 'profile']);
        $this->attributeMap = is_array($config['attribute_map'] ?? null) ? $config['attribute_map'] : [];
        $this->endpoints = [
            'authorization_endpoint' => trim((string) ($config['authorization_endpoint'] ?? '')),
            'token_endpoint' => trim((string) ($config['token_endpoint'] ?? '')),
            'userinfo_endpoint' => trim((string) ($config['userinfo_endpoint'] ?? '')),
            'jwks_uri' => trim((string) ($config['jwks_uri'] ?? '')),
        ];

        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new Error\Exception($this->providerName . ': missing required client_id/client_secret.');
        }
    }

    public function authenticate(array &$state): void
    {
        $state[self::AUTH_ID] = $this->authId;
        $stateId = Auth\State::saveState($state, self::STAGE_ID);
        $endpoints = $this->resolveEndpoints();
        $redirectUri = Module::getModuleURL('ubuntunetbroker/oidc/callback.php');
        $query = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes),
            'state' => $stateId,
        ];

        if ($this->prompt !== '') {
            $query['prompt'] = $this->prompt;
        }

        if ($this->loginHint !== '') {
            $query['login_hint'] = $this->loginHint;
        }

        (new HTTP())->redirectTrustedURL($endpoints['authorization_endpoint'], $query);
    }

    public function completeOidc(array $state, array $queryParameters): void
    {
        if (!empty($queryParameters['error'])) {
            $description = trim((string) ($queryParameters['error_description'] ?? $queryParameters['error']));
            throw new Error\Exception(sprintf('%s login failed: %s', $this->providerName, $description));
        }

        $code = trim((string) ($queryParameters['code'] ?? ''));
        if ($code === '') {
            throw new Error\BadRequest('Missing authorization code from upstream OIDC provider.');
        }

        $endpoints = $this->resolveEndpoints();
        $redirectUri = Module::getModuleURL('ubuntunetbroker/oidc/callback.php');
        $tokenResponse = $this->requestToken($endpoints['token_endpoint'], $code, $redirectUri);
        $accessToken = trim((string) ($tokenResponse['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new Error\Exception(sprintf('%s did not return an access token.', $this->providerName));
        }

        $claims = [];
        if ($endpoints['userinfo_endpoint'] !== '') {
            $claims = $this->fetchUserInfo($endpoints['userinfo_endpoint'], $accessToken);
        }

        if (!is_array($claims)) {
            $claims = [];
        }

        foreach ($tokenResponse as $claim => $value) {
            if (!array_key_exists($claim, $claims)) {
                $claims[$claim] = $value;
            }
        }

        $state['Attributes'] = $this->mapClaimsToAttributes($claims);
        Auth\Source::completeAuth($state);
    }

    private function resolveEndpoints(): array
    {
        if (
            $this->endpoints['authorization_endpoint'] !== ''
            && $this->endpoints['token_endpoint'] !== ''
        ) {
            return $this->endpoints;
        }

        if ($this->issuer === '') {
            throw new Error\Exception($this->providerName . ': missing issuer and explicit endpoint URLs.');
        }

        $wellKnown = $this->issuer;
        if (substr($wellKnown, -34) !== '/.well-known/openid-configuration') {
            $wellKnown .= '/.well-known/openid-configuration';
        }

        $discovery = $this->httpJson($wellKnown);
        foreach (['authorization_endpoint', 'token_endpoint'] as $requiredKey) {
            if (empty($discovery[$requiredKey]) || !is_string($discovery[$requiredKey])) {
                throw new Error\Exception(sprintf('%s discovery document is missing %s.', $this->providerName, $requiredKey));
            }
        }

        return [
            'authorization_endpoint' => $discovery['authorization_endpoint'],
            'token_endpoint' => $discovery['token_endpoint'],
            'userinfo_endpoint' => is_string($discovery['userinfo_endpoint'] ?? null) ? $discovery['userinfo_endpoint'] : '',
            'jwks_uri' => is_string($discovery['jwks_uri'] ?? null) ? $discovery['jwks_uri'] : '',
        ];
    }

    private function requestToken(string $tokenEndpoint, string $code, string $redirectUri): array
    {
        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ];
        $headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ];

        if ($this->clientAuthMethod === 'client_secret_basic') {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret);
        } else {
            $body['client_id'] = $this->clientId;
            $body['client_secret'] = $this->clientSecret;
        }

        $response = $this->httpJson($tokenEndpoint, [
            'method' => 'POST',
            'headers' => $headers,
            'body' => http_build_query($body, '', '&', PHP_QUERY_RFC3986),
        ]);

        if (!is_array($response)) {
            throw new Error\Exception($this->providerName . ': token endpoint returned an invalid response.');
        }

        return $response;
    }

    private function fetchUserInfo(string $userinfoEndpoint, string $accessToken): array
    {
        $response = $this->httpJson($userinfoEndpoint, [
            'method' => 'GET',
            'headers' => [
                'Accept: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
        ]);

        if (!is_array($response)) {
            throw new Error\Exception($this->providerName . ': userinfo endpoint returned an invalid response.');
        }

        return $response;
    }

    private function mapClaimsToAttributes(array $claims): array
    {
        $attributes = [];

        foreach ($this->attributeMap as $claimName => $targetAttributes) {
            if (!array_key_exists($claimName, $claims)) {
                continue;
            }

            $values = is_array($claims[$claimName]) ? $claims[$claimName] : [$claims[$claimName]];
            $values = array_values(array_filter(array_map(static function ($value) {
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }
                if (is_scalar($value)) {
                    $stringValue = trim((string) $value);
                    return $stringValue !== '' ? $stringValue : null;
                }

                return null;
            }, $values)));

            if ($values === []) {
                continue;
            }

            $targets = is_array($targetAttributes) ? $targetAttributes : [$targetAttributes];
            foreach ($targets as $targetName) {
                $targetName = trim((string) $targetName);
                if ($targetName === '') {
                    continue;
                }
                if (!isset($attributes[$targetName])) {
                    $attributes[$targetName] = [];
                }
                $attributes[$targetName] = array_values(array_unique(array_merge($attributes[$targetName], $values)));
            }
        }

        if (!isset($attributes['mail']) && isset($claims['email']) && is_string($claims['email']) && $claims['email'] !== '') {
            $attributes['mail'] = [$claims['email']];
        }

        if (!isset($attributes['displayName']) && isset($claims['name']) && is_string($claims['name']) && $claims['name'] !== '') {
            $attributes['displayName'] = [$claims['name']];
        }

        if (!isset($attributes['username'])) {
            $preferred = trim((string) ($claims['preferred_username'] ?? ''));
            if ($preferred !== '') {
                $attributes['username'] = [$preferred];
            }
        }

        if (!isset($attributes['uid'])) {
            $seed = trim((string) ($claims['sub'] ?? ($attributes['mail'][0] ?? '')));
            if ($seed !== '') {
                $attributes['uid'] = [$seed];
            }
        }

        return $attributes;
    }

    private function httpJson(string $url, array $options = []): array
    {
        $method = strtoupper((string) ($options['method'] ?? 'GET'));
        $headers = $options['headers'] ?? [];
        $body = (string) ($options['body'] ?? '');
        $headerBlock = implode("\r\n", $headers);
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $headerBlock,
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 15,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new Error\Exception(sprintf('%s request to %s failed.', $this->providerName, $url));
        }

        $statusCode = 0;
        foreach ($http_response_header ?? [] as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches)) {
                $statusCode = (int) $matches[1];
                break;
            }
        }

        $decoded = json_decode($response, true);
        if ($statusCode >= 400) {
            $message = is_array($decoded)
                ? json_encode($decoded)
                : substr($response, 0, 500);
            throw new Error\Exception(sprintf('%s request to %s failed with HTTP %d: %s', $this->providerName, $url, $statusCode, $message));
        }

        if (!is_array($decoded)) {
            throw new Error\Exception(sprintf('%s returned a non-JSON response from %s.', $this->providerName, $url));
        }

        return $decoded;
    }

    private function normalizeStringList($value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $normalized = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $normalized[] = $item;
            }
        }

        return $normalized === [] ? ['openid', 'email', 'profile'] : array_values(array_unique($normalized));
    }
}
