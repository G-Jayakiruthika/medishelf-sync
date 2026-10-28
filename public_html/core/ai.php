<?php
/* Optional AI layer. Every caller has a rule-based fallback, so the site works the same without a key.
   Providers: 'anthropic' (Claude Messages API) or 'openai' (any OpenAI-compatible chat endpoint).
   The AI only ever writes words around facts the site computed itself; it never runs queries or changes data. */
declare(strict_types=1);

function ai_enabled(): bool
{
    if (!in_array((string) config('ai_provider'), ['anthropic', 'openai'], true) || (string) config('ai_api_key') === '') return false;
    if (state_get('ai_on', '1') !== '1') return false;   // admin switch
    $limit = (int) (config('ai_monthly_limit') ?? 3000);
    return (int) q("SELECT COUNT(*) FROM ai_log WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn() < $limit;
}

/* One question, one answer. Returns null on any problem (callers then use their fallback). */
function ai_text(string $purpose, string $system, string $prompt, int $maxTokens = 500): ?string
{
    if (!ai_enabled()) return null;
    $provider = (string) config('ai_provider');
    $model = (string) (config('ai_model') ?: 'claude-haiku-5-5');
    $t0 = microtime(true);
    if ($provider === 'anthropic') {
        $url = 'https://api.anthropic.com/v1/messages';
        $headers = ['x-api-key: ' . config('ai_api_key'), 'anthropic-version: 2023-06-01', 'content-type: application/json'];
        $body = ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $prompt]]];
    } else {
        $url = rtrim((string) (config('ai_base_url') ?: 'https://api.openai.com/v1'), '/') . '/chat/completions';
        $headers = ['Authorization: Bearer ' . config('ai_api_key'), 'content-type: application/json'];
        $body = ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]]];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => $headers,
                            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 6]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $text = null; $in = 0; $out = 0;
    if ($code >= 200 && $code < 300 && is_array($data)) {
        if ($provider === 'anthropic') {
            $text = implode('', array_map(fn($b) => ($b['type'] ?? '') === 'text' ? $b['text'] : '', $data['content'] ?? []));
            $in = (int) ($data['usage']['input_tokens'] ?? 0); $out = (int) ($data['usage']['output_tokens'] ?? 0);
        } else {
            $text = (string) ($data['choices'][0]['message']['content'] ?? '');
            $in = (int) ($data['usage']['prompt_tokens'] ?? 0); $out = (int) ($data['usage']['completion_tokens'] ?? 0);
        }
    } else {
        error_log("ai $purpose: HTTP $code " . substr((string) $raw, 0, 200));
    }
    q('INSERT INTO ai_log (purpose, ok, tokens_in, tokens_out, ms) VALUES (?,?,?,?,?)', [substr($purpose, 0, 20), $text ? 1 : 0, $in, $out, (int) ((microtime(true) - $t0) * 1000)]);
    $text = trim((string) $text);
    return $text !== '' ? $text : null;
}

/* Asks for JSON and parses the first {...} in the answer. */
function ai_json(string $purpose, string $system, string $prompt, int $maxTokens = 300): ?array
{
    $t = ai_text($purpose, $system . "\nReply with one JSON object only, no other text.", $prompt, $maxTokens);
    if ($t === null || !preg_match('/\{.*\}/s', $t, $m)) return null;
    $j = json_decode($m[0], true);
    return is_array($j) ? $j : null;
}

function ai_usage(): array
{
    $r = q("SELECT COUNT(*) calls, COALESCE(SUM(ok),0) ok, COALESCE(SUM(tokens_in),0) tin, COALESCE(SUM(tokens_out),0) tout FROM ai_log WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetch();
    return ['configured' => in_array((string) config('ai_provider'), ['anthropic', 'openai'], true) && (string) config('ai_api_key') !== '',
            'on' => state_get('ai_on', '1') === '1', 'provider' => (string) config('ai_provider'), 'model' => (string) (config('ai_model') ?: ''),
            'callsThisMonth' => (int) $r['calls'], 'okThisMonth' => (int) $r['ok'], 'tokensIn' => (int) $r['tin'], 'tokensOut' => (int) $r['tout'],
            'limit' => (int) (config('ai_monthly_limit') ?? 3000)];
}

/* ---- small key-value store (switches, last run times) ---- */
function &state_cache(): array { static $c = []; return $c; }
function state_get(string $k, ?string $default = null): ?string
{
    $cache = &state_cache();
    if (!array_key_exists($k, $cache)) { $v = q('SELECT v FROM system_state WHERE k = ?', [$k])->fetchColumn(); $cache[$k] = $v === false ? null : $v; }
    return $cache[$k] ?? $default;
}
function state_set(string $k, ?string $v): void
{
    q('INSERT INTO system_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
    $cache = &state_cache(); $cache[$k] = $v;
}
function autopilot_log(string $kind, string $title, ?string $detail = null): void
{
    q('INSERT INTO autopilot_log (kind, title, detail) VALUES (?,?,?)', [$kind, mb_substr($title, 0, 200), $detail]);
}
