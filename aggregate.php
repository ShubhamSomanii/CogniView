<?php
require __DIR__ . '/_bootstrap.php';
allow_cors();

// Always return JSON on errors
set_error_handler(function ($sev,$msg,$file,$line) {
  json_out(['error'=>'PHP Error','detail'=>compact('sev','msg','file','line')], 500);
});
set_exception_handler(function ($ex) {
  json_out(['error'=>'PHP Exception','detail'=>$ex->getMessage()], 500);
});

// --- Request ---
$body   = json_body();
$prompt = trim($body['prompt'] ?? '');
if ($prompt === '') json_out(['error' => 'Missing prompt'], 422);

// --- Env ---
$googleKey   = envv('GOOGLE_API_KEY');
$geminiModel = envv('GEMINI_MODEL', 'gemini-2.5-flash');

// --- AIMLAPI (replaces DeepSeek) ---
$aimlKey   = envv('AIMLAPI_KEY');
$aimlModel = envv('AIMLAPI_MODEL', 'llama-3-8b-instruct'); // Using the simpler name

$hfKey   = envv('HUGGINGFACE_API_KEY');
$hfModel = envv('HUGGINGFACE_MODEL', 'meta-llama/Meta-Llama-3-8B-Instruct');


// --- Prepare default response ---
$responses = [
  'openai' => ['ok' => false, 'text' => null, 'raw' => null], // AIMLAPI results will go here
  'gemini' => ['ok' => false, 'text' => null, 'raw' => null],
  'huggingface' => ['ok' => false, 'text' => null, 'raw' => null],
];

// --- Helper: recursively find the first non-empty 'text' key ---
function find_first_text($node) {
  if (is_array($node)) {
    if (array_key_exists('text', $node) && is_string($node['text'])) {
      $t = trim($node['text']);
      if ($t !== '') return $t;
    }
    foreach ($node as $child) {
      $found = find_first_text($child);
      if ($found !== null) return $found;
    }
  }
  return null;
}

// ---
// --- 1. GEMINI API CALL ---
// ---
if (!$googleKey) {
  $responses['gemini']['text'] = 'Missing GOOGLE_API_KEY in .env';
} else {
  // [Gemini cURL logic]
  $geminiApiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/' .
               preg_replace('#^models/#', '', $geminiModel) .
               ':generateContent?key=' . urlencode($googleKey);
  $geminiPayload = [
    'contents' => [[ 'parts' => [[ 'text' => $prompt ]] ]],
    'generationConfig' => ['temperature' => 0.6, 'maxOutputTokens' => 2048],
  ];
  $ch_gemini = curl_init($geminiApiUrl);
  curl_setopt_array($ch_gemini, [
    CURLOPT_POST           => true, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($geminiPayload), CURLOPT_TIMEOUT => 30,
  ]);
  $raw_gemini = curl_exec($ch_gemini);
  $http_gemini = curl_getinfo($ch_gemini, CURLINFO_HTTP_CODE);
  $curlErrNo_gemini = curl_errno($ch_gemini); $curlErr_gemini = curl_error($ch_gemini);
  curl_close($ch_gemini);
  $decoded_gemini = json_decode($raw_gemini, true);
  $text_gemini = null;
  $candidate_gemini = $decoded_gemini['candidates'][0] ?? null;
  $finishReason_gemini = $candidate_gemini['finishReason'] ?? null;
  if ($http_gemini === 200 && $candidate_gemini && $finishReason_gemini !== null && $finishReason_gemini !== 'STOP') {
    $text_gemini = 'Gemini returned no text (Reason: ' . htmlspecialchars($finishReason_gemini) . ')';
  } elseif ($http_gemini !== 200 && isset($decoded_gemini['error']['message'])) {
    $text_gemini = 'Gemini API Error: ' . $decoded_gemini['error']['message'];
  } else {
    if (isset($decoded_gemini['output_text'])) $text_gemini = $decoded_gemini['output_text'];
    elseif (isset($candidate_gemini['content']['parts'][0]['text'])) $text_gemini = $candidate_gemini['content']['parts'][0]['text'];
    elseif (isset($candidate_gemini['content'][0]['text'])) $text_gemini = $candidate_gemini['content'][0]['text'];
    elseif (isset($candidate_gemini['text'])) $text_gemini = $candidate_gemini['text'];
    elseif ($candidate_gemini) $text_gemini = find_first_text($candidate_gemini);
  }
  if ($text_gemini === null) {
    $msg = 'Gemini returned no text';
    if ($curlErrNo_gemini > 0) $msg .= ' (cURL Error: ' . $curlErr_gemini . ')';
    elseif ($http_gemini !== 200) $msg .= ' (HTTP Status: ' . $http_gemini . ')';
    else $msg .= ' (Unknown parsing error)';
    $text_gemini = $msg;
  }
  $responses['gemini'] = [
    'ok'   => ($http_gemini === 200 && $finishReason_gemini === 'STOP'),
    'text' => $text_gemini,
    'raw'  => ['http' => $http_gemini, 'curl_errno' => $curlErrNo_gemini, 'curl_error' => $curlErr_gemini, 'body' => $decoded_gemini],
  ];
}


// ---
// --- 2. AIMLAPI CALL (Replaces DeepSeek, uses 'openai' slot) ---
// ---
if (!$aimlKey) {
  $responses['openai']['text'] = 'Missing AIMLAPI_KEY in .env';
} else {
  $aimlApiUrl = 'https://api.aimlapi.com/v1/chat/completions';
  $aimlPayload = [
    'model' => $aimlModel, 'messages' => [['role' => 'user', 'content' => $prompt]],
    'temperature' => 0.6, 'max_tokens' => 2048,
  ];
  $ch_aiml = curl_init($aimlApiUrl);
  curl_setopt_array($ch_aiml, [
    CURLOPT_POST           => true, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $aimlKey],
    CURLOPT_POSTFIELDS     => json_encode($aimlPayload), CURLOPT_TIMEOUT => 30,
  ]);
  $raw_aiml = curl_exec($ch_aiml);
  $http_aiml = curl_getinfo($ch_aiml, CURLINFO_HTTP_CODE);
  $curlErrNo_aiml = curl_errno($ch_aiml); $curlErr_aiml = curl_error($ch_aiml);
  curl_close($ch_aiml);
  $decoded_aiml = json_decode($raw_aiml, true);
  $text_aiml = null; $ok_aiml = false;

  // --- NEWER, MORE ROBUST ERROR HANDLING ---
  if ($http_aiml === 200 && isset($decoded_aiml['choices'][0]['message']['content'])) {
    // 1. Success
    $text_aiml = $decoded_aiml['choices'][0]['message']['content'];
    $ok_aiml = true;
  } elseif ($http_aiml !== 200) {
    // 2. It's an error. Try to find the message string.
    $errorMsg = null;
    if (isset($decoded_aiml['error']['message'])) {
      $errorMsg = $decoded_aiml['error']['message']; // e.g. OpenAI style
    } elseif (isset($decoded_aiml['message'])) {
      $errorMsg = $decoded_aiml['message']; // e.g. AIMLAPI style
    } elseif (isset($decoded_aiml['error'])) {
      $errorMsg = is_string($decoded_aiml['error']) ? $decoded_aiml['error'] : json_encode($decoded_aiml['error']); // e.g. Hugging Face style
    } elseif (isset($decoded_aiml['detail'])) {
        $errorMsg = is_string($decoded_aiml['detail']) ? $decoded_aiml['detail'] : json_encode($decoded_aiml['detail']); // e.g. Validation error
    } elseif (isset($decoded_aiml['meta']['message'])) {
        $errorMsg = $decoded_aiml['meta']['message']; // Guessing based on your log
    }

    if ($errorMsg) {
      $text_aiml = 'AIMLAPI Error: ' . $errorMsg;
    } else {
      // 3. Fallback: still can't find it.
      $msg = 'AIMLAPI returned no text';
      if ($curlErrNo_aiml > 0) $msg .= ' (cURL Error: ' . $curlErr_aiml . ')';
      elseif ($http_aiml !== 200) $msg .= ' (HTTP Status: ' . $http_aiml . ')';
      else $msg .= ' (Unknown parsing error)';
      $text_aiml = $msg;
    }
  } else {
     // 4. This is the case where http=200 but no 'choices' key (e.g. safety block)
     $text_aiml = 'AIMLAPI returned 200 OK but no answer. (Check for safety block)';
  }
  // --- END NEW ERROR HANDLING ---

  // Store in the 'openai' slot to match the frontend
  $responses['openai'] = [
    'ok'   => $ok_aiml, 'text' => $text_aiml,
    'raw'  => ['http' => $http_aiml, 'curl_errno' => $curlErrNo_aiml, 'curl_error' => $curlErr_aiml, 'body' => $decoded_aiml],
  ];
}


// ---
// --- 3. HUGGING FACE API CALL ---
// ---
if (!$hfKey) {
  $responses['huggingface']['text'] = 'Missing HUGGINGFACE_API_KEY in .env';
} else {
  // Using the new router HOST + new chat/completions PATH
  $hfApiUrl = 'https://router.huggingface.co/v1/chat/completions';
  
  $hfPayload = [
    'model' => $hfModel,
    'messages' => [['role' => 'user', 'content' => $prompt]],
    'max_tokens' => 2048,
    'temperature' => 0.6
  ];

  $ch_hf = curl_init($hfApiUrl);
  curl_setopt_array($ch_hf, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $hfKey
    ],
    CURLOPT_POSTFIELDS     => json_encode($hfPayload),
    CURLOPT_TIMEOUT        => 30,
  ]);

  $raw_hf       = curl_exec($ch_hf);
  $http_hf      = curl_getinfo($ch_hf, CURLINFO_HTTP_CODE);
  $curlErrNo_hf = curl_errno($ch_hf);
  $curlErr_hf   = curl_error($ch_hf);
  curl_close($ch_hf);

  $decoded_hf = json_decode($raw_hf, true);
  $text_hf = null;
  $ok_hf = false;

  if ($http_hf === 503) {
    $text_hf = 'Model is loading on Hugging Face. This is normal for the free tier. Please wait 30 seconds and try again.';
  } elseif ($http_hf === 200 && isset($decoded_hf['choices'][0]['message']['content'])) {
    $text_hf = $decoded_hf['choices'][0]['message']['content'];
    $ok_hf = true;
  } elseif (isset($decoded_hf['error']['message'])) {
    $text_hf = 'Hugging Face Error: ' . $decoded_hf['error']['message'];
  } elseif (isset($decoded_hf['error'])) {
    $error_details = is_string($decoded_hf['error']) ? $decoded_hf['error'] : json_encode($decoded_hf['error']);
    $text_hf = 'HF Error: ' . $error_details;
  } else {
    $msg = 'Hugging Face returned no text';
    if ($curlErrNo_hf > 0) $msg .= ' (cURL Error: ' . $curlErr_hf . ')';
    elseif ($http_hf !== 200) $msg .= ' (HTTP Status: ' . $http_hf . ')';
    else $msg .= ' (Unknown parsing error)';
    $text_hf = $msg;
  }
  
  $responses['huggingface'] = [
    'ok'   => $ok_hf,
    'text' => $text_hf,
    'raw'  => ['http' => $http_hf, 'curl_errno' => $curlErrNo_hf, 'curl_error' => $curlErr_hf, 'body' => $decoded_hf],
  ];
}


// ---
// --- FINAL RESPONSE ---
// ---
log_line('PROMPT ' . json_encode(['prompt' => $prompt]));
json_out(['prompt' => $prompt, 'answers' => $responses]);