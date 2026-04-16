#!/bin/bash
set -u

if [ "$(id -u)" -ne 0 ]; then
    echo "请以 root 身份运行此卸载脚本。"
    exit 1
fi

echo -e ''
echo -e "\033[32m========Mihomo for pfSense 卸载脚本=========\033[0m"
echo -e ''

# 定义颜色变量
GREEN="\033[32m"
YELLOW="\033[33m"
RED="\033[31m"
RESET="\033[0m"

# 定义日志函数
log() {
    local color="$1"
    local message="$2"
    echo -e "${color}${message}${RESET}"
}

restore_config_backup() {
    local backup_file="$1"
    local config_file="/cf/conf/config.xml"

    if [ -n "$backup_file" ] && [ -f "$backup_file" ]; then
        cp -pf "$backup_file" "$config_file" && \
        log "$YELLOW" "已从备份恢复 config.xml: $backup_file"
    fi
}

cleanup_config_xml() {
    local config_file="/cf/conf/config.xml"
    local backup_file="/cf/conf/config.xml.uninstall.bak.$(date +%Y%m%d%H%M%S)"

    if [ ! -f "$config_file" ]; then
        log "$RED" "未找到 $config_file，跳过配置清理。"
        return 1
    fi

    cp -pf "$config_file" "$backup_file" || {
        log "$RED" "备份 config.xml 失败！"
        return 1
    }
    log "$GREEN" "已备份 config.xml 到: $backup_file"

    php <<'PHP'
<?php
$configFile = '/cf/conf/config.xml';
$tmpFile = '/tmp/config.xml.mihomo.uninstall.tmp';
libxml_use_internal_errors(true);

$dom = new DOMDocument('1.0');
$dom->preserveWhiteSpace = false;
$dom->formatOutput = true;
if (!$dom->load($configFile)) {
    fwrite(STDERR, "加载 config.xml 失败\n");
    foreach (libxml_get_errors() as $error) {
        fwrite(STDERR, trim($error->message) . "\n");
    }
    exit(1);
}

$root = $dom->documentElement;
if (!$root || $root->nodeName !== 'pfsense') {
    fwrite(STDERR, "config.xml 根节点异常\n");
    exit(1);
}

function findDirectChild(DOMElement $parent, string $name): ?DOMElement {
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === $name) {
            return $child;
        }
    }
    return null;
}

function removeDirectChildrenByValue(DOMElement $parent, string $tagName, array $values): bool {
    $changed = false;
    $toRemove = [];
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === $tagName) {
            $text = preg_replace('/\s+/', ' ', trim($child->textContent));
            foreach ($values as $value) {
                $target = preg_replace('/\s+/', ' ', trim($value));
                if ($text === $target) {
                    $toRemove[] = $child;
                    break;
                }
            }
        }
    }
    foreach ($toRemove as $node) {
        $parent->removeChild($node);
        $changed = true;
    }
    return $changed;
}

$changed = false;

$systemNode = findDirectChild($root, 'system');
if ($systemNode !== null) {
    if (removeDirectChildrenByValue($systemNode, 'shellcmd', [
        'service mihomo start',
        'service mosdns start',
    ])) {
        $changed = true;
    }

    $afterFilterNode = findDirectChild($systemNode, 'afterfilterchangeshellcmd');
    if ($afterFilterNode !== null) {
        $systemNode->removeChild($afterFilterNode);
        $changed = true;
    }
}

$interfacesNode = findDirectChild($root, 'interfaces');
if ($interfacesNode !== null) {
    $opt10Node = findDirectChild($interfacesNode, 'opt10');
    if ($opt10Node !== null) {
        $ifNode = findDirectChild($opt10Node, 'if');
        if ($ifNode !== null && trim($ifNode->textContent) === 'tun_3000') {
            $interfacesNode->removeChild($opt10Node);
            $changed = true;
        }
    }
}

$filterNode = findDirectChild($root, 'filter');
if ($filterNode !== null) {
    $toRemove = [];
    foreach ($filterNode->childNodes as $rule) {
        if (!($rule instanceof DOMElement) || $rule->tagName !== 'rule') {
            continue;
        }
        $tracker = '';
        $iface = '';
        $type = '';
        foreach ($rule->childNodes as $child) {
            if (!($child instanceof DOMElement)) {
                continue;
            }
            if ($child->tagName === 'tracker') {
                $tracker = trim($child->textContent);
            } elseif ($child->tagName === 'interface') {
                $iface = trim($child->textContent);
            } elseif ($child->tagName === 'type') {
                $type = trim($child->textContent);
            }
        }
        if ($tracker === '1111111111' && $iface === 'opt10' && $type === 'pass') {
            $toRemove[] = $rule;
        }
    }
    foreach ($toRemove as $rule) {
        $filterNode->removeChild($rule);
        $changed = true;
    }
}

$unboundNode = findDirectChild($root, 'unbound');
if ($unboundNode !== null) {
    $portNode = findDirectChild($unboundNode, 'port');
    if ($portNode !== null && trim($portNode->textContent) === '5355') {
        $portNode->nodeValue = '53';
        $changed = true;
    }
}

$installedPackagesNode = findDirectChild($root, 'installedpackages');
if ($installedPackagesNode !== null) {
    $shellcmdSettingsNode = findDirectChild($installedPackagesNode, 'shellcmdsettings');
    if ($shellcmdSettingsNode !== null) {
        $configsToRemove = [];
        foreach ($shellcmdSettingsNode->childNodes as $configNode) {
            if (!($configNode instanceof DOMElement) || $configNode->tagName !== 'config') {
                continue;
            }
            $cmdNode = findDirectChild($configNode, 'cmd');
            if ($cmdNode !== null) {
                $cmdText = preg_replace('/\s+/', ' ', trim($cmdNode->textContent));
                if (in_array($cmdText, [
                    'service mosdns start',
                    'service mihomo start',
                ], true)) {
                    $configsToRemove[] = $configNode;
                }
            }
        }
        foreach ($configsToRemove as $configNode) {
            $shellcmdSettingsNode->removeChild($configNode);
            $changed = true;
        }

        $hasConfig = false;
        foreach ($shellcmdSettingsNode->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'config') {
                $hasConfig = true;
                break;
            }
        }
        if (!$hasConfig) {
            $installedPackagesNode->removeChild($shellcmdSettingsNode);
            $changed = true;
        }
    }
}

$xmlString = $dom->saveXML();
if ($xmlString === false || trim($xmlString) === '') {
    fwrite(STDERR, "生成 config.xml 内容失败\n");
    exit(1);
}

if (file_put_contents($tmpFile, $xmlString, LOCK_EX) === false) {
    fwrite(STDERR, "写入临时 config.xml 失败\n");
    exit(1);
}

$verify = new DOMDocument('1.0');
libxml_clear_errors();
if (!$verify->load($tmpFile)) {
    fwrite(STDERR, "校验临时 config.xml 失败\n");
    foreach (libxml_get_errors() as $error) {
        fwrite(STDERR, trim($error->message) . "\n");
    }
    @unlink($tmpFile);
    exit(1);
}

$originalSize = @filesize($configFile);
$tmpSize = @filesize($tmpFile);
if ($originalSize !== false && $originalSize > 0 && $tmpSize !== false) {
    if ($tmpSize < (int)($originalSize * 0.7)) {
        fwrite(STDERR, "临时 config.xml 大小异常，已停止，请检查备份文件\n");
        @unlink($tmpFile);
        exit(1);
    }
}

if (!rename($tmpFile, $configFile)) {
    fwrite(STDERR, "替换 config.xml 失败\n");
    @unlink($tmpFile);
    exit(1);
}

if ($changed) {
    echo "config.xml 已清理\n";
} else {
    echo "config.xml 无需清理\n";
}
PHP

    if [ $? -ne 0 ]; then
        log "$RED" "清理 config.xml 失败！"
        restore_config_backup "$backup_file"
        return 1
    fi

    log "$GREEN" "config.xml 清理完成。"
    log "$YELLOW" "重载防火墙规则..."
    /etc/rc.filter_configure > /dev/null 2>&1 || log "$RED" "重载防火墙规则失败，请手动执行 /etc/rc.filter_configure。"

    log "$YELLOW" "重启 DNS Resolver..."
    service unbound onerestart > /dev/null 2>&1 || log "$RED" "重启 unbound 失败，请手动执行 service unbound onerestart。"

    log "$GREEN" "pfSense 配置已重新载入。"
    return 0
}

# 删除程序和配置
log "$YELLOW" "删除程序和配置，请稍等..."

# 停止服务
service mihomo stop > /dev/null 2>&1 || true
service mosdns stop > /dev/null 2>&1 || true

# 删除配置
rm -rf /usr/local/etc/mihomo
rm -rf /usr/local/etc/mosdns

# 删除rc.d
rm -f /usr/local/etc/rc.d/mihomo
rm -f /usr/local/etc/rc.d/mosdns

# 删除rc.conf
rm -f /etc/rc.conf.d/mihomo
rm -f /etc/rc.conf.d/mosdns

# 删除菜单
rm -f /usr/local/share/pfSense/menu/pfSense-VPN_Proxy.xml

# 删除php
rm -f /usr/local/www/vpn_mihomo.php
rm -f /usr/local/www/vpn_mosdns.php
rm -f /usr/local/www/vpn_sub.php
rm -f /usr/local/www/status_mihomo_logs.php
rm -f /usr/local/www/status_mosdns_logs.php
rm -f /usr/local/www/status_sub_logs.php
rm -f /usr/local/www/status_mihomo.php
rm -f /usr/local/www/status_mosdns.php
rm -f /usr/bin/mihomo_sub

# 清理 config.xml 中的安装项
log "$YELLOW" "清理 config.xml 中的相关配置..."
cleanup_config_xml || log "$RED" "config.xml 清理失败，请手动检查。"

# 删除程序
rm -f /usr/local/bin/mihomo
rm -f /usr/local/bin/mosdns
echo ""

# 完成提示
log "$GREEN" "卸载完成，脚本已尝试删除程序文件、启动项、TUN 接口配置、防火墙规则和 Shellcmd 配置。"
log "$GREEN" "如仍有残留，请在 pfSense Web 界面中检查接口分配、规则列表和任务列表。"
echo ""