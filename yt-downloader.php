<?php

// --- Configuration ---
// !! VERIFY THESE PATHS !! Use `which yt-dlp` and `which ffmpeg`
define('YTDLP_PATH', '/usr/local/bin/yt-dlp'); // yt-dlp path (pip install location)
define('FFMPEG_PATH', '/usr/bin/ffmpeg'); // Path for direct FFmpeg cutting command
// Directory for final videos, relative to this script's location
define('DEFAULT_OUTPUT_FOLDER', 'youtube_downloads');
// Directory for temporary FULL downloads
define('TEMP_DOWNLOAD_DIR', __DIR__ . '/temp_yt_downloads');
// YouTube cookies file path (optional, for bypassing bot detection)
define('YOUTUBE_COOKIES_PATH', __DIR__ . '/youtube_cookies.txt');
// Base yt-dlp format selection (gets best MP4 video/audio, falls back)
define('YTDLP_DEFAULT_FORMAT', 'bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]');
// Set higher limits for potentially long operations
// !! Ensure these values (or higher) are also set in php.ini / FPM config !!
define('MAX_EXECUTION_TIME', 1200); // 20 minutes (needs to cover FULL download + cut)
define('MEMORY_LIMIT', '1024M'); // Adjust based on video size & server RAM
// --- End Configuration ---

// --- Global Variable for Log Path ---
$globalLogFilePath = null;

// --- Helper Functions ---

/**
 * Writes a message to the request-specific log file or falls back to PHP error_log.
 */
function writeToLog($message) {
    global $globalLogFilePath; $targetLogPath = $globalLogFilePath;
    if ($targetLogPath) { $timestamp = date('Y-m-d H:i:s'); $logDir = dirname($targetLogPath);
        if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
        file_put_contents($targetLogPath, "[$timestamp] " . $message . "\n", FILE_APPEND | LOCK_EX);
    } else { error_log("YouTubeDL Script (Log Path Not Set): " . $message); }
}

/**
 * Centralized error handler: Logs details, sends JSON response, exits.
 */
function handleErrorAndLog($errorMessage, $httpCode, $logContext = null) {
    global $globalLogFilePath; $logMessage = "Error (HTTP $httpCode): $errorMessage";
    if ($logContext) { $contextStr = is_string($logContext) ? $logContext : print_r($logContext, true);
        if (strlen($contextStr) > 2048) { $contextStr = substr($contextStr, 0, 2048) . "... (truncated)"; }
        $logMessage .= "\nContext/Details:\n" . $contextStr; }
    writeToLog($logMessage);
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    http_response_code($httpCode); echo json_encode(["error" => $errorMessage]); exit;
}

/**
 * Executes an external command, checks exit code, returns error message or null.
 * Now takes full command path as first argument.
 */
function executeExternalCommand($command_path, $arguments_string) {
    $fullCommand = $command_path . ' ' . $arguments_string;
    writeToLog("Executing Command: " . $fullCommand);
    $output_lines = []; $return_var = -1;
    exec($fullCommand . ' 2>&1', $output_lines, $return_var); // Capture stderr
    $full_output = implode("\n", $output_lines);

    if ($return_var !== 0) {
        writeToLog("Command Execution Failed (Return Code: $return_var). Full Output:\n" . $full_output);
        
        // Check for executable not found
        if ($return_var === 127 || stripos($full_output, 'No such file or directory') !== false || stripos($full_output, 'not found') !== false ) {
            if (stripos($full_output, $command_path) !== false && (stripos($full_output, 'No such file or directory') !== false || stripos($full_output, 'not found') !== false)) {
                return "Command execution failed: Executable not found or inaccessible. Path used: '$command_path'. Verify path, OS permissions, CageFS, SELinux.";
            }
        }
        
        // For Python tracebacks, capture more context
        if (stripos($full_output, 'Traceback') !== false) {
            // Get last 10 lines for better error context
            $last_lines = array_slice($output_lines, -10);
            return "Command failed: " . implode(" | ", $last_lines);
        }
        
        $error_message = "Command failed with exit code $return_var.";
        foreach (array_reverse($output_lines) as $line) {
            // Added common ffmpeg errors
            if (preg_match('/^(error|fatal|traceback|unsupported url|unable to extract|private video|video unavailable|copyright|download aborted|invalid time|permission denied|no such file|conversion failed|muxing failed)/i', trim($line))) {
                $error_message = "Command failed: " . trim($line); break;
            }
        }
        if ($error_message === "Command failed with exit code $return_var.") { $error_message .= " Check full output in log. Preview: " . substr(preg_replace('/\s+/', ' ', $full_output), 0, 500); }
        return $error_message;
    }
    return null; // Success
}


/**
 * Generates a reasonably unique filename base using time and random bytes.
 */
function generateFilenameBase($prefix = 'file') {
    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix);
    return $safePrefix . '_' . time() . '_' . bin2hex(random_bytes(4));
}

/**
 * Extracts YouTube Video ID from various URL formats. Returns ID or null.
 */
function extractYouTubeVideoId($url) {
    $videoId = null; $patterns = ['/(?:https?:\/\/)?(?:www\.)?youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/', '/(?:https?:\/\/)?(?:www\.)?youtu\.be\/([a-zA-Z0-9_-]{11})/', '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/', '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/v\/([a-zA-Z0-9_-]{11})/', '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/shorts\/([a-zA-Z0-9_-]{11})/'];
    foreach ($patterns as $pattern) { if (preg_match($pattern, $url, $matches)) { $videoId = $matches[1]; break; } }
    return $videoId;
}

/**
 * Sanitizes a filename, removing potentially problematic characters.
 */
function sanitizeFilename($filename) {
    $filename = preg_replace('/[\x00-\x1F\x7F\/\?<>\\:\*\|"]/', '', $filename); $filename = str_replace(' ', '_', $filename);
    $filename = trim($filename, '._- '); $filename = mb_substr($filename, 0, 150, 'UTF-8');
    if (empty($filename)) { $filename = 'download'; } return $filename;
}


/**
 * Cleans up temporary files.
 */
function cleanupTempFiles(array $files) {
    $filesToClean = array_filter($files); if(empty($filesToClean)) return;
    writeToLog("Cleaning up temp files: " . implode(', ', $filesToClean));
    foreach ($filesToClean as $file) { if (file_exists($file)) { @unlink($file); } }
}

// --- Script Execution Starts ---

// --- Determine Server URL for Documentation ---
$scriptName = basename($_SERVER['PHP_SELF']); $scriptDirPath = dirname($_SERVER['SCRIPT_NAME']);
$scriptDirPath = ($scriptDirPath == '/' || $scriptDirPath == '\\') ? '' : $scriptDirPath;
$protocol = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http");
$host = $_SERVER['HTTP_HOST']; $serverUrl = $protocol . "://" . $host . $scriptDirPath . "/{$scriptName}";
$basePublicUrl = $protocol . "://" . $host . $scriptDirPath;


// --- Handle GET Request (API Documentation) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    // Prerequisite Checks
    $ytdlpInstalled = 'Checking...'; $ffmpegInstalled = 'Checking...'; $curlInstalled = 'Checking...';
    $permissionsOk = false; $permissions = [];
    // Check yt-dlp
    $ytdlpVersionOutput = []; $ytdlpReturnCode = -1; @exec(YTDLP_PATH . ' --version 2>&1', $ytdlpVersionOutput, $ytdlpReturnCode); $ytdlpVersionOutput = implode("\n", $ytdlpVersionOutput);
    if ($ytdlpReturnCode === 0 && preg_match('/^\d{4}\.\d{2}\.\d{2}/', $ytdlpVersionOutput)) { $ytdlpInstalled = 'Installed ✅ (Version: ' . trim($ytdlpVersionOutput) . ')'; }
    elseif ($ytdlpReturnCode === 127 || stripos($ytdlpVersionOutput, 'No such file') !== false || stripos($ytdlpVersionOutput, 'not found') !== false ) { $ytdlpInstalled = 'Not Found ❌ (Path: `' . YTDLP_PATH . '` incorrect, or yt-dlp not installed/accessible).'; }
    else { if ($ytdlpReturnCode === -1 && empty($ytdlpVersionOutput)) { $ytdlpInstalled = 'Unknown Status ❓ (`exec` might be disabled).'; } else { $ytdlpInstalled = 'Unknown Status ❓ (Command failed. RC: ' . $ytdlpReturnCode . ')'; } }
    // Check FFmpeg (Using path defined for direct use now)
    $ffmpegVersionOutput = []; $ffmpegReturnCode = -1; @exec(FFMPEG_PATH . ' -version 2>&1', $ffmpegVersionOutput, $ffmpegReturnCode); $ffmpegVersionOutput = implode("\n", $ffmpegVersionOutput);
    if ($ffmpegReturnCode === 0 && stripos($ffmpegVersionOutput, 'ffmpeg version') !== false) { $ffmpegInstalled = 'Found ✅ (Required for cutting sections)'; }
    elseif ($ffmpegReturnCode === 127 || stripos($ffmpegVersionOutput, 'No such file') !== false || stripos($ffmpegVersionOutput, 'not found') !== false ) { $ffmpegInstalled = 'Not Found ❌ (Path: `' . FFMPEG_PATH . '`. Cannot cut sections).'; }
    else { if ($ffmpegReturnCode === -1 && empty($ffmpegVersionOutput)) { $ffmpegInstalled = 'Check Failed ❓ (`exec` might be disabled).'; } else { $ffmpegInstalled = 'Check Failed ❓ (RC: ' . $ffmpegReturnCode .'. Cannot cut sections).'; } }
    // Check PHP cURL
    $curlInstalled = function_exists('curl_version') ? 'Installed ✅' : 'Not Installed ❌';
    // Check Dirs
    $outputDir = __DIR__ . '/' . DEFAULT_OUTPUT_FOLDER; $tempDirCheck = TEMP_DOWNLOAD_DIR;
    if (!is_dir($outputDir)) @mkdir($outputDir, 0775, true); if (!is_dir($tempDirCheck)) @mkdir($tempDirCheck, 0775, true);
    $outputDirWritable = is_writable($outputDir); $tempDirWritable = is_writable($tempDirCheck);
    $permissions[] = 'Output Dir (' . DEFAULT_OUTPUT_FOLDER . '): ' . ($outputDirWritable ? 'Writable ✅' : 'Not Writable ❌');
    $permissions[] = 'Temp Dir (' . basename(TEMP_DOWNLOAD_DIR) . '): ' . ($tempDirWritable ? 'Writable ✅' : 'Not Writable ❌');
    $permissionsOk = $outputDirWritable && $tempDirWritable;

    // Generate HTML Output
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>YouTube Video Download API (Segments via FFmpeg)</title>
        <link href='https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap' rel='stylesheet'>
        <style> /* Identical CSS */
            body { font-family: 'Roboto', sans-serif; background-color: #f3f4f6; margin: 0; padding: 0; color: #333; line-height: 1.6; }
            h1 { background-color: #c0392b; color: white; padding: 20px; text-align: center; margin: 0; font-weight: 500; }
            div.container { padding: 20px 30px 40px 30px; margin: 30px auto; max-width: 900px; background: white; border-radius: 10px; box-shadow: 0 0 15px rgba(0, 0, 0, 0.1); }
            h2 { border-bottom: 2px solid #e5e7eb; padding-bottom: 10px; margin-top: 30px; color: #a02314; font-weight: 500;}
            code, pre { background-color: #f0f4f8; padding: 12px 15px; border: 1px solid #d1d5db; border-radius: 5px; display: block; margin-bottom: 15px; white-space: pre-wrap; word-wrap: break-word; font-family: Consolas, Monaco, 'Andale Mono', 'Ubuntu Mono', monospace; font-size: 0.9em; color: #1f2937; }
            ul { list-style-type: disc; margin-left: 20px; padding-left: 5px;} li { margin-bottom: 10px; }
            strong { color: #a02314; font-weight: 500; }
            button { padding: 10px 20px; background-color: #c0392b; color: white; border: none; border-radius: 5px; cursor: pointer; margin-top: 10px; font-size: 0.95em; }
            button:hover { background-color: #a02314; }
            .note { background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 15px; margin: 20px 0; border-radius: 4px;}
            .error { color: #dc2626; font-weight: bold; } .success { color: #059669; font-weight: bold; }
            .config-path { font-style: italic; color: #555; background-color: #e5e7eb; padding: 2px 4px; border-radius: 3px;}
            .status-list li { margin-bottom: 5px; list-style-type: none;}
            .status-icon { margin-right: 8px; display: inline-block; width: 20px; text-align: center;}
            .attribution a { color: #c0392b; text-decoration: none; } .attribution a:hover { text-decoration: underline; }
        </style>
    </head>
    <body>
        <h1>YouTube Video Download API (Segments via FFmpeg)</h1>
        <div class="container">
            <p style="text-align:center;">
                <img src="https://blog.automation-tribe.com/wp-content/uploads/2025/05/logo-automation-tribe-750.webp" alt="Automation Tribe Logo" style="max-width: 200px; margin-bottom: 10px;">
            </p>
            <p class="attribution" style="text-align:center; font-size: 0.9em; margin-bottom: 25px;">
                This API endpoint was made by <a href="https://www.automation-tribe.com" target="_blank" rel="noopener noreferrer">Automation Tribe</a>.<br>
                Join our community at <a href="https://www.skool.com/automation-tribe" target="_blank" rel="noopener noreferrer">https://www.skool.com/automation-tribe</a>.
            </p>
            <p>Downloads YouTube videos using `yt-dlp`, then optionally cuts segments using `ffmpeg`. Input via <strong>JSON</strong>.</p>
            <p class="note"><strong>Disclaimer:</strong> Downloading YouTube videos may violate their Terms of Service. Use responsibly.</p>
            <p class="note"><strong>Logging:</strong> Check <code>.log</code> files in <code><?php echo htmlspecialchars(DEFAULT_OUTPUT_FOLDER); ?>/</code> on errors.</p>

            <h2>Server Status</h2>
            <ul class="status-list">
                <li><span class="status-icon">🔧</span><strong>yt-dlp Path:</strong> <code class="config-path"><?php echo htmlspecialchars(YTDLP_PATH); ?></code></li>
                <li><span class="<?php echo strpos($ytdlpInstalled, '✅') !== false ? 'success' : 'error'; ?>"><span class="status-icon"><?php echo strpos($ytdlpInstalled, '✅') !== false ? '✅' : '❓'; ?></span><strong>yt-dlp Status:</strong> <?php echo $ytdlpInstalled; ?></span></li>
                <li><span class="status-icon">✂️</span><strong>FFmpeg Path:</strong> <code class="config-path"><?php echo htmlspecialchars(FFMPEG_PATH); ?></code></li>
                <li><span class="<?php echo strpos($ffmpegInstalled, '✅') !== false ? 'success' : 'error'; ?>"><span class="status-icon"><?php echo strpos($ffmpegInstalled, '✅') !== false ? '✅' : '❓'; ?></span><strong>FFmpeg Status:</strong> <?php echo $ffmpegInstalled; ?></span></li>
                <li><span class="<?php echo strpos($curlInstalled, '✅') !== false ? 'success' : 'error'; ?>"><span class="status-icon"><?php echo strpos($curlInstalled, '✅') !== false ? '✅' : '❌'; ?></span><strong>PHP cURL Ext:</strong> <?php echo $curlInstalled; ?></span></li>
                <li><span class="status-icon">📁</span><strong>Permissions:</strong>
                    <ul style="margin-left: 10px; margin-top: 5px;">
                        <?php foreach ($permissions as $perm): ?><li><span class="<?php echo strpos($perm, '✅') !== false ? 'success' : 'error'; ?>"><span class="status-icon"><?php echo strpos($perm, '✅') !== false ? '✅' : '❌'; ?></span><?php echo $perm; ?></span></li><?php endforeach; ?>
                    </ul>
                </li>
            </ul>
            <?php if (strpos($ytdlpInstalled, '❌') !== false || strpos($ytdlpInstalled, '❓') !== false || strpos($ffmpegInstalled, '❌') !== false || strpos($ffmpegInstalled, '❓') !== false || strpos($curlInstalled, '❌') !== false || !$permissionsOk): ?>
                <p class="error note"><strong>Action Required:</strong> Address items marked ❌/❓. Ensure `yt-dlp` & `ffmpeg` are installed/accessible via configured paths, cURL enabled, dirs writable. Check PHP `disable_functions` (`exec`).</p>
            <?php endif; ?>

            <h2>API Usage</h2>
            <h3>Endpoint</h3> <code><?php echo htmlspecialchars($serverUrl); ?></code>
            <h3>Method</h3> <code>POST</code>
            <h3>Headers</h3> <code>Content-Type: application/json</code>

            <h3>Request Body (JSON Payload)</h3>
            <p>Send a JSON object with the following structure:</p>
            <?php
            $exampleJsonPayload = [ "url" => "https://www.youtube.com/watch?v=dQw4w9WgXcQ", "name" => "custom_video_name", "start_time" => 30, "end_time" => 120 ]; // Changed example URL
            ?>
            <pre><code><?php echo htmlspecialchars(json_encode($exampleJsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></code></pre>
            <ul>
                <li><code>url</code> (<strong>Required</strong>): String (Full YouTube video URL).</li>
                <li><code>name</code> (Optional): String. Custom base name for the output file (sanitized). Defaults to Video ID.</li>
                <li><code>start_time</code> (Optional): Number or String. Time in seconds to start the segment.</li>
                <li><code>end_time</code> (Optional): Number or String. Time in seconds to end the segment.</li>
            </ul>
            <p><strong>Note on Timestamps:</strong> If timestamps are provided, the full video is downloaded first, then cut using FFmpeg (`-c copy` for speed). Accuracy depends on video keyframes.</p>

            <h3>Success Response (JSON)</h3>
            <p>Returns a link to the downloaded file and its filename.</p>
            <pre><code><?php echo htmlspecialchars(json_encode(["url" => rtrim($basePublicUrl, '/') . '/' . DEFAULT_OUTPUT_FOLDER . "/output_filename.mp4", "filename" => "output_filename.mp4"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></code></pre>

            <h3>Error Response (JSON)</h3>
            <pre><code><?php echo htmlspecialchars(json_encode(["error" => "Error message from yt-dlp, ffmpeg, or script."], JSON_PRETTY_PRINT)); ?></code></pre>
            <p>Check the corresponding <code>.log</code> file in <code><?php echo htmlspecialchars(DEFAULT_OUTPUT_FOLDER); ?>/</code> for details.</p>

            <?php
            $escapedJsonPayload = escapeshellarg(json_encode($exampleJsonPayload, JSON_UNESCAPED_SLASHES));
            $curlCommand = "curl -X POST " . escapeshellarg($serverUrl) . " \\\n";
            $curlCommand .= "  -H \"Content-Type: application/json\" \\\n"; // Corrected spacing
            $curlCommand .= "  -d $escapedJsonPayload";
            ?>
            <h2>How to Use (cURL Example)</h2>
            <p>Copy the command, replace values inside the JSON (`-d` argument), and run.</p>
            <pre id='curl-command'><?php echo htmlspecialchars($curlCommand); ?></pre>
            <button onclick="navigator.clipboard.writeText(document.getElementById('curl-command').innerText.replace(/\\\n/g, '')); alert('cURL command copied (single line format)!');">Copy cURL Command (Single Line)</button>

            <h3>Using with n8n:</h3>
            <ul>
                <li>Use 'HTTP Request' node (POST, URL: <code><?php echo htmlspecialchars($serverUrl); ?></code>, Body: JSON).</li>
                <li>In JSON / Parameter field, construct payload: <br>
                    <code style="font-size: 0.85em;">={{ { "url": $json.youtubeUrl, "name": $json.customName, "start_time": $json.startTimeInSeconds, "end_time": $json.endTimeInSeconds } }}</code><br>(Adjust expressions, omit optional fields if not needed).</li>
            </ul>

            <h2>Important Notes & Troubleshooting</h2>
            <ul>
                <li><strong>Dependencies:</strong> `yt-dlp` (install/update: `yt-dlp -U`), `ffmpeg` (required for cutting).</li>
                <li><strong>Workflow:</strong> Full video downloaded first, then cut. Uses more bandwidth/temp space than section download.</li>
                <li><strong>YouTube Changes/ToS:</strong> Keep `yt-dlp` updated. Respect Terms of Service.</li>
                <li><strong>Rate Limiting/Blocking:</strong> Possible with heavy use.</li>
                <li><strong>Errors:</strong> Check `.log` files & PHP error log. Common: paths, `exec` disabled, permissions, timeouts, invalid URLs, copyrights, ffmpeg cut errors.</li>
                <li><strong>Cut Accuracy:</strong> Using `-c copy` is fast but cuts on keyframes; exact times might vary slightly.</li>
            </ul>
            <button onclick="location.reload()">Refresh Status & Documentation</button>
        </div>
    </body>
    </html>
    <?php
    exit;
}


// --- Handle POST Request (Download YouTube Video, then Cut) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    set_time_limit(MAX_EXECUTION_TIME);
    ini_set('memory_limit', MEMORY_LIMIT);
    header('Content-Type: application/json; charset=utf-8');

    // --- Read and Decode JSON Input ---
    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true);

    // --- Determine Base Output Dir and Log File Path ---
    $outputFolder = DEFAULT_OUTPUT_FOLDER;
    $outputDirPath = __DIR__ . '/' . $outputFolder;
    $logFileBaseName = 'yt_';
    if (isset($data['name']) && is_string($data['name']) && !empty(trim($data['name']))) { $logFileBaseName .= sanitizeFilename($data['name']); }
    elseif (isset($data['url']) && ($videoId = extractYouTubeVideoId($data['url']))) { $logFileBaseName .= $videoId; }
    else { $logFileBaseName .= 'download'; }
    $logFileBaseName .= '_' . time();
    $globalLogFilePath = $outputDirPath . '/' . $logFileBaseName . '.log';

    // --- Validate JSON and Input Data ---
    if (json_last_error() !== JSON_ERROR_NONE) { handleErrorAndLog("Invalid JSON received: " . json_last_error_msg(), 400, $jsonInput); }

    $youtubeUrl = $data['url'] ?? null;
    $customName = isset($data['name']) && is_string($data['name']) ? trim($data['name']) : null;
    // Validate numeric timestamps carefully
    $startTime = null;
    if(isset($data['start_time']) && (is_numeric($data['start_time']) || (is_string($data['start_time']) && is_numeric(trim($data['start_time']))))) {
        $startTime = floatval(trim($data['start_time']));
        if ($startTime < 0) handleErrorAndLog("'start_time' must be a positive number (seconds).", 400, $data);
    } elseif (isset($data['start_time']) && $data['start_time'] !== null) { handleErrorAndLog("'start_time' must be numeric or null.", 400, $data); }

    $endTime = null;
    if(isset($data['end_time']) && (is_numeric($data['end_time']) || (is_string($data['end_time']) && is_numeric(trim($data['end_time']))))) {
        $endTime = floatval(trim($data['end_time']));
        if ($endTime <= 0) handleErrorAndLog("'end_time' must be a positive number greater than 0.", 400, $data);
    } elseif (isset($data['end_time']) && $data['end_time'] !== null) { handleErrorAndLog("'end_time' must be numeric or null.", 400, $data); }

    if ($startTime !== null && $endTime !== null && $startTime >= $endTime) { handleErrorAndLog("'start_time' must be less than 'end_time'.", 400, $data); }

    if (empty($youtubeUrl) || !filter_var($youtubeUrl, FILTER_VALIDATE_URL)) { handleErrorAndLog("A valid 'url' is required.", 400, $data); }
    $videoId = extractYouTubeVideoId($youtubeUrl);
    if (!$videoId) { handleErrorAndLog("Could not extract YouTube Video ID from URL.", 400, $youtubeUrl); }

    // Determine base filename
    $outputBaseFilename = !empty($customName) ? sanitizeFilename($customName) : $videoId;
    $segmentSuffix = ''; // Will be added later if cutting happens

    writeToLog("Starting YouTube download job (Log ID: " . $logFileBaseName . "). Video ID: " . $videoId . ($customName ? ", Custom Name: ".$customName : ""));

    // --- Ensure Directories Exist and Are Writable ---
    if (!is_dir($outputDirPath)) { if (!@mkdir($outputDirPath, 0775, true) && !is_dir($outputDirPath)) { handleErrorAndLog("Failed to create output directory: $outputDirPath.", 500); }}
    if (!is_writable($outputDirPath)) { handleErrorAndLog("Output directory not writable: $outputDirPath", 500); }
    if (!is_dir(TEMP_DOWNLOAD_DIR)) { if (!@mkdir(TEMP_DOWNLOAD_DIR, 0775, true) && !is_dir(TEMP_DOWNLOAD_DIR)) { handleErrorAndLog("Failed to create temp directory: ".TEMP_DOWNLOAD_DIR, 500); } }
    if (!is_writable(TEMP_DOWNLOAD_DIR)) { handleErrorAndLog("Temp directory not writable: " . TEMP_DOWNLOAD_DIR, 500); }

    // --- Step 1: Download Full Video ---
    $tempDownloadedFullPath = null; // Path to the successfully downloaded full video
    $tempFilesForCleanup = [];     // Track files created by yt-dlp in temp

    try {
        writeToLog("Attempting full download for URL: $youtubeUrl");

        // Temporary filename pattern using Video ID in TEMP dir
        $tempOutputPathTemplate = TEMP_DOWNLOAD_DIR . '/' . $videoId . '.%(ext)s';

        // Construct basic yt-dlp command for full download
        $ytDlpArgs = "-o " . escapeshellarg($tempOutputPathTemplate) .
                     " -f " . escapeshellarg(YTDLP_DEFAULT_FORMAT) .
                     " --merge-output-format mp4" .
                     " --ffmpeg-location " . escapeshellarg(FFMPEG_PATH); // Path to ffmpeg for merging
        
        // Add cookies if file exists
        if (file_exists(YOUTUBE_COOKIES_PATH) && is_readable(YOUTUBE_COOKIES_PATH)) {
            $ytDlpArgs .= " --cookies " . escapeshellarg(YOUTUBE_COOKIES_PATH);
            writeToLog("Using cookies file: " . YOUTUBE_COOKIES_PATH);
        } else {
            writeToLog("Warning: Cookies file not found at " . YOUTUBE_COOKIES_PATH . ". May encounter bot detection.");
        }
        
        $ytDlpArgs .= " --no-playlist" .
                     " --no-overwrites" . // Changed from --no-overwrite to --no-overwrites for yt-dlp
                     " --no-progress" .
                     " --user-agent \"Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\"" .
                     " --extractor-args \"youtube:player_client=android\"" .
                     " -v" . // Keep verbose for debugging download issues
                     " " . escapeshellarg($youtubeUrl);

        $error = executeExternalCommand(YTDLP_PATH, $ytDlpArgs);

        // Find potentially created temp files *before* checking error
        $tempFilesForCleanup = glob(TEMP_DOWNLOAD_DIR . '/' . $videoId . '.*');

        if ($error) {
            throw new Exception("yt-dlp download failed: " . $error);
        }

        // Find the successfully downloaded file (should be one MP4)
        $downloadedFileCandidates = [];
        foreach($tempFilesForCleanup as $file) { if (!preg_match('/\.(part|ytdl)$/i', $file)) { $downloadedFileCandidates[] = $file; } }

        if (count($downloadedFileCandidates) === 1) {
            $tempDownloadedFullPath = $downloadedFileCandidates[0];
            // Ensure it's mp4 after merge attempt
            if (strtolower(pathinfo($tempDownloadedFullPath, PATHINFO_EXTENSION)) !== 'mp4') {
                writeToLog("Warning: Downloaded file extension is not mp4: " . basename($tempDownloadedFullPath) . ". yt-dlp might not have merged correctly or format selection was overridden.");
                // If critical, could attempt a remux here, but for now rely on yt-dlp
            }
            writeToLog("Full download successful: " . $tempDownloadedFullPath);
        } elseif (count($downloadedFileCandidates) === 0) {
            writeToLog("yt-dlp reported success, but no suitable file found matching pattern: " . TEMP_DOWNLOAD_DIR . '/' . $videoId . '.*');
            throw new Exception("Download completed, but couldn't locate the final video file in temp directory.");
        } else {
            writeToLog("Warning: Multiple files found after download: " . implode(', ', $downloadedFileCandidates) . ". Using the first MP4 or first overall.");
            $tempDownloadedFullPath = $downloadedFileCandidates[0]; // Default to first
            foreach($downloadedFileCandidates as $candidate) { if(strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) === 'mp4') {$tempDownloadedFullPath = $candidate; break;} } // Prefer mp4
            writeToLog("Selected file for processing: " . $tempDownloadedFullPath);
        }

        // --- Step 2: Cut Video Segment (if timestamps provided) ---
        $finalProcessedPath = null; // This will hold the path to the file to respond with

        if ($startTime !== null || $endTime !== null) {
            writeToLog("Cutting segment from downloaded file...");
            $segmentSuffix = '_segment' . ($startTime !==null ? '_' . str_replace('.','p',number_format($startTime, 1)) : '_start') . ($endTime !==null ? '_' . str_replace('.','p',number_format($endTime, 1)) : '_end');
            $finalFilename = $outputBaseFilename . $segmentSuffix . '.mp4'; // Assume mp4 output
            $finalOutputPath = $outputDirPath . '/' . $finalFilename;

            // Construct FFmpeg command for cutting using -c copy
            $ffmpegCutArgs = ""; // Start fresh
            if ($startTime !== null) {
                // -ss before -i is faster for seeking but less accurate for some formats
                // For precise cuts, -ss after -i is better but slower.
                // Since we are using -c copy, input seeking (-ss before -i) is generally preferred for speed.
                $ffmpegCutArgs .= " -ss " . number_format($startTime, 3, '.', '');
            }
            $ffmpegCutArgs .= " -i " . escapeshellarg($tempDownloadedFullPath); // Input file
            if ($endTime !== null) {
                // If start_time is used, -to is relative to the new start.
                // If only end_time, it's absolute.
                // For simplicity with -c copy, if start_time is present, calculate duration for -t
                if ($startTime !== null) {
                    $durationToCut = $endTime - $startTime;
                    if ($durationToCut <=0) throw new Exception("Calculated duration for cut is not positive.");
                    $ffmpegCutArgs .= " -t " . number_format($durationToCut, 3, '.', '');
                } else { // Only end_time is present
                    $ffmpegCutArgs .= " -to " . number_format($endTime, 3, '.', '');
                }
            }
            // Copy codecs, avoid re-encoding. '-map 0' copies all streams (v/a/subs)
            // Add -movflags +faststart for web playback optimization
            $ffmpegCutArgs .= " -map 0 -c copy -movflags +faststart " . escapeshellarg($finalOutputPath);

            $error = executeExternalCommand(FFMPEG_PATH, $ffmpegCutArgs);

            if ($error || !file_exists($finalOutputPath) || filesize($finalOutputPath) < 1024) { // Check for small file size too
                if(file_exists($finalOutputPath)) @unlink($finalOutputPath); // Clean failed cut
                // Provide more specific error if -c copy often fails
                if (stripos($error ?? '', 'copy') !== false) {
                    $error .= " (Note: Cutting with '-c copy' requires cuts near keyframes. Re-encoding might be needed for precise cuts, but is much slower and not implemented here.)";
                }
                throw new Exception($error ?: "Failed to cut video segment (output file small/missing).");
            }

            writeToLog("Segment cut successfully: " . $finalOutputPath);
            $finalProcessedPath = $finalOutputPath; // The cut segment is our final file
            // The original full download is now temporary and should be cleaned up
            // It's already in $tempFilesForCleanup from the glob() earlier

        } else {
            // --- Step 3: No Cutting - Move Full Video ---
            writeToLog("No time segment requested. Moving full download to final destination.");
            $finalFilename = $outputBaseFilename . '.' . pathinfo($tempDownloadedFullPath, PATHINFO_EXTENSION); // Use original extension
            $finalOutputPath = $outputDirPath . '/' . $finalFilename;

            if (!rename($tempDownloadedFullPath, $finalOutputPath)) {
                writeToLog("Rename failed, attempting copy...");
                if (copy($tempDownloadedFullPath, $finalOutputPath)) { @unlink($tempDownloadedFullPath); writeToLog("Copy succeeded."); $finalProcessedPath = $finalOutputPath; }
                else { $sysError = error_get_last(); throw new Exception("Failed to move full download to output directory. Copy error: " . ($sysError['message'] ?? 'Unknown')); }
            } else { writeToLog("Move successful."); $finalProcessedPath = $finalOutputPath; }
            // Remove the moved file from the cleanup list
            $key = array_search($tempDownloadedFullPath, $tempFilesForCleanup); if($key !== false) unset($tempFilesForCleanup[$key]);
        }


        // --- Success ---
        if (!$finalProcessedPath || !file_exists($finalProcessedPath)) {
            throw new Exception("Processing finished, but final output file path is missing or invalid.");
        }
        $finalFilename = basename($finalProcessedPath);
        $publicUrlPathParts = array_filter([rtrim($basePublicUrl, '/'), $outputFolder, $finalFilename]);
        $publicUrl = implode('/', $publicUrlPathParts);

        writeToLog("Processing successful. Final URL: " . $publicUrl);
        http_response_code(200);
        echo json_encode([ "url" => $publicUrl, "filename" => $finalFilename ]);

    } catch (Exception $e) {
        handleErrorAndLog($e->getMessage(), 500, "YouTube processing failed.");
    } finally {
        // --- Cleanup ---
        // Clean up the full download and any other temp files
        cleanupTempFiles($tempFilesForCleanup);
    }

    exit; // End script after POST processing

} else {
    // Handle disallowed methods
    handleErrorAndLog("Method not allowed. Use GET for documentation or POST with JSON body.", 405);
}

?>
