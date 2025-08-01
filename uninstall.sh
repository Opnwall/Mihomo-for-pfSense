#!/bin/bash

echo -e ''
echo -e "\033[32m========Clash for pfSense 代理全家桶一键卸载脚本=========\033[0m"
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

# 删除程序和配置
log "$YELLOW" "删除程序和配置，请稍等..."


# 停止服务
service clash stop > /dev/null 2>&1
service mosdns stop > /dev/null 2>&1

# 删除配置
rm -rf /usr/local/etc/clash
rm -rf /usr/local/etc/mosdns

# 删除rc.d
rm -f /usr/local/etc/rc.d/clash
rm -f /usr/local/etc/rc.d/mosdns

# 删除rc.conf
rm -f /etc/rc.conf.d/clash
rm -f /etc/rc.conf.d/mosdns

# 删除菜单
rm -f /usr/local/share/pfSense/menu/pfSense-Services_Proxy.xml

# 删除php
rm -f /usr/local/www/services_clash.php
rm -f /usr/local/www/services_mosdns.php
rm -f /usr/local/www/status_clash_logs.php
rm -f /usr/local/www/status_clash.php
rm -f /usr/local/www/status_mosdns_logs.php
rm -f /usr/local/www/status_mosdns.php
rm -f /usr/local/www/sub.php
rm -f /usr/bin/sub

# 删除程序
rm -f /usr/local/bin/clash
rm -f /usr/local/bin/mosdns
echo ""

# 完成提示
log "$GREEN" "卸载完成，请手动删除TUN接口和防火墙规则，任务列表的自动更新项，删除shellcmd中的启动项，并将DNS解析器端口更改为53。"
log "$GREEN" "所有配置修改完成以后，运行/etc/rc.reload_all或重启防火墙，让新配置生效。"
echo ""
