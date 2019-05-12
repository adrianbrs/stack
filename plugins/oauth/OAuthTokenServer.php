<?php
namespace Stack\Plugins\OAuth;

use Stack\Lib\HttpException;
use Stack\Lib\HttpRequest;
use Stack\Lib\HttpResponse;
use Stack\Lib\StackApp;

/**
 * OAuth Token Server
 *
 * @method object|null getClient($client_id, ?string $secret = null)
 * @method object|null getUser($username_or_id, ?string $password = null)
 * @method string|null generateAccessToken(object $client, object $user)
 * @method string|null generateRefreshToken(object $client, object $user, string $accessToken)
 * @method object|null getAccessToken(string $access_token)
 * @method object|null getRefreshToken(string $refresh_token)
 * @method object|null saveToken(object $client, object $user, string $access_token, string $refresh_token)
 * @method bool|null revokeToken($access_token, $refresh_token)
 * @method bool validateAccessToken($access_token, array $options = [])
 * @method bool validateRefreshToken($refresh_token, array $options = [])
 * @package Stack\Plugins\OAuth
 */
class OAuthTokenServer {

    /**
     * Token Server Controller
     *
     * @var string
     */
    private $controller = '';

    /**
     * Server options
     *
     * @var array
     */
    private $options;

    /**
     * @param string $controller Token Server Controller
     * @param array $options
     */
    public function __construct(string $controller, array $options = []) {
        $this->options = array_merge([], [
            'refresh_token_exp_tolerance' => 3600 // 1h
        ], $options);

        if(! class_exists($controller) || !is_subclass_of($controller, OAuthController::class)) {
            HttpResponse::_throw(new HttpException(HttpException::INTERNAL_SERVER_ERROR,
                'invalid_oauth_controller'));
        }

        $this->controller = new $controller($this->options);
    }

    /**
     * Call controller method
     *
     * @param $name
     * @param mixed ...$args
     * @return bool
     */

    /**
     * Call controller functions
     *
     * @param $name
     * @param $arguments
     * @return mixed
     * @throws \Exception
     */
    public function __call($name, $arguments) {
        if(! \method_exists($this->controller, $name)) {
            if(in_array($name, ['saveAccessToken'])) {
                return true;
            }
            throw new \Exception('invalid_oauth_method_call');
        }
        return call_user_func_array([$this->controller, $name], $arguments);
    }

    /**
     * Generate token from request
     *
     * @param OAuthRequest $request
     * @return object|HttpException
     */
    private function token(OAuthRequest $request) {
        if (empty($request->authorization)) {
            return new HttpException(HttpException::BAD_REQUEST, 'full_authentication_required');
        }

        if (empty($request->grant_type)) {
            return new HttpException(HttpException::BAD_REQUEST, 'missing_grant_type');
        }

        if ($request->grant_type === 'password') {
            return $this->auth_password($request);
        }

        if ($request->grant_type === 'refresh_token') {
            return $this->auth_refresh_token($request);
        }
    }

    /**
     * Generate token from user credentials
     *
     * @param OAuthRequest $request
     * @return object|HttpException
     */
    private function auth_password(OAuthRequest $request) {
        // Get client
        $client = $this->getClient($request->client->id, $request->client->secret);
        if (empty($client)) {
            return new HttpException(HttpException::BAD_REQUEST, 'invalid_client');
        }

        // Get user
        $user = $this->getUser($request->username, $request->password);
        if (empty($user)) {
            return new HttpException(HttpException::BAD_REQUEST, 'invalid_credentials');
        }

        // Generate access token
        $accessToken = $this->generateAccessToken($client, $user);
        if (empty($accessToken)) {
            return new HttpException(HttpException::INTERNAL_SERVER_ERROR, 'invalid_access_token');
        }

        // Generate refresh token
        $refreshToken = $this->generateRefreshToken($client, $user, $accessToken);
        if (empty($refreshToken)) {
            return new HttpException(HttpException::INTERNAL_SERVER_ERROR, 'invalid_refresh_token');
        }

        // Save token
        $save = $this->saveToken($client, $user, $accessToken, $refreshToken);
        if (! $save) {
            return new HttpException(HttpException::INTERNAL_SERVER_ERROR, 'cannot_save_token');
        }

        return (object) (is_bool($save) ? [
            'client' => $client,
            'user' => $user,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ] : $save);
    }

    /**
     * Generate new access token from refresh token
     *
     * @param OAuthRequest $request
     * @return object|HttpException
     */
    public function auth_refresh_token(OAuthRequest $request) {
        $refreshToken = $request->refresh_token;
        
        // Get refresh token payload
        $payload = $this->getRefreshToken($refreshToken);
        if (empty($payload)) {
            return new HttpException(HttpException::BAD_REQUEST, 'invalid_refresh_token');
        }
        $payload = (object) $payload;

        // Validate expiration date field
        if(! isset($payload->refresh_token_exp)) {
            return new HttpException(HttpException::INTERNAL_SERVER_ERROR,
                'refresh_token_exp_not_set');
        }

        /**
         * Verifies that the current access token is expired
         */
        $expires_at = $payload->refresh_token_exp;
        $token_expired = $expires_at <= time();

        // Verifies that the refresh token is expired and out of tolerance
        if($expires_at >= 0 && $token_expired) {
            return new HttpException(HttpException::UNAUTHORIZED, 'refresh_token_expired');
        }

        // Get client credentials
        $client = $this->getClient($request->client->id, $request->client->secret);
        if (empty($client)) {
            return new HttpException(HttpException::BAD_REQUEST, 'invalid_client');
        }
        $client = (object) $client;

        // Get user credentials
        $user = $this->getUser($payload->user);
        if (empty($user)) {
            return new HttpException(HttpException::BAD_REQUEST, 'invalid_user');
        }
        $user = (object) $user;

        // Generates new access token
        $accessToken = $this->generateAccessToken($client, $user);
        if (empty($accessToken)) {
            return new HttpException(HttpException::INTERNAL_SERVER_ERROR, 'invalid_access_token');
        }

        /**
         * Generate new refresh token if needed
         */
        // Verifies that current refresh token is expired
        if($token_expired) {
            $refreshToken = $this->generateRefreshToken($client, $user, $accessToken);
            if (empty($refreshToken)) {
                return new HttpException(HttpException::INTERNAL_SERVER_ERROR, 'invalid_refresh_token');
            }
        }

        // Save token to database
        $save = $this->saveToken($client, $user, $accessToken, $refreshToken);
        if (!$save) {
            return new HttpException(HttpException::INTERNAL_SERVER_ERROR, 'cannot_save_token');
        }

        return (object) (is_bool($save) ? [
            'client' => $client,
            'user' => $user,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ] : $save);
    }

    /**
     * Token generation server
     *
     * @param HttpRequest $req
     * @param HttpResponse $res
     * @return object|HttpException|HttpResponse
     * @throws HttpException
     */
    public function server(HttpRequest $req, HttpResponse $res) {
        $result = static::token(new OAuthRequest($req));

        if ($result instanceof \Exception) {
            return $result;
        }

        return $res->json([
            'access_token' => $result->access_token,
            'refresh_token' => $result->refresh_token,
        ]);
    }

    /**
     * Revoke token server
     *
     * @param HttpRequest $req
     * @param HttpResponse $res
     * @return HttpException|HttpResponse
     */
    public function revoke(HttpRequest $req, HttpResponse $res) {
        $oauth_req = $req->oauth_request;
        $access_token = $oauth_req->authorization ?? null;
        $refresh_token = $oauth_req->refresh_token ?? null;

        if(is_null($access_token)) {
            return new HttpException(HttpException::BAD_REQUEST, 'missing_access_token');
        }
        if(is_null($refresh_token)) {
            return new HttpException(HttpException::BAD_REQUEST, 'missing_refresh_token');
        };
        $access_token = preg_replace('@^\s*Bearer|\s*@', '', $access_token);

        $access_token = $this->getAccessToken($access_token);
        $refresh_token = $this->getRefreshToken($refresh_token);

        if(! $access_token) {
            return new HttpException(HttpException::BAD_REQUEST, 'invalid_access_token');
        }
        if(! $refresh_token) {
            return new HttpException(HttpException::BAD_REQUEST, 'invalid_refresh_token');
        };

        if($this->revokeToken($access_token, $refresh_token)) {
            return $res->status(204);
        }
        return new HttpException(HttpException::BAD_REQUEST,
            'cannot_revoke_token');
    }

    /**
     * Protect request
     *
     * @param HttpRequest $req
     * @return HttpException
     */
    public function session(HttpRequest $req) {
        // Check if oauth is set
        if(! isset($req->oauth_request)) {
            return new HttpException(HttpException::INTERNAL_SERVER_ERROR, 'missing_oauth_request');
        }
        $oauth_req = $req->oauth_request;

        // Check authorization
        if (empty($oauth_req->authorization)) {
            return new HttpException(HttpException::FORBIDDEN, 'missing_authorization');
        }

        // Get access token payload
        $payload = $this->getAccessToken($oauth_req->authorization);
        if (!$payload) {
            return new HttpException(HttpException::BAD_REQUEST, 'invalid_access_token');
        }

        // Validate expiration date field
        if(! isset($payload->access_token_exp)) {
            return new HttpException(HttpException::INTERNAL_SERVER_ERROR,
                'access_token_exp_not_set');
        }
        $expire_at = $payload->access_token_exp;

        // Verifies if the current token is expired
        if($expire_at >= 0 && $expire_at <= time()) {
            return new HttpException(HttpException::UNAUTHORIZED, 'access_token_expired');
        }

        // Get client credentials
        $client = $this->getClient($payload->client->id);
        if (!$client) {
            return new HttpException(HttpException::BAD_REQUEST, 'invalid_client');
        }

        // Get user credentials
        $user = $this->getUser($payload->user->id);
        if (!$user) {
            return new HttpException(HttpException::BAD_REQUEST, 'invalid_user');
        }

        // Inject auth data to request
        $req->auth = (object) [
            'user' => $user,
            'client' => $client,
            'access_token' => $oauth_req->authorization,
        ];
    }

    /**
     * Get option
     *
     * @param string $name
     * @return mixed|null
     */
    private function option(string $name) {
        return $this->options[$name] ?? null;
    }
}