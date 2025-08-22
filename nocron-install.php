<?php
/**
 * noCron Installer (Version 2.4)
 * IMPORTANT: This script must be placed in the project's root directory (e.g., /var/www/html/).
 * Generates all files in noCron-{suffix}/:
 *   - noCron-{suffix}.php (worker script)
 *   - noCronControl-{suffix}.php (manager script with web interface)
 *   - noCron-{suffix}.config.json (configuration file)
 *   - stats-{suffix}.json (statistics file)
 *   - nocron-{suffix}.log (log file)
 *   - stop-{suffix}.txt (created temporarily to pause the worker)
 * The {suffix} is user-provided (3-20 alphanumeric characters) or randomly generated.
 * Multiple instances can coexist by using unique suffixes.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action'])) {
    // Input validation
    $taskType = $_POST['taskType'] ?? '';
    if (!in_array($taskType, ['php', 'url'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Invalid task type.']);
        exit;
    }
    $taskCode = trim($_POST['taskCode'] ?? '');
    if (empty($taskCode)) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Task code/URL cannot be empty.']);
        exit;
    }
    $interval = intval($_POST['interval'] ?? 0);
    if ($interval < 1 || $interval > 3600) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Interval must be between 1 and 3600 seconds.']);
        exit;
    }
    $window = intval($_POST['window'] ?? 0);
    if ($window < $interval || $window > 86400) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Window must be greater than interval and less than 86400 seconds.']);
        exit;
    }
    $suffix = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['suffix'] ?? '');
    if (strlen($suffix) < 3 || strlen($suffix) > 20) {
        $suffix = substr(md5(mt_rand()), 0, 6);
    }

    // Check if noCron-{suffix} folder already exists
    $baseDir = "noCron-$suffix";
    if (is_dir(__DIR__ . "/$baseDir")) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => "Folder noCron-$suffix already exists. Choose a different suffix."]);
        exit;
    }

    // Ensure HTTPS
    if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'HTTPS is required for secure operation.']);
        exit;
    }

    // Create noCron-{suffix} folder
    if (!mkdir(__DIR__ . "/$baseDir", 0755, true)) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Failed to create noCron directory.']);
        exit;
    }

    // Define file paths within noCron-{suffix}/
    $workerFile = "$baseDir/noCron-$suffix.php";
    $managerFile = "$baseDir/noCronControl-$suffix.php";
    $configFile = "$baseDir/noCron-$suffix.config.json";
    $statsFile = "$baseDir/stats-$suffix.json";
    $logFile = "$baseDir/nocron-$suffix.log";
    $secret = bin2hex(random_bytes(32));

    // Check for secret collision across all noCron-* config files
    $configFiles = glob(__DIR__ . "/noCron-*/noCron-*.config.json");
    foreach ($configFiles as $file) {
        $existing = json_decode(file_get_contents($file), true);
        if ($existing['secret'] === $secret) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Secret collision detected. Try again.']);
            exit;
        }
    }

    // Save config
    $config = [
        'worker' => "noCron-$suffix.php",
        'manager' => "noCronControl-$suffix.php",
        'statsFile' => "stats-$suffix.json",
        'logFile' => "nocron-$suffix.log",
        'suffix' => $suffix,
        'secret' => $secret,
        'interval' => $interval,
        'window' => $window,
        'taskType' => $taskType,
        'taskCode' => $taskCode
    ];
    $fp = fopen(__DIR__ . "/$configFile", 'w');
    if (flock($fp, LOCK_EX)) {
        fwrite($fp, json_encode($config, JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Failed to lock config file.']);
        exit;
    }
    fclose($fp);

    // Initialize stats
    $initialStats = [
        'total_runs' => 0,
        'total_loops' => 0,
        'total_success' => 0,
        'total_fails' => 0,
        'last_run' => null,
        'next_run' => 'Not started yet'
    ];
    $fp = fopen(__DIR__ . "/$statsFile", 'w');
    if (flock($fp, LOCK_EX)) {
        fwrite($fp, json_encode($initialStats, JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Failed to lock stats file.']);
        exit;
    }
    fclose($fp);

    // Worker script
    $workerCode = <<<PHP
<?php
// Auto-generated noCron Worker ($suffix)
\$configFile = __DIR__ . "/noCron-$suffix.config.json";
if (!file_exists(\$configFile)) {
    file_put_contents(__DIR__ . "/nocron-$suffix.log", date('Y-m-d H:i:s') . ": Config file missing\\n", FILE_APPEND);
    exit;
}
\$config = json_decode(file_get_contents(\$configFile), true);
if (!\$config) {
    file_put_contents(__DIR__ . "/nocron-$suffix.log", date('Y-m-d H:i:s') . ": Failed to parse config file\\n", FILE_APPEND);
    exit;
}
if (!isset(\$_GET['auth']) || \$_GET['auth'] !== \$config['secret']) {
    file_put_contents(__DIR__ . "/nocron-$suffix.log", date('Y-m-d H:i:s') . ": Authentication failed\\n", FILE_APPEND);
    http_response_code(403);
    exit("Forbidden");
}

\$statsFile = __DIR__ . "/{\$config['statsFile']}";
\$logFile = __DIR__ . "/{\$config['logFile']}";
\$stopFile = __DIR__ . "/stop-{\$config['suffix']}.txt";

// Ensure log file is writable
if (!is_writable(dirname(\$logFile)) || (file_exists(\$logFile) && !is_writable(\$logFile))) {
    file_put_contents(__DIR__ . "/nocron-$suffix.log", date('Y-m-d H:i:s') . ": Log file not writable\\n", FILE_APPEND);
    exit;
}

// Load stats
\$stats = file_exists(\$statsFile) ? json_decode(file_get_contents(\$statsFile), true) : [];
if (!\$stats) {
    \$stats = ['total_runs' => 0, 'total_loops' => 0, 'total_success' => 0, 'total_fails' => 0, 'last_run' => null, 'next_run' => 'Not started yet'];
}

file_put_contents(\$logFile, date('Y-m-d H:i:s') . ": Worker started\\n", FILE_APPEND);

\$start = time();
while ((time() - \$start) < \$config['window']) {
    if (file_exists(\$stopFile)) {
        unlink(\$stopFile);
        file_put_contents(\$logFile, date('Y-m-d H:i:s') . ": Worker stopped gracefully\\n", FILE_APPEND);
        exit;
    }

    \$stats['total_loops'] = (\$stats['total_loops'] ?? 0) + 1;
    \$stats['total_runs'] = (\$stats['total_runs'] ?? 0) + 1;

    try {
        if (\$config['taskType'] === 'php') {
            eval(\$config['taskCode']);
            \$stats['total_success'] = (\$stats['total_success'] ?? 0) + 1;
            file_put_contents(\$logFile, date('Y-m-d H:i:s') . ": Task executed successfully\\n", FILE_APPEND);
        } else {
            file_put_contents(\$logFile, date('Y-m-d H:i:s') . ": Attempting URL hit: {\$config['taskCode']}\\n", FILE_APPEND);
            \$ch = curl_init(\$config['taskCode']);
            curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt(\$ch, CURLOPT_TIMEOUT, 10);
            \$result = curl_exec(\$ch);
            if (curl_errno(\$ch)) {
                throw new Exception("cURL error: " . curl_error(\$ch));
            }
            curl_close(\$ch);
            \$stats['total_success'] = (\$stats['total_success'] ?? 0) + 1;
            file_put_contents(\$logFile, date('Y-m-d H:i:s') . ": Task executed successfully\\n", FILE_APPEND);
        }
    } catch (Exception \$e) {
        \$stats['total_fails'] = (\$stats['total_fails'] ?? 0) + 1;
        \$errorMsg = date('Y-m-d H:i:s') . ": Task failed - " . \$e->getMessage() . "\\n";
        file_put_contents(\$logFile, \$errorMsg, FILE_APPEND);
    }

    // Update timestamps
    \$now = new DateTime('now', new DateTimeZone('UTC'));
    \$stats['last_run'] = \$now->format('Y-m-d\\TH:i:s\\Z');
    \$next = clone \$now;
    \$next->add(new DateInterval('PT' . \$config['interval'] . 'S'));
    \$diff = \$next->diff(\$now);
    \$nextRunStr = '';
    if (\$diff->d > 0) \$nextRunStr .= \$diff->d . ' days ';
    if (\$diff->h > 0) \$nextRunStr .= \$diff->h . ' hrs ';
    if (\$diff->i > 0) \$nextRunStr .= \$diff->i . ' mins ';
    \$nextRunStr .= \$diff->s . ' seconds';
    \$stats['next_run'] = trim(\$nextRunStr);

    // Save stats
    file_put_contents(\$statsFile, json_encode(\$stats, JSON_PRETTY_PRINT));

    sleep(\$config['interval']);
}

// Respawn with cURL
\$url = "https://{\$_SERVER['HTTP_HOST']}/noCron-{\$config['suffix']}/{\$config['worker']}?auth={\$config['secret']}";
file_put_contents(\$logFile, date('Y-m-d H:i:s') . ": Attempting respawn: \$url\\n", FILE_APPEND);
\$ch = curl_init(\$url);
curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt(\$ch, CURLOPT_TIMEOUT, 5);
\$response = curl_exec(\$ch);
if (curl_errno(\$ch)) {
    file_put_contents(\$logFile, date('Y-m-d H:i:s') . ": Respawn failed - " . curl_error(\$ch) . "\\n", FILE_APPEND);
}
curl_close(\$ch);
PHP;

    file_put_contents(__DIR__ . "/$workerFile", $workerCode);

    // Manager script
    $managerCode = <<<PHP
<?php
// Auto-generated noCron Manager ($suffix)
\$configFile = __DIR__ . "/noCron-$suffix.config.json";
if (!file_exists(\$configFile)) {
    http_response_code(500);
    exit("Config file missing");
}
\$config = json_decode(file_get_contents(\$configFile), true);
if (!\$config) {
    http_response_code(500);
    exit("Failed to parse config file");
}
if (!isset(\$_GET['auth']) || \$_GET['auth'] !== \$config['secret']) {
    http_response_code(403);
    exit("Forbidden");
}

// Handle AJAX requests
if (isset(\$_GET['action'])) {
    header('Content-Type: application/json');
    if (\$_GET['action'] === 'validate') {
        \$taskType = \$_POST['taskType'] ?? '';
        if (!in_array(\$taskType, ['php', 'url'])) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid task type.']);
            exit;
        }
        \$taskCode = trim(\$_POST['taskCode'] ?? '');
        if (empty(\$taskCode)) {
            echo json_encode(['status' => 'error', 'message' => 'Task code/URL cannot be empty.']);
            exit;
        }
        \$interval = intval(\$_POST['interval'] ?? 0);
        if (\$interval < 1 || \$interval > 3600) {
            echo json_encode(['status' => 'error', 'message' => 'Interval must be between 1 and 3600 seconds.']);
            exit;
        }
        \$window = intval(\$_POST['window'] ?? 0);
        if (\$window < \$interval || \$window > 86400) {
            echo json_encode(['status' => 'error', 'message' => 'Window must be greater than interval and less than 86400 seconds.']);
            exit;
        }
        echo json_encode(['status' => 'success', 'message' => 'Input validated successfully.']);
        exit;
    } elseif (\$_GET['action'] === 'update') {
        \$config['interval'] = intval(\$_POST['interval']);
        \$config['window'] = intval(\$_POST['window']);
        \$config['taskType'] = \$_POST['taskType'];
        \$config['taskCode'] = \$_POST['taskCode'];
        \$fp = fopen(\$configFile, 'w');
        if (flock(\$fp, LOCK_EX)) {
            fwrite(\$fp, json_encode(\$config, JSON_PRETTY_PRINT));
            flock(\$fp, LOCK_UN);
            echo json_encode(['status' => 'success', 'message' => 'Config updated.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to lock config file.']);
        }
        fclose(\$fp);
        exit;
    } elseif (\$_GET['action'] === 'pause') {
        file_put_contents(__DIR__ . "/stop-{\$config['suffix']}.txt", '');
        echo json_encode(['status' => 'success', 'message' => 'Worker paused.']);
        exit;
    } elseif (\$_GET['action'] === 'resume') {
        if (file_exists(__DIR__ . "/stop-{\$config['suffix']}.txt")) {
            unlink(__DIR__ . "/stop-{\$config['suffix']}.txt");
            echo json_encode(['status' => 'success', 'message' => 'Worker resumed.']);
        } else {
            echo json_encode(['status' => 'success', 'message' => 'Worker already running.']);
        }
        exit;
    } elseif (\$_GET['action'] === 'kill') {
        \$files = glob(__DIR__ . "/*");
        foreach (\$files as \$file) {
            if (is_file(\$file)) {
                unlink(\$file);
            }
        }
        rmdir(__DIR__);
        echo json_encode(['status' => 'success', 'message' => 'noCron killed.']);
        exit;
    } elseif (\$_GET['action'] === 'stats') {
        \$statsFile = __DIR__ . "/{\$config['statsFile']}";
        \$logFile = __DIR__ . "/{\$config['logFile']}";
        \$stats = file_exists(\$statsFile) ? json_decode(file_get_contents(\$statsFile), true) : [];
        if (!\$stats) {
            \$stats = ['total_runs' => 0, 'total_loops' => 0, 'total_success' => 0, 'total_fails' => 0, 'last_run' => null, 'next_run' => 'Not started yet'];
        }
        \$logs = file_exists(\$logFile) ? 
            array_slice(array_reverse(file(\$logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)), 0, 10) : [];
        echo json_encode([
            'status' => 'success',
            'data' => [
                'stats' => \$stats,
                'logs' => \$logs,
                'paused' => file_exists(__DIR__ . "/stop-{\$config['suffix']}.txt")
            ]
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>noCron Manager (<?php echo htmlspecialchars(\$config['worker']); ?>)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .fade-in { animation: fadeIn 0.5s; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .log-panel { max-height: 200px; overflow-y: auto; font-family: monospace; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h2 class="mb-4">noCron Manager (<?php echo htmlspecialchars(\$config['worker']); ?>)</h2>
        <div id="alerts"></div>
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Configure Task</h5>
                <form id="configForm">
                    <div class="mb-3">
                        <label for="taskType" class="form-label">Task Type</label>
                        <select name="taskType" id="taskType" class="form-select">
                            <option value="php" <?php if(\$config['taskType']==='php') echo 'selected';?>>PHP Code</option>
                            <option value="url" <?php if(\$config['taskType']==='url') echo 'selected';?>>URL</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="taskCode" class="form-label">Task Code/URL</label>
                        <textarea name="taskCode" id="taskCode" class="form-control" rows="5"><?php echo htmlspecialchars(\$config['taskCode']); ?></textarea>
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="interval" class="form-label">Interval (sec)</label>
                        <input type="number" name="interval" id="interval" class="form-control" value="<?php echo \$config['interval'];?>">
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="window" class="form-label">Window (sec)</label>
                        <input type="number" name="window" id="window" class="form-control" value="<?php echo \$config['window'];?>">
                        <div class="invalid-feedback"></div>
                    </div>
                    <button type="submit" class="btn btn-primary" id="updateBtn">Update</button>
                    <button type="button" class="btn btn-warning" id="pauseResumeBtn"><?php echo file_exists(__DIR__ . "/stop-{\$config['suffix']}.txt") ? 'Resume' : 'Pause'; ?></button>
                    <button type="button" class="btn btn-danger" id="killBtn">Uninstall</button>
                    <div class="form-check mt-3">
                        <input type="checkbox" class="form-check-input" id="autoRefresh" checked>
                        <label class="form-check-label" for="autoRefresh">Auto-refresh stats/logs</label>
                    </div>
                </form>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Stats</h5>
                <ul class="list-group list-group-flush" id="statsList">
                    <li class="list-group-item">Total Runs: <span id="totalRuns">0</span></li>
                    <li class="list-group-item">Total Loops: <span id="totalLoops">0</span></li>
                    <li class="list-group-item">Total Success: <span id="totalSuccess">0</span></li>
                    <li class="list-group-item">Total Fails: <span id="totalFails">0</span></li>
                    <li class="list-group-item">Last Run: <span id="lastRun">N/A</span></li>
                    <li class="list-group-item">Next Run In: <span id="nextRun">N/A</span></li>
                </ul>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Recent Logs (Last 10 Entries)</h5>
                <div class="log-panel p-3 bg-light rounded" id="logPanel"></div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showAlert(message, type) {
            const alert = $(`<div class="alert alert-\${type} alert-dismissible fade show fade-in" role="alert">\${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`);
            $('#alerts').append(alert);
            setTimeout(() => alert.alert('close'), 5000);
        }

        const baseUrl = window.location.pathname + '?auth=<?php echo \$_GET['auth']; ?>';
        let lastRunTime = null;
        let interval = null;
        let countdownInterval = null;

        function updateCountdown() {
            if (!lastRunTime || !interval) {
                $('#nextRun').text('Task not run yet');
                return;
            }
            const now = new Date();
            const lastRun = new Date(lastRunTime);
            const elapsed = Math.floor((now - lastRun) / 1000);
            const secondsUntilNext = interval - (elapsed % interval);
            if (secondsUntilNext <= 0) {
                $('#nextRun').text('0 seconds');
            } else {
                $('#nextRun').text(secondsUntilNext + ' seconds');
            }
        }

        function updateStatsAndLogs() {
            $.ajax({
                url: baseUrl + '&action=stats',
                method: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        const { stats, logs, paused } = response.data;
                        $('#totalRuns').text(stats.total_runs || 0);
                        $('#totalLoops').text(stats.total_loops || 0);
                        $('#totalSuccess').text(stats.total_success || 0);
                        $('#totalFails').text(stats.total_fails || 0);
                        $('#lastRun').text(stats.last_run || 'N/A');
                        $('#logPanel').html(logs.join('<br>'));
                        $('#pauseResumeBtn').text(paused ? 'Resume' : 'Pause').toggleClass('btn-warning', !paused).toggleClass('btn-success', paused);

                        // Update countdown variables
                        lastRunTime = stats.last_run;
                        interval = <?php echo \$config['interval']; ?>;
                        if (!lastRunTime) {
                            $('#nextRun').text('Task not run yet');
                            clearInterval(countdownInterval);
                            countdownInterval = null;
                        } else {
                            updateCountdown();
                            if (!countdownInterval) {
                                countdownInterval = setInterval(updateCountdown, 1000);
                            }
                        }
                    } else {
                        showAlert(response.message, 'danger');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Stats fetch error:', {
                        status: jqXHR.status,
                        statusText: jqXHR.statusText,
                        responseText: jqXHR.responseText,
                        textStatus: textStatus,
                        errorThrown: errorThrown
                    });
                    showAlert(`Failed to fetch stats/logs: \${textStatus} (\${jqXHR.status})`, 'danger');
                }
            });
        }

        $('#configForm').on('submit', function(e) {
            e.preventDefault();
            $('.is-invalid').removeClass('is-invalid');
            $('.invalid-feedback').text('');
            const formData = $(this).serialize();
            $.ajax({
                url: baseUrl + '&action=validate',
                method: 'POST',
                data: formData,
                dataType: 'json',
                beforeSend: function() {
                    $('#updateBtn').prop('disabled', true).text('Validating...');
                },
                success: function(response) {
                    if (response.status === 'success') {
                        $.ajax({
                            url: baseUrl + '&action=update',
                            method: 'POST',
                            data: formData,
                            dataType: 'json',
                            success: function(updateResponse) {
                                showAlert(updateResponse.message, updateResponse.status === 'success' ? 'success' : 'danger');
                            },
                            error: function(jqXHR, textStatus, errorThrown) {
                                console.error('Update error:', {
                                    status: jqXHR.status,
                                    statusText: jqXHR.statusText,
                                    responseText: jqXHR.responseText,
                                    textStatus: textStatus,
                                    errorThrown: errorThrown
                                });
                                showAlert(`Failed to update config: \${textStatus} (\${jqXHR.status})`, 'danger');
                            },
                            complete: function() {
                                $('#updateBtn').prop('disabled', false).text('Update');
                            }
                        });
                    } else {
                        showAlert(response.message, 'danger');
                        if (response.message.includes('task type')) {
                            $('#taskType').addClass('is-invalid').next('.invalid-feedback').text(response.message);
                        } else if (response.message.includes('Task code')) {
                            $('#taskCode').addClass('is-invalid').next('.invalid-feedback').text(response.message);
                        } else if (response.message.includes('Interval')) {
                            $('#interval').addClass('is-invalid').next('.invalid-feedback').text(response.message);
                        } else if (response.message.includes('Window')) {
                            $('#window').addClass('is-invalid').next('.invalid-feedback').text(response.message);
                        }
                        $('#updateBtn').prop('disabled', false).text('Update');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Validate error:', {
                        status: jqXHR.status,
                        statusText: jqXHR.statusText,
                        responseText: jqXHR.responseText,
                        textStatus: textStatus,
                        errorThrown: errorThrown
                    });
                    showAlert(`Failed to validate inputs: \${textStatus} (\${jqXHR.status})`, 'danger');
                    $('#updateBtn').prop('disabled', false).text('Update');
                }
            });
        });

        $('#pauseResumeBtn').on('click', function() {
            const action = $(this).text() === 'Pause' ? 'pause' : 'resume';
            $.ajax({
                url: baseUrl + '&action=' + action,
                method: 'POST',
                dataType: 'json',
                beforeSend: function() {
                    $('#pauseResumeBtn').prop('disabled', true).text(action === 'pause' ? 'Pausing...' : 'Resuming...');
                },
                success: function(response) {
                    showAlert(response.message, response.status === 'success' ? 'success' : 'danger');
                    updateStatsAndLogs();
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Pause/Resume error:', {
                        status: jqXHR.status,
                        statusText: jqXHR.statusText,
                        responseText: jqXHR.responseText,
                        textStatus: textStatus,
                        errorThrown: errorThrown
                    });
                    showAlert(`Failed to \${action} worker: \${textStatus} (\${jqXHR.status})`, 'danger');
                },
                complete: function() {
                    $('#pauseResumeBtn').prop('disabled', false);
                }
            });
        });

        $('#killBtn').on('click', function() {
            if (!confirm('Are you sure you want to kill this noCron instance? This will delete all associated files?')) return;
            $.ajax({
                url: baseUrl + '&action=kill',
                method: 'POST',
                dataType: 'json',
                beforeSend: function() {
                    $('#killBtn').prop('disabled', true).text('Killing...');
                },
                success: function(response) {
                    showAlert(response.message, response.status === 'success' ? 'success' : 'danger');
                    if (response.status === 'success') {
                        setTimeout(() => window.location.href = '/', 2000);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Kill error:', {
                        status: jqXHR.status,
                        statusText: jqXHR.statusText,
                        responseText: jqXHR.responseText,
                        textStatus: textStatus,
                        errorThrown: errorThrown
                    });
                    showAlert(`Failed to kill noCron: \${textStatus} (\${jqXHR.status})`, 'danger');
                },
                complete: function() {
                    $('#killBtn').prop('disabled', false).text('Kill');
                }
            });
        });

        let autoRefresh = setInterval(updateStatsAndLogs, 10000);
        $('#autoRefresh').on('change', function() {
            if ($(this).is(':checked')) {
                autoRefresh = setInterval(updateStatsAndLogs, 10000);
            } else {
                clearInterval(autoRefresh);
            }
        });

        updateStatsAndLogs();
    </script>
</body>
</html>
PHP;

    file_put_contents(__DIR__ . "/$managerFile", $managerCode);

    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'message' => 'noCron installed successfully!',
        'worker' => "$baseDir/noCron-$suffix.php?auth=$secret",
        'manager' => "$baseDir/noCronControl-$suffix.php?auth=$secret"
    ]);
    exit;
}

// Handle AJAX validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'validate') {
    header('Content-Type: application/json');
    $taskType = $_POST['taskType'] ?? '';
    if (!in_array($taskType, ['php', 'url'])) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid task type.']);
        exit;
    }
    $taskCode = trim($_POST['taskCode'] ?? '');
    if (empty($taskCode)) {
        echo json_encode(['status' => 'error', 'message' => 'Task code/URL cannot be empty.']);
        exit;
    }
    $interval = intval($_POST['interval'] ?? 0);
    if ($interval < 1 || $interval > 3600) {
        echo json_encode(['status' => 'error', 'message' => 'Interval must be between 1 and 3600 seconds.']);
        exit;
    }
    $window = intval($_POST['window'] ?? 0);
    if ($window < $interval || $window > 86400) {
        echo json_encode(['status' => 'error', 'message' => 'Window must be greater than interval and less than 86400 seconds.']);
        exit;
    }
    $suffix = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['suffix'] ?? '');
    if (strlen($suffix) < 3 || strlen($suffix) > 20) {
        $suffix = substr(md5(mt_rand()), 0, 6);
    }
    if (is_dir(__DIR__ . "/noCron-$suffix")) {
        echo json_encode(['status' => 'error', 'message' => "Folder noCron-$suffix already exists. Choose a different suffix."]);
        exit;
    }
    echo json_encode(['status' => 'success', 'message' => 'Input validated successfully.']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>noCron Installer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .fade-in { animation: fadeIn 0.5s; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h2 class="mb-4">noCron Installer</h2>
        <p>Place this script in the project's root directory. All files will be generated in a noCron-{suffix} folder.</p>
        <div id="alerts"></div>
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Install noCron</h5>
                <form id="installForm">
                    <div class="mb-3">
                        <label for="taskType" class="form-label">Task Type</label>
                        <select name="taskType" id="taskType" class="form-select">
                            <option value="php">PHP Code</option>
                            <option value="url">URL</option>
                        </select>
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="taskCode" class="form-label">Task Code/URL</label>
                        <textarea name="taskCode" id="taskCode" class="form-control" rows="5"></textarea>
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="interval" class="form-label">Interval (sec)</label>
                        <input type="number" name="interval" id="interval" class="form-control" value="10">
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="window" class="form-label">Window (sec)</label>
                        <input type="number" name="window" id="window" class="form-control" value="60">
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="suffix" class="form-label">Custom Suffix (optional, 3-20 chars)</label>
                        <input type="text" name="suffix" id="suffix" class="form-control">
                        <div class="invalid-feedback"></div>
                    </div>
                    <button type="submit" class="btn btn-primary" id="installBtn">Install</button>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showAlert(message, type) {
            const alert = $(`<div class="alert alert-${type} alert-dismissible fade show fade-in" role="alert">${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`);
            $('#alerts').append(alert);
            setTimeout(() => alert.alert('close'), 5000);
        }

        $('#installForm').on('submit', function(e) {
            e.preventDefault();
            $('.is-invalid').removeClass('is-invalid');
            $('.invalid-feedback').text('');
            const formData = $(this).serialize();
            $.ajax({
                url: '?action=validate',
                method: 'POST',
                data: formData,
                dataType: 'json',
                beforeSend: function() {
                    $('#installBtn').prop('disabled', true).text('Validating...');
                },
                success: function(response) {
                    if (response.status === 'success') {
                        $.ajax({
                            url: window.location.href,
                            method: 'POST',
                            data: formData,
                            dataType: 'json',
                            success: function(installResponse) {
                                if (installResponse.status === 'success') {
                                    showAlert(installResponse.message, 'success');
                                    const workerLink = `<a href="${installResponse.worker}" target="_blank">${installResponse.worker}</a>`;
                                    const managerLink = `<a href="${installResponse.manager}" target="_blank">${installResponse.manager}</a>`;
                                    $('#alerts').append(`<div class="alert alert-success fade-in">Worker: ${workerLink}<br>Note: Click on worker to start the worker for the first time and close the window afterwards.<br>Manager: ${managerLink}</div>`);
                                } else {
                                    showAlert(installResponse.message, 'danger');
                                }
                            },
                            error: function(jqXHR, textStatus, errorThrown) {
                                console.error('Install error:', {
                                    status: jqXHR.status,
                                    statusText: jqXHR.statusText,
                                    responseText: jqXHR.responseText,
                                    textStatus: textStatus,
                                    errorThrown: errorThrown
                                });
                                showAlert(`Failed to install noCron: ${textStatus} (${jqXHR.status})`, 'danger');
                            },
                            complete: function() {
                                $('#installBtn').prop('disabled', false).text('Install');
                            }
                        });
                    } else {
                        showAlert(response.message, 'danger');
                        if (response.message.includes('task type')) {
                            $('#taskType').addClass('is-invalid').next('.invalid-feedback').text(response.message);
                        } else if (response.message.includes('Task code')) {
                            $('#taskCode').addClass('is-invalid').next('.invalid-feedback').text(response.message);
                        } else if (response.message.includes('Interval')) {
                            $('#interval').addClass('is-invalid').next('.invalid-feedback').text(response.message);
                        } else if (response.message.includes('Window')) {
                            $('#window').addClass('is-invalid').next('.invalid-feedback').text(response.message);
                        } else if (response.message.includes('Folder noCron')) {
                            $('#suffix').addClass('is-invalid').next('.invalid-feedback').text(response.message);
                        }
                        $('#installBtn').prop('disabled', false).text('Install');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('Validate error:', {
                        status: jqXHR.status,
                        statusText: jqXHR.statusText,
                        responseText: jqXHR.responseText,
                        textStatus: textStatus,
                        errorThrown: errorThrown
                    });
                    showAlert(`Failed to validate inputs: ${textStatus} (${jqXHR.status})`, 'danger');
                    $('#installBtn').prop('disabled', false).text('Install');
                }
            });
        });
    </script>
</body>
</html>
