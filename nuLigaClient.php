<?php

/**
 * Class for accessing the nuPortalRS REST interface.
 * Available endpoints are listed under https://dartde-portal.liga.nu/rs/documentation/.
 */

class nuLigaClient
{

    // nuLiga API access
    protected $baseUrl = '';
    protected $clientId = '';
    protected $clientSecret = '';

    // API scope for a "Verband"
    const SCOPE = 'nuPortalRS_federation';
    // Federation name
    const FEDERATION = 'BDV';
    // How many seconds is an access token valid?
    const TOKEN_VALID = 300;

    // Database connection.
    protected $dbHost = '';
    protected $dbUser = '';
    protected $dbPassword = '';
    protected $dbName = '';
    private $db = null;

    // The access token used for authenticating.
    private $accessToken = null;

    public function __construct($config)
    {
        $this->baseUrl = $config['api']['base_url'];
        $this->clientId = $config['api']['client_id'];
        $this->clientSecret = $config['api']['client_secret'];

        $this->dbHost = $config['db']['host'];
        $this->dbUser = $config['db']['user'];
        $this->dbPassword = $config['db']['password'];
        $this->dbName = $config['db']['name'];
    }

    /**
     * Generic request method for API endpoints.
     *
     * @param string $path the API endpoint to call
     * @param string $method which HTTP method (GET, POST, PUT, PATCH, DELETE,...)
     * @param array $queryParams additional query parameters as array (the actual query will be built here)
     * @param array $body optional data for POST or PUT requests as array
     * @param array $customHeaders optional HTTP headers as array
     * @param boolean $authenticated are we authenticated? If yes, pass Authentication header.
     *
     * @return array|mixed
     * @throws Exception
     */
    public function request(string $path, string $method = 'GET', array $queryParams = [],
                            array $body = [], array $customHeaders = [], $authenticated = true)
    {
        $url = $this->baseUrl . $path;

        $queryParams['maxResults'] = 250;

        if (count($queryParams) > 0) {
            $url .= '?' . http_build_query($queryParams);
        }

        // Get a cURL handle...
        $curl = curl_init();
        // ... for the given endpoint.
        curl_setopt($curl, CURLOPT_URL, $url);

        // Handle POST and PUT requests.
        if ($method == 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
        } else if ($method == 'PUT') {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
        }

        // Set body data for POST and PUT requests.
        if (($method == 'POST' || $method == 'PUT') && count($body) > 0) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($body));
        }

        // Needed HTTP headers.
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'accept' => 'application/json'
        ];

        if ($authenticated) {
            $this->accessToken = $this->authenticate();
            $headers['Authorization'] = sprintf('Bearer %s', $this->accessToken);
        }

        // Add custom header fields if necessary.
        if (count($customHeaders) > 0) {
            $headers = array_merge($headers, $customHeaders);
        }

        // Set headers to correct 'name: value' format and add them to request.
        $headerFields = [];
        foreach ($headers as $name => $value) {
            $headerFields[] = sprintf('%s: %s', $name, $value);
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headerFields);

        // Return call result.
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $result = curl_exec($curl);

        // Something went wrong.
        if ($result === false) {

            $http_error = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            throw new Exception(
                sprintf('Received HTTP error code %d.', $http_error)
            );

        }

        curl_close($curl);

        // Get real data from result.
        $data = json_decode($result, true);

        // Received no JSON-data?
        if (!is_array($data)) {
            throw new Exception($result);
        }

        // Some API error was returned.
        if (array_key_exists('error_description', $data)) {
            throw new Exception($data['error_description']);
        }

        // Return data from API call.
        return $data;
    }

    /**
     * Provides an access token for authenticating against the API.
     *
     * @return string
     * @throws Exception
     */
    protected function authenticate()
    {
        // Try to find an access token which was last updated less than "validity time" ago.
        $stmt = $this->getDatabase()->prepare("SELECT * FROM `token`
                        WHERE `federation` = ?
                            AND `type` = 'access'
                            AND `last_update` >= ?
                        ORDER BY `last_update` DESC LIMIT 1");
        $stmt->execute([
            nuLigaClient::FEDERATION,
            date('Y-m-d H:i:s', time() - nuLigaClient::TOKEN_VALID)
        ]);

        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        // Valid access token found, return it.
        if ($data && $data['value']) {

            return $data['value'];

        // No valid access token found, try getting a new one via refresh token.
        } else {

            // Try to find a current refresh token.
            $stmt = $this->getDatabase()->prepare("SELECT * FROM `token`
                        WHERE `federation` = ?
                            AND `type` = 'refresh'
                        ORDER BY `last_update` DESC LIMIT 1");
            $stmt->execute([
                nuLigaClient::FEDERATION
            ]);

            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            // Refreshing access token.
            if ($data && $data['value']) {

                return $this->refreshTokensFromAPI($data['value']);

            // Something went wrong, we need a completely new authentication.
            } else {

                return $this->refreshTokensFromAPI();

            }

        }
    }

    /**
     * Refresh authentication tokens via API, either by giving a refresh token
     * or by generating a new set.
     *
     * @param null|string $refreshToken
     * @return string
     * @throws Exception
     */
    protected function refreshTokensFromAPI($refreshToken = null)
    {

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];

        if ($refreshToken == null) {

            $body = [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => nuLigaClient::SCOPE
            ];

            $auth = $this->request('/rs/auth/token', 'POST', [], $body, $headers, false);

        } else {

            $body = [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => nuLigaClient::SCOPE
            ];

            try {
                $auth = $this->request('/rs/auth/token', 'POST', [], $body, $headers, false);
            // Available refresh token has failed, generate new tokens.
            } catch (Exception $e) {
                return $this->refreshTokensFromAPI();
            }

        }

        // Obtained a new token set.
        if ($auth['access_token'] && $auth['refresh_token']) {

            // Write tokens to database.
            $stored = $this->storeTokens($auth['access_token'], $auth['refresh_token']);

            return $auth['access_token'];

        // Something is seriously wrong here.
        } else {

            throw new Exception('Could not authenticate!');

        }

    }

    /**
     * Store access and refresh tokens to databaswe.
     *
     * @param string $accessToken
     * @param string $refreshToken
     * @return bool
     */
    protected function storeTokens($accessToken, $refreshToken)
    {
        $stmt = $this->getDatabase()->prepare("INSERT INTO `token`
            (`federation`, `value`, `last_update`, `type`)
            VALUES
            (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE `value` = VALUES(value), `last_update` = VALUES(last_update)");
        return $stmt->execute([nuLigaClient::FEDERATION, $accessToken, date('Y-m-d H:i:s', time()), 'access']) &&
            $stmt->execute([nuLigaClient::FEDERATION, $refreshToken, date('Y-m-d H:i:s', time()), 'refresh']);
    }

    /**
     * Get a database connection (instance singleton).
     *
     * @return PDO|null
     */
    private function getDatabase()
    {
        if ($this->db) {
            return $this->db;
        } else {
            return new PDO(
                sprintf('mysql:host=%s;dbname=%s', $this->dbHost, $this->dbName),
                $this->dbUser, $this->dbPassword
            );
        }

    }

    /**
     * Fetches all federations.
     *
     * @return array|mixed
     * @throws Exception
     */
    public function getFederations()
    {
        $data = $this->request('/rs/2014/federations');
        return $data['federationAbbr'] ?: [];
    }

    /**
     * Fetches data for a single federation.
     *
     * @param string $federation a federation nickname.
     * @return array|mixed
     * @throws Exception
     */
    public function getFederation($federation)
    {
        return $this->request(sprintf('/rs/2014/federations/%s', $federation));
    }

    /**
     * Fetches all players for a given federation.
     *
     * @param string $federation a federation nickname.
     * @return array|mixed
     * @throws Exception
     */
    public function getPlayers($federation)
    {
        $data = $this->request(sprintf('/rs/2014/federations/%s/players', $federation));
        return $data['playerAbbr'] ?: [];
    }

    /**
     * Fetches all clubs for a given federation.
     *
     * @param string $federation a federation nickname.
     * @return array|mixed
     * @throws Exception
     */
    public function getClubs($federation)
    {
        $data = $this->request(sprintf('/rs/2014/federations/%s/clubs', $federation));
        return $data['clubAbbr'] ?: [];
    }

    /**
     * Fetches all seasons for a given federation.
     *
     * @param string $federation a federation nickname.
     * @return array|mixed
     * @throws Exception
     */
    public function getSeasons($federation)
    {
        $data = $this->request(sprintf('/rs/2014/federations/%s/seasons', $federation));
        return $data['seasonAbbr'] ?: [];
    }

    /**
     * Fetches data for a single season in the given federation.
     *
     * @param string $federation a federation nickname.
     * @param string $season a season nickname.
     * @return array|mixed
     * @throws Exception
     */
    public function getSeason($federation, $season)
    {
        return $this->request(sprintf('/rs/2014/federations/%s/seasons/%s', $federation, $season));
    }

}