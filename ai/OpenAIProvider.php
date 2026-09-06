<?php
/**
 * AI Auto Work — OpenAI driver.
 *
 * All requests run server-side. The API key is read from encrypted settings at
 * call time and is never returned to the caller, logged, or sent to a browser.
 */
require_once __DIR__ . '/AIProvider.php';

class ShOpenAIProvider implements ShAIProvider
{
    public function key(): string { return 'openai'; }
    public function label(): string { return 'OpenAI'; }

    public function textModels(): array
    {
        return ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1', 'gpt-3.5-turbo'];
    }

    public function imageModels(): array
    {
        return ['gpt-image-1', 'dall-e-3', 'dall-e-2'];
    }

    /** Base URL is overridable so the pipeline can be tested against a local mock. */
    private function base(): string
    {
        $b = trim((string)sh_ai_setting('api_base', ''));
        return rtrim($b !== '' ? $b : 'https://api.openai.com', '/');
    }

    private function apiKey(): string
    {
        return sh_ai_secret('api_key');
    }

    /**
     * One HTTP call. Returns the decoded body plus status; never throws.
     *
     * @return array{ok:bool,status:int,json:?array,raw:string,error:string}
     */
    private function request(string $path, array $payload, int $timeout = 90): array
    {
        $key = $this->apiKey();
        if ($key === '') {
            return ['ok' => false, 'status' => 0, 'json' => null, 'raw' => '',
                    'error' => 'No API key configured. Add one in AI Settings.'];
        }

        $url = $this->base() . $path;
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];

        $raw = ''; $status = 0; $netErr = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $out = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $netErr = (string)curl_error($ch);
            curl_close($ch);
            $raw = $out === false ? '' : (string)$out;
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => 'POST', 'header' => implode("\r\n", $headers),
                'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true,
            ]]);
            set_error_handler(static fn(): bool => true);
            try { $out = file_get_contents($url, false, $ctx); } finally { restore_error_handler(); }
            $raw = $out === false ? '' : (string)$out;
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $status = (int)$m[1];
            }
        }

        if ($raw === '' && $status === 0) {
            return ['ok' => false, 'status' => 0, 'json' => null, 'raw' => '',
                    'error' => $netErr !== '' ? 'Could not reach the AI provider.' : 'No response from the AI provider.'];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['ok' => false, 'status' => $status, 'json' => null, 'raw' => $raw,
                    'error' => 'The AI provider returned an unreadable response.'];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'status' => $status, 'json' => $json, 'raw' => $raw,
                    'error' => $this->friendlyError($status, (string)($json['error']['message'] ?? ''))];
        }
        return ['ok' => true, 'status' => $status, 'json' => $json, 'raw' => $raw, 'error' => ''];
    }

    /** Turn a provider error into something an admin can act on. */
    private function friendlyError(int $status, string $message): string
    {
        $message = trim($message);
        return match (true) {
            $status === 401 => 'The API key was rejected. Check it in AI Settings.',
            $status === 403 => 'This API key is not allowed to use that model.',
            $status === 404 => 'That model was not found. Pick a different model in AI Settings.',
            $status === 429 => 'Rate limit or quota reached at the provider. Wait and try again.',
            $status >= 500  => 'The AI provider is temporarily unavailable. Try again shortly.',
            default         => $message !== '' ? $message : 'The AI request failed (HTTP ' . $status . ').',
        };
    }

    public function generateText(string $system, string $user, array $options = []): array
    {
        $cfg = sh_ai_config();
        $payload = [
            'model' => (string)($options['model'] ?? $cfg['model']),
            'messages' => array_values(array_filter([
                $system !== '' ? ['role' => 'system', 'content' => $system] : null,
                ['role' => 'user', 'content' => $user],
            ])),
            'temperature' => (float)($options['temperature'] ?? $cfg['temperature']),
            'max_tokens'  => (int)($options['max_tokens'] ?? $cfg['max_tokens']),
        ];

        $res = $this->request('/v1/chat/completions', $payload);
        if (!$res['ok']) { return ['ok' => false, 'error' => $res['error']]; }

        $text = (string)($res['json']['choices'][0]['message']['content'] ?? '');
        if (trim($text) === '') {
            return ['ok' => false, 'error' => 'The AI returned an empty response. Try again.'];
        }
        return [
            'ok' => true,
            'text' => trim($text),
            'tokens' => (int)($res['json']['usage']['total_tokens'] ?? 0),
        ];
    }

    public function generateImage(string $prompt, array $options = []): array
    {
        $cfg = sh_ai_config();
        $model = (string)($options['image_model'] ?? $cfg['image_model']);
        $payload = [
            'model'  => $model,
            'prompt' => mb_substr($prompt, 0, 3800),
            'n'      => 1,
            'size'   => (string)($options['size'] ?? '1024x1024'),
        ];
        // dall-e-3 rejects response_format=b64_json on some accounts; request it
        // only where it is supported and fall back to the URL form below.
        if ($model !== 'gpt-image-1') { $payload['response_format'] = 'b64_json'; }

        $res = $this->request('/v1/images/generations', $payload, 180);
        if (!$res['ok']) { return ['ok' => false, 'error' => $res['error']]; }

        $item = $res['json']['data'][0] ?? [];
        $b64 = (string)($item['b64_json'] ?? '');
        if ($b64 !== '') {
            $bin = base64_decode($b64, true);
            if ($bin === false || $bin === '') {
                return ['ok' => false, 'error' => 'The generated image could not be decoded.'];
            }
            return ['ok' => true, 'binary' => $bin, 'mime' => 'image/png'];
        }

        $url = (string)($item['url'] ?? '');
        if ($url === '') { return ['ok' => false, 'error' => 'The provider returned no image.']; }
        $bin = $this->download($url);
        if ($bin === null) { return ['ok' => false, 'error' => 'The generated image could not be downloaded.']; }
        return ['ok' => true, 'binary' => $bin, 'mime' => 'image/png'];
    }

    private function download(string $url): ?string
    {
        if (!preg_match('#^https://#i', $url)) { return null; }
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $out = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($out !== false && $code === 200 && $out !== '') ? (string)$out : null;
        }
        set_error_handler(static fn(): bool => true);
        try {
            $out = file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 120]]));
        } finally { restore_error_handler(); }
        return ($out === false || $out === '') ? null : (string)$out;
    }

    public function testConnection(): array
    {
        $key = $this->apiKey();
        if ($key === '') { return ['ok' => false, 'error' => 'No API key configured.']; }

        // One tiny completion proves key, model and connectivity in a single call.
        $res = $this->generateText('You reply with one word.', 'Reply with the single word: ready',
            ['max_tokens' => 5, 'temperature' => 0]);
        if (empty($res['ok'])) { return ['ok' => false, 'error' => (string)$res['error']]; }
        return ['ok' => true, 'detail' => 'Model responded: ' . mb_substr((string)$res['text'], 0, 40)];
    }
}
