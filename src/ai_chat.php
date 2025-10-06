<?php
// Development mode
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session early for CSRF persistence
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

require_once __DIR__ . '/utils.php';

// Helper functions (restored after manual edits)
if (!function_exists('is_json')) {
    function is_json($str) {
        if (!is_string($str) || $str === '') return false;
        json_decode($str);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
if (!function_exists('pretty_json')) {
    function pretty_json($str) {
        $decoded = json_decode($str, true);
        if (json_last_error() !== JSON_ERROR_NONE) return $str;
        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
if (!function_exists('normalize_newlines')) {
    function normalize_newlines($s) { return str_replace(["\r\n", "\r"], "\n", (string)$s); }
}
if (!function_exists('parse_headers_input')) {
    function parse_headers_input($text) {
        $headers = [];
        $text = normalize_newlines((string)$text);
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) continue;
            $parts = explode(':', $line, 2);
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            if ($name !== '') $headers[] = $name . ': ' . $value;
        }
        return $headers;
    }
}
if (!function_exists('kv_text_to_array')) {
    function kv_text_to_array($text) {
        $out = [];
        $text = normalize_newlines((string)$text);
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $eq = strpos($line, '=');
            if ($eq === false) { $out[$line] = ''; continue; }
            $k = trim(substr($line, 0, $eq));
            $v = trim(substr($line, $eq+1));
            $out[$k] = $v;
        }
        return $out;
    }
}
if (!function_exists('has_header')) {
    function has_header($headers, $needle) {
        $needle = strtolower($needle);
        foreach ($headers as $hline) {
            $name = strtolower(trim(explode(':', $hline, 2)[0] ?? ''));
            if ($name === $needle) return true;
        }
        return false;
    }
}
if (!function_exists('ensure_content_type')) {
    function ensure_content_type(&$headers, $contentType) {
        if (!$contentType) return;
        if (!has_header($headers, 'content-type')) {
            $headers[] = 'Content-Type: ' . $contentType;
        }
    }
}
if (!function_exists('build_chat_json_body')) {
	function build_chat_json_body($model, $numCtx, $temperature = 0.7) {
		$model = (string)$model;
		$numCtx = max(1, (int)$numCtx);
		return json_encode([
			'model' => $model !== '' ? $model : 'openai/gpt-oss-20b',
			'stream' => false,
			'temperature' => $temperature,
			'messages' => [
				[ 'role' => 'system', 'content' => 'You are a helpful assistant that replies in English.' ],
				[ 'role' => 'user', 'content' => 'Hello' ],
			],
			'options' => [
				'num_ctx' => $numCtx,
			],
			'tags' => [ 'lang' => 'en', 'source' => 'php_mc' ],
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}

$chat_json =  __DIR__ . '/private/codewalker.json';
$db_chat =  __DIR__ . '/private/db/chat.db';

// Prefill sources (env + codewalker config)
$prefill = [
    'base_url' => null,
    'api_key' => null,
    'model' => null,
    'timeout' => 60,
    'max_filesize_kb' => 5 * 1024, // 5 MB default
    'max_context_size' => null,
];

$cfg = [];

// Lightweight .env parser (KEY=VALUE, ignores comments) - keeps values in memory only.
(function() use (&$prefill) {
    $envFile = __DIR__ . '/private/.env';
    if (!is_readable($envFile)) return;
    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $k = trim(substr($line, 0, $eq));
        $v = trim(substr($line, $eq+1));
        if ($k === 'LLM_BASE_URL') $prefill['base_url'] = rtrim($v, '/');
        elseif ($k === 'LLM_API_KEY') $prefill['api_key'] = $v;
		elseif ($k === 'LLM_MODEL') $prefill['model'] = $v;
		elseif (in_array($k, ['LLM_NUM_CTX','LLM_CONTEXT_TOKENS','LLM_MAX_TOKENS','LLM_MAX_CONTEXT'], true)) {
			$prefill['max_context_size'] = max(1, (int)$v);
		} elseif (in_array($k, ['OPENAI_NUM_CTX','OPENAI_MAX_TOKENS','OPENAI_CONTEXT_TOKENS'], true) && empty($prefill['max_context_size'])) {
			$prefill['max_context_size'] = max(1, (int)$v);
		} elseif ($k === 'OLLAMA_NUM_CTX' && empty($prefill['max_context_size'])) {
			$prefill['max_context_size'] = max(1, (int)$v);
		}
    }
})();

// Load codewalker.json to capture model/context defaults if not provided in .env
if (is_readable($chat_json)) {
	$raw = @file_get_contents($chat_json);
	if ($raw !== false) {
		$decoded = json_decode($raw, true);
		if (is_array($decoded)) {
			$cfg = $decoded;
			if (!$prefill['model'] && !empty($cfg['model'])) {
				$prefill['model'] = (string)$cfg['model'];
			}
			if (isset($cfg['max_filesize_kb'])) {
				$prefill['max_filesize_kb'] = max(1, (int)$cfg['max_filesize_kb']);
			}
			$contextKeys = ['max_context_size','max_context_tokens','context_tokens','num_ctx','num_ctx_tokens','max_tokens'];
			foreach ($contextKeys as $contextKey) {
				if (isset($cfg[$contextKey]) && $cfg[$contextKey] !== '') {
					$prefill['max_context_size'] = max(1, (int)$cfg[$contextKey]);
					break;
				}
			}
			if (empty($prefill['max_context_size']) && isset($cfg['max_filesize_kb'])) {
				// As a last resort, reuse max_filesize_kb (treating the numeric value as the desired context size)
				$prefill['max_context_size'] = max(1, (int)$cfg['max_filesize_kb']);
			}
		}
	}
}

// Derive endpoint from base_url (assume OpenAI-compatible /v1/chat/completions OR /v1/chat) heuristics
if ($prefill['base_url']) {
    // Prefer /v1/chat if user already typed a URL we'll not override (handled later).
    $prefill['url'] = $prefill['base_url'] . '/v1/chat';
}

// Build Authorization header template
$prefill['auth_header'] = $prefill['api_key'] ? 'Authorization: Bearer ' . $prefill['api_key'] : '';

// Provide a default JSON body including model if available
$prefill['json_body'] = build_chat_json_body($prefill['model'] ?? 'openai/gpt-oss-20b', $prefill['max_context_size'] ?? 4096);


// CSRF token
if (empty($_SESSION['csrf_api_console'])) {
    $_SESSION['csrf_api_console'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_api_console'];

// Defaults
$defaultUrl = isset($_GET['url']) ? (string)$_GET['url'] : $prefill['url'];
$defaultMethod = isset($_GET['method']) ? (string)$_GET['method'] : 'POST';
$defaultBodyFormat = isset($_GET['body_format']) ? (string)$_GET['body_format'] : 'json'; // json|form|raw
$defaultHeaders = isset($_GET['headers']) ? (string)$_GET['headers'] : $prefill['auth_header'];
$defaultJsonBody = isset($_GET['json']) ? (string)$_GET['json'] : $prefill['json_body'];
$defaultFormBody = isset($_GET['form']) ? (string)$_GET['form'] : "key1=value1\nkey2=value2";
$defaultRawBody  = isset($_GET['raw']) ? (string)$_GET['raw'] : '';
$defaultTimeout  = isset($_GET['timeout']) ? (int)$_GET['timeout'] : $prefill['timeout'];

// Load from saved log for re-run
$loadData = null;
if (isset($_GET['load'])) {
		$loadId = (int)$_GET['load'];
		if ($loadId > 0 && is_readable($db_chat)) {
				try {
						$pdo = new PDO('sqlite:' . $db_chat);
						$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
						$row = $pdo->query('SELECT * FROM api_test_logs WHERE id=' . $loadId)->fetch(PDO::FETCH_ASSOC);
						if ($row) {
								$loadData = $row;
								$defaultUrl = $row['url'] ?? $defaultUrl;
								$defaultMethod = $row['method'] ?? $defaultMethod;
								$defaultHeaders = $row['req_headers'] ?? $defaultHeaders;
								$defaultBodyFormat = $row['body_format'] ?? $defaultBodyFormat;
								if ($defaultBodyFormat === 'json') $defaultJsonBody = (string)($row['req_body'] ?? $defaultJsonBody);
								elseif ($defaultBodyFormat === 'form') $defaultFormBody = (string)($row['req_body'] ?? $defaultFormBody);
								else $defaultRawBody = (string)($row['req_body'] ?? $defaultRawBody);
						}
				} catch (Throwable $e) {
						// ignore load errors silently in UI
				}
		}
}

$result = null; // will hold execution results
$saveResultMsg = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['run_test'])) {
    // Basic CSRF check
    if (!isset($_POST['csrf'])) {
        $result = ['error' => 'Missing CSRF token.'];
    } elseif (!hash_equals($csrf, (string)$_POST['csrf'])) {
        $result = ['error' => 'Invalid CSRF token.'];
    } else {
				$url = trim((string)($_POST['url'] ?? $defaultUrl));
				$method = strtoupper(trim((string)($_POST['method'] ?? 'POST')));
				$bodyFormat = (string)($_POST['body_format'] ?? 'json');
				$headersText = (string)($_POST['headers'] ?? '');
				$timeout = max(1, (int)($_POST['timeout'] ?? 60));
				$saveToDb = isset($_POST['save_to_db']) ? 1 : 0;

				$jsonBody = (string)($_POST['json_body'] ?? '');
				$formBody = (string)($_POST['form_body'] ?? '');
				$rawBody  = (string)($_POST['raw_body'] ?? '');

				$headers = parse_headers_input($headersText);

				$postFields = null;
				$reqBodyForLog = '';
				$contentTypeToEnsure = null;

				if ($method === 'GET' || $method === 'HEAD') {
						// Typically no body
						$postFields = null;
						$reqBodyForLog = '';
				} else {
						if ($bodyFormat === 'json') {
								$reqBodyForLog = trim($jsonBody);
								// Pretty validate JSON; if invalid, still send raw to allow testing, but mark warning
								$contentTypeToEnsure = 'application/json';
								$postFields = $reqBodyForLog;
						} elseif ($bodyFormat === 'form') {
								$arr = kv_text_to_array($formBody);
								$reqBodyForLog = normalize_newlines($formBody);
								$contentTypeToEnsure = 'application/x-www-form-urlencoded';
								$postFields = http_build_query($arr);
						} else { // raw
								$reqBodyForLog = $rawBody;
								// Let user supply content-type in headers
								$postFields = $rawBody;
						}
				}

				// Ensure content-type header if we know the format
				ensure_content_type($headers, $contentTypeToEnsure);

				// Execute HTTP request via cURL
				$ch = curl_init();
				$responseHeaders = '';
				$responseChunks = [];
				curl_setopt($ch, CURLOPT_URL, $url);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
				curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
				curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
				curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
				curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$responseHeaders) {
						$responseHeaders .= $header;
						return strlen($header);
				});
				$chunkStart = microtime(true);
				curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) use (&$responseChunks, $chunkStart) {
						$now = microtime(true);
						$responseChunks[] = [
							'offset' => $now - $chunkStart,
							'size' => strlen($chunk),
							'data' => $chunk,
						];
						return strlen($chunk);
				});

				// Method and body
				switch ($method) {
						case 'GET':
								curl_setopt($ch, CURLOPT_HTTPGET, true);
								break;
						case 'POST':
								curl_setopt($ch, CURLOPT_POST, true);
								break;
						default:
								curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
				}
				if ($postFields !== null && $method !== 'GET' && $method !== 'HEAD') {
						curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
				}
				if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

				$start = microtime(true);
				$curlSuccess = curl_exec($ch);
				$end = microtime(true);
				$curlErrNo = curl_errno($ch);
				$curlError = $curlErrNo ? curl_error($ch) : '';
				$info = curl_getinfo($ch);
				curl_close($ch);
				$responseBody = '';
				if ($responseChunks) {
						foreach ($responseChunks as $chunk) {
							$responseBody .= $chunk['data'];
						}
				} elseif (is_string($curlSuccess)) {
						$responseBody = $curlSuccess;
				}

				$statusCode = (int)($info['http_code'] ?? 0);
				$duration = ($end - $start);

				$result = [
						'url' => $url,
						'method' => $method,
						'body_format' => $bodyFormat,
						'req_headers' => $headers,
						'req_body' => $reqBodyForLog,
						'status' => $statusCode,
						'resp_headers' => (string)$responseHeaders,
						'resp_body' => (string)$responseBody,
						'curl_errno' => $curlErrNo,
						'curl_error' => $curlError,
						'curl_info' => $info,
						'chunks' => array_map(function($chunk) {
							return [
								'offset_ms' => $chunk['offset'] * 1000,
								'size' => $chunk['size'],
								'data' => $chunk['data'],
							];
						}, $responseChunks),
						'duration' => $duration,
				];

				// Optional save to DB
				if ($saveToDb) {
						try {
								$pdo = new PDO('sqlite:' . $db_chat);
								$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
								$pdo->exec('CREATE TABLE IF NOT EXISTS api_test_logs (
										id INTEGER PRIMARY KEY AUTOINCREMENT,
										created_at TEXT NOT NULL,
										url TEXT NOT NULL,
										method TEXT NOT NULL,
										body_format TEXT NOT NULL,
										req_headers TEXT,
										req_body TEXT,
										status_code INTEGER,
										resp_headers TEXT,
										resp_body TEXT,
										curl_errno INTEGER,
										curl_error TEXT,
										curl_info TEXT
								)');

								$stmt = $pdo->prepare('INSERT INTO api_test_logs (
										created_at, url, method, body_format, req_headers, req_body, status_code, resp_headers, resp_body, curl_errno, curl_error, curl_info
								) VALUES (
										:created_at, :url, :method, :body_format, :req_headers, :req_body, :status_code, :resp_headers, :resp_body, :curl_errno, :curl_error, :curl_info
								)');
								$stmt->execute([
										':created_at' => gmdate('c'),
										':url' => $url,
										':method' => $method,
										':body_format' => $bodyFormat,
										':req_headers' => implode("\n", $headers),
										':req_body' => $reqBodyForLog,
										':status_code' => $statusCode,
										':resp_headers' => (string)$responseHeaders,
										':resp_body' => (string)$responseBody,
										':curl_errno' => $curlErrNo,
										':curl_error' => $curlError,
										':curl_info' => json_encode($info),
								]);
								$saveId = (int)$pdo->lastInsertId();
								$saveResultMsg = 'Saved log as ID ' . $saveId;
						} catch (Throwable $e) {
								$saveResultMsg = 'DB save failed: ' . h($e->getMessage());
						}
				}
		}
}

// Fetch recent logs for listing
$recentLogs = [];
if (is_readable($db_chat)) {
		try {
				$pdo = new PDO('sqlite:' . $db_chat);
				$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				$stmt = $pdo->query('SELECT id, created_at, url, method, status_code FROM api_test_logs ORDER BY id DESC LIMIT 50');
				if ($stmt) $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (Throwable $e) {
				// ignore listing error
		}
}

// View specific log
$viewLog = null;
if (isset($_GET['view'])) {
		$viewId = (int)$_GET['view'];
		if ($viewId > 0 && is_readable($db_chat)) {
				try {
						$pdo = new PDO('sqlite:' . $db_chat);
						$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
						$stmt = $pdo->prepare('SELECT * FROM api_test_logs WHERE id = :id');
						$stmt->execute([':id' => $viewId]);
						$row = $stmt->fetch(PDO::FETCH_ASSOC);
						if ($row) $viewLog = $row;
				} catch (Throwable $e) {
						$viewLog = ['error' => $e->getMessage()];
				}
		}
}

// Extend prefill to hold multiple endpoints
$endpoints = [];

// Parse env again for broad patterns (keeping prior parsing — could be unified but kept small for clarity)
(function() use (&$endpoints) {
    $envFile = __DIR__ . '/private/.env';
    if (!is_readable($envFile)) return;
    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return;
    $data = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $k = trim(substr($line, 0, $eq));
        $v = trim(substr($line, $eq+1));
        $data[$k] = $v;
    }
    // Detect groups
    // OPENAI_* (assume standard api.openai.com style base)
    if (!empty($data['OPENAI_API_KEY'])) {
        $base = !empty($data['OPENAI_BASE_URL']) ? rtrim($data['OPENAI_BASE_URL'], '/') : 'https://api.openai.com';
        $model = !empty($data['OPENAI_MODEL']) ? $data['OPENAI_MODEL'] : (!empty($data['LLM_MODEL']) ? $data['LLM_MODEL'] : 'gpt-4o');
        $endpoints[] = [
            'id' => 'openai',
            'label' => 'OpenAI (' . $model . ')',
            'url' => $base . '/v1/chat/completions',
            'auth_header' => 'Authorization: Bearer ' . $data['OPENAI_API_KEY'],
            'model' => $model,
            'kind' => 'openai'
        ];
    }
    // LLM_ (local LM Studio style)
    if (!empty($data['LLM_BASE_URL'])) {
        $model = !empty($data['LLM_MODEL']) ? $data['LLM_MODEL'] : 'openai/gpt-oss-20b';
        $endpoints[] = [
            'id' => 'lmstudio',
            'label' => 'LM Studio (' . $model . ')',
            'url' => rtrim($data['LLM_BASE_URL'], '/') . '/v1/chat',
            'auth_header' => !empty($data['LLM_API_KEY']) ? 'Authorization: Bearer ' . $data['LLM_API_KEY'] : '',
            'model' => $model,
            'kind' => 'openai'
        ];
    }
    // OLLAMA_ (ollama host) — uses /api/chat or /v1/chat? We'll choose /v1/chat for OpenAI-compatible proxies if present.
    if (!empty($data['OLLAMA_HOST'])) {
        $model = !empty($data['OLLAMA_MODEL']) ? $data['OLLAMA_MODEL'] : 'llama3';
        $ollamaBase = rtrim($data['OLLAMA_HOST'], '/');
        // Heuristic: prefer /v1/chat if some proxy is used; else fall back to local /api/chat
        $url = $ollamaBase . '/api/chat';
        $endpoints[] = [
            'id' => 'ollama',
            'label' => 'Ollama (' . $model . ')',
            'url' => $url,
            'auth_header' => '',
            'model' => $model,
            'kind' => 'ollama'
        ];
    }
})();

// Choose default endpoint (first in list) if no URL provided via GET
$selectedEndpointId = isset($_POST['endpoint']) ? (string)$_POST['endpoint'] : (isset($_GET['endpoint']) ? (string)$_GET['endpoint'] : null);
$endpointMap = [];
foreach ($endpoints as $ep) { $endpointMap[$ep['id']] = $ep; }
if ($selectedEndpointId && isset($endpointMap[$selectedEndpointId])) {
    $activeEp = $endpointMap[$selectedEndpointId];
} else {
    $activeEp = $endpoints ? $endpoints[0] : null;
}

// If we have an active endpoint and no explicit GET url override, set prefill accordingly (but don't stomp existing user GET params already parsed earlier)
if ($activeEp) {
    if (empty($_GET['url'])) $prefill['url'] = $activeEp['url'];
    if (empty($_GET['headers']) && $activeEp['auth_header']) $prefill['auth_header'] = $activeEp['auth_header'];
    // Ensure JSON body model matches chosen endpoint if user hasn't overridden it via GET
    if (empty($_GET['json'])) {
		$prefill['json_body'] = build_chat_json_body($activeEp['model'], $prefill['max_context_size'] ?? 4096);
    }
}

?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>API Test Console</title>
	<style>
		body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 1rem; color: #222; }
		h1 { margin: 0 0 0.5rem 0; }
		small.muted { color: #666; }
		form .row { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; flex-wrap: wrap; }
		input[type=text], input[type=number], select, textarea { width: 100%; box-sizing: border-box; padding: 8px; font-family: inherit; }
		label { font-weight: 600; }
		.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
		.grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
		.card { border: 1px solid #ddd; border-radius: 8px; padding: 12px; background: #fff; }
		.btn { padding: 8px 12px; border: 1px solid #444; background: #111; color: #fff; border-radius: 6px; cursor: pointer; }
		.btn.secondary { background: #fff; color: #111; }
		.note { background: #f5f5f5; padding: 8px; border-radius: 6px; }
		pre { background: #0b0b0b; color: #e7e7e7; padding: 8px; border-radius: 6px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
		code.block { display: block; }
		.status-ok { color: #0a7a0a; }
		.status-bad { color: #b30000; }
		details { margin-top: 8px; }
		table { border-collapse: collapse; width: 100%; }
		th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
		.flex { display: flex; gap: 8px; align-items: center; }
		.w-100 { width: 100%; }
		.mh-200 { max-height: 300px; overflow: auto; }
	</style>
	<script>
		function switchBodyFormat(fmt) {
			document.getElementById('body_json').style.display = (fmt === 'json') ? 'block' : 'none';
			document.getElementById('body_form').style.display = (fmt === 'form') ? 'block' : 'none';
			document.getElementById('body_raw').style.display  = (fmt === 'raw')  ? 'block' : 'none';
		}
		function applyEndpointOption(opt) {
			if (!opt) return;
			var urlEl = document.getElementById('url');
			var headersEl = document.getElementById('headers');
			var jsonEl = document.getElementById('json_body');
			var newUrl = opt.getAttribute('data-url');
			var newAuth = opt.getAttribute('data-auth');
			var newModel = opt.getAttribute('data-model');
			// Only replace URL if blank or unchanged from previous selected endpoint
			if (urlEl && (urlEl.value.trim() === '' || urlEl.dataset.autofill === '1')) {
				urlEl.value = newUrl;
				urlEl.dataset.autofill = '1';
			}
			// Only set Authorization if field does not already contain a non-matching Authorization
			if (headersEl && newAuth) {
				var hVal = headersEl.value.trim();
				if (hVal === '' || headersEl.dataset.autofill === '1') {
					headersEl.value = newAuth + (hVal ? '\n' + hVal : '');
					headersEl.dataset.autofill = '1';
				}
			}
			// Replace model inside JSON body if it contains a model field and was autofilled
			if (jsonEl && newModel) {
				var body = jsonEl.value;
				if (jsonEl.dataset.autofill === '1') {
					body = body.replace(/"model"\s*:\s*"[^"]+"/, '"model": "' + newModel + '"');
					jsonEl.value = body;
				}
			}
		}
		document.addEventListener('DOMContentLoaded', function() {
			var sel = document.getElementById('body_format');
			if (sel) switchBodyFormat(sel.value);
			if (sel) sel.addEventListener('change', function(){ switchBodyFormat(sel.value); });
			var epSel = document.getElementById('endpoint');
			if (epSel) {
				// Mark initial autofill state
				var urlEl = document.getElementById('url');
				var headersEl = document.getElementById('headers');
				var jsonEl = document.getElementById('json_body');
				if (urlEl && urlEl.value) urlEl.dataset.autofill = '1';
				if (headersEl && headersEl.value) headersEl.dataset.autofill = '1';
				if (jsonEl && jsonEl.value) jsonEl.dataset.autofill = '1';
				epSel.addEventListener('change', function() { applyEndpointOption(epSel.options[epSel.selectedIndex]); });
			}
			['url','headers','json_body'].forEach(function(id){
				var el = document.getElementById(id);
				if (el) el.addEventListener('input', function(){ delete el.dataset.autofill; });
			});
		});
	</script>
</head>
<body>
	<h1>API Test Console</h1>
    <?php echo render_nav_menu(basename(__FILE__)); ?>
	<div class="note">Use this tool to test endpoints like <code>https://127.0.0.1:5000/v1/chat</code> or OpenAI-compatible APIs. Provide headers (e.g., Authorization) as needed.</div>

	<form method="post" class="card" autocomplete="off">
		<input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
		<div class="row">
			<label for="url" class="w-100">Request URL</label>
			<input type="text" id="url" name="url" value="<?php echo h($defaultUrl); ?>" placeholder="https://127.0.0.1:5000/v1/chat">
		</div>
		<div class="grid">
			<div>
				<label for="method">Method</label>
				<select id="method" name="method">
					<?php
						$methods = ['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'];
						foreach ($methods as $m) {
								$sel = ($m === $defaultMethod) ? 'selected' : '';
								echo '<option value="' . h($m) . '" ' . $sel . '>' . h($m) . '</option>';
						}
					?>
				</select>
			</div>
			<div>
				<label for="timeout">Timeout (s)</label>
				<input type="number" id="timeout" name="timeout" min="1" max="300" value="<?php echo (int)$defaultTimeout; ?>">
			</div>
			<div>
				<label>&nbsp;</label>
				<div class="flex"><input type="checkbox" id="save_to_db" name="save_to_db" checked> <label for="save_to_db">Save request + response</label></div>
			</div>
		</div>

		<div class="row">
			<label for="headers" class="w-100">Headers (one per line: Name: Value)</label>
			<!-- Note: The Authorization header (if prefilled) comes from /private/.env. Editing it here only affects this request unless you save the log. Avoid pasting long-term secrets into saved logs if logs may be shared. -->
			<textarea id="headers" name="headers" rows="4" placeholder="Authorization: Bearer sk-...\nContent-Type: application/json"><?php echo h($defaultHeaders); ?></textarea>
		</div>

		<div class="row">
            <label for="endpoint" class="w-100">Endpoint Preset</label>
            <select id="endpoint" name="endpoint">
                <?php foreach ($endpoints as $ep): $sel = ($activeEp && $activeEp['id'] === $ep['id']) ? 'selected' : ''; ?>
                    <option value="<?php echo h($ep['id']); ?>" data-url="<?php echo h($ep['url']); ?>" data-auth="<?php echo h($ep['auth_header']); ?>" data-model="<?php echo h($ep['model']); ?>" <?php echo $sel; ?>><?php echo h($ep['label']); ?></option>
                <?php endforeach; ?>
                <?php if (!$endpoints): ?>
                    <option value="" selected disabled>No .env endpoints detected</option>
                <?php endif; ?>
            </select>
            <small class="muted">Select an environment-defined endpoint or type a custom URL below. Switching updates empty URL/headers/model fields.</small>
        </div>

		<div class="grid">
			<div>
				<label for="body_format">Body format</label>
				<select id="body_format" name="body_format">
					<?php
						$formats = ['json'=>'JSON (application/json)','form'=>'Form-encoded (x-www-form-urlencoded)','raw'=>'Raw'];
						foreach ($formats as $val=>$label) {
								$sel = ($val === $defaultBodyFormat) ? 'selected' : '';
								echo '<option value="' . h($val) . '" ' . $sel . '>' . h($label) . '</option>';
						}
					?>
				</select>
			</div>
		</div>

		<div id="body_json" style="display:none;">
			<label for="json_body">JSON Body</label>
			<textarea id="json_body" name="json_body" rows="10" placeholder="{\n  &quot;messages&quot;: [ { &quot;role&quot;: &quot;user&quot;, &quot;content&quot;: &quot;Hello&quot; } ]\n}"><?php echo h($defaultJsonBody); ?></textarea>
			<small class="muted">JSON will be sent as-is. Ensure it's valid for best results.</small>
		</div>

		<div id="body_form" style="display:none;">
			<label for="form_body">Form Body (key=value per line)</label>
			<textarea id="form_body" name="form_body" rows="8" placeholder="key1=value1\nkey2=value2\n# comment lines start with #"><?php echo h($defaultFormBody); ?></textarea>
		</div>

		<div id="body_raw" style="display:none;">
			<label for="raw_body">Raw Body</label>
			<textarea id="raw_body" name="raw_body" rows="8" placeholder="Paste raw payload here"><?php echo h($defaultRawBody); ?></textarea>
			<small class="muted">Set Content-Type header above to match your raw payload.</small>
		</div>

		<div class="row">
			<button class="btn" type="submit" name="run_test" value="1">Send Request</button>
			<?php if ($loadData): ?>
				<a class="btn secondary" href="?">Reset</a>
			<?php endif; ?>
		</div>
	</form>

	<?php if ($saveResultMsg): ?>
		<p class="note"><?php echo h($saveResultMsg); ?></p>
	<?php endif; ?>

	<?php if ($result): ?>
		<div class="card">
			<h2>Result</h2>
			<?php if (isset($result['error'])): ?>
				<p class="status-bad">Error: <?php echo h($result['error']); ?></p>
			<?php else: ?>
				<p>
					<strong><?php echo h($result['method']); ?></strong>
					<code><?php echo h($result['url']); ?></code>
					&mdash; Status:
					<strong class="<?php echo ($result['status'] >= 200 && $result['status'] < 300) ? 'status-ok' : 'status-bad'; ?>"><?php echo (int)$result['status']; ?></strong>
					&middot; Time: <?php echo number_format($result['duration'] * 1000, 1); ?> ms
				</p>
				<details open>
					<summary>Request</summary>
					<div class="grid">
						<div>
							<h3>Headers</h3>
							<pre class="mh-200"><code class="block"><?php echo h(implode("\n", $result['req_headers'] ?? [])); ?></code></pre>
						</div>
						<div>
							<h3>Body (<?php echo h($result['body_format']); ?>)</h3>
							<?php $reqBody = (string)($result['req_body'] ?? ''); ?>
							<?php if ($result['body_format'] === 'json' && is_json($reqBody)): ?>
								<pre class="mh-200"><code class="block"><?php echo h(pretty_json($reqBody)); ?></code></pre>
							<?php else: ?>
								<pre class="mh-200"><code class="block"><?php echo h($reqBody); ?></code></pre>
							<?php endif; ?>
						</div>
					</div>
				</details>
				<details open>
					<summary>Response</summary>
					<div class="grid">
						<div>
							<h3>Headers</h3>
							<pre class="mh-200"><code class="block"><?php echo h($result['resp_headers'] ?? ''); ?></code></pre>
						</div>
						<div>
							<h3>Body</h3>
							<?php $respBody = (string)($result['resp_body'] ?? ''); ?>
							<?php if (is_json($respBody)): ?>
								<pre class="mh-200"><code class="block"><?php echo h(pretty_json($respBody)); ?></code></pre>
							<?php else: ?>
								<pre class="mh-200"><code class="block"><?php echo h($respBody); ?></code></pre>
							<?php endif; ?>
						</div>
					</div>
				</details>
				<?php if (!empty($result['chunks'])): ?>
				<details>
					<summary>Response chunks (<?php echo count($result['chunks']); ?>)</summary>
					<pre class="mh-200"><code class="block"><?php foreach ($result['chunks'] as $idx => $chunk): ?>[<?php echo str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT); ?>] +<?php echo number_format((float)$chunk['offset_ms'], 1); ?> ms (<?php echo (int)$chunk['size']; ?> bytes)
<?php echo h($chunk['data']); ?>

<?php endforeach; ?></code></pre>
				</details>
				<?php endif; ?>
				<details>
					<summary>cURL Info <?php echo $result['curl_errno'] ? '(errors present)' : ''; ?></summary>
					<?php if ($result['curl_errno']): ?>
						<p class="status-bad">cURL error <?php echo (int)$result['curl_errno']; ?>: <?php echo h($result['curl_error']); ?></p>
					<?php endif; ?>
					<pre class="mh-200"><code class="block"><?php echo h(json_encode($result['curl_info'], JSON_PRETTY_PRINT)); ?></code></pre>
				</details>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<div class="card">
		<h2>Saved Logs</h2>
		<?php if (!$recentLogs): ?>
			<p class="muted">No saved logs yet.</p>
		<?php else: ?>
			<table>
				<thead>
					<tr><th>ID</th><th>When (UTC)</th><th>Method</th><th>URL</th><th>Status</th><th>Actions</th></tr>
				</thead>
				<tbody>
					<?php foreach ($recentLogs as $log): ?>
						<tr>
							<td><?php echo (int)$log['id']; ?></td>
							<td><?php echo h($log['created_at']); ?></td>
							<td><?php echo h($log['method']); ?></td>
							<td class="w-100"><div style="max-width:700px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?php echo h($log['url']); ?>"><?php echo h($log['url']); ?></div></td>
							<td><?php echo (int)$log['status_code']; ?></td>
							<td>
								<a href="?view=<?php echo (int)$log['id']; ?>">View</a>
								|
								<a href="?load=<?php echo (int)$log['id']; ?>">Load into form</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<?php if ($viewLog): ?>
		<div class="card">
			<h2>Log #<?php echo (int)$viewLog['id']; ?></h2>
			<?php if (isset($viewLog['error'])): ?>
				<p class="status-bad">Error loading log: <?php echo h($viewLog['error']); ?></p>
			<?php else: ?>
				<p>
					<strong><?php echo h($viewLog['method']); ?></strong>
					<code><?php echo h($viewLog['url']); ?></code>
					&mdash; Status: <strong class="<?php echo ((int)$viewLog['status_code'] >= 200 && (int)$viewLog['status_code'] < 300) ? 'status-ok' : 'status-bad'; ?>"><?php echo (int)$viewLog['status_code']; ?></strong>
					&middot; Created: <?php echo h($viewLog['created_at']); ?> UTC
				</p>
				<details open>
					<summary>Request</summary>
					<div class="grid">
						<div>
							<h3>Headers</h3>
							<pre class="mh-200"><code class="block"><?php echo h($viewLog['req_headers'] ?? ''); ?></code></pre>
						</div>
						<div>
							<h3>Body (<?php echo h($viewLog['body_format']); ?>)</h3>
							<?php $vb = (string)($viewLog['req_body'] ?? ''); ?>
							<?php if (($viewLog['body_format'] ?? '') === 'json' && is_json($vb)): ?>
								<pre class="mh-200"><code class="block"><?php echo h(pretty_json($vb)); ?></code></pre>
							<?php else: ?>
								<pre class="mh-200"><code class="block"><?php echo h($vb); ?></code></pre>
							<?php endif; ?>
						</div>
					</div>
				</details>
				<details open>
					<summary>Response</summary>
					<div class="grid">
						<div>
							<h3>Headers</h3>
							<pre class="mh-200"><code class="block"><?php echo h($viewLog['resp_headers'] ?? ''); ?></code></pre>
						</div>
						<div>
							<h3>Body</h3>
							<?php $rb = (string)($viewLog['resp_body'] ?? ''); ?>
							<?php if (is_json($rb)): ?>
								<pre class="mh-200"><code class="block"><?php echo h(pretty_json($rb)); ?></code></pre>
							<?php else: ?>
								<pre class="mh-200"><code class="block"><?php echo h($rb); ?></code></pre>
							<?php endif; ?>
						</div>
					</div>
				</details>
				<details>
					<summary>cURL Info</summary>
					<pre class="mh-200"><code class="block"><?php echo h(json_encode(json_decode((string)($viewLog['curl_info'] ?? '{}'), true), JSON_PRETTY_PRINT)); ?></code></pre>
					<?php if (!empty($viewLog['curl_errno'])): ?>
						<p class="status-bad">cURL error <?php echo (int)$viewLog['curl_errno']; ?>: <?php echo h($viewLog['curl_error']); ?></p>
					<?php endif; ?>
				</details>
			<?php endif; ?>
		</div>
	<?php endif; ?>

</body>
</html>