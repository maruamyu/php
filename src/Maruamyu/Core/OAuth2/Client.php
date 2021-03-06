<?php

namespace Maruamyu\Core\OAuth2;

use Maruamyu\Core\Base64Url;
use Maruamyu\Core\Cipher\Digest;
use Maruamyu\Core\Http\Client as HttpClient;
use Maruamyu\Core\Http\Message\Headers;
use Maruamyu\Core\Http\Message\QueryString;
use Maruamyu\Core\Http\Message\Request;
use Maruamyu\Core\Http\Message\Response;
use Maruamyu\Core\Http\Message\Uri;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * The OAuth 2.0 Authorization Framework (RFC 6749)
 * and OpenID Connect Core 1.0
 * light-weight client
 */
class Client
{
    /** OpenID Connect Core 1.0 implements */
    use OpenIDExtendsTrait;

    /** @var AuthorizationServerMetadata */
    protected $metadata;

    /** @var string */
    protected $clientId;

    /** @var string */
    protected $clientSecret;

    /** @var AccessToken */
    protected $accessToken;

    /** @var HttpClient */
    protected $httpClient;

    /**
     * @param AuthorizationServerMetadata $metadata
     * @param string $clientId
     * @param string $clientSecret
     * @param AccessToken $accessToken
     */
    public function __construct(AuthorizationServerMetadata $metadata, $clientId, $clientSecret, AccessToken $accessToken = null)
    {
        $this->metadata = clone $metadata;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        if ($accessToken) {
            $this->setAccessToken($accessToken);
        }
        $this->httpClient = null;  # see getHttpClient()
    }

    /**
     * @param string $issuer
     * @return AuthorizationServerMetadata
     * @throws \Exception if failed
     */
    public static function fetchAuthorizationServerMetadata($issuer)
    {
        $configurationUrl = $issuer . '/.well-known/oauth-authorization-server';
        $httpClient = static::createHttpClientInstance();
        $response = $httpClient->request('GET', $configurationUrl);
        if ($response->statusCodeIsOk() == false) {
            throw new \RuntimeException('oauth-authorization-server fetch failed. (HTTP ' . $response->getStatusCode() . ')');
        }
        $metadataJson = strval($response->getBody());
        if (strlen($metadataJson) < 1) {
            throw new \RuntimeException('oauth-authorization-server fetch failed.');
        }
        $metadataValues = json_decode($metadataJson, true);
        if (empty($metadataValues) || (isset($metadataValues['issuer']) == false)) {
            throw new \RuntimeException('oauth-authorization-server fetch failed.');
        }
        if ($metadataValues['issuer'] !== $issuer) {
            $errorMsg = 'issuer not match. (args=' . $issuer . ', metadata=' . $metadataValues['issuer'] . ')';
            throw new \RuntimeException($errorMsg);
        }
        return new AuthorizationServerMetadata($metadataValues);
    }

    /**
     * @return string
     */
    public function getClientId()
    {
        return $this->clientId;
    }

    /**
     * @return AccessToken|null
     */
    public function getAccessToken()
    {
        if ($this->accessToken) {
            return clone $this->accessToken;
        } else {
            return null;
        }
    }

    /**
     * @param AccessToken $accessToken
     */
    public function setAccessToken(AccessToken $accessToken)
    {
        $this->accessToken = $accessToken;
    }

    /**
     * @param \DateTimeInterface $currentTime
     * @return bool true
     */
    public function hasValidAccessToken(\DateTimeInterface $currentTime = null)
    {
        # not has access_token
        if (!$this->accessToken) {
            return false;
        }

        # check expires_at
        $expiresAt = $this->accessToken->getExpiresAt();
        if ($expiresAt) {
            if (!$currentTime) {
                try {
                    $currentTime = new \DateTime();
                } catch (\Exception $exception) {
                    return false;
                }
            }
            if ($currentTime > $expiresAt) {
                return false;
            }
        }

        # valid
        return true;
    }

    /**
     * @note not reload if hasValidAccessToken()
     * @return AccessToken|null
     * @throws \Exception if invalid settings
     * @see hasValidAccessToken()
     * @see refreshAccessToken()
     */
    public function reloadAccessToken()
    {
        if ($this->hasValidAccessToken()) {
            return $this->accessToken;
        }
        return $this->refreshAccessToken();
    }

    /**
     * @param string $method
     * @param string|UriInterface $uri
     * @param string|StreamInterface $body
     * @param Headers|string|array $headers
     * @return Response
     * @see makeRequest()
     */
    public function request($method, $uri, $body = null, $headers = null)
    {
        $request = $this->makeRequest($method, $uri, $body, $headers);
        return $this->getHttpClient()->send($request);
    }

    /**
     * @note add access_token if not include Authorization header
     * @param Request $request
     * @return Response
     */
    public function sendRequest(Request $request)
    {
        if (!($request->hasHeader('Authorization'))) {
            if ($this->accessToken) {
                $request = $request->withHeader('Authorization', $this->accessToken->getHeaderValue());
            }
        }
        return $this->getHttpClient()->send($request);
    }

    /**
     * @param string $method
     * @param string|UriInterface $uri
     * @param string|StreamInterface $body
     * @param Headers|string|array $headers
     * @return Request HTTP request message including access_token
     */
    public function makeRequest($method, $uri, $body = null, $headers = null)
    {
        if (!($headers instanceof Headers)) {
            $headers = new Headers($headers);
        }
        if ($this->accessToken) {
            $headers->set('Authorization', $this->accessToken->getHeaderValue());
        }
        return new Request($method, $uri, $body, $headers);
    }

    /**
     * Authorization Code Grant : generate Authorization URL
     *
     * @param string[] $scopes
     * @param string|UriInterface $redirectUrl
     * @param string $state
     * @param array $optionalParameters
     * @return string Authorization URL
     * @throws \Exception if invalid settings or arguments
     */
    public function startAuthorizationCodeGrant(
        array $scopes = [],
        $redirectUrl = null,
        $state = null,
        array $optionalParameters = []
    ) {
        if (isset($this->metadata->authorizationEndpoint) == false) {
            throw new \RuntimeException('authorizationEndpoint not set yet.');
        }
        $parameters = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
        ];
        if ($redirectUrl) {
            $parameters['redirect_uri'] = strval($redirectUrl);
        }
        if ($scopes) {
            $parameters['scope'] = join(' ', $scopes);
        }
        if ($state) {
            $parameters['state'] = $state;
        }
        if ($optionalParameters) {
            $parameters = array_merge($parameters, $optionalParameters);
        }
        $url = new Uri($this->metadata->authorizationEndpoint);
        return strval($url->withQueryString($parameters));
    }

    /**
     * Authorization Code Grant : exchange code to access_token
     *
     * @note update holding AccessToken if succeeded
     * @param string $code
     * @param string|UriInterface $redirectUrl
     * @param array $optionalParameters
     * @return AccessToken|null
     * @throws \Exception if invalid settings or arguments
     */
    public function finishAuthorizationCodeGrant($code, $redirectUrl = null, array $optionalParameters = [])
    {
        if (isset($this->metadata->tokenEndpoint) == false) {
            throw new \RuntimeException('tokenEndpoint not set yet.');
        }

        $parameters = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => strval($redirectUrl),
        ];
        if ($optionalParameters) {
            $parameters = array_merge($parameters, $optionalParameters);
        }

        $request = $this->makeTokenEndpointPostRequestWithClientCredentials($parameters);
        $response = $this->getHttpClient()->send($request);
        if ($response->statusCodeIsOk() == false) {
            return null;
        }
        $this->setAccessTokenByResponse($response);
        return $this->getAccessToken();
    }

    /**
     * Authorization Code Grant with PKCE (RFC 7636) : generate Authorization URL
     *
     * @param string[] $scopes
     * @param string|UriInterface $redirectUrl
     * @param string $codeVerifier
     * @param string $codeChallengeMethod 'plain' or 'S256'
     * @param array $optionalParameters
     * @return string Authorization URL
     * @throws \Exception if invalid settings or arguments
     */
    public function startAuthorizationCodeGrantWithPkce(
        array $scopes = [],
        $redirectUrl = null,
        $codeVerifier = null,
        $codeChallengeMethod = 'S256',
        array $optionalParameters = []
    ) {
        if (isset($optionalParameters) == false) {
            $optionalParameters = [];
        }
        if (strlen($codeVerifier) < 43 || strlen($codeVerifier) > 128) {
            throw new \DomainException('code_verifier is too short or long');
        }
        switch ($codeChallengeMethod) {
            case 'plain':
                $optionalParameters['code_challenge'] = $codeVerifier;
                break;
            case 'S256':
                $optionalParameters['code_challenge'] = Base64Url::encode(Digest::sha256($codeVerifier));
                break;
            default:
                throw new \DomainException('invalid code_challenge_method=' . $codeChallengeMethod . '');
        }
        $optionalParameters['code_challenge_method'] = $codeChallengeMethod;

        return $this->startAuthorizationCodeGrant($scopes, $redirectUrl, null, $optionalParameters);
    }

    /**
     * finish Authorization Code Grant with PKCE (RFC 7636) : exchange code to access_token
     *
     * @note update holding AccessToken if succeeded
     * @param string $code
     * @param string|UriInterface $redirectUrl
     * @param string $codeVerifier
     * @param array $optionalParameters
     * @return AccessToken|null
     * @throws \Exception if invalid settings or arguments
     */
    public function finishAuthorizationCodeGrantWithPkce(
        $code,
        $redirectUrl = null,
        $codeVerifier = null,
        array $optionalParameters = []
    ) {
        if (isset($optionalParameters) == false) {
            $optionalParameters = [];
        }
        if (strlen($codeVerifier) < 43 || strlen($codeVerifier) > 128) {
            throw new \DomainException('code_verifier is too short or long');
        }
        $optionalParameters['code_verifier'] = $codeVerifier;

        return $this->finishAuthorizationCodeGrant($code, $redirectUrl, $optionalParameters);
    }

    /**
     * Device Authorization Grant (RFC 8628) : start
     *
     * @param string[] $scopes
     * @return array|null Device Authorization Response
     */
    public function startDeviceAuthorizationGrant(array $scopes = [])
    {
        if (isset($this->metadata->deviceAuthorizationEndpoint) == false) {
            throw new \RuntimeException('deviceAuthorizationEndpoint not set yet.');
        }
        $parameters = [
            'client_id' => $this->clientId,
        ];
        if ($scopes) {
            $parameters['scope'] = join(' ', $scopes);
        }
        $requestBodoy = QueryString::build($parameters);
        $request = new Request('POST', $this->metadata->deviceAuthorizationEndpoint, $requestBodoy);
        $response = $this->getHttpClient()->send($request);
        if ($response->statusCodeIsOk() == false) {
            return null;
        }
        $responseBody = strval($response->getBody());
        return json_decode($responseBody, true);
    }

    /**
     * Device Authorization Grant (RFC 8628) : exchange device_code to token
     *
     * @param string $deviceCode
     * @return array|null Device Authorization Response
     */
    public function finishDeviceAuthorizationGrant($deviceCode)
    {
        if (isset($this->metadata->tokenEndpoint) == false) {
            throw new \RuntimeException('tokenEndpoint not set yet.');
        }

        $parameters = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $deviceCode,
            'client_id' => $this->clientId,
        ];

        $requestBodoy = QueryString::build($parameters);
        $request = new Request('POST', $this->metadata->deviceAuthorizationEndpoint, $requestBodoy);
        $response = $this->getHttpClient()->send($request);
        if ($response->statusCodeIsOk() == false) {
            return null;
        }
        $responseData = json_decode(strval($response->getBody()), true);
        if (isset($responseData['error']) == false) {
            $this->setAccessTokenByResponse($response);
        }
        return $responseData;
    }

    /**
     * start Implicit Grant
     *
     * @param string[] $scopes
     * @param string|UriInterface $redirectUrl
     * @param string $state
     * @param array $optionalParameters
     * @return string Authorization URL
     * @throws \Exception if invalid settings
     */
    public function startImplicitGrant(array $scopes = [], $redirectUrl = null, $state = null, array $optionalParameters = [])
    {
        if (isset($this->metadata->authorizationEndpoint) == false) {
            throw new \RuntimeException('authorizationEndpoint not set yet.');
        }
        $parameters = [
            'response_type' => 'token',
            'client_id' => $this->clientId,
        ];
        if ($redirectUrl) {
            $parameters['redirect_uri'] = strval($redirectUrl);
        }
        if ($scopes) {
            $parameters['scope'] = join(' ', $scopes);
        }
        if ($state) {
            $parameters['state'] = $state;
        }
        if ($optionalParameters) {
            $parameters = array_merge($parameters, $optionalParameters);
        }
        $url = new Uri($this->metadata->authorizationEndpoint);
        return strval($url->withQueryString($parameters));
    }

    /**
     * Resource Owner Password Credentials Grant
     *
     * @note update holding AccessToken if succeeded
     * @param string $username
     * @param string $password
     * @param string[] $scopes
     * @return AccessToken|null
     * @throws \Exception if invalid settings
     */
    public function requestResourceOwnerPasswordCredentialsGrant($username, $password, array $scopes = [])
    {
        if (isset($this->metadata->tokenEndpoint) == false) {
            throw new \RuntimeException('tokenEndpoint not set yet.');
        }

        $parameters = [
            'grant_type' => 'password',
            'username' => $username,
            'password' => $password,
        ];
        if ($scopes) {
            $parameters['scope'] = join(' ', $scopes);
        }

        $request = $this->makeTokenEndpointPostRequestWithClientCredentials($parameters);
        $response = $this->getHttpClient()->send($request);
        if ($response->statusCodeIsOk() == false) {
            return null;
        }
        $this->setAccessTokenByResponse($response);
        return $this->getAccessToken();
    }

    /**
     * Client Credentials Grant
     *
     * @note update holding AccessToken if succeeded
     * @param string[] $scopes
     * @return AccessToken|null
     * @throws \Exception if invalid settings
     */
    public function requestClientCredentialsGrant(array $scopes = [])
    {
        if (isset($this->metadata->tokenEndpoint) == false) {
            throw new \RuntimeException('tokenEndpoint not set yet.');
        }

        $parameters = [
            'grant_type' => 'client_credentials',
        ];
        if ($scopes) {
            $parameters['scope'] = join(' ', $scopes);
        }

        $request = $this->makeTokenEndpointPostRequestWithClientCredentials($parameters);
        $response = $this->getHttpClient()->send($request);
        if ($response->statusCodeIsOk() == false) {
            return null;
        }
        $this->setAccessTokenByResponse($response);
        return $this->getAccessToken();
    }

    /**
     * @param array $parameters
     * @return Request
     * @internal
     */
    protected function makeTokenEndpointPostRequestWithClientCredentials(array $parameters = [])
    {
        if (empty($parameters)) {
            $parameters = [];
        }
        $request = new Request('POST', $this->metadata->tokenEndpoint);
        # if ($request->getUri()->getScheme() !== 'https') {
        #     throw new \RuntimeException('required https if including client credentials');
        # }

        if (
            isset($this->metadata->supportedTokenEndpointAuthMethods)
            && is_array($this->metadata->supportedTokenEndpointAuthMethods)
            && in_array('client_secret_post', $this->metadata->supportedTokenEndpointAuthMethods)
        ) {
            $parameters['client_id'] = $this->clientId;
            $parameters['client_secret'] = $this->clientSecret;
        } else {
            # default is client_secret_basic
            $request = $request->withAddedHeader('Authorization', $this->makeClientSecretBasicAuthorizationHeaderValue());
        }

        if (empty($parameters) == false) {
            $request = $request->withAddedHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withBodyContents(QueryString::build($parameters));
        }

        return $request;
    }

    /**
     * HTTP Request of
     * JSON Web Token Profile Authorization Grants (RFC 7523)
     *
     * @param JsonWebKey $jsonWebKey private key
     * @param string $issuer
     * @param string $subject
     * @param int $expiresAtTimestamp expire(seconds)
     * @param string[] $scopes list of scopes
     * @param string[] $optionalParameters
     * @return AccessToken|null
     * @throws \Exception if failed
     */
    public function requestJwtBearerGrant(
        JsonWebKey $jsonWebKey,
        $issuer,
        $subject,
        $expiresAtTimestamp,
        array $scopes = [],
        array $optionalParameters = []
    ) {
        $request = $this->makeJwtBearerGrantRequest($jsonWebKey, $issuer, $subject, $expiresAtTimestamp, $scopes, $optionalParameters);
        $response = $this->getHttpClient()->send($request);
        if ($response->statusCodeIsOk() == false) {
            return null;
        }
        $this->setAccessTokenByResponse($response);
        return $this->getAccessToken();
    }

    /**
     * make HTTP Request of
     * JSON Web Token Profile Authorization Grants (RFC 7523)
     *
     * @param JsonWebKey $jsonWebKey private key
     * @param string $issuer
     * @param string $subject
     * @param int $expiresAtTimestamp
     * @param string[] $scopes
     * @param string[] $optionalParameters
     * @return Request
     * @throws \Exception if failed
     */
    public function makeJwtBearerGrantRequest(
        JsonWebKey $jsonWebKey,
        $issuer,
        $subject,
        $expiresAtTimestamp,
        array $scopes = [],
        array $optionalParameters = []
    ) {
        if (!($jsonWebKey->hasPrivateKey())) {
            throw new \RuntimeException('not has private key.');
        }

        $jwtClaimSet = [
            'iss' => $issuer,
            'sub' => $subject,
            'aud' => $this->metadata->tokenEndpoint,
            'exp' => $expiresAtTimestamp,
        ];
        if ($scopes) {
            $jwtClaimSet['scope'] = join(' ', $scopes);
        }
        if ($optionalParameters) {
            $jwtClaimSet = array_merge($jwtClaimSet, $optionalParameters);
        }

        $queryParameters = [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => JsonWebToken::build($jwtClaimSet, $jsonWebKey),
        ];
        $requestBody = QueryString::build($queryParameters);

        return new Request('POST', $this->metadata->tokenEndpoint, $requestBody);
    }

    /**
     * refresh access_token by refresh_token
     *
     * @return AccessToken|null
     * @throws \Exception if invalid settings or not has refresh_token
     */
    public function refreshAccessToken()
    {
        if (isset($this->metadata->tokenEndpoint) == false) {
            throw new \RuntimeException('tokenEndpoint not set yet.');
        }
        if (!($this->accessToken)) {
            throw new \RuntimeException('not has access_token!!');
        }

        $refreshToken = strval($this->accessToken->getRefreshToken());
        if (strlen($refreshToken) < 1) {
            throw new \RuntimeException('not has refresh_token!!');
        }

        $parameters = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ];

        $scopes = $this->accessToken->getScopes();
        if ($scopes) {
            $parameters['scope'] = join(' ', $scopes);
        }

        $request = $this->makeTokenEndpointPostRequestWithClientCredentials($parameters);
        $response = $this->getHttpClient()->send($request);
        if ($response->statusCodeIsOk() == false) {
            return null;
        }

        $tokenData = json_decode($response->getBody(), true);
        $issuedAt = $response->getDate();

        $this->accessToken->update($tokenData, $issuedAt);
        return $this->getAccessToken();
    }

    /**
     * revoke access token
     *
     * @return bool true if revoked
     * @throws \Exception if invalid settings
     * @see makeTokenRevocationRequest()
     */
    public function revokeAccessToken()
    {
        if (!($this->accessToken)) {
            return false;
        }
        $request = $this->makeTokenRevocationRequest($this->accessToken->getToken(), 'access_token');
        $response = $this->getHttpClient()->send($request);
        if ($response->statusCodeIsOk()) {
            $this->accessToken = null;
            return true;
        } else {
            return false;
        }
    }

    /**
     * revoke refresh token
     *
     * @return bool true if revoked
     * @throws \Exception if invalid settings
     * @see makeTokenRevocationRequest()
     */
    public function revokeRefreshToken()
    {
        if (!($this->accessToken)) {
            return false;
        }
        $request = $this->makeTokenRevocationRequest($this->accessToken->getRefreshToken(), 'refresh_token');
        $response = $this->getHttpClient()->send($request);
        if ($response->statusCodeIsOk()) {
            $this->accessToken = null;
            return true;
        } else {
            return false;
        }
    }

    /**
     * make token revocation request (RFC 7009)
     *
     * @param string $token
     * @param string $tokenTypeHint 'access_token' or 'refresh_token'
     * @return Request
     * @throws \Exception if invalid settings
     */
    public function makeTokenRevocationRequest($token, $tokenTypeHint = '')
    {
        if (isset($this->metadata->revocationEndpoint) == false) {
            throw new \RuntimeException('revocationEndpoint not set yet.');
        }

        $parameters = [
            'token' => $token,
        ];
        if (strlen($tokenTypeHint) > 0) {
            $parameters['token_type_hint'] = strval($tokenTypeHint);
        }

        $request = new Request('POST', $this->metadata->revocationEndpoint);
        # if ($request->getUri()->getScheme() !== 'https') {
        #     throw new \RuntimeException('required https if including client credentials');
        # }

        if (
            isset($this->metadata->supportedRevocationEndpointAuthMethods)
            && is_array($this->metadata->supportedRevocationEndpointAuthMethods)
            && in_array('client_secret_post', $this->metadata->supportedRevocationEndpointAuthMethods)
        ) {
            $parameters['client_id'] = $this->clientId;
            $parameters['client_secret'] = $this->clientSecret;
        } else {
            # default is client_secret_basic
            $request = $request->withAddedHeader('Authorization', $this->makeClientSecretBasicAuthorizationHeaderValue());
        }

        return $request->withAddedHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBodyContents(QueryString::build($parameters));
    }

    /**
     * execute token introspection request (RFC 7662)
     *
     * @param string $token
     * @param string $tokenTypeHint 'access_token' or 'refresh_token'
     * @return array
     * @throws \Exception if invalid settings
     * @see makeTokenIntrospectionRequest()
     */
    public function requestTokenIntrospection($token, $tokenTypeHint = '')
    {
        $request = $this->makeTokenIntrospectionRequest($token, $tokenTypeHint);
        $response = $this->getHttpClient()->send($request);
        $responseBody = strval($response->getBody());
        return json_decode($responseBody, true);
    }

    /**
     * make token introspection request (RFC 7662)
     * use Bearer Authorization if has access_token
     * use Client Credentials Authorization if not has access_token
     *
     * @param string $token
     * @param string $tokenTypeHint 'access_token' or 'refresh_token'
     * @return Request
     * @throws \Exception if invalid settings
     */
    public function makeTokenIntrospectionRequest($token, $tokenTypeHint = '')
    {
        if (isset($this->metadata->tokenIntrospectionEndpoint) == false) {
            throw new \RuntimeException('tokenIntrospectionEndpoint not set yet.');
        }

        $parameters = [
            'token' => $token,
        ];
        if (strlen($tokenTypeHint) > 0) {
            $parameters['token_type_hint'] = strval($tokenTypeHint);
        }

        $request = $this->makeRequest('POST', $this->metadata->tokenIntrospectionEndpoint);
        if (!($this->accessToken)) {
            # not has access_token
            # if ($request->getUri()->getScheme() !== 'https') {
            #     throw new \RuntimeException('required https if including client credentials');
            # }
            if (
                isset($this->metadata->supportedRevocationEndpointAuthMethods)
                && is_array($this->metadata->supportedRevocationEndpointAuthMethods)
                && in_array('client_secret_post', $this->metadata->supportedRevocationEndpointAuthMethods)
            ) {
                $parameters['client_id'] = $this->clientId;
                $parameters['client_secret'] = $this->clientSecret;
            } else {
                # default is client_secret_basic
                $request = $request->withAddedHeader('Authorization', $this->makeClientSecretBasicAuthorizationHeaderValue());
            }
        }
        return $request->withAddedHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBodyContents(QueryString::build($parameters));
    }

    /**
     * @param Response $response
     * @return bool
     */
    protected function setAccessTokenByResponse(Response $response)
    {
        $tokenData = json_decode($response->getBody(), true);
        if (isset($tokenData['error'])) {
            return false;
        }
        $issuedAt = $response->getDate();
        $accessToken = new AccessToken($tokenData, $issuedAt);
        $this->setAccessToken($accessToken);
        return true;
    }

    /**
     * @return string
     */
    protected function makeClientSecretBasicAuthorizationHeaderValue()
    {
        $credentials = base64_encode($this->clientId . ':' . $this->clientSecret);
        return 'Basic ' . $credentials;
    }

    /**
     * @return Response
     */
    public function getLatestResponse()
    {
        return $this->getHttpClient()->getLatestResponse();
    }

    /**
     * @return HttpClient
     */
    protected function getHttpClient()
    {
        if (isset($this->httpClient) == false) {
            $this->httpClient = static::createHttpClientInstance();
        }
        return $this->httpClient;
    }

    /**
     * @return HttpClient
     * @internal
     */
    protected static function createHttpClientInstance()
    {
        return new HttpClient();
    }
}
