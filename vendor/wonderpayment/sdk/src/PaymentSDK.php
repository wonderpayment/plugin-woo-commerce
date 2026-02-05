<?php



class PaymentSDK
{
    // QR code login constants
    private $qrCodeClientId;
    private $qrCodeAppKey;
    private $qrCodeAppSlug;
    private $qrCodeLinkClientId;

    /**
     * array(
     * 'appid' => '',
     * 'signaturePrivateKey' => '',
     * 'webhookVerifyPublicKey' = > '',
     * 'callback_url' => '',
     * 'redirect_url' => '',
     * 'environment' => 'stg' // Environment configuration: 'stg' or 'prod', default is 'stg'
     * 'request_id' => '' // Optional: Request ID for tracking requests, auto-generated if not provided
     * 'jwtToken' => '' // QR code login JWT Token (optional)
     * 'userAccessToken' => '' // QR code login user access token (optional)
     * 'language' => 'en-US' // Language (optional, default en-US)
     * )
     */
    private $options;
    private $appId;
    private $privateKey;
    private $publicKey;
    private $requestId;
    private $jwtToken;
    private $userAccessToken;
    private $language;

    public function __construct($options) {
        $this->options = $options;

        // Check if required parameters exist
        if (!isset($options['appid']) || !isset($options['signaturePrivateKey']) || !isset($options['webhookVerifyPublicKey'])) {
            throw new \Exception('Missing required options: appid, signaturePrivateKey, or webhookVerifyPublicKey');
        }

        // Store authentication information directly
        $this->appId = $options['appid'];
        $this->privateKey = $options['signaturePrivateKey'];
        $this->publicKey = $options['webhookVerifyPublicKey'];

        // Set request_id, generate default value if not provided
        $this->requestId = isset($options['request_id']) ? $options['request_id'] : $this->generateRequestId();

        // Initialize QR code login related properties
        $this->jwtToken = isset($options['jwtToken']) ? $options['jwtToken'] : '';
        $this->userAccessToken = isset($options['userAccessToken']) ? $options['userAccessToken'] : '';
        $this->language = isset($options['language']) ? $options['language'] : 'en-US';

        // Initialize QR code login constants based on environment
        $environment = isset($options['environment']) ? $options['environment'] : 'stg';
        $this->initQRCodeConstants($environment);
    }

    /**
     * Initialize QR code login constants
     *
     * @param string $environment Environment ('stg', 'alpha', or 'prod')
     */
    private function initQRCodeConstants($environment)
    {
        if ($environment === 'alpha') {
            // Alpha environment configuration
            $this->qrCodeClientId = '2adf8123-d65e-435e-a7c2-e0f90edd2b3d';
            $this->qrCodeAppKey = '6bad4911-baa7-4588-997c-09d23d1072df';
            $this->qrCodeAppSlug = 'JgG9C';
            $this->qrCodeLinkClientId = '2adf8123-d65e-435e-a7c2-e0f90edd2b3d';
        } elseif ($environment === 'prod') {
            // Production environment configuration
            $this->qrCodeClientId = '17175e11-d12b-43a1-b00e-ceca79876f45';
            $this->qrCodeAppKey = '9a54ed52-7a2c-4d08-aabc-4e1c548fff02';
            $this->qrCodeAppSlug = '3rTiiv';
            $this->qrCodeLinkClientId = '17175e11-d12b-43a1-b00e-ceca79876f45';
        } else {
            // Test environment configuration
            $this->qrCodeClientId = 'c4a2b6cf-983a-4117-b75f-bbeac3897c0f';
            $this->qrCodeAppKey = '02eb3362-1ccb-4063-8f5e-825fde761efb';
            $this->qrCodeAppSlug = '3Kswi8';
            $this->qrCodeLinkClientId = 'c4a2b6cf-983a-4117-b75f-bbeac3897c0f';
        }
    }

    public function verify() {
        return true;
    }

    /**
     * @throws Exception
     */
    public function createPaymentLink($params) {
        if(!is_array($params)) {
            throw new \Exception('Parameters must be an array');
        }
        $order = $params['order'];
        if(!is_array($order)) {
            throw new \Exception('order must be an array');
        }
        if(empty($order['reference_number'])) {
            throw new \Exception('Order reference number must be provided');
        }
        if(empty($order['charge_fee'])) {
            throw new \Exception('Order charge fee must be provided');
        }
        $order['callback_url'] = $this->options['callback_url'];
        $order['redirect_url'] = $this->options['redirect_url'];
        $params['order'] = $order;

        // Call internal request method to create payment link
        return $this->_request("POST","/svc/payment/api/v1/openapi/orders?with_payment_link=true", null, $params);

    }

    public function voidTransaction($params) {
        if(!is_array($params)) {
            throw new \Exception('Parameters must be an array');
        }

        $order = isset($params['order']) ? $params['order'] : null;
        $transaction = isset($params['transaction']) ? $params['transaction'] : null;

        if(!is_array($order)) {
            throw new \Exception('order must be an array');
        }

        if(!is_array($transaction)) {
            throw new \Exception('transaction must be an array');
        }

        if(empty($order['reference_number']) && empty($order['number'])) {
            throw new \Exception('Order reference number or number must be provided');
        }

        if(empty($transaction['uuid'])) {
            throw new \Exception('Transaction UUID must be provided');
        }

        // Check if transaction can be voided
        $orderResponse = $this->queryOrder($params);
        if(isset($orderResponse['data']['transactions']) && is_array($orderResponse['data']['transactions'])) {
            $allowedVoid = false;
            foreach($orderResponse['data']['transactions'] as $t) {
                if(isset($t['allowed_void']) && $t['allowed_void'] === true) {
                    $allowedVoid = true;
                    break;
                }
            }
            if(!$allowedVoid) {
                throw new \Exception('Transaction cannot be voided');
            }
        }

        return $this->_request("POST", "/svc/payment/api/v1/openapi/orders/void", null, $params);
    }

    public function refundTransaction($params) {
        if(!is_array($params)) {
            throw new \Exception('Parameters must be an array');
        }

        $order = isset($params['order']) ? $params['order'] : null;
        $transaction = isset($params['transaction']) ? $params['transaction'] : null;
        $refund = isset($params['refund']) ? $params['refund'] : null;

        if(!is_array($order)) {
            throw new \Exception('order must be an array');
        }

        if(!is_array($transaction)) {
            throw new \Exception('transaction must be an array');
        }

        if(!is_array($refund)) {
            throw new \Exception('refund must be an array');
        }

        if(empty($order['reference_number']) && empty($order['number'])) {
            throw new \Exception('Order reference number or number must be provided');
        }

        if(empty($transaction['uuid'])) {
            throw new \Exception('Transaction UUID must be provided');
        }

        if(empty($refund['amount'])) {
            throw new \Exception('Refund amount must be provided');
        }

        return $this->_request("POST", "/svc/payment/api/v1/openapi/orders/refund", null, $params);
    }

    public function voidOrder($params) {
        if(!is_array($params)) {
            throw new \Exception('Parameters must be an array');
        }

        $order = isset($params['order']) ? $params['order'] : null;
        $transaction = isset($params['transaction']) ? $params['transaction'] : null;

        if(!is_array($order)) {
            throw new \Exception('order must be an array');
        }

        // Check payment status
        $orderResponse = $this->queryOrder($params);
        if(isset($orderResponse['data']['order']['correspondence_state'])) {
            $correspondenceState = $orderResponse['data']['order']['correspondence_state'];
            if($correspondenceState !== 'unpaid') {
                throw new \Exception('Order cannot be voided. Current state: ' . $correspondenceState);
            }
        }

        return $this->_request("POST", "/svc/payment/api/v1/openapi/orders/void", null, $params);
    }

    public function queryOrder($params) {
        if(!is_array($params)) {
            throw new \Exception('Parameters must be an array');
        }
        $order = $params['order'];
        if(!is_array($order)) {
            throw new \Exception('order must be an array');
        }
        if(empty($order['reference_number'])) {
            throw new \Exception('Order reference number must be provided');
        }
        return $this->_request("POST","/svc/payment/api/v1/openapi/orders/check", null, $params);
    }


    private function _request($method, $uri, $queryParams = array(), $body = array()) {
        // Build complete URL
        $environment = isset($this->options['environment']) ? $this->options['environment'] : 'stg';
        if ($environment === 'prod') {
            $apiEndpoint = 'https://gateway.wonder.today';
        } elseif ($environment === 'alpha') {
            $apiEndpoint = 'https://gateway-alpha.wonder.today';
        } else {
            $apiEndpoint = 'https://gateway-stg.wonder.today';
        }
        $fullUrl = $apiEndpoint . $uri;

        // Add query parameters to URL if present
        if (!empty($queryParams)) {
            $fullUrl .= '?' . http_build_query($queryParams);
        }

        // Generate authentication headers
        $headers = $this->generateAuthHeaders(
            $method,
            $uri,
            json_encode($body),
            null,
            null
        );

        // Add Content-Type header
        $headers[] = 'Content-Type: application/json';

        // Initialize cURL
        $ch = curl_init();

        // Set cURL options
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        // Add request body for POST/PUT requests
        if ($method === 'POST' || $method === 'PUT') {
            $jsonData = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        }

        // Set timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);  // Connection timeout 5 seconds
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);        // Total timeout 30 seconds

        // Execute request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            curl_close($ch);
            throw new Exception('cURL error: ' . $error);
        }

        // Close cURL handle
        curl_close($ch);

        // Parse response
        $responseData = json_decode($response, true);

        if ($httpCode >= 400) {
            throw new Exception('API请求失败，HTTP状态码: ' . $httpCode . ', 响应: ' . $response);
        }

        if ($responseData === null) {
            throw new Exception('无法解析API响应: ' . $response);
        }
        return $responseData;
    }


    /**
     * Generate random string
     *
     * @param int $length String length
     * @return string Random string
     */
    private function generateRandomString($length) {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $randomString = '';
        $alphabetLength = strlen($alphabet);

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $alphabet[rand(0, $alphabetLength - 1)];
        }

        return $randomString;
    }

    /**
     * Generate pre-sign string
     *
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @param string $body Request body
     * @return string Pre-sign string
     */
    public function generatePreSignString($method, $uri, $body = null) {
        $content = strtoupper($method) . "\n" . $uri;

        if ($body !== null && strlen($body) > 0) {
            $content .= "\n" . $body;
        }

        return $content;
    }

    /**
     * Generate signature message
     *
     * @param string $credential Credential string
     * @param string $nonce Random number
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @param string $body Request body
     * @return string Signature message
     */
    public function generateSignatureMessage($credential, $nonce, $method, $uri, $body = null) {
        // Parse credential
        $parsedCredential = explode('/', $credential);
        $requestTime = $parsedCredential[1];
        $algorithm = $parsedCredential[2]; // This will be 'Wonder-RSA-SHA256'

        // First HMAC-SHA256: nonce + requestTime
        $hmac1 = hash_hmac('sha256', $requestTime, $nonce, true);

        // Second HMAC-SHA256: result + algorithm
        $hmac2 = hash_hmac('sha256', $algorithm, $hmac1, true);

        // Generate pre-sign string
        $preSignString = $this->generatePreSignString($method, $uri, $body);

        // Third HMAC-SHA256: result + preSignString
        $hmac3 = hash_hmac('sha256', $preSignString, $hmac2, true);

        // Return hexadecimal format
        return bin2hex($hmac3);
    }

    /**
     * Generate signature using private key
     *
     * @param string $data Data to sign
     * @return string Signed data (Base64 encoded)
     * @throws Exception
     */
    public function sign($data)
    {
        if (empty($this->privateKey)) {
            throw new Exception('Private key not set');
        }

        $privateKeyId = openssl_pkey_get_private($this->privateKey);
        if (!$privateKeyId) {
            throw new Exception('Unable to load private key');
        }

        // Perform SHA256 hash on HMAC-SHA256 hexadecimal result, then RSA sign
        // This corresponds to RSA_SHA256_PKCS1v15 in documentation
        $signature = '';
        $result = openssl_sign($data, $signature, $privateKeyId, OPENSSL_ALGO_SHA256);

        if (!$result) {
            throw new Exception('Signature failed');
        }

        return base64_encode($signature);
    }

    /**
     * Verify signature using public key
     * If the create API returns a payment link, this function returns true
     *
     * @param array $params Order parameters
     * @return array|bool
     */
    public function verifySignature()
    {
        $params = array(
            'order' => array(
                'reference_number' => 'test_ref_' . time() . '_' . rand(1000, 9999),
                'charge_fee' => 100,
                'due_date' => date('Y-m-d', strtotime('+7 days')),
                'currency' => 'HKD',
                'note' => 'Test order'
            )
        );
        try {
            $response = $this->createPaymentLink($params);
            // Check if response contains payment link
            if (isset($response['data']) && isset($response['data']['payment_link']) && !empty($response['data']['payment_link'])) {
                // Verification successful, return business data and true
                return [
                    'business' => isset($response['data']['order']['business']) ? $response['data']['order']['business']: null,
                    'success' => true
                ];
            }
            // Verification failed, return null and false
            return [
                'business' => null,
                'success' => false
            ];
        } catch (Exception $e) {
            // If exception occurs during payment link creation, return null and false
            return [
                'business' => null,
                'success' => false
            ];
        }
    }

    /**
     * Complete signature process: generate signature message and sign
     *
     * @param string $credential Credential string
     * @param string $nonce Random number
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @param string $body Request body
     * @return string Signed data (Base64 encoded)
     * @throws Exception
     */
    public function signRequest($credential, $nonce, $method, $uri, $body = null)
    {
        $signatureMessage = $this->generateSignatureMessage($credential, $nonce, $method, $uri, $body);
        return $this->sign($signatureMessage);
    }


    /**
     * Generate API request headers
     *
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @param string $body Request body
     * @param string $requestTime Request time (format: yyyymmddHHMMSS)
     * @param string $nonce Random number (16 random characters)
     * @return array Headers containing authentication information
     * @throws Exception
     */
    public function generateAuthHeaders($method, $uri, $body = null, $requestTime = null, $nonce = null)
    {
        if ($requestTime === null) {
            // Use UTC time, format yyyymmddHHMMSS
            $requestTime = gmdate('YmdHis');
        }

        if ($nonce === null) {
            $nonce = $this->generateRandomString(16);
        }

        $credential = $this->appId . '/' . $requestTime . '/Wonder-RSA-SHA256';

        // Normal signature process
        $signature = $this->signRequest($credential, $nonce, $method, $uri, $body);
        $headers = array(
            'Credential: ' . $credential,
            'Nonce: ' . $nonce,
            'Signature: ' . $signature
        );

        $headers[] = 'X-Request-ID: ' . $this->requestId; // Add X-Request-ID header, use the provided request_id
        return $headers;
    }
    /**
     * Generate default request_id
     *
     * @return string Generated request_id
     */
    private function generateRequestId() {
        return uniqid('req_', true);
    }

    /**
     * _signature is implemented in generateAuthHeaders
     * */
    private function _signature($method,$uri,$body = '') {
        return array(
            'Credential' => '',
            'Signature' => '',
            'Nonce' => ''
        );
    }
    /**
     * Generate RSA key pair
     *
     * @param int $keyBits Key length, default 4096
     * @return array Array containing private key and public key
     */
    public static function generateKeyPair($keyBits = 4096)
    {
        $config = array(
            'digest_alg' => 'sha256',
            'private_key_bits' => $keyBits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        );

        $res = openssl_pkey_new($config);

        if (!$res) {
            throw new Exception('Unable to generate RSA key pair');
        }

        // Extract private key
        openssl_pkey_export($res, $privateKey);

        // Extract public key
        $publicKeyDetails = openssl_pkey_get_details($res);
        $publicKey = $publicKeyDetails['key'];

        // Release resources
        openssl_pkey_free($res);

        return array(
            'private_key' => $privateKey,
            'public_key' => $publicKey
        );
    }

    // ==================== QR Code Login Related Methods ====================

    /**
     * Get base URL for QR code login
     *
     * @return string
     */
    private function getQRCodeBaseUrl()
    {
        $environment = isset($this->options['environment']) ? $this->options['environment'] : 'stg';
        if ($environment === 'prod') {
            return 'https://main.bindo.co';
        }
        if ($environment === 'alpha') {
            return 'https://main-alpha.bindo.co';
        }
        return 'https://main-stg.bindo.co';
    }

    /**
     * Get gateway base URL for QR code login
     *
     * @return string
     */
    private function getQRCodeGatewayBaseUrl()
    {
        $environment = isset($this->options['environment']) ? $this->options['environment'] : 'stg';
        if ($environment === 'prod') {
            return 'https://gateway.wonder.app';
        }
        if ($environment === 'alpha') {
            return 'https://gateway-alpha.wonder.app';
        }
        return 'https://gateway-stg.wonder.app';
    }

    /**
     * Generate UUID
     * Used for QR code login flow
     *
     * @return array Response containing UUID
     * @throws \Exception
     */
    public function generateQRCodeUUID()
    {
        $url = $this->getQRCodeBaseUrl() . '/user/b2c/qr_code';
        $requestId = $this->generateUUIDv4();

        $headers = [
            'X-Request-Id: ' . $requestId,
            'x-client-id: ' . $this->qrCodeClientId,
            'x-i18n-lang: ' . $this->language,
            'x-app-key: ' . $this->qrCodeAppKey,
            'x-app-slug: ' . $this->qrCodeAppSlug,
            'x-internal: TRUE',
            'Accept: application/json',
            'Content-Length: 0'
        ];

        return $this->makeQRCodeRequest('POST', $url, $headers, null);
    }

    /**
     * Create QR code short link
     *
     * @param string $uuid UUID obtained from generateQRCodeUUID()
     * @return array Response containing short link information
     * @throws \Exception
     */
    public function createQRCodeShortLink($uuid)
    {
        if (empty($uuid)) {
            throw new \Exception('UUID is required');
        }

        if (empty($this->jwtToken)) {
            throw new \Exception('JWT Token is required for creating QR code short link');
        }

        $url = $this->getQRCodeGatewayBaseUrl() . '/api/short-chain/qrcode-links';
        $requestId = $this->generateUUIDv4();

        $headers = [
            'authorization: Bearer ' . $this->jwtToken,
            'x-app-key: ' . $this->qrCodeAppKey,
            'x-app-slug: ' . $this->qrCodeAppSlug,
            'x-client-id: ' . $this->qrCodeLinkClientId,
            'x-i18n-lang: ' . $this->language,
            'x-request-id: ' . $requestId,
            'Content-Type: application/json',
            'Accept: application/json, text/plain, */*'
        ];

        // Add user access token if present
        if (!empty($this->userAccessToken)) {
            $headers[] = 'x-user-access-token: ' . $this->userAccessToken;
        }

        $body = [
            'type' => 'Normal',
            'link_type' => 'Scan Code Login',
            'data' => [
                'uuid' => $uuid,
                'client_id' => $this->qrCodeLinkClientId
            ]
        ];

        return $this->makeQRCodeRequest('POST', $url, $headers, json_encode($body));
    }

    /**
     * Get QR code status (single query, no polling)
     * Frontend needs to poll this interface itself
     *
     * @param string $uuid UUID of the QR code
     * @return array Status information
     * @throws \Exception
     */
    public function getQRCodeStatus($uuid)
    {
        if (empty($uuid)) {
            throw new \Exception('UUID is required');
        }

        $url = $this->getQRCodeBaseUrl() . '/user/b2c/qr_code/info?id=' . urlencode($uuid);
        $requestId = $this->generateUUIDv4();

        $headers = [
            'x-client-id: ' . $this->qrCodeClientId,
            'x-i18n-lang: ' . $this->language,
            'x-request-id: ' . $requestId,
            'Accept: application/json'
        ];

        return $this->makeQRCodeRequest('GET', $url, $headers, null);
    }

    /**
     * Create QR code (complete flow)
     * Complete UUID generation and short link creation in one step
     *
     * @return array Information containing QR code short link and UUID
     * @throws \Exception
     */
    public function createQRCode()
    {
        // 1. Generate UUID
        $uuidResponse = $this->generateQRCodeUUID();

        if (!isset($uuidResponse['data']['id'])) {
            throw new \Exception('Failed to generate UUID');
        }

        $uuid = $uuidResponse['data']['id'];

        // 2. Create short link
        $shortLinkResponse = $this->createQRCodeShortLink($uuid);

        if (!isset($shortLinkResponse['data']['shortChain'])) {
            throw new \Exception('Failed to create QR code short link');
        }

        $shortChain = $shortLinkResponse['data']['shortChain'];

        return [
            'uuid' => $uuid,
            'sUrl' => $shortChain['sUrl'],
            'lUrl' => $shortChain['lUrl'],
            'short' => $shortChain['short'],
            'expiresAt' => $shortChain['expires_at'],
            'environment' => $shortLinkResponse['data']['env']
        ];
    }

    /**
     * Make HTTP request for QR code login
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param array $headers Request headers
     * @param string $body Request body
     * @return array Response data
     * @throws \Exception
     */
    private function makeQRCodeRequest($method, $url, $headers, $body = null)
    {
        $logger = null;
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
        }

        $redactedHeaders = [];
        foreach ($headers as $header) {
            if (stripos($header, 'authorization:') === 0 || stripos($header, 'x-user-access-token:') === 0) {
                $parts = explode(':', $header, 2);
                $redactedHeaders[] = $parts[0] . ': ***';
            } else {
                $redactedHeaders[] = $header;
            }
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        // Set timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);  // Connection timeout 5 seconds
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);        // Total timeout 30 seconds

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            if ($logger) {
                $logger->error('SDK request cURL error', [
                    'source' => 'wonderpay-gateway-for-woocommerce',
                    'method' => $method,
                    'url' => $url,
                    'headers' => $redactedHeaders,
                    'body' => $body,
                    'error' => $error
                ]);
            }
            curl_close($ch);
            throw new \Exception('cURL error: ' . $error);
        }

        curl_close($ch);

        $responseData = json_decode($response, true);

        // Check API returned errors
        if (isset($responseData['code']) && $responseData['code'] != 200) {
            $errorMessage = !empty($responseData['error_message'])
                ? $responseData['error_message']
                : (!empty($responseData['message']) ? $responseData['message'] : 'Unknown error');
            if ($logger) {
                $logger->error('SDK request API error', [
                    'source' => 'wonderpay-gateway-for-woocommerce',
                    'method' => $method,
                    'url' => $url,
                    'headers' => $redactedHeaders,
                    'body' => $body,
                    'http_status' => $httpCode,
                    'response' => $responseData
                ]);
            }
            throw new \Exception('API error: ' . $errorMessage);
        }

        if ($httpCode >= 400) {
            if ($logger) {
                $logger->error('SDK request HTTP error', [
                    'source' => 'wonderpay-gateway-for-woocommerce',
                    'method' => $method,
                    'url' => $url,
                    'headers' => $redactedHeaders,
                    'body' => $body,
                    'http_status' => $httpCode,
                    'response' => $response
                ]);
            }
            throw new \Exception('API request failed, HTTP status: ' . $httpCode . ', Response: ' . $response);
        }

        if ($responseData === null) {
            if ($logger) {
                $logger->error('SDK request invalid JSON', [
                    'source' => 'wonderpay-gateway-for-woocommerce',
                    'method' => $method,
                    'url' => $url,
                    'headers' => $redactedHeaders,
                    'body' => $body,
                    'http_status' => $httpCode,
                    'response' => $response
                ]);
            }
            throw new \Exception('Failed to parse API response: ' . $response);
        }

        if ($logger) {
            $logger->debug('SDK request success', [
                'source' => 'wonderpay-gateway-for-woocommerce',
                'method' => $method,
                'url' => $url,
                'headers' => $redactedHeaders,
                'body' => $body,
                'http_status' => $httpCode,
                'response' => $responseData
            ]);
        }

        return $responseData;
    }

    /**
     * Generate UUID v4
     * Compatible with PHP 5.6+
     *
     * @return string
     */
    private function generateUUIDv4()
    {
        // Random byte generation compatible with PHP 5.6+
        if (function_exists('random_bytes')) {
            $data = random_bytes(16);
        } else if (function_exists('openssl_random_pseudo_bytes')) {
            $data = openssl_random_pseudo_bytes(16);
        } else {
            // Fallback: use mt_rand
            $data = '';
            for ($i = 0; $i < 16; $i++) {
                $data .= chr(mt_rand(0, 255));
            }
        }

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Get business list
     *
     * @return array Business list
     * @throws \Exception
     */
    public function getBusinesses()
    {
        if (empty($this->userAccessToken)) {
            throw new \Exception('User Access Token is required for getting business list');
        }

        $url = $this->getQRCodeGatewayBaseUrl() . '/api/galaxy/associates/businesses';
        $requestId = $this->generateUUIDv4();

        $headers = [
            'X-Request-Id: ' . $requestId,
            'X-USER-ACCESS-TOKEN: ' . $this->userAccessToken,
            'x-request-id: ' . $requestId,
            'Accept: application/json',
            'Connection: keep-alive'
        ];

        return $this->makeQRCodeRequest('GET', $url, $headers, null);
    }

    /**
     * Generate key pair and get App ID
     *
     * This method will:
     * 1. Receive the 2048-bit public key generated by the frontend
     * 2. Upload the public key to the server
     * 3. Server generates key pair and returns app_id
     *
     * @param string $businessId Business ID
     * @param string $publicKey 2048-bit public key
     * @return array app_id and related information
     * @throws \Exception
     */
    public function generateAppId($businessId, $publicKey)
    {
        if (empty($this->userAccessToken)) {
            throw new \Exception('User Access Token is required for generating app_id');
        }

        if (empty($businessId)) {
            throw new \Exception('Business ID is required');
        }

        if (empty($publicKey)) {
            throw new \Exception('Public Key is required');
        }

        // URL format: https://main-<env>.bindo.co/svc/user/api/v1/{business_id}/app
        $environment = isset($this->options['environment']) ? $this->options['environment'] : 'stg';
        if ($environment === 'prod') {
            $baseUrl = 'https://main.bindo.co';
        } elseif ($environment === 'alpha') {
            $baseUrl = 'https://main-alpha.bindo.co';
        } else {
            $baseUrl = 'https://main-stg.bindo.co';
        }

        $url = $baseUrl . '/svc/user/api/v1/' . $businessId . '/app';
        $requestId = $this->generateUUIDv4();

        $headers = [
            'X-Request-Id: ' . $requestId,
            'x-app-key: ' . $this->qrCodeAppKey,
            'x-app-slug: ' . $this->qrCodeAppSlug,
            'x-client-id: ' . $this->qrCodeLinkClientId,
            'x-i18n-lang: ' . $this->language,
            'x-internal: TRUE',
            'x-p-business-id: ' . $businessId,
            'X-USER-ACCESS-TOKEN: ' . $this->userAccessToken,
            'x-request-id: ' . $requestId,
            'Accept: application/json',
            'Content-Type: application/json'
        ];
        // Convert PEM format public key to Base64 format
        // API expects Base64 encoding of the entire PEM file (including header and footer)
        $publicKeyBase64 = base64_encode($publicKey);

        // Request body contains public key - parameter name must be signature_public_key, value must be Base64 format
        $body = json_encode([
            'signature_public_key' => $publicKeyBase64
        ]);
        return $this->makeQRCodeRequest('POST', $url, $headers, $body);
    }

    /**
     * Fetch current user info by access token
     *
     * @return array
     * @throws \Exception
     */
    public function getUserInfo()
    {
        if (empty($this->userAccessToken)) {
            throw new \Exception('User Access Token is required to fetch user info');
        }

        $url = $this->getQRCodeBaseUrl() . '/user/b2c/me';
        $requestId = $this->generateUUIDv4();

        $headers = [
            'x-app-key: ' . $this->qrCodeAppKey,
            'x-app-slug: ' . $this->qrCodeAppSlug,
            'x-client-id: ' . $this->qrCodeClientId,
            'x-i18n-lang: ' . $this->language,
            'x-internal: TRUE',
            'x-request-id: ' . $requestId,
            'x-user-access-token: ' . $this->userAccessToken,
            'Accept: application/json'
        ];

        return $this->makeQRCodeRequest('GET', $url, $headers, null);
    }

    /**
     * Sandbox public login
     *
     * @param string $referenceId
     * @return array
     * @throws \Exception
     */
    public function sandboxPublicLogin($referenceId)
    {
        if (empty($this->userAccessToken)) {
            throw new \Exception('User Access Token is required for sandbox public login');
        }

        if (empty($referenceId)) {
            throw new \Exception('reference_id is required for sandbox public login');
        }

        $requestId = $this->generateUUIDv4();

//        $headers = [
//            'x-app-key: 6bad4911-baa7-4588-997c-09d23d1072df',
//            'x-app-slug: JgG9C',
//            'x-client-id: c4a2b6cf-983a-4117-b75f-bbeac3897c0f',
//            'x-i18n-lang: zh-CN',
//            'x-internal: TRUE',
//            'x-request-id: ' . $requestId,
//            'x-user-access-token: ' . $this->userAccessToken,
//            'Accept: application/json',
//            'Content-Type: application/json'
//        ];
        $headers = [
            'x-app-key: ' . $this->qrCodeAppKey,
            'x-app-slug: ' . $this->qrCodeAppSlug,
            'x-client-id: ' . $this->qrCodeClientId,
            'x-i18n-lang: ' . $this->language,
            'x-internal: TRUE',
            'x-request-id: ' . $requestId,
            'x-user-access-token: ' . $this->userAccessToken,
            'Accept: application/json',
            'Content-Type: application/json'
        ];

        $body = json_encode([
            'reference_id' => $referenceId
        ]);

        $url = 'https://main-stg.bindo.co/svc/user/public/login';
        return $this->makeQRCodeRequest('POST', $url, $headers, $body);
    }

    /**
     * Sandbox onboarding business
     *
     * @param string $sandboxUserId
     * @param string $sandboxUserToken
     * @param string $pBusinessId
     * @param string $sandboxBusinessName
     * @return array
     * @throws \Exception
     */
    public function sandboxOnboardingBusiness($sandboxUserId, $sandboxUserToken, $pBusinessId, $sandboxBusinessName = '')
    {
        if (empty($this->userAccessToken)) {
            throw new \Exception('User Access Token is required for sandbox onboarding');
        }
        if (empty($sandboxUserId) || empty($sandboxUserToken)) {
            throw new \Exception('sandbox_user_id and sandbox_user_token are required');
        }
        if (empty($pBusinessId)) {
            throw new \Exception('p_business_id is required');
        }

        $requestId = $this->generateUUIDv4();
        $url = 'https://gateway-alpha.wonder.app/api/registry/onboarding/sandbox/business';

        $headers = [
            'x-app-key: 6bad4911-baa7-4588-997c-09d23d1072df',
            'x-app-slug: JgG9C',
            'x-client-id: 2adf8123-d65e-435e-a7c2-e0f90edd2b3d',
            'x-i18n-lang: zh-CN',
            'x-internal: TRUE',
            'x-p-business-id: ' . $pBusinessId,
            'x-request-id: ' . $requestId,
            'x-user-access-token: ' . $this->userAccessToken,
            'Accept: application/json',
            'Content-Type: application/json'
        ];

        $body = [
            'sandbox_user_id' => $sandboxUserId,
            'sandbox_user_token' => $sandboxUserToken,
            'p_business_id' => $pBusinessId
        ];

        if (!empty($sandboxBusinessName)) {
            $body['sandbox_business_name'] = $sandboxBusinessName;
        }

        return $this->makeQRCodeRequest('POST', $url, $headers, json_encode($body));
    }
}
