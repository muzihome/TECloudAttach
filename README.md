# TECloudAttach — 腾讯云 COS 对象存储插件 for Typecho

> 一款将图片、附件等静态资源存储到腾讯云 COS 的 Typecho 博客插件，可降低本地存储负载，配合 CDN 加速提升访问体验。支持上传目录自定义、图片自动转 WebP/AVIF（多引擎回退）、本地路径同步、运行状态健康检查等实用功能。

[![Typecho](https://img.shields.io/badge/Typecho-1.3.0%2B-blue)](https://typecho.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple)](https://www.php.net)
[![License](https://img.shields.io/badge/license-GPL--2.0-green)](#许可证)
[![Version](https://img.shields.io/badge/version-1.4.0-orange)](./CHANGELOG.md)

---

## 目录

- [功能特性](#功能特性)
- [环境要求](#环境要求)
- [部署流程](#部署流程)
- [配置说明](#配置说明)
- [上传目录结构](#上传目录结构)
- [WebP 自动转换](#webp-自动转换)
- [AVIF 自动转换](#avif-自动转换)
- [运行状态 / 健康检查](#运行状态--健康检查)
- [自定义域名与 CDN](#自定义域名与-cdn)
- [子账号权限配置](#子账号权限配置推荐)
- [代码架构](#代码架构)
- [常见问题](#常见问题)
- [贡献指南](#贡献指南)
- [更新日志](./CHANGELOG.md)
- [许可证](#许可证)

---

## 功能特性

### 核心功能

- **文件上传**：将图片、附件上传至腾讯云 COS 存储桶，自动设置公有读权限与长缓存头（`Cache-Control: max-age=31536000, immutable`）
- **文件修改**：后台替换已上传附件时，COS 存储桶内对应文件同步覆盖更新
- **文件删除**：删除附件时可选择是否同步删除 COS 上的文件（含 WebP/AVIF 衍生文件，内置重试机制）
- **自动回退**：COS 未配置或连接失败时，自动回退到 Typecho 原生本地存储，后台文件管理不中断
- **网络重试**：COS 上传、删除、WebP 上传均内置 1 次自动重试（间隔 200ms），应对网络瞬时波动
- **连通性校验**：保存配置时自动校验存储桶是否存在及本地路径可写性，校验失败在后台以黄色警告条提示用户（不阻断保存），同时写入错误日志

### 存储与路径

- **COS 根目录支持**：对象存储路径留空即使用 COS 存储桶根目录，文件按上传目录结构分子目录存放
- **本地备份**：可选择在服务器本地保留一份文件副本
- **本地存储路径可配置**：可自定义本地备份与回退上传的根目录（默认 `usr/uploads`，更安全）
- **目录结构自定义**：通过 `{year}` / `{month}` / `{day}` / `{type}` / `{ext}` 变量自由组合存放路径
- **媒体类型自动分类**：images / videos / audios / documents / code / archives / fonts / other 八类

### WebP 自动转换

- **三引擎回退转换**：优先使用 GD 库 `imagewebp()`；GD 不支持的格式（如 TIFF）自动回退到 Imagick 扩展（不依赖 exec）；两者均不可用时回退到 `cwebp` 命令行工具（需 exec 可用）
- **智能链接返回**：转换并上传成功后，附件记录直接存储 WebP 路径，编辑器自动返回 WebP 格式链接；转换失败则存储原格式路径，确保图片可访问
- **三端同步**：上传、修改、删除均同步处理 WebP 衍生文件（删除时不依赖 WebP 功能开关，避免残留）
- **安全保护**：超过 5000 万像素的超大图片自动跳过（避免内存溢出），GIF 动图自动跳过（避免丢失动画）

### AVIF 自动转换

- **三级回退转换**：优先使用 GD 库 `imageavif()`（PHP 8.1+ 编译 `--with-avif`）；GD 支持编码但不支持源格式解码（如 TIFF）时采用**混合解码模式**（Imagick 解码为临时 PNG 再交 GD 编码，无需 libheif）；GD 不可用时回退到 Imagick 扩展（需编译 libheif）；AVIF 无命令行转换工具，不依赖 `exec()`
- **浏览器兼容性回退（组合方案）**：服务器端通过 HTTP `Accept` 头检测浏览器是否支持 AVIF，不支持时自动回退到 WebP；前端内联 JS 在 WebP 加载失败时进一步回退到原图，确保老旧浏览器也能正常显示
- **智能链接返回**：转换并上传成功后，附件记录直接存储 AVIF 路径，编辑器自动返回 AVIF 格式链接；转换失败则存储 WebP 或原格式路径，确保图片可访问
- **三端同步**：上传、修改、删除均同步处理 AVIF 衍生文件（含原图↔AVIF↔WebP 双向清理）
- **格式支持**：支持 jpg/jpeg/png/bmp/webp（GD 库转换）、tif/tiff（混合解码模式或 Imagick libheif，GD 不支持 TIFF 解码）；建议不要包含 gif（动图转换会丢失动画）、avif（已是目标格式）

### 运行状态 / 健康检查

- **运行环境展示**：插件版本、PHP 版本、COS SDK 版本、phar/curl/GD 扩展状态
- **图片处理检测**：GD 版本、WebP 转换支持及引擎、AVIF 转换支持及 `imageavif()`/`imagecreatefromavif()` 函数、cwebp 工具安装状态/版本/路径、`exec()` 函数可用性
- **COS 配置状态**：存储桶、地域、对象存储路径、上传目录结构、同步本地路径、本地备份、删除同步 COS、WebP 自动转换
- **最近一次上传结果**：上传时间、成功/失败状态、消息、附加详情（模式、文件名、路径、大小、WebP 转换状态、存储桶）
- **WebP 不支持诊断**：自动分析不支持原因并给出解决方案（GD 未编译 WebP / exec 被禁用 / cwebp 未安装）

### 域名与访问

- **三种域名模式**：默认 COS 域名、自定义源站域名、CDN 加速域名
- **永久无签名链接**：所有访问 URL 均为静态无签名链接，不会过期

### 界面体验

- **四 Tab 配置面板**：使用说明 → 基础配置 → 高级配置 → 运行状态，左侧导航切换，内容区 Grid 布局
- **下划线输入框**：去除顶部/左右边框及阴影，只保留底部 1px 边框，focus 时底部边框变蓝，简洁现代
- **必填校验交互**：SecretId/SecretKey/所属地域/存储桶名称未填全时，"保存设置"按钮灰色禁用，填全后恢复可点击
- **WebP 子项动态显隐**：转换质量、转换格式仅在勾选"上传图片自动转换 WebP"时显示，减少视觉干扰
- **宽度自适应 100%**：配置面板占满 Typecho 后台可用宽度，左侧固定导航 + 右侧弹性内容区
- **响应式适配**：768px 以下自动切换为单列布局，导航变为横向标签，适配移动端

### 安全与可维护性

- **SecretKey 密码框**：配置页面使用 Password 输入框，不明文显示密钥
- **日志脱敏**：错误日志自动屏蔽 SecretId、SecretKey、COS 签名参数等敏感信息
- **文件名防护**：清洗危险字符，`basename()` 防路径穿越，点文件（`.htaccess`）视为无扩展名
- **必填校验**：基础配置中 SecretId/SecretKey/所属地域/存储桶名称为必填项，未填全时"保存设置"按钮灰色禁用
- **零侵入**：通过 Typecho 1.3.0 原生插件钩子实现，不修改任何核心文件

---

## 环境要求

| 项目 | 要求 |
|------|------|
| Typecho | 1.3.0 及以上 |
| PHP | 8.0 及以上（推荐 8.2+） |
| PHP 扩展 | phar（加载 COS SDK 必需）、curl、GD（图片处理） |
| WebP 转换 | GD 编译 WebP 支持，或安装 `webp` 包（提供 `cwebp` 命令）且 PHP `exec()` 可用 |
| AVIF 转换 | PHP 8.1+ GD 编译 `--with-avif`，或 Imagick 扩展编译 libheif |
| 腾讯云 | 已开通 COS 服务，拥有存储桶 |

---

## 部署流程

### 第一步：创建 COS 存储桶

1. 登录 [腾讯云 COS 控制台](https://console.cloud.tencent.com/cos/bucket)
2. 点击「创建存储桶」
3. 填写名称（格式为 `BucketName-AppId`，如 `typecho-1300000000`）
4. 选择**所属地域**（建议与服务器同地域，减少回源延迟）
5. **访问权限**选择「公有读私有写」
6. 点击创建

> 公有读私有写：任何人可读取文件，但只有授权账号可写入/删除。博客图片属于公开内容，此权限最合适。

### 第二步：获取 API 密钥

1. 进入 [CAM - 访问管理](https://console.cloud.tencent.com/cam/user)
2. 推荐创建**子账号**（安全），关联策略 `QcloudCOSDataFullControl`，或参考[下文](#子账号权限配置推荐)配置最小权限
3. 创建后获取 `SecretId` 和 `SecretKey`（SecretKey 仅显示一次，请妥善保存）

### 第三步：安装插件

1. 将 `TECloudAttach/` 文件夹上传至 Typecho 站点的 `/usr/plugins/` 目录
2. 确保目录结构为 `/usr/plugins/TECloudAttach/Plugin.php`
3. 登录 Typecho 后台 → 「控制台」→「插件」
4. 找到「腾讯云对象存储（COS）插件」，点击「启用」

### 第四步：配置插件

1. 启用后点击「设置」进入配置面板
2. 配置面板包含四个 Tab：**使用说明** → **基础配置** → **高级配置** → **运行状态**
3. 在「基础配置」中填写 SecretId、SecretKey、所属地域、存储桶名称（四项为必填，未填全时保存按钮禁用）
4. （可选）调整对象存储路径（留空使用 COS 根目录）、上传目录结构、本地存储路径（默认 `usr/uploads`）
5. （可选）在「高级配置」中配置访问域名、请求超时、WebP 转换、AVIF 转换、本地备份等
6. 点击保存，插件会自动校验存储桶连通性和本地路径可写性
7. 切换到「运行状态」Tab 查看健康检查结果，确认环境和配置正常

### 第五步：验证

1. 写一篇文章或在文件管理上传一张图片
2. 确认 COS 控制台对应路径下出现该文件
3. 确认前台图片正常显示
4. 如开启了 WebP/AVIF，确认 COS 同目录下多了对应格式文件，且编辑器插入的链接为对应格式
5. 在「运行状态」Tab 确认"最近一次上传结果"显示成功

---

## 配置说明

### 基础配置

| 配置项 | 必填 | 默认值 | 说明 |
|--------|:----:|--------|------|
| SecretId | 是 | 无 | 腾讯云 API 密钥 ID |
| SecretKey | 是 | 无 | 腾讯云 API 密钥 Key（密码框输入，页面不明文显示） |
| 所属地域 | 是 | `ap-guangzhou` | COS 存储桶所在地域，如 `ap-guangzhou`、`ap-shanghai`、`ap-beijing` |
| 存储桶名称 | 是 | 无 | 格式为 `BucketName-AppId`，如 `typecho-1300000000` |
| 对象存储路径 | 否 | 空（根目录） | 文件在 COS 中的存储前缀，留空使用 COS 存储桶根目录；如需指定子目录请填写，如 `usr/uploads`（无需以 `/` 开头） |
| 本地存储路径 | 否 | `usr/uploads` | 本地备份与回退上传的根目录（相对 Typecho 根目录），留空使用默认 `usr/uploads` |
| 上传目录结构 | 否 | `{type}/{year}` | 文件子目录模板，详见[上传目录结构](#上传目录结构) |

### 高级配置

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| 访问域名 | 留空 | 留空使用默认 COS 域名；可填写自定义源站域名或 CDN 加速域名（仅填域名，不带协议前缀和末尾斜杠） |
| 请求超时(秒) | 5 | COS API 请求总超时时间，网络较差时可适当增大 |
| 上传图片自动转换 WebP | 动态默认 | 全新安装时根据环境自动检测：支持 WebP 转换则默认开启，否则关闭。已保存过配置的用户不受影响。开启后上传图片时自动转换为 WebP 并一同上传，详见[WebP 自动转换](#webp-自动转换) |
| WebP 转换质量 | 80 | 0-100，推荐 75-85，越大画质越好文件越大（仅在开启 WebP 转换时显示） |
| 需要转换为 WebP 的格式 | `jpg,jpeg,png,bmp,avif,tiff,tif` | 逗号分隔的扩展名列表（仅在开启 WebP 转换时显示）。`jpg/jpeg/png/bmp` 由 GD 库转换；`tif/tiff` 需 `php-imagick` 扩展（推荐，不依赖 exec）或 `cwebp` 命令行工具（需 PHP exec 可用），GD 库不支持 TIFF 解码；`avif` 为 AVIF 原图转换为 WebP，供不支持 AVIF 的浏览器回退。建议不要包含 `gif`（动图转换会丢失动画）、`svg`（矢量图无需转换）和 `webp`（已是目标格式），详见[格式支持与环境要求](#格式支持与环境要求) |
| 上传图片自动转换 AVIF | 动态默认 | 全新安装时根据环境自动检测：支持 AVIF 转换则默认开启，否则关闭。已保存过配置的用户不受影响。开启后上传图片时自动转换为 AVIF 并一同上传，详见[AVIF 自动转换](#avif-自动转换) |
| AVIF 转换质量 | 50 | 0-100，推荐 40-60，越大画质越好文件越大（仅在开启 AVIF 转换时显示） |
| 需要转换为 AVIF 的格式 | `jpg,jpeg,png,bmp,webp,tiff,tif` | 逗号分隔的扩展名列表（仅在开启 AVIF 转换时显示）。`jpg/jpeg/png/bmp` 由 GD 库转换；`webp` 为 WebP 原图转换为 AVIF（AVIF 压缩率更高）；`tif/tiff` 需 Imagick 扩展（GD 不支持 TIFF 解码，采用混合解码模式：Imagick 解码为临时 PNG 再交 GD 编码 AVIF，或 Imagick 编译 libheif 直接编码）。建议不要包含 `gif`（动图转换会丢失动画）、`svg`（矢量图无需转换）和 `avif`（已是目标格式） |
| 本地删除同步删除 COS 文件 | 关闭 | 在后台删除附件时，是否同步删除 COS 上的文件 |
| 在本地保存 | 关闭 | 上传文件时是否在服务器本地保留一份副本（占用磁盘，但可减少 COS 请求） |
| 删除时同步删除本地备份 | 关闭 | 删除附件时是否同步删除本地备份（独立于「在本地保存」开关，只要本地存在对应文件即会清理） |

> 「本地删除同步删除 COS 文件」「在本地保存」「删除时同步删除本地备份」三项位于同一配置组内。

---

## 上传目录结构

通过「上传目录结构」配置项，可自由定义文件在 COS 中的子目录路径。对象存储路径留空时，文件直接上传至 COS 根目录下按此结构分子目录。

### 支持的变量

| 变量 | 说明 | 示例值 |
|------|------|--------|
| `{year}` | 4 位年份 | `2026` |
| `{month}` | 2 位月份 | `08` |
| `{day}` | 2 位日期 | `29` |
| `{type}` | 媒体类型分类 | `images` / `videos` / `documents` / `code` 等 |
| `{ext}` | 文件扩展名（小写） | `jpg` / `mp4` / `pdf` / `js` |

### `{type}` 分类规则

| 分类 | 包含的扩展名 |
|------|-------------|
| **images** | jpg, jpeg, png, gif, bmp, webp, svg, ico, tiff, avif, heic, psd, ai |
| **videos** | mp4, avi, mov, wmv, flv, mkv, webm, m4v, mpg, 3gp, rmvb |
| **audios** | mp3, wav, ogg, flac, aac, m4a, wma, ape, opus, mid |
| **documents** | pdf, doc, docx, xls, xlsx, ppt, pptx, txt, md, csv, epub, rtf |
| **code** | js, css, html, php, json, xml, sql, py, java, c, cpp, ts, vue, go, rs |
| **archives** | zip, rar, 7z, tar, gz, bz2, xz |
| **fonts** | ttf, otf, woff, woff2, eot |
| **other** | 以上未覆盖的扩展名 |

### 配置示例

以下示例假设对象存储路径留空（使用 COS 根目录）：

| 填写内容 | 实际路径（COS） | 适用场景 |
|---------|----------------|---------|
| `{type}/{year}` | `images/2026/` | 默认，按类型+年分类 |
| `{year}/{month}` | `2026/08/` | 按年月 |
| `{year}` | `2026/` | 按年归档 |
| `{year}/{month}/{day}` | `2026/08/29/` | 按年月日 |
| `{type}/{year}/{month}` | `images/2026/08/` | 按类型+年月 |
| `{type}` | `images/` | 仅按类型分类 |
| `{ext}/{year}` | `jpg/2026/` | 按扩展名+年 |
| `{year}/{type}` | `2026/images/` | 按年+类型 |
| 留空 | 桶根目录/ | 全部平铺，不建子目录 |

如对象存储路径配置为 `usr/uploads`，则实际路径为 `usr/uploads/images/2026/xxx.jpg`。

> **注意**：修改目录结构仅影响**新上传**的文件，已上传的旧文件路径不变。

---

## WebP 自动转换

开启后，上传 jpg/png 等图片时，插件会自动将图片转换为 WebP 格式，然后**连同原图一起上传**到 COS。转换成功后，编辑器返回 WebP 链接。

### 格式支持与环境要求

| 格式 | 转换后端 | 环境要求 |
|------|----------|----------|
| `jpg / jpeg / png` | GD 库 | PHP GD 扩展 + libwebp（绝大多数环境默认支持） |
| `bmp` | GD 库 | PHP 7.2+ GD 扩展（内置 `imagecreatefrombmp`） |
| `tif / tiff` | Imagick 或 cwebp | **需额外安装**：① `php-imagick` 扩展 + ImageMagick 编译时带 libwebp（推荐，不依赖 exec）；或 ② `webp` 包提供 cwebp 命令 + PHP `exec()` 函数可用。GD 库不支持 TIFF 解码。 |
| `gif` | 不转换 | 动图转换会丢失动画，自动跳过 |
| `webp` | 直接复制 | 已是目标格式，不重复转换 |
| `avif` | Imagick | AVIF 原图转换为 WebP，供不支持 AVIF 的浏览器回退。需 `php-imagick` 扩展 + ImageMagick 编译 libheif（GD 不支持 AVIF 解码） |

转换优先级：**GD（jpg/jpeg/png/bmp）→ Imagick（tif/tiff/avif 及其他）→ cwebp（后备）**。三者均不可用时自动跳过转换，不影响原图上传。健康检查「运行状态」Tab 可查看当前环境的 GD / Imagick / cwebp 支持状态。

> `tif/tiff` 安装命令参考：Debian/Ubuntu 执行 `sudo apt install php-imagick`（推荐）或 `sudo apt install webp`；CentOS 执行 `sudo yum install php-pecl-imagick` 或 `sudo yum install libwebp-tools`。安装后重启 PHP-FPM / Web 服务器生效。

### 工作流程

```
上传 jpg/png 图片
    ↓
原图上传到 COS（路径不变，如 images/2026/abc.jpg）
    ↓
WebP 转换（GD 优先 → Imagick → cwebp 后备）
    ↓
WebP 上传到 COS（同目录同文件名，如 images/2026/abc.webp）
    ↓
转换+上传成功？→ 附件记录存储 .webp 路径，编辑器返回 .webp 链接
转换失败？→ 附件记录存储原格式路径，编辑器返回原格式链接（确保可访问）
    ↓
清理临时文件
```

**转换成功时附件记录直接存储 WebP 路径**（存入数据库 `text` 字段的 JSON 中），Typecho 从该路径生成访问 URL，自然就是 WebP 链接。转换失败时存储原格式路径，不影响图片访问。

### 三引擎说明

| 引擎 | 条件 | 优点 | 缺点 |
|------|------|------|------|
| **GD 库** | PHP 编译 GD 时含 `--with-webp` | 纯 PHP，无需 exec，支持 jpg/jpeg/png/bmp | 不支持 TIFF 解码；部分编译环境（如 oneinstack）默认不含 WebP |
| **Imagick 扩展** | `php-imagick` 扩展 + ImageMagick 编译 libwebp | 不依赖 exec，支持 TIFF 等 GD 不支持的格式 | 需安装 Imagick 扩展 |
| **cwebp 命令行** | 服务器安装 `webp` 包 + PHP `exec()` 可用 | 转换质量高，不依赖 PHP 编译选项 | 需要 exec() 函数，exec 被禁用时不可用 |

插件自动检测可用引擎，优先级 **GD → Imagick → cwebp**，三者都不可用时跳过转换只上传原图。可在「运行状态」Tab 查看当前使用的引擎和各引擎安装状态。

### 安装 cwebp（GD 不支持 WebP 时）

```bash
# Debian/Ubuntu
apt-get install -y webp

# 验证
cwebp -version
```

确保 PHP `exec()` 函数未被禁用（检查 `php.ini` 的 `disable_functions`）。安装后无需重启 PHP，健康检查页面会自动检测到 cwebp。

### 三端同步

| 操作 | 原图 | WebP |
|------|------|------|
| **上传** | 上传到 COS | 自动转换并上传到 COS，成功后返回 WebP 链接 |
| **修改** | 覆盖上传新原图 | 重新转换并覆盖旧 WebP |
| **删除** | 删除 COS 原图 | 同步删除对应的 WebP 文件（不依赖 WebP 功能开关，避免残留） |

### 环境要求与降级

- **GD 方式**：需 PHP GD 库 + `gd_info()['WebP Support'] = true`
- **cwebp 方式**：需 `webp` 包 + PHP `exec()` 可用
- **自动降级**：两种方式都不可用时，自动跳过转换，只上传原图，不报错
- **GIF 处理**：自动跳过（动图转 WebP 会丢失动画）
- **超大图片**：超过 5000 万像素（约 7000×7000）自动跳过，避免内存溢出
- **转换失败**：单张图片转换失败只记录日志，原图仍正常上传，编辑器返回原格式链接

### 前端使用 WebP

- 插件上传后编辑器直接插入 WebP 链接，无需额外配置
- 如使用 CDN，可开启「图片自适应」功能根据 `Accept: image/webp` 头自动返回 WebP，与本插件的本地转换二选一即可，不要重复转换

---

## AVIF 自动转换

v1.4.0 新增 AVIF 格式支持。AVIF 是基于 AV1 视频编码的新一代图片格式，同等画质下文件体积比 WebP 更小（约小 20-30%）。

### 格式支持与环境要求

| 格式 | GD 引擎 | Imagick 引擎 | 说明 |
|------|---------|-------------|------|
| jpg/jpeg | ✅ | ✅ | 两引擎均支持 |
| png | ✅ | ✅ | 含透明通道 |
| bmp | ✅（PHP 7.2+） | ✅ | GD 需 `imagecreatefrombmp()` |
| webp | ✅ | ✅ | WebP 原图转换为 AVIF（AVIF 压缩率更高），GD 需 `imagecreatefromwebp()` |
| tif/tiff | ❌（GD 无 TIFF 解码器） | ✅ | 采用**混合解码模式**：Imagick 解码为临时 PNG 再交 GD 编码 AVIF（无需 libheif）；或 Imagick 编译 libheif 直接编码 |
| gif | 自动跳过 | 自动跳过 | 动图转 AVIF 丢失动画 |
| avif | 自动跳过 | 自动跳过 | 已是目标格式 |

### 三级回退转换引擎

AVIF 转换采用三级回退机制，自动选择当前环境可用的最优方案：

1. **GD 原生编码**（优先）：`imageavif()` 可用且 GD 支持源格式解码（jpeg/png/bmp/webp）时，直接用 GD 解码 + 编码，纯 PHP 无额外依赖
2. **混合解码模式**（v1.4.0 新增）：GD 支持 AVIF 编码但不支持源格式解码（如 TIFF）且 Imagick 已加载时，用 Imagick 解码源文件为临时 PNG，再交 GD 编码为 AVIF。**解决了 GD 不支持 TIFF 解码、Imagick 未编译 libheif 环境下的能力错位问题**，无需额外安装 libheif
3. **Imagick 原生编码**（后备）：Imagick 扩展编译启用 libheif（`--with-heic`）时，直接用 Imagick 解码 + 编码

三级均不可用时自动跳过转换，只上传原图，不报错。AVIF 无命令行转换工具，不依赖 `exec()`。

### 环境要求

- **GD 引擎**：PHP 8.1+，编译 `--with-avif`（依赖 libavif），`imageavif()` 可用
- **混合解码模式**：GD 支持 `imageavif()` + `imagick` 扩展已加载（无需 Imagick 编译 libheif），适用于 TIFF 等 GD 不支持解码的格式
- **Imagick 引擎**：`imagick` 扩展，ImageMagick 编译启用 libheif（`--with-heic`）
- 两引擎都不可用时自动跳过转换，只上传原图，不报错
- AVIF 无命令行转换工具，不依赖 `exec()`

### 浏览器兼容性回退（组合方案）

AVIF 浏览器支持率约 80%（Chrome 85+、Firefox 93+、Safari 16+）。插件采用双层回退：

- **服务器端**：生成附件 URL 时检测 `Accept` 头，不支持 AVIF 时自动回退到 WebP 路径
- **前端**：页面底部注入内联 JS，WebP 加载失败时 `onerror` 进一步回退到原图
- CLI/cron/feed 等无 Accept 头场景默认返回 AVIF

### 与 WebP 的关系

- AVIF 和 WebP 可同时开启，上传时独立生成、独立上传
- 返回路径优先级：AVIF > WebP > 原图
- 浏览器不支持 AVIF 时回退 WebP，不支持 WebP 时回退原图

---

## 运行状态 / 健康检查

配置面板的「运行状态」Tab 提供实时健康检查，无需登录服务器即可排查问题。

### 检查项一览

| 分类 | 检查项 | 说明 |
|------|--------|------|
| **运行环境** | 插件版本 / PHP 版本 / COS SDK 版本 | 版本信息 |
| | phar 扩展 / curl 扩展 | 必需扩展加载状态 |
| **图片处理** | GD 扩展 / GD 版本 | GD 库状态 |
| | WebP 转换支持 / 转换引擎 | GD 或 cwebp |
| | AVIF 转换支持 / imageavif() / imagecreatefromavif() | AVIF 支持状态（PHP 8.1+） |
| | cwebp 工具 / 版本 / 路径 | 系统是否安装 cwebp |
| | exec() 函数 | 是否被禁用（cwebp 模式需要） |
| **COS 配置** | COS 配置 / 存储桶 / 地域 | 核心配置状态 |
| | 对象存储路径 / 上传目录结构 / 本地存储路径 | 路径配置 |
| | 本地备份 / 删除同步 COS / WebP 自动转换 | 功能开关状态 |
| **上传结果** | 最近一次上传结果 | 时间、成功/失败、消息、详情 |

### WebP 不支持诊断

当 WebP 转换不支持时，健康检查页面会自动分析原因并给出解决方案：

- **GD 已加载但未编译 WebP**：提示安装 `libwebp-dev` 后重新编译 PHP 加 `--with-webp`
- **GD 未加载**：提示安装 `php8.2-gd` 或重新编译 PHP 加 `--enable-gd`
- **exec() 被禁用**：提示在 php.ini 中移除 exec 的 disable_functions 限制
- **cwebp 未安装**：提示 `sudo apt install webp`

### 最近一次上传结果

每次上传（成功或失败）都会记录结果，包括：
- 上传时间
- 成功/失败状态
- 消息描述（如"COS 上传成功（含 WebP 转换）"、"COS 上传失败，已回退到本地上传"）
- 附加详情：上传模式（cos / local_fallback / cos_failed_local_fallback）、文件名、路径、大小、WebP 转换状态、存储桶

---

## 自定义域名与 CDN

插件支持三种域名模式，生成的访问 URL 均为**永久无签名链接**。

### 模式一：默认 COS 域名（最简单）

- 「访问域名」留空
- URL 形式：`https://{bucket}.cos.{region}.myqcloud.com/images/2026/xxx.jpg`
- 无需额外配置，开箱即用
- 缺点：无 CDN 加速，访问速度取决于用户到 COS 节点的距离

### 模式二：自定义源站域名（直达 COS）

1. COS 控制台 → 存储桶 → 域名与传输管理 → 自定义源站域名 → 添加域名
2. DNS 服务商给该域名添加 CNAME 记录，指向 COS 默认域名
3. 插件「访问域名」填写该域名
4. URL 形式：`https://cos.example.com/images/2026/xxx.jpg`

### 模式三：CDN 加速域名（推荐）

1. [CDN 控制台](https://console.cloud.tencent.com/cdn) → 域名管理 → 添加域名
2. 源站类型选「COS 源」，选择你的存储桶
3. DNS 给加速域名添加 CNAME，指向 CDN 分配的 CNAME 地址
4. 插件「访问域名」填写 CDN 加速域名
5. URL 形式：`https://cdn.example.com/images/2026/xxx.jpg`

#### 防盗链配置（推荐）

在 CDN 控制台 → 域名管理 → 访问控制 → **Referer 防盗链**：

- 类型选「白名单」
- 填写你的博客域名，如 `example.com`、`www.example.com`
- 「允许空 Referer」建议关闭

> **不要开启 CDN 时间戳 URL 鉴权**。博客图片是公开内容，文章中存储的是静态 URL，时间戳鉴权会导致链接过期后图片 403。Referer 白名单已能满足绝大多数博客的防盗链需求。

---

## 子账号权限配置（推荐）

出于安全考虑，不建议使用主账号 SecretId/Key。可创建子账号并授予单桶最小权限：

```json
{
  "version": "2.0",
  "statement": [
    {
      "effect": "allow",
      "action": [
        "cos:HeadBucket",
        "cos:GetBucket",
        "cos:PutObject",
        "cos:PostObject",
        "cos:GetObject",
        "cos:HeadObject",
        "cos:CopyObject",
        "cos:PutObjectACL",
        "cos:GetObjectACL",
        "cos:DeleteObject",
        "cos:InitiateMultipartUpload",
        "cos:ListMultipartUploads",
        "cos:ListParts",
        "cos:UploadPart",
        "cos:CompleteMultipartUpload",
        "cos:AbortMultipartUpload"
      ],
      "resource": [
        "qcs::cos:ap-guangzhou:uid/1300000000:typecho-1300000000/*",
        "qcs::cos:ap-guangzhou:uid/1300000000:typecho-1300000000"
      ]
    }
  ]
}
```

将以下值替换为你自己的：

- `ap-guangzhou` → 你的存储桶地域
- `1300000000` → 你的主账号 AppId
- `typecho-1300000000` → 你的存储桶名称

> 如自定义策略配置报错，可直接使用系统预设策略 `QcloudCOSDataFullControl`。

---

## 代码架构

插件采用多类分离设计，便于维护和扩展：

| 文件 | 职责 |
|------|------|
| `Plugin.php` | 插件入口、钩子注册、配置处理、共享状态（单例缓存） |
| `Action.php` | COS 上传/修改/删除、URL 生成、客户端初始化、目录逻辑、路径校验、COS 重试机制、健康检查数据采集 |
| `ConfigPanel.php` | 后台配置面板 HTML 表单渲染（Tab 切换：使用说明 / 基础配置 / 高级配置 / 运行状态） |
| `Image.php` | 图片处理：媒体分类、WebP 双引擎转换（GD + cwebp）、AVIF 转换、文件名清洗、cwebp 探测 |
| `Logger.php` | 统一日志输出（敏感信息脱敏 + 调试级别控制），解耦各模块间日志依赖 |
| `phar/cos-sdk-v5-7.phar` | 腾讯云 COS PHP SDK v5（phar 打包，无需 composer） |
| `statics/` | 配置面板 CSS/JS 资源 |

### 关键设计

- **薄委托**：`Plugin.php` 的钩子方法仅做转发，实际逻辑在 `Action` 类
- **单例缓存**：COS Client 和插件配置在单次请求内缓存，避免重复初始化
- **失败兜底**：所有 COS 操作均有 `try/catch`，失败时自动回退本地上传或记录日志
- **配置保存零异常**：`configHandle` 绝不抛出异常，桶校验/路径校验失败仅推送警告通知，确保保存后页面正常跳转
- **WebP 路径直存**：转换成功时附件记录直接存储 WebP 路径，Typecho 自然生成 WebP URL；转换失败时存原格式路径，确保可访问
- **AVIF 路径直存**：转换成功时附件记录直接存储 AVIF 路径，浏览器不支持时服务器端回退 WebP，前端 onerror 回退原图
- **COS 操作重试**：上传、删除、WebP/AVIF 上传均内置 1 次自动重试（间隔 200ms），应对网络瞬时波动
- **URL 路径编码**：生成访问 URL 时对路径分段做 `rawurlencode`，兼容中文/空格等特殊字符文件名
- **随机数容错**：文件名生成优先使用 `random_bytes`（CSPRNG），极端环境下回退 `uniqid`，避免因缺少 CSPRNG 导致上传失败
- **日志脱敏**：所有写入 `error_log` 的内容自动屏蔽 SecretId/SecretKey/COS 签名参数等敏感信息
- **原子文件替换**：本地备份和文件修改使用"临时文件写入 + rename 原子覆盖"模式，rename 失败时旧文件由原子性保证保留，避免数据丢失
- **健康检查**：`getHealthStatus()` 集中采集运行环境、图片处理、COS 配置、最近上传结果，配置面板实时展示

---

## 常见问题

### 上传成功但前台图片 403？

1. 检查 COS 存储桶权限是否为「公有读私有写」
2. 如使用 CDN，确认未开启「时间戳 URL 鉴权」
3. 如 CDN 配置了 Referer 白名单，确认博客域名在白名单中

### 后台无法上传/修改/删除文件？

插件内置回退机制，COS 连接失败时会自动走本地上传。如遇到问题请检查：

- SecretId / SecretKey 是否正确
- 存储桶名称和地域是否匹配
- 子账号是否授权了对应桶的读写权限
- 服务器能否访问 COS 服务（网络/防火墙）
- 查看「运行状态」Tab 的健康检查结果和最近一次上传结果
- 查看 PHP `error_log` 中 `[TECloudAttach]` 开头的错误日志

### 本地存储路径不可写时上传失败？

1. 保存配置时插件会自动校验本地路径可写性，不可写会在后台显示黄色警告条
2. 检查 web 服务器用户（www-data/nginx）对目标目录的父目录是否有写入权限
3. 如使用共享主机，确保目标路径不在 `open_basedir` 限制之外
4. 建议使用默认的 `/usr/uploads`，目录权限更可靠

### 对象存储路径留空后文件上传到哪里？

留空表示使用 COS 存储桶根目录，文件按"上传目录结构"（默认 `{type}/{year}`）分子目录存放，如 `images/2026/abc.jpg`。本地仍使用 `/usr/uploads`（除非修改了本地存储路径）。

### 启用插件前已上传的文件怎么办？

插件不会自动迁移旧文件。需要手动将本地 `/usr/uploads/` 目录下的文件上传到 COS 对应路径（保持目录结构一致），插件才能正确访问。

### 禁用插件后图片还能显示吗？

禁用插件后，附件 URL 会恢复为本地路径。如果之前开启了「在本地保存」，图片可正常显示；否则需要将 COS 文件下载回本地对应目录。

### 修改存储桶配置后不生效？

切换存储桶、地域等核心配置时，需先**禁用插件 → 修改配置 → 重新启用**，确保旧配置缓存被清除。

### WebP 转换没有生效？

1. 确认「上传图片自动转换 WebP」已开启
2. 确认文件扩展名在「需要转换为 WebP 的格式」列表中（默认 `jpg,jpeg,png,bmp,avif,tiff,tif`）
3. 在「运行状态」Tab 确认 WebP 转换支持和转换引擎
4. 确认转换引擎可用：
   - GD 方式：`php -r "var_dump(gd_info()['WebP Support']);"` 应输出 `bool(true)`
   - cwebp 方式：`cwebp -version` 有输出，且 PHP `exec()` 未被禁用
5. 确认图片像素未超过 5000 万（超大图片自动跳过）
6. 查看 PHP `error_log` 中是否有 `[TECloudAttach]` 相关的 WebP 错误日志

### AVIF 转换没有生效？

1. 确认「上传图片自动转换 AVIF」已开启
2. 确认文件扩展名在「需要转换为 AVIF 的格式」列表中（默认 `jpg,jpeg,png,bmp,webp,tiff,tif`）
3. 在「运行状态」Tab 确认 AVIF 转换支持和引擎
4. 确认转换引擎可用：
   - GD 方式：`php -r "var_dump(function_exists('imageavif'));"` 应输出 `bool(true)`
   - Imagick 方式：`php -r "var_dump(extension_loaded('imagick'));"` 应输出 `bool(true)`，且 ImageMagick 编译了 libheif
5. 确认图片不是 GIF（动图自动跳过）
6. 查看 PHP `error_log` 中是否有 `[TECloudAttach]` 相关的 AVIF 错误日志

### 如何开启调试日志？

在 Typecho 的 `config.inc.php` 中添加：

```php
define('TYPECHO_COS_DEBUG', 1);
```

开启后插件会将详细操作日志写入 PHP `error_log`，便于排查问题。

---

## 贡献指南

欢迎提交 Issue 和 Pull Request 参与项目改进。

### 提交规范

- **Bug 反馈**：请在 Issue 中说明插件版本、PHP 版本、Typecho 版本、COS 配置（隐去密钥）、复现步骤和预期行为
- **功能建议**：请说明使用场景和期望的行为
- **代码提交**：PR 请基于 `main` 分支，提交信息使用中文描述变更内容

### 仓库地址

- 主仓库（GitHub）：[https://github.com/muzihome/TECloudAttach](https://github.com/muzihome/TECloudAttach)

- 备用镜像（Gitee）：[https://gitee.com/muzinext/TECloudAttach](https://gitee.com/muzinext/TECloudAttach)

- 插件发布地址：[https://muzihome.com/archives/252.html](https://muzihome.com/archives/252.html)


---

## 许可证

本项目基于 GPL-2.0 许可证开源，完整许可证文本请参阅 [LICENSE](./LICENSE) 文件。