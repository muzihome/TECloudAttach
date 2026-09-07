<?php

namespace TypechoPlugin\TECloudAttach;

use Typecho\Config;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Utils\Helper;
use Widget\Options;
use Widget\Upload;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 实现网站静态资源存储到云对象存储，有效降低本地存储负载，提升用户体验。
 * @package 腾讯云对象存储插件
 * @author 木子小鱼
 * @version 1.4.0
 * @link https://github.com/muzihome/TECloudAttach
 * @dependence 1.3.0-*
 * @date 2026-09-07
 */
class Plugin implements PluginInterface
{
    
    public const NAME = 'TECloudAttach';

    
    public const UPLOAD_DIR = '/usr/uploads';

    
    public const PLUGIN_VERSION = '1.4.0';

    
    public const USER_AGENT_BASE = 'typecho/1.3.0;tencentcloud-typecho-plugin-cos/' . self::PLUGIN_VERSION;

    
    private static $cachedOptionsInstance = null;

    
    public static $cachedCosSingletonClient = null;
    public static $cachedCosSingletonKey = '';

    
    public static function getSdkVersion(): string
    {
        static $version = null;
        if ($version !== null) {
            return $version;
        }
        $version = '2.6.17'; 
        try {
            if (class_exists('\\Qcloud\\Cos\\Client')) {
                $ref = new \ReflectionClass('\\Qcloud\\Cos\\Client');
                if ($ref->hasConstant('VERSION')) {
                    $v = $ref->getConstant('VERSION');
                    if (is_string($v) && $v !== '') {
                        $version = $v;
                    }
                }
            }
        } catch (\Throwable $e) {
            
        }
        return $version;
    }

    
    public static function getUserAgent(): string
    {
        return self::USER_AGENT_BASE . ';cos-php-sdk-v5/' . self::getSdkVersion();
    }

    
    public static function activate()
    {
        try {
            \Typecho\Plugin::factory(Upload::class)->uploadHandle = [__CLASS__, 'uploadHandle'];
            \Typecho\Plugin::factory(Upload::class)->modifyHandle = [__CLASS__, 'modifyHandle'];
            \Typecho\Plugin::factory(Upload::class)->deleteHandle = [__CLASS__, 'deleteHandle'];
            \Typecho\Plugin::factory(Upload::class)->attachmentHandle = [__CLASS__, 'attachmentHandle'];
            \Typecho\Plugin::factory(Upload::class)->attachmentDataHandle = [__CLASS__, 'attachmentDataHandle'];
            \Typecho\Plugin::factory('Widget_Archive')->footer = [__CLASS__, 'footer'];
        } catch (\Throwable $e) {
            return _t('COS插件激活失败：%s', $e->getMessage());
        }
        return _t('COS插件已激活，正确设置后才可正常使用哦~');
    }

    
    public static function deactivate()
    {
        return _t('插件已禁用，可能会影响文章图片/附件哦~');
    }

    
    public static function footer()
    {
        
        $avifTest = 'data:image/avif;base64,AAAAIGZ0eXBhdmlmAAAAAGF2aWZtaXNwMmNjb2xpc29tYw==';
        
        $js = <<<'JS'
<script>
(function(){
var d=document,q='img[src*=".avif"]',s='__AVIF_TEST__';
function t(c){var i=new Image;i.onload=function(){c(i.width>0)};i.onerror=function(){c(false)};i.src=s}
function r(){var imgs=d.querySelectorAll(q);for(var j=0;j<imgs.length;j++){(function(img){
var exts=['.jpg','.jpeg','.png','.gif','.bmp','.tif','.tiff'],idx=0,oldErr=img.onerror;
img.onerror=function(){var base=img.src.replace(/\.(webp|avif)(\?|$)/,'$2');function n(){if(idx>=exts.length){if(oldErr)oldErr.call(img);return}var ts=base.replace(/(\?|$)/,exts[idx]+'$1');idx++;var ti=new Image();ti.onload=function(){img.src=ts};ti.onerror=n;ti.src=ts}n()};
img.src=img.src.replace(/\.avif(\?|$)/,'.webp$1')
})(imgs[j])}}
t(function(u){if(!u){if(d.readyState==='loading')d.addEventListener('DOMContentLoaded',r);else r()}})
})();
</script>
JS;
        echo str_replace('__AVIF_TEST__', $avifTest, $js);
    }

    
    public static function config(Form $form)
    {
        ConfigPanel::render($form);
    }

    
    public static function personalConfig(Form $form)
    {
    }

    
    public static function configHandle(array $config, bool $is_init = false): void
    {
        try {
            if (!$is_init) {
                $opt = (object)$config;

                
                $bucketWarn = null;
                try {
                    $exist = Action::doesBucketExist($opt);
                    if ($exist === false) {
                        $bucketWarn = '存储桶不存在：请核对 BucketName-AppId 与地域（region）是否正确。配置已保存，但上传会失败并回退到本地上传。';
                    } elseif ($exist === null) {
                        $bucketWarn = '无法确认存储桶存在（可能凭证错误、网络不通、地域/桶名有误、或子账号缺少 headBucket 权限）。配置已保存，后续上传时会再次尝试连接，如失败会自动回退到本地上传。';
                    }
                } catch (\Throwable $e) {
                    $bucketWarn = '存储桶连通性校验异常：' . $e->getMessage() . '。配置已保存，上传时会再次尝试连接。';
                    Action::debugLog(sprintf(
                        '[configHandle] doesBucketExist raised unexpected exception: cls=%s, code=%s, msg=%s, file=%s:%d',
                        get_class($e),
                        $e->getCode(),
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    ), true);
                }
                if ($bucketWarn !== null) {
                    Action::debugLog('[configHandle] 桶校验警告(不阻断保存): ' . $bucketWarn, true);
                    self::setWarning($bucketWarn);
                }

                
                $rawLocalPath = $config['sync_local_path'] ?? '';
                if (is_array($rawLocalPath)
                    || (is_string($rawLocalPath) && (trim($rawLocalPath) === '' || strtolower(trim($rawLocalPath)) === 'array' || trim($rawLocalPath) === '.'))) {
                    $rawLocalPath = 'usr/uploads';
                } else {
                    $rawLocalPath = trim((string)$rawLocalPath);
                    
                    
                    $rawLocalPath = Action::sanitizePathSegments($rawLocalPath);
                    if ($rawLocalPath === '') {
                        $rawLocalPath = 'usr/uploads';
                    }
                }
                $config['sync_local_path'] = $rawLocalPath;
                
                $localWarn = Action::checkLocalPathWritable($rawLocalPath);
                if ($localWarn !== null) {
                    Action::debugLog('[configHandle] 本地路径警告(不阻断保存): ' . $localWarn, true);
                    self::setWarning($localWarn);
                }
            }

            Helper::configPlugin(self::NAME, $config);
            
            self::$cachedOptionsInstance = null;
            self::$cachedCosSingletonClient = null;
            self::$cachedCosSingletonKey = '';
        } catch (\Throwable $e) {
            Action::debugLog(sprintf(
                '[configHandle] FATAL: cls=%s, code=%s, msg=%s, file=%s:%d, trace=%s',
                get_class($e),
                $e->getCode(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            ), true);
            
            self::setWarning('配置保存失败：' . $e->getMessage() . '。请检查目录权限或查看日志后重试。');
        }
    }

    
    public static function getOptions()
    {
        if (self::$cachedOptionsInstance !== null) {
            return self::$cachedOptionsInstance;
        }
        try {
            $opt = Options::alloc()->plugin(self::NAME);
            
            if ($opt !== null) {
                self::$cachedOptionsInstance = $opt;
            }
            return $opt;
        } catch (\Throwable $e) {
            return null;
        }
    }

    
    private static function setWarning(string $msg): void
    {
        try {
            \Widget\Notice::alloc()->set($msg, 'warning');
        } catch (\Throwable $e) {
            Action::debugLog('[setWarning] failed: ' . $e->getMessage());
        }
    }

    

    public static function uploadHandle(array $file)
    {
        return Action::uploadHandle($file);
    }

    public static function modifyHandle(array $content, array $file)
    {
        return Action::modifyHandle($content, $file);
    }

    public static function deleteHandle(array $content): bool
    {
        return Action::deleteHandle($content);
    }

    public static function attachmentHandle(Config $attachment): string
    {
        return Action::attachmentHandle($attachment);
    }

    public static function attachmentDataHandle(array $content): string
    {
        return Action::attachmentDataHandle($content);
    }
}
