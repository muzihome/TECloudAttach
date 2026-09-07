<?php

namespace TypechoPlugin\TECloudAttach;

use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Text;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class ConfigPanel
{
    public static function render(Form $form): void
    {
        $rootUrl = htmlspecialchars(Helper::options()->rootUrl, ENT_QUOTES, 'UTF-8');
        $githubUrl = 'https://github.com/muzihome/TECloudAttach';
        $giteeUrl = 'https://gitee.com/muzinext/TECloudAttach';
        
        $pluginBaseUrl = rtrim((string)(Helper::options()->pluginUrl ?? ''), '/');
        if ($pluginBaseUrl === '') {
            $pluginBaseUrl = $rootUrl . '/usr/plugins';
        }
        $pluginBaseUrl = htmlspecialchars($pluginBaseUrl . '/' . Plugin::NAME, ENT_QUOTES, 'UTF-8');
        
        $jsFile = __DIR__ . '/statics/js/joe.config.min.js';
        $cssFile = __DIR__ . '/statics/css/joe.config.min.css';
        $assetVer = Plugin::PLUGIN_VERSION;
        if (file_exists($jsFile)) {
            $assetVer .= '.' . filemtime($jsFile);
        }
        if (file_exists($cssFile)) {
            $assetVer .= '.' . filemtime($cssFile);
        }

        
        
        
        $webpSupportedByEnv = Image::isWebpSupported();
        $avifSupportedByEnv = Image::isAvifSupported();
        $webpEnableDefault = $webpSupportedByEnv ? ['open'] : [];
        $avifEnableDefault = $avifSupportedByEnv ? ['open'] : [];
        ?>
        <link rel="stylesheet" href="<?php echo $pluginBaseUrl; ?>/statics/css/joe.config.min.css?v=<?php echo $assetVer; ?>">
        <script src="<?php echo $pluginBaseUrl; ?>/statics/js/joe.config.min.js?v=<?php echo $assetVer; ?>"></script>
        <div class="joe_config">
            <!-- 修复(L12)：noscript 兜底——JS 禁用时仍可见全部内容，仅提示 Tab 切换不可用 -->
            <noscript>
                <div class="joe_config__error">您的浏览器未启用 JavaScript：配置面板将显示全部内容，Tab 切换与保存按钮联动不可用。请启用 JavaScript 后刷新页面以体验完整交互。</div>
            </noscript>
            <div class="joe_config__aside">
                <div class="logo">腾讯云COS</div>
                <ul class="tabs">
                    <li class="item active" data-current="joe_notice">使用说明</li>
                    <li class="item" data-current="joe_base">基础配置</li>
                    <li class="item" data-current="joe_advanced">高级配置</li>
                    <li class="item" data-current="joe_health">运行状态</li>
                </ul>
            </div>
            <div class="joe_config__main">
            <span id="joe_version" style="display: none;" data-version="<?php echo Plugin::PLUGIN_VERSION; ?>"></span>
            <div class="joe_config__notice">
                <p class="title">使用说明</p>
                <ol>
                    <?php if (version_compare(PHP_VERSION, '8.0.0', '<')): ?>
                        <li style="color:red; font-weight:800;">本插件推荐在 PHP 8.0+ 环境下运行（适配 Typecho 1.3.0），低版本可能存在兼容性问题。<br></li>
                    <?php endif; ?>
                    <li>插件基于腾讯云 cos-php-sdk-v5 开发，若发现插件不可用，请到 <a target="_blank" href="<?php echo $githubUrl; ?>">GitHub</a> 或 <a target="_blank" href="<?php echo $giteeUrl; ?>">Gitee（备用镜像）</a> 检查是否有更新，或者提交Issues<br></li>
                    <li>插件会验证配置的正确性，如填写错误会报错<br></li>
                    <li>插件会自动替换之前文件的链接，若启用插件前已上传文件，为保证正常显示，请自行将其上传至COS相同路径<br></li>
                    <li>禁用插件会恢复为本地路径，为保证正常显示，请自行将数据从COS下载至相同路径<br></li>
                    <li>重新更改插件中关于COS桶的配置需要先禁用插件，再重新设置<br></li>
                </ol>
            </div>
            <?php
            
            $health = Action::getHealthStatus();
            
            $h = function ($bool, $ok = '正常', $bad = '未启用') {
                return $bool
                    ? '<span class="joe_health__badge joe_health__badge--ok">' . $ok . '</span>'
                    : '<span class="joe_health__badge joe_health__badge--bad">' . $bad . '</span>';
            };
            ?>
            <div class="joe_content joe_health">
                <h3 class="joe_health__title">运行状态 / 健康检查</h3>

                <!-- 运行环境 -->
                <div class="joe_health__card">
                    <div class="joe_health__card-title">运行环境</div>
                    <table class="joe_health__table">
                        <tbody>
                            <tr><th>插件版本</th><td><?php echo htmlspecialchars($health['plugin_version']); ?></td></tr>
                            <tr><th>PHP 版本</th><td><?php echo htmlspecialchars($health['php_version']); ?></td></tr>
                            <tr><th>COS SDK 版本</th><td><?php echo htmlspecialchars($health['sdk_version']); ?></td></tr>
                            <tr><th>phar 扩展</th><td><?php echo $h($health['phar_enabled'], '已加载', '未加载（必需）'); ?></td></tr>
                            <tr><th>curl 扩展</th><td><?php echo $h($health['curl_enabled'], '已加载', '未加载'); ?></td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- 图片处理 / WebP & AVIF -->
                <div class="joe_health__card">
                    <div class="joe_health__card-title">图片处理 / WebP &amp; AVIF 转换</div>
                    <table class="joe_health__table">
                        <tbody>
                            <tr><th>GD 扩展</th><td><?php echo $h($health['gd_enabled'], '已加载', '未加载'); ?></td></tr>
                            <?php if ($health['gd_enabled']): ?>
                            <tr><th>GD 版本</th><td><?php echo htmlspecialchars($health['gd_version']); ?></td></tr>
                            <?php endif; ?>
                            <!-- Imagick 扩展状态（修复 1.3.2：不依赖 exec，可作为 TIFF→WebP 转换后端） -->
                            <tr><th>Imagick 扩展</th><td><?php echo $h($health['imagick_enabled'], '已加载', '未加载'); ?></td></tr>
                            <?php if ($health['imagick_enabled']): ?>
                            <tr><th>Imagick 版本</th><td class="joe_health__cell--mono"><?php echo htmlspecialchars($health['imagick_version']); ?></td></tr>
                            <tr><th>Imagick WebP 支持</th><td><?php echo $h($health['imagick_webp_support'], '支持（可转 TIFF 等）', '不支持'); ?></td></tr>
                            <?php endif; ?>
                            <tr><th>WebP 转换支持</th><td><?php echo $h($health['webp_supported'], '支持', '不支持'); ?></td></tr>
                            <?php if (!$health['webp_supported']): ?>
                            <tr>
                                <th>WebP 不支持原因与解决</th>
                                <td class="joe_health__cell--help">
                                    <?php
                                $reasons = [];
                                if ($health['gd_enabled'] && empty($health['gd_webp_support'])) {
                                    $reasons[] = '• GD 扩展已加载但未编译 WebP 支持（缺少 libwebp）。解决：<code>sudo apt install libwebp-dev</code> 后重新编译 PHP 加 <code>--with-webp</code>';
                                } elseif (!$health['gd_enabled']) {
                                    $reasons[] = '• GD 扩展未加载。解决：<code>sudo apt install php8.2-gd</code>';
                                }
        if ($health['webp_engine'] !== 'cwebp') {
            if (!$health['exec_enabled']) {
                $reasons[] = '• exec() 函数被禁用，无法使用 cwebp 后备方案。解决：在 php.ini 中移除 exec 的 disable_functions 限制';
            } else {
                $reasons[] = '• cwebp 命令行工具未安装。解决：<code>sudo apt install webp</code>（安装后插件自动回退使用）';
            }
        }
        echo implode('<br>', $reasons);
        ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr><th>WebP 转换引擎</th><td><?php
                                $engineMap = ['gd' => 'GD 库（imagewebp）', 'imagick' => 'Imagick 扩展（不依赖 exec）', 'cwebp' => 'cwebp 命令行', 'none' => '不可用'];
        echo htmlspecialchars($engineMap[$health['webp_engine']] ?? $health['webp_engine']);
        ?></td></tr>
                            <tr><th>AVIF 转换支持</th><td><?php echo $h($health['avif_supported'], '支持', '不支持'); ?></td></tr>
                            <?php if ($health['avif_supported']): ?>
                            <tr><th>imageavif() 函数</th><td><?php echo $h($health['avif_imageavif'], '可用', '不可用'); ?></td></tr>
                            <tr><th>imagecreatefromavif() 函数</th><td><?php echo $h($health['avif_createfrom'], '可用', '不可用'); ?></td></tr>
                            <?php else: ?>
                            <tr>
                                <th>AVIF 不支持原因与解决</th>
                                <td class="joe_health__cell--help">
                                    <?php if (!$health['gd_enabled']): ?>
                                        • GD 扩展未加载。解决：<code>sudo apt install php8.2-gd</code> 或重新编译 PHP 加 <code>--enable-gd</code>
                                    <?php else: ?>
                                        • GD 已加载但未编译 AVIF 支持（缺少 libavif）。解决：安装 <code>sudo apt install libavif-dev</code> 后重新编译 PHP 加 <code>--with-avif</code>（需 PHP 8.1+）
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <!-- cwebp 工具独立检测：不管当前 WebP 引擎是什么，都显示系统是否安装了 cwebp -->
                            <tr><th>cwebp 工具</th><td><?php
        
        if (!$health['exec_enabled']) {
            echo '<span class="joe_health__badge joe_health__badge--bad">无法检测（exec 被禁用）</span>';
        } else {
            echo $h($health['cwebp_installed'], '已安装', '未安装');
        }
        ?></td></tr>
                            <?php if ($health['cwebp_installed']): ?>
                            <tr><th>cwebp 版本</th><td class="joe_health__cell--mono"><?php echo htmlspecialchars($health['cwebp_version'] ?? '未知'); ?></td></tr>
                            <tr><th>cwebp 路径</th><td class="joe_health__cell--mono"><?php echo htmlspecialchars($health['cwebp_path'] ?? ''); ?></td></tr>
                            <?php endif; ?>
                            <tr><th>exec() 函数</th><td><?php echo $h($health['exec_enabled'], '可用', '被禁用（cwebp 模式需要）'); ?></td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- COS 配置状态 -->
                <div class="joe_health__card">
                    <div class="joe_health__card-title">COS 配置状态</div>
                    <table class="joe_health__table">
                        <tbody>
                            <tr><th>COS 配置</th><td><?php echo $h($health['cos_configured'], '已配置', '未配置（将回退本地上传）'); ?></td></tr>
                            <?php if ($health['cos_configured']): ?>
                            <tr><th>存储桶</th><td class="joe_health__cell--mono"><?php echo htmlspecialchars($health['cos_bucket']); ?></td></tr>
                            <tr><th>地域</th><td class="joe_health__cell--mono"><?php echo htmlspecialchars($health['cos_region']); ?></td></tr>
                            <tr><th>对象存储路径</th><td class="joe_health__cell--mono"><?php echo htmlspecialchars($health['cos_path'] ?? '（根目录）'); ?></td></tr>
                            <tr><th>上传目录结构</th><td class="joe_health__cell--mono"><?php echo htmlspecialchars($health['dir_structure'] ?? '{type}/{year}'); ?></td></tr>
                            <tr><th>本地存储路径</th><td class="joe_health__cell--mono"><?php echo htmlspecialchars($health['sync_local_path'] ?? 'usr/uploads'); ?></td></tr>
                            <tr><th>本地备份</th><td><?php echo $h($health['local_backup'], '已开启', '已关闭'); ?></td></tr>
                            <tr><th>删除同步 COS</th><td><?php echo $h($health['remote_sync'], '已开启', '已关闭'); ?></td></tr>
                            <tr><th>删除同步本地备份</th><td><?php echo $h($health['local_sync'], '已开启', '已关闭'); ?></td></tr>
                            <tr><th>WebP 自动转换</th><td><?php echo $h($health['webp_enabled'], '已开启', '已关闭'); ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 最近一次上传结果 -->
                <div class="joe_health__card">
                    <div class="joe_health__card-title">最近一次上传结果</div>
                    <table class="joe_health__table">
                        <tbody>
                            <?php if ($health['last_upload']): ?>
                            <tr><th>时间</th><td><?php echo htmlspecialchars($health['last_upload']['time'] ?? ''); ?></td></tr>
                            <tr><th>状态</th><td><?php echo !empty($health['last_upload']['success'])
            ? '<span class="joe_health__badge joe_health__badge--ok">成功</span>'
            : '<span class="joe_health__badge joe_health__badge--bad">失败</span>'; ?></td></tr>
                            <tr><th>消息</th><td><?php echo htmlspecialchars($health['last_upload']['message'] ?? ''); ?></td></tr>
                            <?php if (!empty($health['last_upload']['extra'])): ?>
                            <tr><th>详细信息</th><td class="joe_health__cell--mono joe_health__cell--help"><?php echo htmlspecialchars(json_encode($health['last_upload']['extra'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></td></tr>
                            <?php endif; ?>
                            <?php else: ?>
                            <tr><td colspan="2" class="joe_health__cell--empty">暂无上传记录（上传一次文件后此处会显示结果）</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <p class="joe_health__tip">
                    提示：健康检查数据为实时采集，上传结果记录保存在系统临时目录（<code><?php echo htmlspecialchars(sys_get_temp_dir()); ?></code>），重启服务器后会清空。
                </p>
            </div>
            <?php

            

            $secid = new Text('secid', null, '', _t('SecretId(必需)'), _t('腾讯云控制台 <a target="_blank" href="https://console.cloud.tencent.com/capi">个人API密钥</a> 获取 SecretId'));
        $secid->setAttribute('class', 'joe_content joe_base');
        $secid->setAttribute('data-required', 'true');
        $secid->addRule('required', _t('SecretId不能为空！'));

        
        $sekey = new Password('sekey', null, '', _t('SecretKey(必需)'), _t('腾讯云控制台 <a target="_blank" href="https://console.cloud.tencent.com/capi">个人API密钥</a> 获取 SecretKey（密码框输入，页面不明文显示）'));
        $sekey->setAttribute('class', 'joe_content joe_base');
        $sekey->setAttribute('data-required', 'true');
        $sekey->setAttribute('autocomplete', 'new-password');
        $sekey->addRule('required', _t('SecretKey不能为空！'));

        $region = new Select(
            'region',
            [
                'ap-beijing' => _t('北京'),
                'ap-beijing-1' => _t('天津'),
                'ap-nanjing' => _t('南京'),
                'ap-shanghai' => _t('上海'),
                'ap-guangzhou' => _t('广州'),
                'ap-chengdu' => _t('成都'),
                'ap-chongqing' => _t('重庆'),
                'ap-shenzhen-fsi' => _t('深圳金融'),
                'ap-shanghai-fsi' => _t('上海金融'),
                'ap-beijing-fsi' => _t('北京金融'),
                'ap-hongkong' => _t('香港'),
                'ap-singapore' => _t('新加坡'),
                'ap-mumbai' => _t('孟买'),
                'ap-jakarta' => _t('雅加达'),
                'ap-seoul' => _t('首尔'),
                'ap-bangkok' => _t('曼谷'),
                'ap-tokyo' => _t('东京'),
                'na-toronto' => _t('多伦多'),
                'na-siliconvalley' => _t('硅谷（美西）'),
                'na-ashburn' => _t('弗吉尼亚（美东）'),
                'sa-saopaulo' => _t('圣保罗'),
                'eu-frankfurt' => _t('法兰克福'),
                'eu-moscow' => _t('莫斯科'),
            ],
            
            'ap-guangzhou',
            _t('所属地域(必需)')
        );
        $region->setAttribute('class', 'joe_content joe_base');
        
        $region->setAttribute('data-required', 'true');

        $bucket = new Text('bucket', null, '', _t('存储桶名称(必需)'), _t('格式为 BucketName-Appid ，如 typecho-12345678 ,可在 <a target="_blank" href="https://console.cloud.tencent.com/cos/bucket">腾讯云控制台</a> 获取'));
        $bucket->setAttribute('class', 'joe_content joe_base');
        $bucket->setAttribute('data-required', 'true');
        $bucket->addRule('required', _t('存储桶名称不能为空！'));

        
        $path = new Text('path', null, '', _t('对象存储路径'), _t('留空则使用 COS 存储桶根目录（文件直接上传至桶根目录，按上方"上传目录结构"分子目录）；如需指定子目录请填写，如 <code>usr/uploads</code>（无需以 / 开头）'));
        $path->setAttribute('class', 'joe_content joe_base');
        
        
        $path->addRule('regexp', _t('对象存储路径只能包含字母、数字、/、_、-，不能包含 ..（留空表示使用根目录）'), '/^(?!.*\.\.)[a-zA-Z0-9\/_\-]*$/');

        $sync_local_path = new Text('sync_local_path', null, 'usr/uploads', _t('本地存储路径'), _t('本地备份保存的相对路径，默认 <code>usr/uploads</code>（Typecho 默认上传目录）。留空则使用默认值。'));
        $sync_local_path->setAttribute('class', 'joe_content joe_base');
        
        $sync_local_path->addRule('regexp', _t('本地存储路径只能包含字母、数字、/、_、-、.（留空则使用默认值 usr/uploads）'), '/^(?!.*\.\.)[a-zA-Z0-9\/_\-.]*$/');

        $dir_structure = new Text(
            'dir_structure',
            null,
            '{type}/{year}',
            _t('上传目录结构'),
            _t('支持变量：<code>{year}</code>（年）、<code>{month}</code>（月）、<code>{day}</code>（日）、<code>{type}</code>（媒体类型）、<code>{ext}</code>（扩展名）。<br>'
                . '<b>{type}</b> 自动分类：images / videos / audios / documents / code / archives / fonts / other<br>'
                . '常用示例：<br>'
                . '&nbsp;&nbsp;• 按类型+年（默认）：<code>{type}/{year}</code> → images/2026/<br>'
                . '&nbsp;&nbsp;• 按年月：<code>{year}/{month}</code> → 2026/08/<br>'
                . '&nbsp;&nbsp;• 按类型+年月：<code>{type}/{year}/{month}</code> → images/2026/08/<br>'
                . '&nbsp;&nbsp;• 仅按类型：<code>{type}</code> → images/<br>'
                . '&nbsp;&nbsp;• 按扩展名+年：<code>{ext}/{year}</code> → jpg/2026/<br>'
                . '&nbsp;&nbsp;• 按年+类型：<code>{year}/{type}</code> → 2026/images/<br>'
                . '&nbsp;&nbsp;• 不建子目录：<code>留空</code> → 桶根目录/<br>'
                . '<b>修改后仅影响新上传的文件，已上传的文件路径不变。</b>')
        );
        $dir_structure->setAttribute('class', 'joe_content joe_base');
        $dir_structure->addRule('regexp', _t('目录结构只能包含字母、数字、/、_、- 及 {year}/{month}/{day}/{type}/{ext} 变量'), '/^[a-zA-Z0-9\/_\-\{\}]*$/');

        

        $domain = new Text(
            'domain',
            null,
            '',
            _t('访问域名（若配置错误无法正常访问）'),
            _t('留空则使用默认域名，如：https://images-sh-123456789.cos.ap-shanghai.myqcloud.com<br>
        可使用自定义源站域名，例如：cos.example.com（支持直接填写域名，默认以 https 访问；也可带协议前缀如 http://cos.example.com）,如需配置可参考 <a target="_blank" href="https://cloud.tencent.com/document/product/436/36638">官方文档</a><br>
        可使用自定义CDN域名，例如：cdn.example.com（支持直接填写域名，默认以 https 访问；也可带协议前缀如 https://cdn.example.com/static）,如需配置可参考 <a target="_blank" href="https://cloud.tencent.com/document/product/436/36637">官方文档</a>
        ')
        );
        $domain->setAttribute('class', 'joe_content joe_advanced');
        $domain->addRule(
            'regexp',
            _t('访问域名格式不正确：只允许 http(s):// 前缀 + 域名 + 可选端口/子路径，例 cos.example.com 或 https://cdn.example.com/static'),
            '/^(https?:\/\/)?(\[?[a-zA-Z0-9.\-:]+\]?)(:[0-9]+)?(\/[a-zA-Z0-9._\-\/]*)?$/'
        );

        
        $timeout = new Text('timeout', null, '5', _t('请求超时(秒)'), _t('COS API 请求总超时时间，默认 5 秒。网络较差时可适当增大'));
        $timeout->setAttribute('class', 'joe_content joe_advanced');
        $timeout->addRule('regexp', _t('超时必须是正整数'), '/^[1-9][0-9]*$/');

        $webp_enable = new Checkbox('webp_enable', [
            'open' => _t('上传图片自动转换 WebP'),
        ], $webpEnableDefault, null, _t('勾选后，上传 jpg/png 等图片时自动转换为 WebP 格式，连同原图一起上传到 COS。修改、删除时同步处理 WebP 文件。<br>优先使用 GD 库转换；GD 不支持 WebP 时自动回退到 cwebp 命令行工具（需服务器安装 <code>webp</code> 包且 PHP <code>exec()</code> 可用）。两者都不可用时自动跳过转换，不影响原图上传。'));
        $webp_enable->setAttribute('class', 'joe_content joe_advanced');

        $webp_quality = new Text(
            'webp_quality',
            null,
            '80',
            _t('WebP 转换质量（0-100）'),
            _t('WebP 压缩质量，数值越大画质越好但文件越大。推荐 75-85，默认 80。')
        );
        $webp_quality->setAttribute('class', 'joe_content joe_advanced');
        $webp_quality->addRule('regexp', _t('质量必须是 0-100 的整数'), '/^(100|[1-9]?[0-9])$/');

        $webp_formats = new Text(
            'webp_formats',
            null,
            'jpg,jpeg,png,bmp,avif,tiff,tif',
            _t('需要转换为 WebP 的格式'),
            _t('逗号分隔的扩展名列表，默认 <code>jpg,jpeg,png,bmp,avif,tiff,tif</code>。<br>支持格式：<code>jpg/jpeg/png/bmp</code>（GD 库转换）、<code>tif/tiff</code>（需 cwebp 命令行，GD 不支持 TIFF 解码）、<code>avif</code>（AVIF 原图转换为 WebP，供不支持 AVIF 的浏览器回退）。<br>建议不要包含 gif（动图转换会丢失动画）、svg（矢量图无需转换）和 webp（已是目标格式）。')
        );
        $webp_formats->setAttribute('class', 'joe_content joe_advanced');
        $webp_formats->addRule('regexp', _t('格式只能包含字母、数字、逗号和空格（大小写均可，内部统一转小写）'), '/^[a-zA-Z0-9, ]+$/');

        
        $avif_enable = new Checkbox('avif_enable', [
            'open' => _t('上传图片自动转换 AVIF'),
        ], $avifEnableDefault, null, _t('勾选后，上传 jpg/png 等图片时自动转换为 AVIF 格式，连同原图一起上传到 COS。修改、删除时同步处理 AVIF 文件。<br>AVIF 压缩率高于 WebP，需 PHP 8.1+ 且 GD 编译 <code>--with-avif</code>（依赖 libavif），或 Imagick 扩展编译 libheif（支持 TIFF 等 GD 不支持的格式）。两者都不可用时自动跳过转换，不影响原图上传。<br>AVIF 转换仅依赖 GD/Imagick，不使用命令行工具（避免 exec 被禁用时不可用）。'));
        $avif_enable->setAttribute('class', 'joe_content joe_advanced');

        $avif_quality = new Text(
            'avif_quality',
            null,
            '50',
            _t('AVIF 转换质量（0-100）'),
            _t('AVIF 压缩质量，数值越大画质越好但文件越大。AVIF 同等画质质量值低于 WebP，推荐 40-60，默认 50。')
        );
        $avif_quality->setAttribute('class', 'joe_content joe_advanced');
        $avif_quality->addRule('regexp', _t('质量必须是 0-100 的整数'), '/^(100|[1-9]?[0-9])$/');

        $avif_formats = new Text(
            'avif_formats',
            null,
            'jpg,jpeg,png,bmp,webp,tiff,tif',
            _t('需要转换为 AVIF 的格式'),
            _t('逗号分隔的扩展名列表，默认 <code>jpg,jpeg,png,bmp,webp,tiff,tif</code>。<br>支持格式：<code>jpg/jpeg/png/bmp</code>（GD 库转换）、<code>tif/tiff</code>（需 Imagick 扩展编译 libheif，GD 不支持 TIFF 解码）、<code>webp</code>（WebP 原图转换为 AVIF，AVIF 压缩率更高）。<br>建议不要包含 gif（动图转换会丢失动画）、svg（矢量图无需转换）、avif（已是目标格式）。')
        );
        $avif_formats->setAttribute('class', 'joe_content joe_advanced');
        $avif_formats->addRule('regexp', _t('格式只能包含字母、数字、逗号和空格（大小写均可，内部统一转小写）'), '/^[a-zA-Z0-9, ]+$/');

        $remote_sync = new Checkbox('remote_sync', [
            'open' => _t('本地删除同步删除COS文件'),
        ], [], null, _t('在文件管理删除文件时，同步删除 COS 上的对应文件'));
        $remote_sync->setAttribute('class', 'joe_content joe_advanced');

        $local = new Checkbox('local', [
            'open' => _t('在本地保存'),
        ], [], null, _t('在本地保存一份副本，会占用本地存储空间'));
        $local->setAttribute('class', 'joe_content joe_advanced');

        $local_sync = new Checkbox('local_sync', [
            'open' => _t('删除时同步删除本地备份'),
        ], [], null, _t('在文件管理删除文件时，同步删除本地备份的对应文件（含原图、WebP、AVIF 衍生文件），并递归清理空目录。独立于"在本地保存"开关，只要本地存在对应文件即会清理。'));
        $local_sync->setAttribute('class', 'joe_content joe_advanced');

        $form->addInput($secid);
        $form->addInput($sekey);
        $form->addInput($region);
        $form->addInput($bucket);
        $form->addInput($path);
        $form->addInput($sync_local_path);
        $form->addInput($dir_structure);
        $form->addInput($domain);
        $form->addInput($timeout);
        $form->addInput($webp_enable);
        $form->addInput($webp_quality);
        $form->addInput($webp_formats);
        $form->addInput($avif_enable);
        $form->addInput($avif_quality);
        $form->addInput($avif_formats);
        $form->addInput($remote_sync);
        $form->addInput($local);
        $form->addInput($local_sync);
        ?>
        <script>
        // 注入调试开关：仅当定义 TYPECHO_COS_DEBUG 且为真时，外部 JS 才输出 console 日志，
        // 避免生产环境配置面板 console 刷屏。
        window.__COS_DEBUG = <?php echo (defined('TYPECHO_COS_DEBUG') && TYPECHO_COS_DEBUG) ? 'true' : 'false'; ?>;
        // 外部JS加载诊断：仅记录加载状态，便于排查配置面板无样式/无交互问题
        // 修复：移除失败时动态创建 script 重试的逻辑——该逻辑可能重复加载外部 JS，
        // 且页面刷新后 version 参数变化本身即可绕过缓存，无需运行时重试。
        (function () {
            if (window.__COS_PLUGIN_JS_LOADED) {
                if (window.__COS_DEBUG) { console.log('[TECloudAttach] 外部JS加载成功'); }
            } else {
                console.warn('[TECloudAttach] 外部JS未加载成功，请检查 Network 面板与服务器 MIME 类型');
            }
        })();
        </script>
        <?php
    }
}
