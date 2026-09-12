# FOSSBilling-vps 二次开发说明

本仓库为 [FOSSBilling/FOSSBilling](https://github.com/FOSSBilling/FOSSBilling) 的 fork，用于 VPS 销售面板二次开发。

## 已接入

- **易支付（支付宝）**：见 [docs/EPAY.md](docs/EPAY.md)
  - 适配器路径：`src/library/Payment/Adapter/Epay.php`
  - 来源与署名：[xkatld/FOSSBilling-Patch](https://github.com/xkatld/FOSSBilling-Patch)

## 演示部署（云电脑 Docker）

运行实例使用官方镜像 `fossbilling/fossbilling`，适配器装在容器内：

`/var/www/html/library/Payment/Adapter/Epay.php`

与源码树的 `src/library/...` 对应；镜像构建/发布后路径会落到 `library/`。

**勿将** `.env`、数据库密码、商户密钥、API token 提交到本仓库。
