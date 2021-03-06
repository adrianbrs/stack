<?php
namespace Stack\Plugins\OAuth;

use Stack\Lib\HttpException;
use Stack\Lib\HttpRequest;

/**
 * Class OAuthRequest
 * @package Stack\Plugins\OAuth
 */
class OAuthRequest
{
    public $authorization = null;
    public $grant_type    = null;
    public $client        = null;
    public $username      = null;
    public $password      = null;
    public $refresh_token = null;
    public $token_payload = null;
    public $form          = [];

    /**
     * @param HttpRequest $req
     * @throws HttpException
     */
    public function __construct(HttpRequest $req)
    {
        $authorization       = $req->headers['authorization'] ?? '';
        $this->refresh_token = $req->headers['x-refresh-token'] ?? '';

        $this->authorization = preg_replace('@^\s*B(earer|asic)|\s*@', '', $authorization);
        $this->form          = (object) $req->body;
        $this->grant_type    = $this->form->grant_type ?? null;

        /**
         * Check grant type
         */
        if (in_array($this->grant_type, ['password', 'refresh_token'])) {
            $this->client = $this->getClient();

            /**
             * Grant by password
             */
            if ($this->grant_type === 'password') {
                $this->username = \filter_var($this->form->username, \FILTER_SANITIZE_STRING);
                $this->password = \filter_var($this->form->password, \FILTER_SANITIZE_STRING);

                if (empty($this->username) || empty($this->password)) {
                    throw new HttpException(HttpException::BAD_REQUEST, 'missing_credentials');
                }
            }

            /**
             * Grant by refresh token
             */
            if ($this->grant_type === 'refresh_token') {
                if (empty($this->refresh_token)) {
                    throw new HttpException(HttpException::BAD_REQUEST, 'missing_refresh_token');
                }
            }
        }
    }

    /**
     * Get client credentials from authorization
     *
     * @return object
     */
    private function getClient()
    {
        @list($id, $secret) = explode(":", base64_decode($this->authorization));
        return (object) [
            'id'     => $id,
            'secret' => $secret,
        ];
    }
}
