<?php
require_once("guiconfig.inc");
require_once("services.inc");

$pgtitle = [gettext('VPN'), gettext('Mihomo')];
include("head.inc");

$config_file = "/usr/local/etc/mihomo/config.yaml";
$log_file = "/var/log/mihomo.log";
$message = "";
$message_type = "info";
$input_errors = [];
$config_missing = !file_exists($config_file);

$tab_array = [
    1 => [gettext("Mihomo"), true, "vpn_mihomo.php"],
    2 => [gettext("MosDNS"), false, "vpn_mosdns.php"],	
    3 => [gettext("Sub"), false, "vpn_sub.php"],
];

display_top_tabs($tab_array);

function mihomo_exec($command, &$output = null, &$return_var = null)
{
    $output = [];
    $return_var = 0;
    exec($command . ' 2>&1', $output, $return_var);
    return implode("\n", $output);
}

function mihomo_exec_background($command)
{
    $nohup = '/usr/bin/nohup';
    $shell = '/bin/sh';

    $background_command = $nohup . ' ' . $shell . ' -c ' . escapeshellarg($command . ' > /dev/null 2>&1') . ' >/dev/null 2>&1 &';

    $output = [];
    $return_var = 0;
    exec($background_command, $output, $return_var);

    return $return_var === 0;
}

function handleServiceAction($action)
{
    $allowedActions = ['start', 'stop', 'restart'];
    if (!in_array($action, $allowedActions, true)) {
        return [
            'text' => '无效的操作。',
            'type' => 'danger',
        ];
    }


    $messages = [
        'start' => '',
        'stop' => '',
        'restart' => '',
    ];

    $ok = mihomo_exec_background('/usr/sbin/service mihomo ' . escapeshellarg($action));

    $result = [
        'text' => '',
        'type' => 'success',
    ];


    return $result;
}

function saveConfig($file, $content)
{
    $dir = dirname($file);

    if (!is_dir($dir)) {
        return [
            'text' => '配置保存失败：配置目录不存在。',
            'type' => 'danger',
        ];
    }

    if (file_exists($file) && !is_writable($file)) {
        return [
            'text' => '配置保存失败：配置文件不可写。',
            'type' => 'danger',
        ];
    }

    if (!file_exists($file) && !is_writable($dir)) {
        return [
            'text' => '配置保存失败：配置目录不可写。',
            'type' => 'danger',
        ];
    }

    $bytes = @file_put_contents($file, $content, LOCK_EX);
    if ($bytes === false) {
        $error = error_get_last();
        $detail = !empty($error['message']) ? $error['message'] : '未知错误';
        return [
            'text' => '配置保存失败：' . $detail,
            'type' => 'danger',
        ];
    }

    clearstatcache(true, $file);

    return [
        'text' => '配置保存成功。',
        'type' => 'success',
    ];
}

function mihomo_csrf_check()
{
    if (function_exists('csrf_check')) {
        return csrf_check();
    }

    return true;
}

function mihomo_csrf_token_field()
{
    if (function_exists('csrf_token')) {
        csrf_token();
    }
}

if ($_POST) {
    if (!mihomo_csrf_check()) {
        $input_errors[] = gettext('CSRF 校验失败，请刷新页面后重试。');
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_config') {
            $config_content_post = $_POST['config_content'] ?? '';
            $result = saveConfig($config_file, $config_content_post);
            $message = $result['text'];
            $message_type = $result['type'];
            $config_missing = !file_exists($config_file);
        } else {
            $result = handleServiceAction($action);
            $message = $result['text'];
            $message_type = $result['type'];
        }
    }
}

if ($_POST && ($_POST['ajax'] ?? '') === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'message' => $message,
        'message_type' => $message_type,
    ]);
    exit;
}

$config_content_raw = '';
if (!$config_missing) {
    $read_result = @file_get_contents($config_file);
    if ($read_result === false) {
        $input_errors[] = '配置文件存在，但无法读取。';
    } else {
        $config_content_raw = $read_result;
    }
} else {
    $input_errors[] = '配置文件不存在：' . $config_file;
}
?>

<?php if (!empty($input_errors)): ?>
    <?php print_input_errors($input_errors); ?>
<?php endif; ?>

<?php if (!empty($message) && (($_POST['ajax'] ?? '') !== '1')): ?>
    <div class="alert alert-<?php echo htmlspecialchars($message_type); ?>" role="alert">
        <pre style="margin:0; padding:0; border:0; background:transparent; white-space:pre-wrap;"><?php echo htmlspecialchars($message); ?></pre>
    </div>
<?php endif; ?>

<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title">服务状态</h2>
    </div>
    <div class="panel-body">
        <div id="mihomo-status" class="alert alert-info" style="margin-bottom: 0;">
            <i class="fa fa-circle-o-notch fa-spin"></i>
            正在检查 mihomo 服务状态...
        </div>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title">服务控制</h2>
    </div>
    <div class="panel-body">
        <form method="post" class="form-inline" id="mihomo-service-form" style="margin: 6px 0 10px 10px;">
            <?php mihomo_csrf_token_field(); ?>
            <button type="submit" name="action" value="start" class="btn btn-success" style="margin-right: 8px; margin-bottom: 0;">
                <i class="fa fa-play"></i> 启动
            </button>
            <button type="submit" name="action" value="stop" class="btn btn-danger" style="margin-right: 8px; margin-bottom: 0;">
                <i class="fa fa-stop"></i> 停止
            </button>
            <button type="submit" name="action" value="restart" class="btn btn-warning" style="margin-bottom: 0;">
                <i class="fa fa-refresh"></i> 重启
            </button>
            <input type="hidden" name="ajax" value="0" id="mihomo-ajax-flag">
        </form>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading">
        <h2 class="panel-title">配置管理</h2>
    </div>
    <div class="panel-body">
        <form method="post" id="mihomo-config-form">
            <?php mihomo_csrf_token_field(); ?>
            <div class="form-group">
                <label for="config_content">配置文件内容</label>
                <textarea id="config_content" name="config_content" rows="12" class="form-control" spellcheck="false"><?php echo htmlspecialchars($config_content_raw); ?></textarea>
            </div>
            <button type="submit" name="action" value="save_config" class="btn btn-primary" style="margin: 6px 0 10px 10px;" <?php echo $config_missing ? 'disabled="disabled"' : ''; ?>>
                <i class="fa fa-save"></i> 保存配置
            </button>
            <input type="hidden" name="ajax" value="0" id="mihomo-config-ajax-flag">
        </form>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading clearfix">
        <h2 class="panel-title" style="line-height: 34px;">日志查看</h2>
    </div>
    <div class="panel-body">
        <div class="form-group" style="margin-bottom: 0;">
            <textarea id="log-viewer" rows="12" class="form-control" readonly="readonly" spellcheck="false"></textarea>
        </div>
    </div>
</div>

<script>
(function() {
    var statusElement = document.getElementById('mihomo-status');
    var logViewer = document.getElementById('log-viewer');
    var logErrorShown = false;
    var serviceForm = document.getElementById('mihomo-service-form');
    var ajaxFlag = document.getElementById('mihomo-ajax-flag');
    var configForm = document.getElementById('mihomo-config-form');
    var configAjaxFlag = document.getElementById('mihomo-config-ajax-flag');

    function setStatus(html, alertClass) {
        statusElement.innerHTML = html;
        statusElement.className = 'alert ' + alertClass;
    }

    function scheduleStatusRefresh() {
        window.setTimeout(checkMihomoStatus, 1500);
        window.setTimeout(checkMihomoStatus, 3000);
        window.setTimeout(checkMihomoStatus, 5000);
        window.setTimeout(checkMihomoStatus, 8000);
    }

    function checkMihomoStatus() {
        fetch('status_mihomo.php', {
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function(data) {
                if (data.status === 'running') {
                    setStatus('<i class="fa fa-check-circle"></i> mihomo 正在运行', 'alert-success');
                } else {
                    setStatus('<i class="fa fa-times-circle"></i> mihomo 已停止', 'alert-danger');
                }
            })
            .catch(function(error) {
                setStatus('<i class="fa fa-exclamation-circle"></i> 状态检查失败：' + error.message, 'alert-warning');
            });
    }

    function refreshLogs() {
        fetch('status_mihomo_logs.php', {
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
                logViewer.value = logContent;
                logViewer.scrollTop = logViewer.scrollHeight;
                logErrorShown = false;
            })
            .catch(function(error) {
                if (!logErrorShown) {
                    logViewer.value = '[错误] 无法加载日志：' + error.message;
                    logErrorShown = true;
                }
            });
    }

    function submitServiceAction(button) {
        if (!serviceForm) {
            return;
        }

        var formData = new FormData(serviceForm);
        formData.set('action', button.value);
        if (ajaxFlag) {
            formData.set('ajax', '1');
        }

        var buttons = serviceForm.querySelectorAll('button[type="submit"]');
        Array.prototype.forEach.call(buttons, function(btn) {
            btn.disabled = true;
        });

        if (button.value === 'start') {
            setStatus('<i class="fa fa-circle-o-notch fa-spin"></i> 正在启动 mihomo...', 'alert-info');
        } else if (button.value === 'stop') {
            setStatus('<i class="fa fa-circle-o-notch fa-spin"></i> 正在停止 mihomo...', 'alert-warning');
        } else if (button.value === 'restart') {
            setStatus('<i class="fa fa-circle-o-notch fa-spin"></i> 正在重启 mihomo...', 'alert-info');
        }

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
            .then(function(data) {
                scheduleStatusRefresh();
                window.setTimeout(refreshLogs, 1000);
                window.setTimeout(refreshLogs, 3000);
            })
            .catch(function(error) {
                // 忽略错误提示，保持界面简洁
            })
            .finally(function() {
                Array.prototype.forEach.call(buttons, function(btn) {
                    btn.disabled = false;
                });
            });
    }

    function submitConfigForm() {
        if (!configForm) {
            return;
        }

        var formData = new FormData(configForm);
        if (configAjaxFlag) {
            formData.set('ajax', '1');
        }
        formData.set('action', 'save_config');

        var saveButton = configForm.querySelector('button[type="submit"]');
        if (saveButton) {
            saveButton.disabled = true;
        }

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
            .catch(function(error) {
                // 忽略错误提示，保持界面简洁
            })
            .finally(function() {
                if (saveButton) {
                    saveButton.disabled = false;
                }
            });
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (serviceForm) {
            serviceForm.addEventListener('submit', function(event) {
                var button = event.submitter;
                if (!button) {
                    return;
                }
                event.preventDefault();
                submitServiceAction(button);
            });
        }
        if (configForm) {
            configForm.addEventListener('submit', function(event) {
                event.preventDefault();
                submitConfigForm();
            });
        }
        checkMihomoStatus();
        refreshLogs();
        window.setInterval(checkMihomoStatus, 5000);
        window.setInterval(refreshLogs, 3000);
    });
})();
</script>

<?php include("foot.inc"); ?>
