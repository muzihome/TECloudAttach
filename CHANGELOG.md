# TECloudAttach 更新日志

> 本文件记录 TECloudAttach 插件的版本迭代历史。完整使用说明请参阅 [README.md](./README.md)。
>
> 本日志遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/) 规范，版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。
>
> 变更类型：**新增**（Added）、**变更**（Changed）、**废弃**（Deprecated）、**移除**（Removed）、**修复**（Fixed）、**安全**（Security）。

---

## v1.4.0 (2026-09-07)

**功能增强：新增 AVIF 格式支持 + 浏览器兼容性回退 + 配置面板样式优化**

- **AVIF 自动转换**：上传 jpg/png/bmp 等图片时自动转换为 AVIF 格式，连同原图一起上传到 COS；AVIF 压缩率高于 WebP，同等画质下文件更小
- **三级回退转换**：优先使用 GD 库 `imageavif()`（PHP 8.1+ 编译 `--with-avif`）；GD 支持编码但不支持源格式解码（如 TIFF）时采用混合解码模式（Imagick 解码为临时 PNG 再交 GD 编码）；GD 不可用时回退到 Imagick 扩展（需编译 libheif）；三者都不可用时自动跳过转换，不影响原图上传；AVIF 无命令行转换工具，不依赖 `exec()`
- **混合解码模式**：当 GD 支持 AVIF 编码但不支持源格式解码（如 TIFF）且 Imagick 已加载时，用 Imagick 解码源文件为临时 PNG，再交 GD 编码为 AVIF，解决 TIFF→AVIF 转换在 GD 不支持 TIFF 解码、Imagick 未编译 libheif 环境下的能力错位问题
- **浏览器兼容性回退（组合方案）**：服务器端通过 HTTP `Accept` 头检测浏览器是否支持 AVIF，不支持时自动回退到 WebP 路径；前端内联 JS 在 WebP 加载失败时进一步回退到原图，确保老旧浏览器也能正常显示图片
- **返回路径优先级**：AVIF > WebP > 原图，附件记录直接存储最优格式路径，编辑器自动返回对应格式链接
- **三端同步**：上传、修改、删除均同步处理 AVIF 衍生文件（含原图↔AVIF↔WebP 双向清理）
- **配置面板优化**：AVIF 转换质量、转换格式仅在勾选「上传图片自动转换 AVIF」时显示，减少视觉干扰；4个TAB样式统一协调
- **健康检查增强**：新增 AVIF 支持状态、引擎（gd/imagick/none）、`imageavif()`/`imagecreatefromavif()` 函数可用性、Imagick AVIF 支持检测
- **动态默认开关值**：根据系统环境支持情况自动设置 WebP/AVIF 转换功能默认开关状态（仅全新安装生效）
- **代码质量提升**：修复 `getAvifFallbackPath()` 死代码、健康检查 GD 分支 AVIF 判断未检查 `function_exists`、`convertToWebp`/`convertToAvif` GD 分支 BMP fallthrough、`isConvertibleImage`/`isConvertibleAvif` 未排除 GIF 等问题；`clientSupportsAvif()` 改用 Typecho Request 对象获取 Accept 头，符合 Typecho 开发规范

**Bug 修复**

- **删除 avif 原图时 COS 衍生 webp 未同步删除**：根因为衍生文件删除仅用简单 try-catch 无重试机制，网络瞬时故障导致 webp 残留且静默失败；同时 avif 分支 if/else 路径推导复杂缺乏双保险。修复：提取 `$deleteCosKey` 闭包统一处理衍生文件删除，内部使用 `cosCallWithRetry`（1次重试）；avif/webp 分支始终删除从 `$relPath` 直接推导的衍生路径（双保险，COS 删除不存在对象不报错）；原图为其他格式（如 jpg→avif）时额外删除原图和原图推导的衍生文件

## v1.3.3 (2026-09-01)

**功能增强 + Bug 修复：删除文件后递归向上清理本地空文件夹（停止边界修正）**

- 新增 `removeEmptyDirsUpward()` 方法：从被删除文件的父目录开始，向上递归删除空文件夹，遇到非空文件夹立即停止
- `deleteLocalFileCandidates()` 收集所有被删除文件的绝对路径，删除完成后统一调用空目录清理；覆盖 COS 模式本地备份与回退本地模式两种场景
- **Bug 修复（停止边界）**：原实现递归停止边界为站点根目录，导致删完文件后会误删 `usr/uploads` 甚至 `usr` 目录本身；现修正为本地存储目录绝对路径（`getLocalUploadDir()`，默认 `usr/uploads`），递归向上到本地存储目录为止，不删除本地存储目录本身及其上级目录
- 安全边界：文件不在本地存储目录下时不递归删除（如 COS 自定义 path 目录）；仅删除本地存储目录下的空目录；删除失败仅记录日志不阻断主流程
- 经 WSL PHP 8.2.33 端到端验证 16/16：单文件递归清空、非空目录不删、深层目录（年/月/日/时）全清、安全边界外不删，本地存储目录及上级均保留

## v1.3.2 (2026-09-01)

**功能增强：新增 Imagick 转换后端（解决 exec 被禁用时 cwebp/TIFF 不可用）**

- 根因：服务器 PHP `disable_functions` 禁用 `exec`（及所有命令执行函数）时，cwebp 命令行工具无法调用，而 GD 不支持 TIFF 解码，导致配置 `tif/tiff` 后实际不转换
- 修复：新增 Imagick 扩展作为转换后端（不依赖 exec），支持 TIFF 等 GD 不支持的格式；转换优先级调整为 GD（jpg/png/bmp）→ Imagick（tiff/其他）→ cwebp（后备）
- `isWebpSupported()` 增加 Imagick 检测；健康检查页面新增 Imagick 扩展状态、版本、WebP 支持显示；WebP 转换引擎映射增加 `imagick`
- 经 WSL PHP 8.2.33 + ImageMagick 7.1.2 端到端验证：exec 被禁用时 TIFF→WebP 转换成功（Imagick 后端），BMP/JPG/PNG 回归通过

## v1.3.1 (2026-09-01)

**Bug 修复：WebP 自动转换不支持 BMP/TIFF 格式**

- 根因：`convertToWebp` 的 GD 分支白名单仅含 `image/jpeg`/`image/png`，导致在「需要转换为 WebP 的格式」填写 `bmp,tif,tiff` 后实际上传时不转换；cwebp 分支虽支持 TIFF 但不支持 BMP，且未安装 cwebp 时静默失败
- 修复：GD 分支白名单增加 `image/bmp`（PHP 7.2+ 内置 `imagecreatefrombmp`）；TIFF 因 GD 无 `imagecreatefromtiff`，需依赖 cwebp（cwebp 官方支持 TIFF 输入），cwebp 未安装时日志明确提示「TIFF 需安装 cwebp」
- `convertToWebp` 失败日志明确化：区分格式不支持（GD/cwebp 均不支持该输入）、环境缺能力（TIFF 需 cwebp、BMP 需 `imagecreatefrombmp`），便于排查
- 经 WSL PHP 8.2.33 + 真实 Typecho 1.3.0 沙盒端到端验证：BMP 转换 6/6、JPG/PNG 回归 4/4、TIFF 无 cwebp 行为 3/3、配置匹配 5/5，共 18/18

## v1.3.0 (2026-09-01)

**代码审查整改（第三批，2026-09-01）**

- 修复 `toLocalBackupPath` 提前 `return` 导致 P3 防双重前缀代码不可达：回退本地模式下本地备份映射会生成 `usr/uploads/usr/uploads/...` 错误路径（已用真实 Typecho 1.3.0 + SQLite 沙盒实测确认）；现先做本地前缀去重再拼接
- 统一 4 处 WebP 原图推导为公共方法 `deriveOriginalPathFromWebp`（deleteHandle / deleteLocalFileCandidates / modifyHandle / defaultModifyHandle），修复 modify 与本地修改回退分支仍以附件 `type`（MIME）当扩展名推导原图的同类错误，附件 `name` 缺失时回退当前扩展名
- `对象存储路径` 表单增加 `..` 段禁用正则（`(?!.*\.\.)`），与 `本地存储路径` 校验保持一致
- 本轮全部修复经 WSL Debian PHP 8.2.30 + 真实 Typecho 1.3.0 沙盒端到端验证（删除双向清理 16/16、路径推导 6/6、逻辑单测 10/10）

## v1.2.0 (2026-08-01)

**功能变更**

- 基础配置「同步修改本地存储路径」复选框改为输入框「本地存储路径」，默认值 `usr/uploads`：直接指定本地备份/回退上传根目录，不再与 COS 路径联动；旧版 checkbox 数组配置在保存时自动规范化为默认路径
- 健康检查、配置说明、使用文档同步更新

**Bug 修复**

- 修复勾选「删除时同步删除本地备份」保存失效：Typecho 1.3.0 `Request::get()` 对 checkbox 数组值做类型匹配校验，默认值为 `NULL` 的字段勾选值被静默丢弃；`local_sync`/`sync_local_path` 默认值统一改为 `array()`（保持默认不勾选，同时修复勾选保存）
- 修复删除文件时本地备份仅删原图、WebP 副本残留：本地备份删除与 COS 删除一致，做原图/WebP 双向清理

**代码审查整改（29 项）**

- 修复本地备份缺失时 `.webp` 衍生文件处理
- 上传时显式设置对象 ContentType
- SDK 重试逻辑去重、region 纳入必填校验分组
- 保存按钮选择器兼容 `button` / `input[type=submit]`，无 JS 时表单可正常提交（noscript 兜底）
- 路径清洗、健康检查展示等其余低风险项修复

**代码审查整改（第二批，2026-09-01）**

- 修复删除附件时 COS 未配置（回退本地）分支不做 WebP 双向清理：与 COS 模式统一走 `deleteLocalFileCandidates`（双位置兜底：回退本地路径 + COS 备份映射路径），并加固 `toLocalBackupPath` 避免双重前缀
- 修复本地备份删除失败会阻断附件删除流程：改为记日志 + 可见提示，不阻断主流程
- 修复「对象存储路径」留空时被 `__TYPECHO_UPLOAD_DIR__` 干扰无法使用桶根目录：区分「显式留空」与「旧配置无该键」，显式留空一律返回桶根目录
- 「本地存储路径」增加表单校验（仅允许字母/数字/`/`_`-``.`），配置保存时与消费端共用同一套路径清洗逻辑，保证存库值与实际使用值一致
- 「删除同步 COS / 本地保存 / WebP 自动转换」默认改为不勾选，避免全新安装默认开启数据销毁/转换行为
- 配置面板前端联动：未勾选「在本地保存」时自动禁用「本地删除同步删除本地备份」，避免静默无效
- cwebp 版本探测增加结果缓存并在健康检查复用已探测路径，减少 `exec()` 调用；统一复核 `trim($output)` 对数组的 PHP 8 兼容性
- 附件内容读取改为 1MB 分块流式读取并设 100MB 上限，降低大文件内存峰值
- 修复删除 webp 附件时原图推导错误：附件 `type` 字段为 MIME（如 image/jpeg）不可作扩展名，且 `getWebpPath` 为替换扩展名命名（a.jpg→a.webp）；现从附件 `name`（原始文件名）取扩展名替换 `.webp` 正确推导原图

## v1.1.1 (2026-08-29)

**Bug 修复**

- 修复 `saveLocalBackup` 和 `defaultModifyHandle` 中"先删旧文件再 rename"导致的本地备份/附件数据永久丢失风险（rename 原子覆盖，无需先删旧文件，失败时旧文件由原子性保证保留）
- 修复跨类静态属性 `cachedCosSingletonClient/Key` 为 private 导致 COS 上传失败的问题（改为 public）
- 修复 `region` 字段缺少 `data-required` 导致前端必填校验不识别的问题

**功能优化**

- 对象存储路径允许为空，空值表示使用 COS 存储桶根目录（默认值改为空）
- 上传目录结构默认值改为 `{type}/{year}`（按媒体类型+年份分类）
- "同步修改本地存储路径"默认不开启（本地上传使用 Typecho 默认路径 `/usr/uploads`，更安全）
- `uploadHandle` 增加 `region` 配置检查，避免 CosInit 抛异常后再回退
- 健康检查页面增加对象存储路径、上传目录结构、同步本地路径的配置展示
- 健康检查页面增加 cwebp 工具独立检测（显示安装状态、版本、路径）
- 健康检查页面增加 AVIF 支持状态和 `imageavif()`/`imagecreatefromavif()` 函数检测

**配置面板优化**

- Tab 顺序调整为：使用说明 → 基础配置 → 高级配置 → 运行状态
- 基础配置/高级配置/运行状态页面增加标题
- 基础配置字段分组：密钥/地域/存储桶一组，路径/目录结构一组
- 高级配置中"本地删除同步删除COS文件"、"在本地保存备份"、"COS删除时同步删除本地备份"合并到同一内容框
- 输入框样式改为下划线风格（去除顶部/左右边框及阴影，只保留底部 1px 边框）
- 所属地域下拉框选项背景色改为浅淡蓝色
- 增加必需数据输入校验：必填项未填全时"保存设置"按钮灰色禁用
- WebP 子项（转换质量、转换格式）仅在勾选"上传图片自动转换 WebP"时显示
- 健康检查页面改为单列卡片布局，避免多列卡片高度差异导致错位
- 保存按钮栏去卡片化（透明背景+顶部分隔线），按钮优化渐变/圆角/hover 动效/禁用状态
- 配置面板宽度自适应 100%，覆盖 Typecho 后台外层容器宽度限制
- 健康检查表格优化：固定列宽、行 hover 效果、长文本自动换行

## v1.1.0 (2026-08-20)

**WebP 链接机制重构**

- 转换成功时附件记录直接存储 WebP 路径（存入数据库 `text` 字段），Typecho 自然生成 WebP URL，彻底解决编辑器返回原格式链接的问题
- 转换失败时存储原格式路径，确保图片可访问
- `modifyHandle` 支持附件路径为 WebP 时自动推导原始路径用于上传新文件
- `deleteHandle` 支持双向清理：路径为 WebP 时同步删除原始文件，路径为原格式时同步删除 WebP

**配置面板优化**

- 所有布尔选项（同步修改本地存储路径、本地删除同步删除COS文件、在本地保存、删除时同步删除本地备份、上传图片自动转换WebP）统一改为 Checkbox 勾选框样式
- 默认值调整：同步修改本地存储路径默认开启、在本地保存默认开启、WebP 转换默认开启

**Bug 修复**

- 修复 `deleteHandle` 中附件路径为 WebP 时本地备份删除路径错误（原图备份残留）
- 修复 `loadSdk` 中 `set_error_handler` 异常时未恢复导致全局错误处理器泄漏
- 修复 cwebp 命令使用裸命令 `cwebp` 依赖 PATH 环境变量的问题，改为缓存并使用检测到的完整路径
- 修复 Plugin.php 文档块版本号与 `PLUGIN_VERSION` 常量不同步；`USER_AGENT` 改用常量引用

**性能与健壮性**

- COS 上传、删除、WebP 上传均加入 1 次自动重试（间隔 200ms），应对网络瞬时波动
- 提取 `uploadToCos()` 公共方法，消除 `uploadHandle`/`modifyHandle` 重复代码
- 合并 `attachmentHandle`/`attachmentDataHandle` 为 `resolveAttachmentUrl()`，消除重复逻辑
- `buildObjectUrl` 对路径分段做 `rawurlencode`，兼容中文/空格等特殊字符文件名
- 移除从未调用的死代码 `doesObjectExist()`
- 日志脱敏正则移除 `ak`/`sk`/`key`/`sign` 等过短泛匹配词，避免误脱敏 `keyword=`、`apikey=` 等正常参数

## v1.0.9 (2026-08-19)

**核心功能**

- 文件上传至腾讯云 COS，自动设置公有读权限与长缓存头
- 文件修改/删除同步 COS，支持删除时同步清理 WebP 衍生文件
- 本地备份可选，支持本地路径与 COS 路径同步
- 自定义访问域名（默认 COS 域名 / 自定义源站域名 / CDN 加速域名）
- 上传目录结构自定义（`{year}`/`{month}`/`{day}`/`{type}`/`{ext}` 变量）
- COS 连接失败自动回退本地上传，后台不中断

**WebP 自动转换**

- 上传 jpg/png 等图片自动转换为 WebP 并一同上传 COS
- 双引擎转换：优先 GD 库，GD 不支持 WebP 时自动回退 cwebp 命令行工具
- 转换并上传成功后，编辑器返回 WebP 链接
- 支持转换质量配置、可转换格式列表配置
- 超大图片（>5000 万像素）自动跳过，避免内存溢出
- GIF 动图自动跳过（避免丢失动画）

**配置与安全**

- 保存配置时自动校验存储桶连通性与本地路径可写性，失败推送后台警告通知（不阻断保存）
- SecretKey 使用密码框输入，页面不明文显示
- 日志敏感信息（密钥、签名参数）自动脱敏
- 文件名清洗防路径穿越，点文件视为无扩展名
- 请求超时可配置
- 配置保存零异常：任何校验失败均不抛出异常，确保页面正常跳转

**健壮性与修复**

- 修复 Typecho 1.3.0 兼容：`Upload::UPLOAD_DIR` 常量已移除，改用插件内常量
- 修复 bytes/bits 方式上传时 MIME 类型检测失效
- 修复 WebP 临时文件残留（`tempnam()` 空文件问题）
- 修复关闭 WebP 后删除图片不清理 `.webp` 残留
- 修复配置面板 JS 表单选择器空指针异常（Tab 切换失效）
- `defaultUploadHandle` / `defaultModifyHandle` 中 `filesize()` 加错误抑制
- `deleteHandle` 路径空值保护，异常数据不导致 Fatal Error
- `Logger::maskSensitive()` 正则失败时返回原消息而非 null
- `buildObjectUrl()` 移除未使用参数
- `webp_formats` 配置允许输入带空格的格式列表
- `modifyHandle` 本地备份逻辑复用 `saveLocalBackup()`，消除重复代码
- 全局常量 `pluginName` 改为类常量 `Plugin::NAME`，避免命名冲突
- `makeLocalAttachmentUrl()` URL 校验改用 `filter_var(FILTER_VALIDATE_URL)`
- cwebp 探测增加 `which` 命令后备，兼容 busybox/Alpine 等极简环境
- 文件名生成 `random_bytes()` 异常回退 `uniqid()`，无 CSPRNG 环境不中断上传

**架构**

- 多类分离：Plugin（入口）/ Action（核心逻辑）/ Image（图片处理）/ Logger（日志）/ ConfigPanel（配置面板）
- COS SDK 通过 phar 加载，无需 composer
- 适配 Typecho 1.3.0 原生插件钩子，零核心文件修改
