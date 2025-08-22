<?php
/**
 * noCron Installer
 * Drop this file in your web root, run it, and it will generate:
 *   - tmp/noCron-xyz.php (worker)
 *   - tmp/noCronControl-xyz.php (manager)
 *   - tmp/noCron.config.json (config file)
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $taskType   = $_POST['taskType'];    // php or url
    $taskCode   = $_POST['taskCode'];
    $interval   = intval($_POST['interval']);
    $window     = intval($_POST['window']);
    $suffix     = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['suffix']);

    if (strlen($suffix) < 3) {
        $suffix = substr(md5(mt_rand()), 0, 6);
    }

    $workerFile  = "noCron-$suffix.php";
    $managerFile = "noCronControl-$suffix.php";
    $secret      = bin2hex(random_bytes(16));

    // ensure tmp folder
    if (!is_dir(__DIR__ . "/tmp")) {
        mkdir(__DIR__ . "/tmp");
    }

    // Save config
    $config = [
        "worker"   => $workerFile,
        "manager"  => $managerFile,
        "secret"   => $secret,
        "interval" => $interval,
        "window"   => $window,
        "taskType" => $taskType,
        "taskCode" => $taskCode
    ];
    file_put_contents(__DIR__ . "/tmp/noCron.config.json", json_encode($config, JSON_PRETTY_PRINT));

    // Worker script
    $workerCode = <<<PHP
<?php
// Auto-generated noCron Worker ($suffix)
\$config = json_decode(file_get_contents(__DIR__ . "/noCron.config.json"), true);
if (!isset(\$_GET['auth']) || \$_GET['auth'] !== \$config['secret']) {
    http_response_code(403);
    exit("Forbidden");
}

\$start = time();
while ((time() - \$start) < \$config['window']) {
    if (\$config['taskType'] === 'php') {
        eval(\$config['taskCode']);
    } else {
        file_get_contents(\$config['taskCode']);
    }
    sleep(\$config['interval']);
}
// respawn
\$url = (isset(\$_SERVER['HTTPS']) ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}" . dirname(\$_SERVER['REQUEST_URI']) . "/{\$config['worker']}?auth={\$config['secret']}";
file_get_contents(\$url);
PHP;

    file_put_contents(__DIR__ . "/tmp/$workerFile", $workerCode);

    // Manager script
    $managerCode = <<<PHP
<?php
// Auto-generated noCron Manager ($suffix)
\$configFile = __DIR__ . "/noCron.config.json";
\$config = json_decode(file_get_contents(\$configFile), true);

if (!isset(\$_GET['auth']) || \$_GET['auth'] !== \$config['secret']) {
    http_response_code(403);
    exit("Forbidden");
}

if (\$_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset(\$_POST['kill'])) {
        unlink(\$configFile);
        echo "noCron killed.";
        exit;
    }
    \$config['interval'] = intval(\$_POST['interval']);
    \$config['window']   = intval(\$_POST['window']);
    \$config['taskType'] = \$_POST['taskType'];
    \$config['taskCode'] = \$_POST['taskCode'];
    file_put_contents(\$configFile, json_encode(\$config, JSON_PRETTY_PRINT));
    echo "Config updated.";
}
?>
<h2>noCron Manager (<?php echo htmlspecialchars(\$config['worker']); ?>)</h2>
<form method="post">
Task Type:
<select name="taskType">
  <option value="php" <?php if(\$config['taskType']==='php') echo 'selected';?>>PHP Code</option>
  <option value="url" <?php if(\$config['taskType']==='url') echo 'selected';?>>URL</option>
</select><br>
Task Code/URL:<br>
<textarea name="taskCode" rows="5" cols="60"><?php echo htmlspecialchars(\$config['taskCode']); ?></textarea><br>
Interval (sec): <input name="interval" value="<?php echo \$config['interval'];?>"><br>
Window (sec): <input name="window" value="<?php echo \$config['window'];?>"><br>
<button type="submit">Update</button>
<button type="submit" name="kill" value="1">Kill</button>
</form>
PHP;

    file_put_contents(__DIR__ . "/tmp/$managerFile", $managerCode);

    echo "<h3>✅ Installed!</h3>";
    echo "Worker: <a href='tmp/$workerFile?auth=$secret' target='_blank'>tmp/$workerFile</a><br>";
    echo "Manager: <a href='tmp/$managerFile?auth=$secret' target='_blank'>tmp/$managerFile</a><br>";
    exit;
}
?>

<h2>noCron Installer</h2>
<form method="post">
Task Type:
<select name="taskType">
  <option value="php">PHP Code</option>
  <option value="url">URL</option>
</select><br>
Task Code / URL:<br>
<textarea name="taskCode" rows="5" cols="60"></textarea><br>
Interval (sec): <input type="number" name="interval" value="10"><br>
Window (sec): <input type="number" name="window" value="60"><br>
Custom Suffix (optional): <input type="text" name="suffix"><br>
<button type="submit">Install</button>
</form>
