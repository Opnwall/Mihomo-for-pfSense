#!/bin/bash
set -u

if [ "$(id -u)" -ne 0 ]; then
    echo "请以 root 身份运行此安装脚本。"
    exit 1
fi

echo -e ''
echo -e "\033[32m====== Mihomo for pfSense 安装脚本 ======\033[0m"
echo -e ''

# 定义颜色变量
GREEN="\033[32m"
YELLOW="\033[33m"
RED="\033[31m"
CYAN="\033[36m"
RESET="\033[0m"

# 定义目录变量
ROOT="/usr/local"
BIN_DIR="$ROOT/bin"
WWW_DIR="$ROOT/www"
CONF_DIR="$ROOT/etc"
MENU_DIR="$ROOT/share/pfSense/menu/"
RC_DIR="$ROOT/etc/rc.d"
RC_CONF="/etc/rc.conf.d/"

# 定义日志函数
log() {
    local color="$1"
    local message="$2"
    echo -e "${color}${message}${RESET}"
}

wait_for_interface() {
    local ifname="$1"
    local timeout="${2:-20}"
    local i=0

    while [ "$i" -lt "$timeout" ]; do
        if ifconfig "$ifname" >/dev/null 2>&1; then
            log "$GREEN" "检测到接口 $ifname"
            return 0
        fi
        sleep 1
        i=$((i + 1))
    done

    log "$RED" "等待接口 $ifname 超时。"
    return 1
}

restore_config_backup() {
    local backup_file="$1"
    local config_file="/cf/conf/config.xml"

    if [ -n "$backup_file" ] && [ -f "$backup_file" ]; then
        cp -pf "$backup_file" "$config_file" && \
        log "$YELLOW" "已从备份恢复 config.xml: $backup_file"
    fi
}

require_success() {
    local status="$1"
    local message="$2"
    if [ "$status" -ne 0 ]; then
        log "$RED" "$message"
        exit 1
    fi
}

# 创建目录
mkdir -p "$CONF_DIR/mihomo" "$CONF_DIR/mosdns"
require_success $? "目录创建失败！"

# 复制文件
log "$YELLOW" "复制文件..."
log "$YELLOW" "生成菜单..."
log "$YELLOW" "添加权限..."
chmod +x ./bin/* ./rc.d/*
require_success $? "设置执行权限失败！"
cp -f bin/* "$BIN_DIR/"
require_success $? "bin 文件复制失败！"
cp -f www/* "$WWW_DIR/"
require_success $? "www 文件复制失败！"
cp -f rc.d/* "$RC_DIR/"
require_success $? "rc.d 文件复制失败！"
cp -f menu/* "$MENU_DIR/"
require_success $? "menu 文件复制失败！"
cp -f rc.conf/* "$RC_CONF/"
require_success $? "rc.conf 文件复制失败！"
cp -R -f conf/* "$CONF_DIR/mihomo/"
require_success $? "conf 文件复制失败！"
cp -R -f mosdns/* "$CONF_DIR/mosdns/"
require_success $? "mosdns 文件复制失败！"

# 安装bash
sleep 1
log "$YELLOW" "安装bash..."
if ! pkg info -q bash > /dev/null 2>&1; then
  pkg install -y bash > /dev/null 2>&1
  require_success $? "bash 安装失败！"
fi

# 安装cron
log "$YELLOW" "安装cron..."
if ! pkg info -q pfSense-pkg-Cron > /dev/null 2>&1; then
  pkg install -y pfSense-pkg-Cron > /dev/null 2>&1
  require_success $? "cron 安装失败！"
fi

# 安装shellcmd
log "$YELLOW" "安装shellcmd..."
if ! pkg info -q pfSense-pkg-Shellcmd > /dev/null 2>&1; then
  pkg install -y pfSense-pkg-Shellcmd > /dev/null 2>&1
  require_success $? "shellcmd 安装失败！"
fi

# 新建订阅程序
log "$YELLOW" "添加订阅程序..."
cat>/usr/bin/mihomo_sub<<EOF
# 启动mihomo订阅程序
bash /usr/local/etc/mihomo/sub/sub.sh
EOF
require_success $? "写入 mihomo_sub 失败！"
chmod +x /usr/bin/mihomo_sub
require_success $? "设置 mihomo_sub 执行权限失败！"


# 启动服务
log "$YELLOW" "启动 mosdns..."
service mosdns restart > /dev/null 2>&1 || service mosdns restart > /dev/null 2>&1
require_success $? "启动 mosdns 失败！"
log "$YELLOW" "启动 mihomo..."
service mihomo restart > /dev/null 2>&1 || service mihomo restart > /dev/null 2>&1
require_success $? "启动 mihomo 失败！"
log "$YELLOW" "等待 tun 接口出现..."
wait_for_interface tun_3000 20 || log "$RED" "tun 接口未出现，将继续尝试写入配置。"


# 备份并补丁 pfSense 配置
backup_and_patch_config() {
    local config_file="/cf/conf/config.xml"
    local backup_file="/cf/conf/config.xml.bak.$(date +%Y%m%d%H%M%S)"

    if [ ! -f "$config_file" ]; then
        log "$RED" "未找到 $config_file，跳过配置修改。"
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
$tmpFile = '/tmp/config.xml.mihomo.tmp';
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

$xpath = new DOMXPath($dom);
$changed = false;

$root = $dom->documentElement;
if (!$root || $root->nodeName !== 'pfsense') {
    fwrite(STDERR, "config.xml 根节点异常\n");
    exit(1);
}

function ensureChildElement(DOMDocument $dom, DOMElement $parent, string $name): DOMElement {
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === $name) {
            return $child;
        }
    }
    $node = $dom->createElement($name);
    $parent->appendChild($node);
    return $node;
}

function childTextValues(DOMElement $parent, string $name): array {
    $values = [];
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === $name) {
            $values[] = trim($child->textContent);
        }
    }
    return $values;
}

$systemNode = ensureChildElement($dom, $root, 'system');
if (!($xpath->query('/pfsense/system')->length > 0)) {
    $changed = true;
}

$shellcmdValues = childTextValues($systemNode, 'shellcmd');
foreach (['service mihomo start', 'service mosdns start'] as $cmd) {
    if (!in_array($cmd, $shellcmdValues, true)) {
        $systemNode->appendChild($dom->createElement('shellcmd', $cmd));
        $changed = true;
    }
}

$afterNodes = $systemNode->getElementsByTagName('afterfilterchangeshellcmd');
if ($afterNodes->length === 0) {
    $systemNode->appendChild($dom->createElement('afterfilterchangeshellcmd'));
    $changed = true;
}

$unboundNode = ensureChildElement($dom, $root, 'unbound');
$portNode = null;
foreach ($unboundNode->childNodes as $child) {
    if ($child instanceof DOMElement && $child->tagName === 'port') {
        $portNode = $child;
        break;
    }
}
if ($portNode === null) {
    $portNode = $dom->createElement('port', '5355');
    $unboundNode->appendChild($portNode);
    $changed = true;
} elseif (trim($portNode->textContent) !== '5355') {
    $portNode->nodeValue = '5355';
    $changed = true;
}

$interfacesNode = ensureChildElement($dom, $root, 'interfaces');
$opt10Node = null;
foreach ($interfacesNode->childNodes as $child) {
    if ($child instanceof DOMElement && $child->tagName === 'opt10') {
        $opt10Node = $child;
        break;
    }
}
if ($opt10Node === null) {
    $opt10Node = $dom->createElement('opt10');
    $interfacesNode->appendChild($opt10Node);
    $changed = true;
}
foreach (['descr' => 'TUN', 'if' => 'tun_3000', 'enable' => '', 'spoofmac' => ''] as $name => $value) {
    $found = null;
    foreach ($opt10Node->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === $name) {
            $found = $child;
            break;
        }
    }
    if ($found === null) {
        $opt10Node->appendChild($dom->createElement($name, $value));
        $changed = true;
    } elseif (in_array($name, ['descr', 'if'], true) && trim($found->textContent) !== $value) {
        $found->nodeValue = $value;
        $changed = true;
    }
}

$filterNode = ensureChildElement($dom, $root, 'filter');
$ruleExists = false;
foreach ($filterNode->childNodes as $child) {
    if (!($child instanceof DOMElement) || $child->tagName !== 'rule') {
        continue;
    }
    $tracker = '';
    $iface = '';
    $type = '';
    foreach ($child->childNodes as $ruleChild) {
        if (!($ruleChild instanceof DOMElement)) {
            continue;
        }
        if ($ruleChild->tagName === 'tracker') {
            $tracker = trim($ruleChild->textContent);
        } elseif ($ruleChild->tagName === 'interface') {
            $iface = trim($ruleChild->textContent);
        } elseif ($ruleChild->tagName === 'type') {
            $type = trim($ruleChild->textContent);
        }
    }
    if ($tracker === '1111111111' && $iface === 'opt10' && $type === 'pass') {
        $ruleExists = true;
        break;
    }
}
if (!$ruleExists) {
    $rule = $dom->createElement('rule');
    foreach ([
        'id' => '',
        'tracker' => '1111111111',
        'type' => 'pass',
        'interface' => 'opt10',
        'ipprotocol' => 'inet',
        'tag' => '',
        'tagged' => '',
        'max' => '',
        'max-src-nodes' => '',
        'max-src-conn' => '',
        'max-src-states' => '',
        'statetimeout' => '',
        'statepolicy' => '',
        'statetype' => 'keep state',
        'os' => '',
        'descr' => ''
    ] as $name => $value) {
        $rule->appendChild($dom->createElement($name, $value));
    }
    $source = $dom->createElement('source');
    $source->appendChild($dom->createElement('network', 'opt10'));
    $destination = $dom->createElement('destination');
    $destination->appendChild($dom->createElement('network', 'opt10'));
    $rule->appendChild($source);
    $rule->appendChild($destination);
    $filterNode->appendChild($rule);
    $changed = true;
}

$installedPackagesNode = ensureChildElement($dom, $root, 'installedpackages');
$shellcmdSettingsNode = ensureChildElement($dom, $installedPackagesNode, 'shellcmdsettings');
$existingCmds = [];
foreach ($shellcmdSettingsNode->childNodes as $child) {
    if (!($child instanceof DOMElement) || $child->tagName !== 'config') {
        continue;
    }
    foreach ($child->childNodes as $cfgChild) {
        if ($cfgChild instanceof DOMElement && $cfgChild->tagName === 'cmd') {
            $existingCmds[] = trim($cfgChild->textContent);
            break;
        }
    }
}
foreach (['service mosdns start', 'service mihomo start'] as $cmd) {
    if (!in_array($cmd, $existingCmds, true)) {
        $configNode = $dom->createElement('config');
        $configNode->appendChild($dom->createElement('cmd', $cmd));
        $configNode->appendChild($dom->createElement('cmdtype', 'shellcmd'));
        $configNode->appendChild($dom->createElement('description'));
        $shellcmdSettingsNode->appendChild($configNode);
        $changed = true;
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
clearstatcache(true, $configFile);

if ($changed) {
    echo "配置已更新\n";
} else {
    echo "配置无需修改\n";
}
PHP

    if [ $? -ne 0 ]; then
        log "$RED" "修改配置失败！"
        restore_config_backup "$backup_file"
        return 1
    fi

    log "$GREEN" "配置修改完成。"
    log "$YELLOW" "重载防火墙规则..."
    /etc/rc.filter_configure > /dev/null 2>&1 || log "$RED" "重载防火墙规则失败，请手动执行 /etc/rc.filter_configure。"

    if ifconfig tun_3000 >/dev/null 2>&1; then
        log "$YELLOW" "刷新 tun 接口状态..."
        /etc/rc.linkup start tun_3000 > /dev/null 2>&1 || true
    fi

    log "$YELLOW" "重启 DNS 解析器..."
    service unbound onerestart > /dev/null 2>&1 || log "$RED" "重启 unbound 失败，请手动执行 service unbound onerestart。"

    log "$GREEN" "新配置已重新加载。"
    return 0
}

# 备份并修改 pfSense 配置
log "$YELLOW" "备份并修改 config.xml..."
backup_and_patch_config
require_success $? "修改配置失败，请手动检查。"
echo ""

# 完成提示
sleep 1
log "$GREEN" "Mihomo 安装完毕，请刷新浏览器，导航到 VPN > Mihomo Proxy 修改配置。"
log "$GREEN" "当前脚本已自动重载关键配置；如个别接口或规则仍未完全接管，请手动重载相关服务或重启防火墙。"
echo ""