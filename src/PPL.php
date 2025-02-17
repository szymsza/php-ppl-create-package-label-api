<?php
declare(strict_types=1);

namespace Szymsza\PhpPplCreatePackageLabelApi;

use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * PPL class provides functionality to interact with the PPL API.
 * It handles authentication and sending requests to the API endpoints.
 * This class supports both development and production environments.
 * It offers some helper functions for encoding requests or decoding responses.
 * Optionally, it can hold the token across requests.
 */
final class PPL
{
    private const ACCESS_TOKEN_URL_DEV = 'https://api-dev.dhl.com/ecs/ppl/myapi2/login/getAccessToken';
    private const ACCESS_TOKEN_URL_PROD = 'https://api.dhl.com/ecs/ppl/myapi2/login/getAccessToken';
    private const API_ENDPOINT_DEV = 'https://api-dev.dhl.com/ecs/ppl/myapi2/';
    private const API_ENDPOINT_PROD = 'https://api.dhl.com/ecs/ppl/myapi2/';
    /**
     * Token lifetime from PPL API is dynamically read from the AccessToken object.
     * To avoid a situation where the token is valid when it is read but not valid when it is sent to the API,
     * we add a buffer that decreases the validity time for its caching.
     */
    private const TOKEN_CACHE_TTL_BUFFER = 10;  // seconds
    private const TOKEN_CACHE_KEY = __CLASS__ . '-token';

    private GenericProvider $provider;
    private bool $isDevelopment;
    private ?AccessTokenInterface $token = null;
    /*
     * Psr\SimpleCache\CacheInterface - Optional but strongly recommended.
     * When not passed, the class won't cache the token and the PPL limit "12 token requests per min" can be easily exceeded.
     */
    private ?CacheInterface $cache;


    public function __construct(
        string          $clientId,
        string          $clientSecret,
        bool            $isDevelopment = false,
        ?CacheInterface $cache = null
    ) {
        $this->isDevelopment = $isDevelopment;
        $this->cache = $cache;
        $this->provider = new GenericProvider([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri' => 'NOT_NECESSARY',
            'urlAuthorize' => 'NOT_NECESSARY',
            'urlAccessToken' => $this->getAccessTokenUrl(),
            'urlResourceOwnerDetails' => 'NOT_NECESSARY',
        ]);
    }


    /**
     * Get the current access token and handles the eventual loading from / saving to cache
     */
    private function getAccessToken(): AccessTokenInterface
    {
        // Try to get an existing token first
        $token = $this->token;

        // If there is no token but the cache is passed, try to load the token from the cache
        if ($token === null && $this->cache) {
            $cachedToken = $this->cache->get(self::TOKEN_CACHE_KEY);
            if ($cachedToken !== null) {
                $token = new AccessToken($cachedToken);
            }
        }

        // Get a new token if there isn't one or the existing is not valid
        if ($token === null || $token->hasExpired()) {
            $token = $this->provider->getAccessToken('client_credentials');

            // Save token to cache if available
            if ($this->cache) {
                $this->cache->set(
                    self::TOKEN_CACHE_KEY,
                    $token->jsonSerialize(),
                    $token->getExpires() - time() - self::TOKEN_CACHE_TTL_BUFFER
                );
            }
        }

        $this->token = $token;

        return $this->token;
    }


    protected function getAccessTokenUrl(): string
    {
        return $this->isDevelopment ? self::ACCESS_TOKEN_URL_DEV : self::ACCESS_TOKEN_URL_PROD;
    }


    protected function getApiEndpointUrl(): string
    {
        return $this->isDevelopment ? self::API_ENDPOINT_DEV : self::API_ENDPOINT_PROD;
    }


    /**
     * If the given URL belongs to the API endpoint, only the relative path is returned.
     */
    public function relativizeUrl(string $absoluteUrl): string
    {
        $apiUrl = $this->getApiEndpointUrl();
        if (str_starts_with($absoluteUrl, $apiUrl)) {
            return substr($absoluteUrl, strlen($apiUrl));
        }

        return $absoluteUrl;
    }


    /**
     *  Sends an authenticated request to the API and returns a response instance.
     *
     *  WARNING: This method does not attempt to catch exceptions caused by HTTP
     *  errors! It is recommended to wrap this method in a try/catch block.
     */
    public function request(string $path, string $method = 'get', array $data = []): ResponseInterface
    {
        $options = [];

        if ($data) {
            $options = [
                'headers' => [
                    'content-type' => 'application/json-patch+json',
                ],
                'body' => json_encode($data),
            ];
        }

        $request = $this->provider->getAuthenticatedRequest(
            $method,
            $this->getApiEndpointUrl() . $path,
            $this->getAccessToken(),
            $options
        );

        return $this->provider->getResponse($request);
    }


    /**
     * Sends a request and returns only the JSON body converted to a PHP array
     *
     * @see self::request()
     * @return array|object|null
     */
    public function requestJson(string $path, string $method = 'get', array $data = [])
    {
        return json_decode(
            $this->request($path, $method, $data)->getBody()->getContents(),
            false
        );
    }


    /**
     * Sends a request and returns a single header of the response
     * If the header value is an API location, the URL is relativized.
     *
     * @see self::request()
     */
    public function requestHeader(string $path, string $method = 'get', array $data = [], string $header = 'Location'): ?string
    {
        $result = $this->request($path, $method, $data)->getHeader($header)[0] ?? null;

        if ($result && $header === 'Location') {
            return $this->relativizeUrl($result);
        }

        return $result;
    }


    /**
     * Calls the API to get Swagger JSON describing the available API endpoints.
     * You can view this JSON by pasting it, e.g., to https://editor.swagger.io/
     */
    public function getSwagger(): string
    {
        return $this->request('swagger/v1/swagger.json')->getBody()->getContents();
    }


    /**
     * Calls the API to get basic information, such as the API version or the current time.
     * Useful to test your connection.
     */
    public function versionInformation(): object
    {
        return $this->requestJson('info');
    }
}
