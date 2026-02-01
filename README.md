## Clash for pfSense
Clash安装工具，在pfSense上运行Clash、Mosdns，实现透明代理。支持Clash订阅、DNS分流。带Web控制界面，可以进行配置修改、程序控制、日志查看。在pfSense CE 2.8.0和pfSense plus 25.03(beta)上测试通过。

![](images/proxy.png)

## 集成程序

[MosDNS](https://github.com/IrineSistiana/mosdns) 

[Vincent-Loeng大佬魔改Mihomo](https://github.com/Vincent-Loeng/mihomo) 

## 注意事项
1. 当前仅支持x86_64 平台。
2. 脚本不提供任何订阅信息，请准备好自己的Clash订阅URL。
3. 脚本已集成了可用的默认配置，只需替换clash的proxies和rule部分配置即可使用。
4. 为减少长期运行保存的日志数量，在调试完成后，请将所有配置的日志类型修改为error或warn。

## 安装命令

```bash
sh install.sh
```
![](images/install.png)

## 卸载命令

```bash
sh uninstall.sh
```

## 配置教程
1. 安装完成，导航到VPN>Proxy Suite 菜单，修改clash（ proxies和rules部分) 内容并保存。
2. 启动clash服务，转到接口>分配，将tun_3000虚拟网卡添加为接口并启用，不用输入IPv4地址和网关。
3. 为避免端口冲突，将DNS解析器端口修改为5355，并作为mosdns的默认上游DNS。
5. 转到防火墙>规则策略，在tun接口添加一条any to any防火墙规则，允许tun子网访问。
6. 转到服务>shellcmd，添加两条开机启动条目，命令"service clash start"、"service mosdns start"。
7. 设置完成，客户端访问 ip111.cn，检查分流是否正常。

## 其他事项
1. 默认配置文件开启了clash api功能，访问 http://lan_ip:9090/ui 登录仪表盘查看代理连接信息。
2. 自动更新订阅，在cron插件当中添加一条任务，命令为"/usr/bin/sub"。
