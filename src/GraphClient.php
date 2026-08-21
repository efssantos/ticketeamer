<?php
namespace GlpiPlugin\Ticketeamer;

use RuntimeException;

final class GraphClient
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';
    private const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    public function __construct(private readonly array $config = [])
    {
    }

    public static function authorizationUrl(string $state): string
    {
        $config = Config::all();
        $tenant = rawurlencode((string) $config['tenant_id']);
        $params = [
            'client_id' => $config['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $config['redirect_uri'],
            'response_mode' => 'query',
            'scope' => 'openid profile offline_access User.Read User.ReadBasic.All Chat.Create ChatMessage.Send',
            'state' => $state,
        ];
        return sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/authorize?%s', $tenant, http_build_query($params));
    }

    public static function exchangeAuthorizationCode(string $code): string
    {
        $config = Config::all();
        $secret = getenv('GLPI_TEAMS_BRIDGE_CLIENT_SECRET');
        if (!is_string($secret) || $secret === '') {
            throw new RuntimeException('GLPI_TEAMS_BRIDGE_CLIENT_SECRET is not configured.');
        }

        $token = self::postForm(sprintf(self::TOKEN_URL, rawurlencode($config['tenant_id'])), [
            'client_id' => $config['client_id'],
            'client_secret' => $secret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $config['redirect_uri'],
            'scope' => 'openid profile offline_access User.Read User.ReadBasic.All Chat.Create ChatMessage.Send',
        ]);

        if (empty($token['refresh_token'])) {
            throw new RuntimeException('Microsoft did not return a refresh token. Check offline_access and consent.');
        }

        return Crypto::encrypt($token['refresh_token']);
    }

    public function sendPrivateMessage(string $recipientEmail, string $html): void
    {
        $recipient = $this->getUserByPrincipalName($recipientEmail);
        $recipientId = $recipient['id'] ?? null;
        if (!$recipientId) {
            throw new RuntimeException(sprintf('Microsoft user not found for %s.', $recipientEmail));
        }

        $me = $this->request('GET', '/me?$select=id,displayName,userPrincipalName');
        $senderId = $me['id'] ?? null;
        if (!$senderId) {
            throw new RuntimeException('Unable to identify the Microsoft account used by the integration.');
        }

        $chat = $this->request('POST', '/chats', [
            'chatType' => 'oneOnOne',
            'members' => [
                [
                    '@odata.type' => '#microsoft.graph.aadUserConversationMember',
                    'roles' => ['owner'],
                    'user@odata.bind' => self::GRAPH_BASE . "/users('$senderId')",
                ],
                [
                    '@odata.type' => '#microsoft.graph.aadUserConversationMember',
                    'roles' => ['owner'],
                    'user@odata.bind' => self::GRAPH_BASE . "/users('$recipientId')",
                ],
            ],
        ]);

        if (empty($chat['id'])) {
            throw new RuntimeException('Microsoft did not return a chat ID.');
        }

        $this->request('POST', '/chats/' . rawurlencode($chat['id']) . '/messages', [
            'body' => [
                'contentType' => 'html',
                'content' => $html,
            ],
        ]);
    }

    private function getUserByPrincipalName(string $email): array
    {
        $encoded = rawurlencode($email);
        return $this->request('GET', "/users('$encoded')?\$select=id,displayName,mail,userPrincipalName");
    }

    private function token(): string
    {
        $config = Config::all();
        if (empty($config['refresh_token'])) {
            throw new RuntimeException('Microsoft authorization is not configured.');
        }

        $secret = getenv('GLPI_TEAMS_BRIDGE_CLIENT_SECRET');
        if (!is_string($secret) || $secret === '') {
            throw new RuntimeException('GLPI_TEAMS_BRIDGE_CLIENT_SECRET is not configured.');
        }

        $refreshToken = Crypto::decrypt($config['refresh_token']);
        $token = self::postForm(sprintf(self::TOKEN_URL, rawurlencode($config['tenant_id'])), [
            'client_id' => $config['client_id'],
            'client_secret' => $secret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => 'openid profile offline_access User.Read User.ReadBasic.All Chat.Create ChatMessage.Send',
        ]);

        if (empty($token['access_token'])) {
            throw new RuntimeException('Unable to refresh Microsoft Graph access token.');
        }

        if (!empty($token['refresh_token'])) {
            Config::save(['refresh_token' => Crypto::encrypt($token['refresh_token'])]);
        }

        return $token['access_token'];
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::GRAPH_BASE . $path);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        $headers = [
            'Authorization: Bearer ' . $this->token(),
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Microsoft Graph request failed: ' . $error);
        }

        $decoded = $response !== '' ? json_decode($response, true) : [];
        if ($status < 200 || $status >= 300) {
            $message = $decoded['error']['message'] ?? ('HTTP ' . $status);
            throw new RuntimeException('Microsoft Graph error: ' . $message);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function postForm(string $url, array $fields): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Microsoft token request failed: ' . $error);
        }

        $decoded = json_decode($response, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['error_description'] ?? $decoded['error'] ?? ('HTTP ' . $status)) : ('HTTP ' . $status);
            throw new RuntimeException('Microsoft token error: ' . $message);
        }

        return $decoded;
    }
}
