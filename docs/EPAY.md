# 易支付（支付宝）网关

适配器基于 [xkatld/FOSSBilling-Patch](https://github.com/xkatld/FOSSBilling-Patch)（请保留署名；勿转卖补丁），已按本仓库当前 FOSSBilling API 改写。

云电脑演示用的官方 Docker 镜像若仍是旧 RedBean API，可继续用社区原版 `Epay.php`；本仓库 `src/.../Epay.php` 面向 fork 源码树。

## 安装

1. 将本仓库中的适配器放到运行中的 FOSSBilling 安装目录：

   - 源码树（本仓库）：`src/library/Payment/Adapter/Epay.php`
   - 官方 Docker 镜像常见路径：`library/Payment/Adapter/Epay.php`（容器内多为 `/var/www/html/library/Payment/Adapter/Epay.php`）

2. 后台登录 → **Configuration** → **Payment gateways** → **New payment gateway**，选择 **Epay**（或「易支付-支付宝」）。

3. 填写并保存：
   - **易支付网关地址**（`apiurl`）：如 `https://pay.example.com`（不要末尾多余路径时按你的易支付文档）
   - **商户 ID**（`pid`）
   - **商户密钥**（`key`）

4. 启用该网关。

## 回调 / 公网要求

易支付会向站点发起 `notify_url` / `return_url` 回调。  
**仅 Tailscale 或内网地址时，支付平台通常无法回调**，账单不会自动标记已付。

请在 FOSSBilling **System settings** 把系统 URL 设为公网可访问地址，并保证该地址的 `/ipn`（或网关回调路径）可达。

## 支付方式说明

当前适配器提交参数 `type=alipay`（支付宝）。若你的易支付通道使用其他 `type`，需在适配器中按通道文档调整。

## 与演示环境对齐

云电脑上的演示实例若已拷贝 `Epay.php` 并在 `pay_gateway` 表有「易支付-支付宝」条目，仍需在后台填入真实商户 PID/密钥并启用后，才能完成真实收款测试。
