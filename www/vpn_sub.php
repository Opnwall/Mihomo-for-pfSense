<?php
require_once("guiconfig.inc");
require_once("services.inc");

$pgtitle = [gettext('VPN'), gettext('Sub')];
include("head.inc");

define('ENV_FILE', '/usr/local/etc/mihomo/sub/env');
define('LOG_FILE', '/var/log/sub.log');
define('SUB_SCRIPT', '/usr/local/etc/mihomo/sub/sub.sh');
define('LOG_TAIL_LINES', 200);

$message = "";
$message_type = "info";
$input_errors = [];

$env_missing = !file_exists(ENV_FILE);
$env_dir_missing = !is_dir(dirname(ENV_FILE));

$tab_array = [
    1 => [gettext("Mihomo"), false, "vpn_mihomo.php"],
    2 => [gettext("MosDNS"), false, "vpn_mosdns.php"],
    3 => [gettext("Sub"), true, "vpn_sub.php"],
];

display_top_tabs($tab_array);

function sub_exec_background($command)
{
    $nohup = '/usr/bin/nohup';
    $shell = '/bin/sh';

    $background_command = $nohup . ' ' . $shell . ' -c ' . escapeshellarg($command) . ' >/dev/null 2>&1 &';

    $output = [];
    $return_var = 0;
    exec($background_command, $output, $return_var);

    return $return_var === 0;
}

function sub_csrf_check_compat()
{
    if (function_exists('csrf_check')) {
        return csrf_check();
    }

    return true;
}

function sub_csrf_token_field_compat()
{
    if (function_exists('csrf_token')) {
        csrf_token();
    }
}

function sub_log_message($message, $log_file = LOG_FILE)
{
    $time = date("Y-m-d H:i:s");
    $log_entry = "[{$time}] {$message}\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

function sub_clear_log($log_file = LOG_FILE)
{
    if (!file_exists($log_file)) {
        return [
            'text' => '',
            'type' => 'success',
        ];
    }

    if (!is_writable($log_file)) {
        return [
            'text' => '日志清空失败，请确保日志文件可写。',
            'type' => 'danger',
        ];
    }

    if (@file_put_contents($log_file, '', LOCK_EX) === false) {
        return [
            'text' => '日志清空失败。',
            'type' => 'danger',
        ];
    }

    return [
        'text' => '',
        'type' => 'success',
    ];
}

function sub_escape_env_value($value)
{
    return str_replace("'", "'\"'\"'", $value);
}

function save_env_variable($key, $value, $env_file = ENV_FILE)
{
    if ($key === '') {
        return [
            'ok' => false,
            'message' => '变量名不能为空。',
        ];
    }

    $dir = dirname($env_file);
    if (!is_dir($dir)) {
        return [
            'ok' => false,
            'message' => '目录不存在：' . $dir,
        ];
    }

    if (!is_writable($dir)) {
        return [
            'ok' => false,
            'message' => '目录不可写：' . $dir,
        ];
    }

    $lines = file_exists($env_file) ? file($env_file, FILE_IGNORE_NEW_LINES) : [];
    if ($lines === false) {
        return [
            'ok' => false,
            'message' => '环境变量文件读取失败。',
        ];
    }

    $new_lines = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            $new_lines[] = $line;
            continue;
        }

        $body = (strpos($trimmed, 'export ') === 0) ? substr($trimmed, 7) : $trimmed;

        if (strpos($body, '=') === false) {
            $new_lines[] = $line;
            continue;
        }

        list($existing_key,) = explode('=', $body, 2);
        if (strtoupper(trim($existing_key)) !== strtoupper($key)) {
            $new_lines[] = $line;
        }
    }

    $escaped_value = sub_escape_env_value($value);
    $new_lines[] = "{$key}='{$escaped_value}'";

    $tmp_file = $env_file . '.tmp';
    $content = implode("\n", array_filter($new_lines, static function ($line) {
        return $line !== null;
    })) . "\n";

    if (@file_put_contents($tmp_file, $content, LOCK_EX) === false) {
        @unlink($tmp_file);
        return [
            'ok' => false,
            'message' => '临时文件写入失败：' . $tmp_file,
        ];
    }

    if (!@rename($tmp_file, $env_file)) {
        @unlink($tmp_file);
        return [
            'ok' => false,
            'message' => '无法替换目标文件：' . $env_file,
        ];
    }

    return [
        'ok' => true,
        'message' => '保存成功。',
    ];
}

function load_env_variables($env_file = ENV_FILE)
{
    $env_vars = [];

    if (!file_exists($env_file)) {
        return $env_vars;
    }

    $env_lines = @file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($env_lines === false) {
        return $env_vars;
    }

    foreach ($env_lines as $line) {
        $line = trim($line);

        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        if (preg_match("/^(?:export\s+)?([A-Za-z0-9_]+)='(.*)'$/", $line, $matches)) {
            $env_vars[strtoupper($matches[1])] = str_replace("'\"'\"'", "'", $matches[2]);
        }
    }

    return $env_vars;
}

function cleanup_temp_files()
{
    $files = [
        "/usr/local/etc/mihomo/sub/temp/mihomo_config.yaml",
        "/usr/local/etc/mihomo/sub/temp/proxies.txt",
        "/usr/local/etc/mihomo/sub/temp/config.yaml",
    ];

    foreach ($files as $file) {
        @unlink($file);
    }
}

function save_sub_settings($url, $secret)
{
    if ($url === '') {
        return [
            'text' => '订阅地址不能为空。',
            'type' => 'danger',
        ];
    }

    $url_result = save_env_variable('mihomo_URL', $url);
    if (!$url_result['ok']) {
        return [
            'text' => '保存订阅地址失败：' . $url_result['message'],
            'type' => 'danger',
        ];
    }

    $secret_result = save_env_variable('mihomo_secret', $secret);
    if (!$secret_result['ok']) {
        return [
            'text' => '保存访问密钥失败：' . $secret_result['message'],
            'type' => 'danger',
        ];
    }

    sub_log_message('订阅地址已保存。');
    sub_log_message('访问密钥已保存。');

    return [
        'text' => '',
        'type' => 'success',
    ];
}

function run_subscription_now()
{
    cleanup_temp_files();

    @file_put_contents(LOG_FILE, '', LOCK_EX);
    sub_log_message('开始执行订阅任务。');

    $command =
        '/bin/sh ' . escapeshellarg(SUB_SCRIPT) .
        ' >> ' . escapeshellarg(LOG_FILE) . ' 2>&1; ' .
        'echo "[$(date \'+%Y-%m-%d %H:%M:%S\')] 订阅任务执行完毕。" >> ' . escapeshellarg(LOG_FILE);

    $ok = sub_exec_background($command);

    if (!$ok) {
        sub_log_message('订阅任务启动失败。');
        return [
            'text' => '订阅任务启动失败。',
            'type' => 'danger',
        ];
    }

    return [
        'text' => '',
        'type' => 'success',
    ];
}

$env_vars = load_env_variables();
$current_url = $env_vars['MIHOMO_URL'] ?? '';
$current_secret = $env_vars['MIHOMO_SECRET'] ?? '';

if ($_POST) {
    if (!sub_csrf_check_compat()) {
        $input_errors[] = gettext('CSRF 校验失败，请刷新页面后重试。');
    } else {
        $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

        if ($action === 'save_settings') {
            $url = isset($_POST['subscribe_url']) ? trim((string)$_POST['subscribe_url']) : '';
            $secret = isset($_POST['mihomo_secret']) ? trim((string)$_POST['mihomo_secret']) : '';
            $result = save_sub_settings($url, $secret);
            $message = $result['text'];
            $message_type = $result['type'];
        } elseif ($action === 'subscribe_now') {
            $result = run_subscription_now();
            $message = $result['text'];
            $message_type = $result['type'];
        } elseif ($action === 'clear_log') {
            $result = sub_clear_log();
            $message = $result['text'];
            $message_type = $result['type'];
        } else {
            $message = '无效的操作。';
            $message_type = 'danger';
        }
    }
}

if ($_POST && (($_POST['ajax'] ?? '') === '1')) {
    header('Content-Type: application/json');
    echo json_encode([
        'message' => $message,
        'message_type' => $message_type,
    ]);
    exit;
}

$env_vars = load_env_variables();
$current_url = $env_vars['MIHOMO_URL'] ?? '';
$current_secret = $env_vars['MIHOMO_SECRET'] ?? '';

if ($env_dir_missing) {
    $input_errors[] = '环境变量目录不存在：' . dirname(ENV_FILE);
} elseif ($env_missing) {
    $input_errors[] = '环境变量文件不存在：' . ENV_FILE;
}
?>

<?php if (!empty($input_errors)): ?>
    <?php print_input_errors($input_errors); ?>
<?php endif; ?>

<?php if (!empty($message) && (($_POST['ajax'] ?? '') !== '1')): ?>
    <div class="alert alert-<?php echo htmlspecialchars($message_type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" role="alert">
        <pre style="margin:0; padding:0; border:0; background:transparent; white-space:pre-wrap;"><?php echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre>
    </div>
<?php endif; ?>

<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title">订阅管理</h2>
    </div>
    <div class="panel-body">
        <form method="post" id="sub-settings-form">
            <?php sub_csrf_token_field_compat(); ?>

            <div class="form-group" style="margin-left: 10px;">
                <label for="subscribe_url">订阅地址</label>
                <input
                    type="text"
                    id="subscribe_url"
                    name="subscribe_url"
                    value="<?php echo htmlspecialchars($current_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                    class="form-control"
                    placeholder="输入订阅地址"
                    autocomplete="off"
                    spellcheck="false"
                />
            </div>

            <div class="form-group" style="margin-left: 10px;">
                <label for="mihomo_secret">访问密钥</label>
                <input
                    type="text"
                    id="mihomo_secret"
                    name="mihomo_secret"
                    value="<?php echo htmlspecialchars($current_secret, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"
                    class="form-control"
                    placeholder="输入访问密钥"
                    autocomplete="off"
                    spellcheck="false"
                />
            </div>

            <button type="submit" name="action" value="save_settings" class="btn btn-primary" id="save-settings-btn" style="margin: 6px 8px 10px 10px;">
                <i class="fa fa-save"></i> 保存设置
            </button>
            <button type="submit" name="action" value="subscribe_now" class="btn btn-success" id="subscribe-now-btn" style="margin: 6px 8px 10px 0;">
                <i class="fa fa-refresh"></i> 开始订阅
            </button>
            <button type="submit" name="action" value="clear_log" class="btn btn-default" id="clear-log-btn" style="margin: 6px 0 10px 0;">
                <i class="fa fa-trash"></i> 清空日志
            </button>

            <div id="sub-light-tip" style="display:none; margin: 0 0 10px 10px; color: #666;"></div>
            <input type="hidden" name="ajax" value="0" id="sub-settings-ajax-flag">
        </form>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading clearfix">
        <h2 class="panel-title" style="line-height: 34px;">日志查看</h2>
    </div>
    <div class="panel-body">
        <div class="form-group" style="margin-bottom: 0;">
            <textarea
                id="log-viewer"
                rows="20"
                class="form-control"
                readonly="readonly"
                spellcheck="false"
                style="max-width:none;font-family:monospace;"
            ></textarea>
        </div>
    </div>
</div>

<style>
#sub-settings-form button.is-busy {
    opacity: 0.55;
    cursor: not-allowed;
    box-shadow: none;
}

#sub-settings-form button.is-busy i {
    opacity: 0.8;
}
</style>

<script>
(function() {
    var logViewer = document.getElementById('log-viewer');
    var logErrorShown = false;
    var settingsForm = document.getElementById('sub-settings-form');
    var settingsAjaxFlag = document.getElementById('sub-settings-ajax-flag');
    var lightTip = document.getElementById('sub-light-tip');
    var maxClientLines = <?php echo (int)LOG_TAIL_LINES; ?>;

    function showLightTip(text) {
        if (!lightTip) {
            return;
        }

        lightTip.textContent = text;
        lightTip.style.display = 'block';

        window.clearTimeout(showLightTip._timer);
        showLightTip._timer = window.setTimeout(function() {
            lightTip.style.display = 'none';
            lightTip.textContent = '';
        }, 2200);
    }

    function trimLogLines(text, maxLines) {
        var lines = String(text || '').split('\n');
        if (lines.length <= maxLines) {
            return String(text || '');
        }
        return lines.slice(lines.length - maxLines).join('\n');
    }

    function prependLogMessage(text) {
        if (!logViewer) {
            return;
        }

        var current = logViewer.value || '';
        var merged = text + '\n' + current;
        logViewer.value = trimLogLines(merged, maxClientLines);
        logViewer.scrollTop = 0;
    }

    function refreshLogs() {
        fetch('status_sub_logs.php', {
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.text();
            })
            .then(function(logContent) {
                if (!logViewer) {
                    return;
                }

                var trimmed = logContent.trim().toLowerCase();
                if (
                    trimmed.indexOf('<!doctype html') === 0 ||
                    trimmed.indexOf('<html') === 0 ||
                    logContent.indexOf('<title>') !== -1
                ) {
                    logViewer.value = '[错误] 日志接口返回了 HTML 页面，请检查 logs.php 是否存在且路径正确。';
                    return;
                }

                logViewer.value = trimLogLines(logContent, maxClientLines);
                logViewer.scrollTop = logViewer.scrollHeight;
                logErrorShown = false;
            })
            .catch(function(error) {
                if (!logViewer) {
                    return;
                }

                if (!logErrorShown) {
                    logViewer.value = '[错误] 无法加载日志：' + error.message;
                    logErrorShown = true;
                }
            });
    }

    function setButtonsBusy(busy, activeButton) {
        if (!settingsForm) {
            return;
        }

        var buttons = settingsForm.querySelectorAll('button[type="submit"]');
        Array.prototype.forEach.call(buttons, function(btn) {
            btn.disabled = busy;
            if (busy) {
                btn.classList.add('is-busy');
            } else {
                btn.classList.remove('is-busy');
                btn.style.opacity = '';
            }
        });

        if (busy && activeButton) {
            activeButton.style.opacity = '1';
        }
    }

    function submitSettingsForm(button) {
        if (!settingsForm) {
            return;
        }

        var action = button.value;
        var formData = new FormData(settingsForm);
        formData.set('action', action);

        if (settingsAjaxFlag) {
            formData.set('ajax', '1');
        }

        if (action === 'subscribe_now') {
            prependLogMessage('[本地提示] 任务已提交，正在后台执行订阅...');
        }

        setButtonsBusy(true, button);

        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function() {
                if (action === 'save_settings') {
                    showLightTip('设置已写入。');
                } else if (action === 'clear_log') {
                    refreshLogs();
                    showLightTip('日志已清空。');
                } else if (action === 'subscribe_now') {
                    window.setTimeout(refreshLogs, 800);
                    window.setTimeout(refreshLogs, 2000);
                    window.setTimeout(refreshLogs, 5000);
                }
            })
            .catch(function() {
            })
            .finally(function() {
                setButtonsBusy(false);
            });
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (settingsForm) {
            settingsForm.addEventListener('submit', function(event) {
                var button = event.submitter;
                if (!button) {
                    return;
                }
                event.preventDefault();
                submitSettingsForm(button);
            });
        }

        refreshLogs();
        window.setInterval(refreshLogs, 3000);
    });
})();
</script>

<?php include("foot.inc"); ?>