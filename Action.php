<?php

namespace TypechoPlugin\TECloudAttach;

use Typecho\Common;
use Typecho\Config;
use Typecho\Date;
use Widget\Options;
use Widget\Upload;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Action
{
    
    public static function uploadHandle(array $file)
    {
        if (empty($file['name'])) {
            return false;
        }
        $file['name'] = Image::sanitizeFileName($file['name']);
        $ext = Image::getExtension($file['name']);
        if (!Upload::checkFileType($ext)) {
            return false;
        }
        $uploadfile = self::getUploadFile($file);
        if (!isset($uploadfile)) {
            return false;
        }

        $opt = Plugin::getOptions();
        
        if (!$opt || empty($opt->secid) || empty($opt->sekey) || empty($opt->bucket) || empty($opt->region)) {
            
            $result = self::defaultUploadHandle($file, $ext, $uploadfile);
            self::recordUploadResult(
                $result !== false,
                $result !== false ? 'COS 未配置（缺少必需配置项），已回退到本地上传' : 'COS 未配置且本地上传失败',
                ['mode' => 'local_fallback', 'name' => $file['name']]
            );
            return $result;
        }

        
        $dateSubDir = self::getUploadSubDir($ext);
        $fileDir = self::getUploadDir() . ($dateSubDir !== '' ? '/' . $dateSubDir : '');
        $fileName = self::randomHex(8) . '.' . $ext;
        $path = $fileDir . '/' . $fileName;
        $cosKey = ltrim($path, '/');

        
        
        
        $mime = self::detectMime($file, $uploadfile, $path);

        $cosClient = null;
        try {
            $cosClient = self::CosInit();
            self::uploadToCos($cosClient, $opt, $cosKey, $uploadfile, $file, $mime);
        } catch (\Throwable $e) {
            self::debugLog(sprintf(
                'COS upload failed, fallback to local. Exception: %s, Msg: %s, Bucket: %s, Key: %s',
                get_class($e),
                $e->getMessage(),
                $opt->bucket ?? '',
                $cosKey ?? $path ?? ''
            ));
            
            $result = self::defaultUploadHandle($file, $ext, $uploadfile);
            self::recordUploadResult(
                $result !== false,
                $result !== false
                    ? 'COS 上传失败，已回退到本地上传：' . $e->getMessage()
                    : 'COS 上传失败且本地上传也失败：' . $e->getMessage(),
                ['mode' => 'cos_failed_local_fallback', 'name' => $file['name'], 'error' => $e->getMessage()]
            );
            return $result;
        }

        
        $webpSize = 0;
        $webpSuccess = self::handleWebpConversion($cosClient, $opt, $file, $uploadfile, $path, $ext, $webpSize);

        
        $avifSize = 0;
        $avifSuccess = self::handleAvifConversion($cosClient, $opt, $file, $uploadfile, $path, $ext, $avifSize);

        
        $returnPath = ($ext === 'avif') ? $path : ($avifSuccess ? Image::getAvifPath($path) : ($webpSuccess ? Image::getWebpPath($path) : $path));

        
        if ($ext !== 'avif' && $avifSuccess && $avifSize > 0) {
            $file['size'] = $avifSize;
        } elseif ($ext !== 'avif' && $webpSuccess && $webpSize > 0) {
            $file['size'] = $webpSize;
        } else {
            $file['size'] = self::getFileSizeSafe($file, $uploadfile, $cosClient, $opt, $cosKey);
        }

        

        
        self::saveLocalBackup($file, $uploadfile, $opt, $path);

        
        $resultType = ($ext === 'tif') ? 'tiff' : $ext;
        $result = [
            'name' => $file['name'],
            'path' => $returnPath,
            'size' => $file['size'],
            'type' => $resultType,
            'mime' => $mime ?: 'application/octet-stream'
        ];
        self::recordUploadResult(
            true,
            'COS 上传成功' . ($avifSuccess ? '（含 AVIF 转换）' : ($webpSuccess ? '（含 WebP 转换）' : '')),
            [
                'mode'   => 'cos',
                'name'   => $file['name'],
                'path'   => $returnPath,
                'size'   => $file['size'],
                'webp'   => $webpSuccess,
                'avif'   => $avifSuccess,
                'bucket' => $opt->bucket ?? '',
            ]
        );
        return $result;
    }

    
    private static function defaultUploadHandle(array $file, string $ext, $uploadfile)
    {
        $rootDir = self::getUploadRootDir();
        $uploadDir = self::getLocalUploadDir();
        $dateSubDir = self::getUploadSubDir($ext);
        $dir = $rootDir . $uploadDir . ($dateSubDir !== '' ? '/' . $dateSubDir : '');
        $relPath = $uploadDir . ($dateSubDir !== '' ? '/' . $dateSubDir : '');

        if (!is_dir($dir) && !self::makeUploadDir($dir)) {
            return false;
        }

        $fileName = self::randomHex(8) . '.' . $ext;
        $absPath = $dir . '/' . $fileName;
        $relPath .= '/' . $fileName;

        if (isset($file['tmp_name'])) {
            if (!@move_uploaded_file($uploadfile, $absPath)) {
                return false;
            }
        } elseif (isset($file['bytes'])) {
            if (!@file_put_contents($absPath, $file['bytes'])) {
                return false;
            }
        } elseif (isset($file['bits'])) {
            if (!@file_put_contents($absPath, $file['bits'])) {
                return false;
            }
        } else {
            return false;
        }

        if (!isset($file['size'])) {
            
            $file['size'] = (int)(@filesize($absPath) ?: 0);
        }

        return [
            'name' => $file['name'],
            'path' => $relPath,
            'size' => $file['size'],
            'type' => ($ext === 'tif') ? 'tiff' : $ext,
            'mime' => @Common::mimeContentType($absPath)
        ];
    }

    
    public static function modifyHandle(array $content, array $file)
    {
        if (empty($file['name'])) {
            return false;
        }
        $file['name'] = Image::sanitizeFileName($file['name']);
        $ext = Image::getExtension($file['name']);
        
        if ((string)($content['attachment']->type ?? '') !== $ext) {
            return false;
        }
        $uploadfile = self::getUploadFile($file);
        if (!isset($uploadfile)) {
            return false;
        }

        $opt = Plugin::getOptions();
        
        $relPath = (string)($content['attachment']->path ?? '');
        if ($relPath === '') {
            return false;
        }

        if (!$opt || empty($opt->secid) || empty($opt->sekey) || empty($opt->bucket) || empty($opt->region)) {
            
            $result = self::defaultModifyHandle($content, $file, $ext, $uploadfile, $relPath);
            self::recordUploadResult(
                $result !== false,
                $result !== false ? 'COS 未配置，已回退到本地修改' : 'COS 未配置且本地修改失败',
                ['mode' => 'local_fallback_modify', 'name' => $file['name'], 'path' => $relPath]
            );
            return $result;
        }

        
        
        $originalPath = $relPath;
        if (Image::getExtension($relPath) === 'webp') {
            $derived = self::deriveOriginalPathFromWebp($relPath, $content, $ext);
            if ($derived !== '') {
                $originalPath = $derived;
            }
        } elseif (Image::getExtension($relPath) === 'avif') {
            
            $derived = self::deriveOriginalPathFromAvif($relPath, $content, $ext);
            if ($derived !== '') {
                $originalPath = $derived;
            }
        }

        $cosKey = ltrim($originalPath, '/');
        
        $mime = self::detectMime($file, $uploadfile, $originalPath);
        $cosClient = null;
        try {
            $cosClient = self::CosInit();
            self::uploadToCos($cosClient, $opt, $cosKey, $uploadfile, $file, $mime);
        } catch (\Throwable $e) {
            self::debugLog(sprintf(
                'COS modify failed, fallback to local. Exception: %s, Msg: %s, Key: %s',
                get_class($e),
                $e->getMessage(),
                $cosKey ?: $relPath
            ));
            
            $result = self::defaultModifyHandle($content, $file, $ext, $uploadfile, $relPath);
            self::recordUploadResult(
                $result !== false,
                $result !== false
                    ? 'COS 修改失败，已回退到本地：' . $e->getMessage()
                    : 'COS 修改失败且本地修改也失败：' . $e->getMessage(),
                ['mode' => 'cos_failed_local_fallback_modify', 'name' => $file['name'], 'path' => $relPath, 'error' => $e->getMessage()]
            );
            return $result;
        }

        
        $webpSize = 0;
        $webpSuccess = self::handleWebpConversion($cosClient, $opt, $file, $uploadfile, $originalPath, $ext, $webpSize);

        
        $avifSize = 0;
        $avifSuccess = self::handleAvifConversion($cosClient, $opt, $file, $uploadfile, $originalPath, $ext, $avifSize);

        
        $returnPath = ($ext === 'avif') ? $originalPath : ($avifSuccess ? Image::getAvifPath($originalPath) : ($webpSuccess ? Image::getWebpPath($originalPath) : $originalPath));

        
        if ($ext !== 'avif' && $avifSuccess && $avifSize > 0) {
            $file['size'] = $avifSize;
        } elseif ($ext !== 'avif' && $webpSuccess && $webpSize > 0) {
            $file['size'] = $webpSize;
        } else {
            $file['size'] = self::getFileSizeSafe($file, $uploadfile, $cosClient, $opt, $cosKey);
        }

        
        self::saveLocalBackup($file, $uploadfile, $opt, $originalPath);

        
        $result = [
            'name' => $content['attachment']->name,
            'path' => $returnPath,
            'size' => $file['size'],
            'type' => $content['attachment']->type,
            'mime' => $mime ?: ($content['attachment']->mime ?? 'application/octet-stream')
        ];
        self::recordUploadResult(
            true,
            'COS 文件修改成功' . ($avifSuccess ? '（含 AVIF 转换）' : ($webpSuccess ? '（含 WebP 转换）' : '')),
            [
                'mode'   => 'cos_modify',
                'name'   => $content['attachment']->name,
                'path'   => $returnPath,
                'size'   => $file['size'],
                'webp'   => $webpSuccess,
                'avif'   => $avifSuccess,
                'bucket' => $opt->bucket ?? '',
            ]
        );
        return $result;
    }

    
    private static function defaultModifyHandle(array $content, array $file, string $ext, $uploadfile, string $relPath)
    {
        
        if ((string)($content['attachment']->type ?? '') !== $ext) {
            return false;
        }
        $rootDir = self::getUploadRootDir();
        
        
        
        if (Image::getExtension($relPath) === 'webp') {
            $derived = self::deriveOriginalPathFromWebp($relPath, $content, $ext);
            if ($derived !== '') {
                $relPath = $derived;
            }
        } elseif (Image::getExtension($relPath) === 'avif') {
            
            $derived = self::deriveOriginalPathFromAvif($relPath, $content, $ext);
            if ($derived !== '') {
                $relPath = $derived;
            }
        }
        $absPath = $rootDir . $relPath;
        $dir = dirname($absPath);
        if (!is_dir($dir) && !self::makeUploadDir($dir)) {
            return false;
        }

        $tmp = $absPath . '.tmp_' . self::randomHex(4);
        if (isset($file['tmp_name'])) {
            if (!@move_uploaded_file($uploadfile, $tmp)) {
                @unlink($tmp);
                return false;
            }
        } elseif (isset($file['bytes'])) {
            if (@file_put_contents($tmp, $file['bytes']) === false) {
                @unlink($tmp);
                return false;
            }
        } elseif (isset($file['bits'])) {
            if (@file_put_contents($tmp, $file['bits']) === false) {
                @unlink($tmp);
                return false;
            }
        } else {
            return false;
        }
        
        
        
        if (!@rename($tmp, $absPath)) {
            @unlink($tmp); 
            self::debugLog('defaultModifyHandle rename failed, old file preserved: ' . $tmp . ' -> ' . $absPath);
            return false;
        }
        if (!isset($file['size'])) {
            $file['size'] = (int)(@filesize($absPath) ?: 0);
        }

        return [
            'name' => $content['attachment']->name,
            'path' => $relPath,
            'size' => $file['size'],
            'type' => $content['attachment']->type,
            'mime' => $content['attachment']->mime
        ];
    }

    
    public static function deleteHandle(array $content): bool
    {
        $relPath = $content['attachment']->path ?? '';
        if ($relPath === '') {
            return true;
        }
        $opt = Plugin::getOptions();

        if (!$opt || empty($opt->secid) || empty($opt->sekey) || empty($opt->bucket) || empty($opt->region)) {
            
            
            self::deleteLocalFileCandidates($relPath, $content);
            return true;
        }

        $localSyncEnabled = self::isEnabled($opt, 'local_sync');
        if ($localSyncEnabled) {
            
            
            if (!self::deleteLocalFileCandidates($relPath, $content)) {
                
                self::debugLog('[deleteHandle] 本地备份部分删除失败，需手动清理残留: ' . $relPath, true);
                self::notifyUser('本地备份部分文件删除失败，请检查目录权限后手动清理残留。');
            }
        }

        $cosDeleted = true;
        if (self::isEnabled($opt, 'remote_sync')) {
            try {
                $cosClient = self::CosInit();
                $deleteKey = ltrim($relPath, '/');
                self::cosCallWithRetry(function () use ($cosClient, $opt, $deleteKey) {
                    $cosClient->deleteObject(['Bucket' => $opt->bucket, 'Key' => $deleteKey]);
                }, 1, 'delete');

                
                
                
                
                
                
                $deleteCosKey = static function (string $key, string $label) use ($cosClient, $opt): void {
                    $trimmed = ltrim($key, '/');
                    if ($trimmed === '') {
                        return;
                    }
                    try {
                        self::cosCallWithRetry(static function () use ($cosClient, $opt, $trimmed): void {
                            $cosClient->deleteObject(['Bucket' => $opt->bucket, 'Key' => $trimmed]);
                        }, 1, $label);
                    } catch (\Throwable $e) {
                        self::debugLog('[' . $label . '] delete failed (non-fatal): ' . $e->getMessage());
                    }
                };

                $ext = Image::getExtension($relPath);
                if ($ext === 'avif') {
                    
                    $deleteCosKey(Image::getWebpPath($relPath), 'AVIF->webp(relPath)');
                    $origPath = self::deriveOriginalPathFromAvif($relPath, $content);
                    if ($origPath !== '' && $origPath !== $relPath) {
                        
                        $deleteCosKey($origPath, 'AVIF->original');
                        $deleteCosKey(Image::getWebpPath($origPath), 'AVIF->webp(orig)');
                    }
                } elseif ($ext === 'webp') {
                    
                    $deleteCosKey(Image::getAvifPath($relPath), 'WebP->avif(relPath)');
                    $origPath = self::deriveOriginalPathFromWebp($relPath, $content);
                    if ($origPath !== '' && $origPath !== $relPath) {
                        
                        $deleteCosKey($origPath, 'WebP->original');
                        $deleteCosKey(Image::getAvifPath($origPath), 'WebP->avif(orig)');
                    }
                } elseif (Image::getMediaType($ext) === 'images' && !in_array($ext, ['gif', 'svg', 'ico'], true)) {
                    
                    $deleteCosKey(Image::getWebpPath($relPath), 'WebP');
                    $deleteCosKey(Image::getAvifPath($relPath), 'AVIF');
                }
            } catch (\Throwable $e) {
                self::debugLog('COS deleteObject failed (non-fatal): ' . $e->getMessage());
                $cosDeleted = false;
            }
        }

        if (!$cosDeleted) {
            self::debugLog(sprintf('[deleteHandle] COS 对象残留需手动清理：bucket=%s key=%s', $opt->bucket ?? '', ltrim($relPath, '/')), true);
            
            self::notifyUser('删除附件时 COS 对象清理失败，请到腾讯云 COS 控制台手动删除残留对象：' . ltrim($relPath, '/'));
        }

        return true;
    }

    
    private static function deleteLocalFileCandidates(string $relPath, array $content): bool
    {
        $rootDir = self::getUploadRootDir();
        $deleted = true;
        
        $relList = [$relPath];
        $ext = Image::getExtension($relPath);
        if ($ext === 'avif') {
            $origPath = self::deriveOriginalPathFromAvif($relPath, $content);
            if ($origPath !== '') {
                $relList[] = $origPath;
                $webpPath = Image::getWebpPath($origPath);
                if ($webpPath !== $origPath) {
                    $relList[] = $webpPath;
                }
            } else {
                $webpPath = Image::getWebpPath($relPath);
                if ($webpPath !== $relPath) {
                    $relList[] = $webpPath;
                }
            }
        } elseif ($ext === 'webp') {
            $origPath = self::deriveOriginalPathFromWebp($relPath, $content);
            if ($origPath !== '') {
                $relList[] = $origPath;
                $avifPath = Image::getAvifPath($origPath);
                if ($avifPath !== $origPath) {
                    $relList[] = $avifPath;
                }
            } else {
                $avifPath = Image::getAvifPath($relPath);
                if ($avifPath !== $relPath) {
                    $relList[] = $avifPath;
                }
            }
        } elseif (Image::getMediaType($ext) === 'images' && !in_array($ext, ['gif', 'svg', 'ico'], true)) {
            $webpPath = Image::getWebpPath($relPath);
            if ($webpPath !== $relPath) {
                $relList[] = $webpPath;
            }
            
            $avifPath = Image::getAvifPath($relPath);
            if ($avifPath !== $relPath) {
                $relList[] = $avifPath;
            }
        }
        $deletedPaths = []; 
        foreach ($relList as $r) {
            
            $p1 = $rootDir . $r;
            if (file_exists($p1)) {
                if (@unlink($p1)) {
                    $deletedPaths[] = $p1;
                } else {
                    $deleted = false;
                    self::debugLog('[deleteLocalFileCandidates] unlink failed: ' . $p1, true);
                }
            }
            
            $localRel = self::toLocalBackupPath($r);
            if ($localRel !== '' && $localRel !== $r) {
                $p2 = $rootDir . $localRel;
                if (file_exists($p2)) {
                    if (@unlink($p2)) {
                        $deletedPaths[] = $p2;
                    } else {
                        $deleted = false;
                        self::debugLog('[deleteLocalFileCandidates] unlink failed: ' . $p2, true);
                    }
                }
            }
        }
        
        
        $localRoot = rtrim($rootDir, '/\\') . self::getLocalUploadDir();
        foreach ($deletedPaths as $abs) {
            self::removeEmptyDirsUpward($abs, $localRoot);
        }
        return $deleted;
    }

    
    private static function deriveOriginalPathFromWebp(string $relPath, array $content, string $fallbackExt = ''): string
    {
        $origName = (string)($content['attachment']->name ?? '');
        $origExt = $origName !== '' ? strtolower(pathinfo($origName, PATHINFO_EXTENSION)) : '';
        if (($origExt === '' || $origExt === 'webp') && $fallbackExt !== '') {
            $origExt = strtolower($fallbackExt);
        }
        if ($origExt === '' || $origExt === 'webp') {
            return '';
        }
        $origPath = preg_replace('/\.webp$/', '.' . $origExt, $relPath);
        return ($origPath !== $relPath) ? $origPath : '';
    }

    
    private static function removeEmptyDirsUpward(string $fileAbsPath, string $rootDir): void
    {
        $dir = dirname($fileAbsPath);
        $rootDir = rtrim($rootDir, '/\\');
        
        $dir = str_replace('\\', '/', $dir);
        $rootDir = str_replace('\\', '/', $rootDir);

        
        while ($dir !== $rootDir && strpos($dir, $rootDir . '/') === 0) {
            if (!is_dir($dir)) {
                break; 
            }
            $items = @scandir($dir);
            if ($items === false || count($items) > 2) {
                break; 
            }
            if (!@rmdir($dir)) {
                self::debugLog('[removeEmptyDirs] rmdir failed: ' . $dir, true);
                break; 
            }
            self::debugLog('[removeEmptyDirs] removed empty dir: ' . $dir);
            $dir = dirname($dir);
        }
    }

    

    
    private static function handleWebpConversion($cosClient, $opt, array $file, $uploadfile, string $path, string $ext, ?int &$webpSize = null): bool
    {
        if (!Image::isConvertibleImage($ext, $opt)) {
            return false;
        }
        $srcPath = Image::getSourceFilePath($file, $uploadfile);
        if ($srcPath === null) {
            self::debugLog('[WebP] skipped: source file path null');
            return false;
        }
        $srcIsTmp = ($srcPath !== ($file['tmp_name'] ?? ''));
        $webpTmpPath = Image::tempPath('cos_webp_', 'webp');
        $quality = max(0, min(100, (int)($opt->webp_quality ?? 80)));

        $success = false;
        
        
        try {
            $converted = Image::convertToWebp($srcPath, $webpTmpPath, $quality);
        } catch (\Throwable $e) {
            $converted = false;
            self::debugLog('[WebP] convert exception: ' . $e->getMessage(), true);
        }
        if ($converted) {
            $webpRelPath = Image::getWebpPath($path);
            if (Image::uploadWebpToCos($cosClient, $opt, $webpRelPath, $webpTmpPath)) {
                $success = true;
                
                if ($webpSize !== null) {
                    $webpSize = (int)(@filesize($webpTmpPath) ?: 0);
                }
                
                
                self::saveWebpLocalBackup($opt, $webpRelPath, $webpTmpPath);
            } else {
                self::debugLog('[WebP] upload to COS failed. path=' . $path, true);
            }
        } else {
            self::debugLog('[WebP] convertToWebp returned false, ext=' . $ext . ' src=' . $srcPath, true);
        }

        @unlink($webpTmpPath);
        if ($srcIsTmp) {
            @unlink($srcPath);
        }
        return $success;
    }

    
    private static function detectMime(array $file, $uploadfile, string $path)
    {
        if (isset($file['tmp_name']) && is_string($uploadfile) && file_exists($uploadfile)) {
            $mime = @Common::mimeContentType($uploadfile);
            if ($mime && $mime !== 'application/octet-stream') {
                return $mime;
            }
        } elseif (is_string($uploadfile) && $uploadfile !== '') {
            $mimeTmp = Image::tempPath('cos_mime_', 'tmp');
            if (@file_put_contents($mimeTmp, $uploadfile) !== false) {
                $mime = @Common::mimeContentType($mimeTmp);
                @unlink($mimeTmp);
                if ($mime && $mime !== 'application/octet-stream') {
                    return $mime;
                }
            }
            @unlink($mimeTmp);
        }
        $ext = Image::getExtension($path);
        if ($ext !== '') {
            $extToMime = [
                'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
                'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'avif' => 'image/avif',
                'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'heic' => 'image/heic',
                'heif' => 'image/heif',
                'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
                'avi' => 'video/x-msvideo', 'flv' => 'video/x-flv', 'wmv' => 'video/x-ms-wmv',
                'mkv' => 'video/x-matroska', 'm4v' => 'video/x-m4v', 'mpg' => 'video/mpeg',
                'mpeg' => 'video/mpeg', '3gp' => 'video/3gpp', 'rmvb' => 'application/vnd.rn-realmedia-vbr',
                'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
                'oga' => 'audio/ogg', 'm4a' => 'audio/mp4', 'wma' => 'audio/x-ms-wma',
                'flac' => 'audio/flac', 'aac' => 'audio/aac', 'opus' => 'audio/opus',
                'ape' => 'audio/ape', 'mid' => 'audio/midi', 'midi' => 'audio/midi',
                'pdf' => 'application/pdf', 'zip' => 'application/zip',
                'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed',
                'tar' => 'application/x-tar', 'gz' => 'application/gzip',
                'bz2' => 'application/x-bzip2', 'xz' => 'application/x-xz',
                'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'txt' => 'text/plain', 'md' => 'text/markdown', 'rtf' => 'application/rtf',
                'csv' => 'text/csv', 'epub' => 'application/epub+zip', 'mobi' => 'application/x-mobipocket-ebook',
                'json' => 'application/json', 'xml' => 'application/xml',
                'html' => 'text/html', 'htm' => 'text/html',
                'css' => 'text/css', 'js' => 'application/javascript',
                'ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
            ];
            if (isset($extToMime[$ext])) {
                return $extToMime[$ext];
            }
        }
        return false;
    }

    
    private static function getFileSizeSafe(array $file, $uploadfile, $cosClient, $opt, string $cosKey): int
    {
        if (isset($file['size'])) {
            return (int)$file['size'];
        }
        
        if (is_string($uploadfile) && file_exists($uploadfile)) {
            $size = @filesize($uploadfile);
            if ($size !== false) {
                return $size;
            }
        }
        
        try {
            if ($cosClient) {
                
                
                $response = $cosClient->headObject(['Bucket' => $opt->bucket, 'Key' => $cosKey]);
                if (method_exists($response, 'getHeaderLine')) {
                    $len = $response->getHeaderLine('Content-Length');
                    if ($len !== '') {
                        return (int)$len;
                    }
                }
                if (method_exists($response, 'toArray')) {
                    $fileInfo = $response->toArray();
                    return (int)($fileInfo['ContentLength'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            self::debugLog('headObject failed: ' . $e->getMessage());
        }
        return 0;
    }

    
    private static function toLocalBackupPath(string $path): string
    {
        $cosDir = self::getUploadDir();
        $localDir = self::getLocalUploadDir();
        $trimmed = ltrim($path, '/');
        if ($trimmed === '') {
            return '';
        }
        if ($cosDir !== '' && $cosDir === $localDir) {
            return '/' . $trimmed;
        }
        if ($cosDir !== '') {
            $prefix = ltrim($cosDir, '/');
            if ($prefix !== '' && strpos($trimmed, $prefix . '/') === 0) {
                $trimmed = substr($trimmed, strlen($prefix) + 1);
            } elseif ($prefix !== '' && $trimmed === $prefix) {
                $trimmed = '';
            }
        }
        
        
        
        $localPrefix = ltrim($localDir, '/');
        if ($localPrefix !== '' && $trimmed !== '' && strpos($trimmed, $localPrefix . '/') === 0) {
            return '/' . $trimmed;
        }
        if ($localPrefix !== '' && $trimmed === $localPrefix) {
            return '/' . $localPrefix;
        }
        return rtrim($localDir, '/') . ($trimmed !== '' ? '/' . $trimmed : '');
    }

    
    private static function saveLocalBackup(array $file, $uploadfile, $opt, string $path): void
    {
        if (!self::isEnabled($opt, 'local')) {
            return;
        }
        $rootDir = self::getUploadRootDir();
        
        
        $localRel = self::toLocalBackupPath($path);
        if ($localRel === '') {
            return;
        }
        $localDir = $rootDir . dirname($localRel);
        $localPath = $rootDir . $localRel;
        if (!self::makeUploadDir($localDir)) {
            return;
        }
        
        
        
        $tmpPath = $localPath . '.tmp_' . self::randomHex(4);
        $ok = false;
        if (isset($file['tmp_name'])) {
            $ok = @move_uploaded_file($uploadfile, $tmpPath);
        } elseif (isset($file['bytes']) || isset($file['bits'])) {
            $ok = @file_put_contents($tmpPath, $file['bytes'] ?? $file['bits']) !== false;
        }
        if (!$ok) {
            @unlink($tmpPath);
            self::debugLog('[saveLocalBackup] failed to write local backup: ' . $localPath, true);
            return;
        }
        
        
        
        
        if (!@rename($tmpPath, $localPath)) {
            
            
            @unlink($tmpPath);
            self::debugLog('[saveLocalBackup] rename failed, old backup preserved: ' . $localPath, true);
        }
    }

    
    private static function saveWebpLocalBackup($opt, string $webpRelPath, string $webpTmpPath): void
    {
        if (!self::isEnabled($opt, 'local')) {
            return;
        }
        if (!file_exists($webpTmpPath)) {
            return;
        }
        $rootDir = self::getUploadRootDir();
        $localRel = self::toLocalBackupPath($webpRelPath);
        if ($localRel === '') {
            return;
        }
        $localPath = $rootDir . $localRel;
        $localDir = dirname($localPath);
        if (!self::makeUploadDir($localDir)) {
            return;
        }
        $tmpPath = $localPath . '.tmp_' . self::randomHex(4);
        if (@copy($webpTmpPath, $tmpPath)) {
            if (!@rename($tmpPath, $localPath)) {
                @unlink($tmpPath);
                self::debugLog('[saveWebpLocalBackup] rename failed: ' . $localPath, true);
            }
        } else {
            @unlink($tmpPath);
            self::debugLog('[saveWebpLocalBackup] copy failed: ' . $localPath, true);
        }
    }

    

    
    private static function handleAvifConversion($cosClient, $opt, array $file, $uploadfile, string $path, string $ext, ?int &$avifSize = null): bool
    {
        if (!Image::isConvertibleAvif($ext, $opt)) {
            return false;
        }
        $srcPath = Image::getSourceFilePath($file, $uploadfile);
        if ($srcPath === null) {
            self::debugLog('[AVIF] skipped: source file path null');
            return false;
        }
        $srcIsTmp = ($srcPath !== ($file['tmp_name'] ?? ''));
        $avifTmpPath = Image::tempPath('cos_avif_', 'avif');
        $quality = max(0, min(100, (int)($opt->avif_quality ?? 50)));

        $success = false;
        try {
            $converted = Image::convertToAvif($srcPath, $avifTmpPath, $quality);
        } catch (\Throwable $e) {
            $converted = false;
            self::debugLog('[AVIF] convert exception: ' . $e->getMessage(), true);
        }
        if ($converted) {
            $avifRelPath = Image::getAvifPath($path);
            if (Image::uploadAvifToCos($cosClient, $opt, $avifRelPath, $avifTmpPath)) {
                $success = true;
                if ($avifSize !== null) {
                    $avifSize = (int)(@filesize($avifTmpPath) ?: 0);
                }
                
                self::saveAvifLocalBackup($opt, $avifRelPath, $avifTmpPath);
            } else {
                self::debugLog('[AVIF] upload to COS failed. path=' . $path, true);
            }
        } else {
            self::debugLog('[AVIF] convertToAvif returned false, ext=' . $ext . ' src=' . $srcPath, true);
        }

        @unlink($avifTmpPath);
        if ($srcIsTmp) {
            @unlink($srcPath);
        }
        return $success;
    }

    
    private static function saveAvifLocalBackup($opt, string $avifRelPath, string $avifTmpPath): void
    {
        if (!self::isEnabled($opt, 'local')) {
            return;
        }
        if (!file_exists($avifTmpPath)) {
            return;
        }
        $rootDir = self::getUploadRootDir();
        $localRel = self::toLocalBackupPath($avifRelPath);
        if ($localRel === '') {
            return;
        }
        $localPath = $rootDir . $localRel;
        $localDir = dirname($localPath);
        if (!self::makeUploadDir($localDir)) {
            return;
        }
        $tmpPath = $localPath . '.tmp_' . self::randomHex(4);
        if (@copy($avifTmpPath, $tmpPath)) {
            if (!@rename($tmpPath, $localPath)) {
                @unlink($tmpPath);
                self::debugLog('[saveAvifLocalBackup] rename failed: ' . $localPath, true);
            }
        } else {
            @unlink($tmpPath);
            self::debugLog('[saveAvifLocalBackup] copy failed: ' . $localPath, true);
        }
    }

    
    private static function deriveOriginalPathFromAvif(string $relPath, array $content, string $fallbackExt = ''): string
    {
        $origName = (string)($content['attachment']->name ?? '');
        $origExt = $origName !== '' ? strtolower(pathinfo($origName, PATHINFO_EXTENSION)) : '';
        if (($origExt === '' || $origExt === 'avif') && $fallbackExt !== '') {
            $origExt = strtolower($fallbackExt);
        }
        if ($origExt === '' || $origExt === 'avif') {
            return '';
        }
        $origPath = preg_replace('/\.avif$/', '.' . $origExt, $relPath);
        return ($origPath !== $relPath) ? $origPath : '';
    }

    

    public static function attachmentHandle(Config $attachment): string
    {
        $path = (string)($attachment->path ?? '');
        
        if (Image::getExtension($path) === 'avif' && !self::clientSupportsAvif()) {
            $path = self::getAvifFallbackPath($path, $attachment);
        }
        return self::resolveAttachmentUrl($path);
    }

    
    private static function clientSupportsAvif(): bool
    {
        
        
        $accept = '';
        try {
            $request = \Widget\Request::getInstance();
            if ($request !== null) {
                $accept = (string)$request->getHeader('accept');
            }
        } catch (\Throwable $e) {
            
            $accept = '';
        }
        if ($accept === '') {
            return true;
        }
        return stripos($accept, 'image/avif') !== false;
    }

    
    private static function getAvifFallbackPath(string $avifPath, $attachment): string
    {
        
        
        $origName = (string)($attachment->name ?? '');
        $origExt = $origName !== '' ? strtolower(pathinfo($origName, PATHINFO_EXTENSION)) : '';
        if ($origExt !== '' && $origExt !== 'avif') {
            $origPath = preg_replace('/\.avif$/', '.' . $origExt, $avifPath);
            if ($origPath !== $avifPath) {
                return $origPath;
            }
        }
        
        return Image::getWebpPath($avifPath);
    }

    public static function attachmentDataHandle(array $content): string
    {
        $path = (string)($content['attachment']->path ?? '');
        if ($path === '') {
            return '';
        }
        $opt = Plugin::getOptions();
        
        
        
        if ($opt && !empty($opt->secid) && !empty($opt->sekey) && !empty($opt->bucket) && !empty($opt->region)) {
            try {
                $cosClient = self::CosInit();
                $result = $cosClient->getObject([
                    'Bucket' => $opt->bucket,
                    'Key'    => ltrim($path, '/'),
                ]);
                if (isset($result['Body'])) {
                    $body = $result['Body'];
                    
                    if (is_object($body) && method_exists($body, 'eof') && method_exists($body, 'read')) {
                        $data = '';
                        while (!$body->eof()) {
                            $chunk = $body->read(1048576); 
                            if ($chunk === '' || $chunk === false) {
                                break;
                            }
                            $data .= $chunk;
                            
                            if (strlen($data) > 104857600) {
                                self::debugLog('[attachmentDataHandle] 附件超过 100MB，放弃整读', true);
                                return '';
                            }
                        }
                        return $data;
                    }
                    return (string)$body;
                }
            } catch (\Throwable $e) {
                self::debugLog('[attachmentDataHandle] COS getObject failed, fallback local: ' . $e->getMessage());
            }
        }
        $absPath = self::getUploadRootDir() . $path;
        if (file_exists($absPath) && is_readable($absPath)) {
            $data = @file_get_contents($absPath);
            if ($data !== false) {
                return $data;
            }
        }
        return '';
    }

    
    private static function notifyUser(string $message): void
    {
        try {
            if (class_exists('\Widget\Notice')) {
                \Widget\Notice::alloc()->set($message, 'error');
            }
        } catch (\Throwable $e) {
            
        }
    }

    
    private static function resolveAttachmentUrl(string $path): string
    {
        $fallbackUrl = self::makeLocalAttachmentUrl($path);
        $opt = Plugin::getOptions();
        $hasDomain = $opt && !empty($opt->domain);
        
        
        if (!$opt || empty($opt->secid) || empty($opt->sekey) || empty($opt->bucket)) {
            return $fallbackUrl;
        }
        if (!$hasDomain && empty($opt->region)) {
            return $fallbackUrl;
        }
        try {
            return self::buildObjectUrl($opt, $path);
        } catch (\Throwable $e) {
            self::debugLog('[resolveAttachmentUrl] failed, fallback: ' . $e->getMessage());
            return $fallbackUrl;
        }
    }

    private static function buildObjectUrl($opt, string $path): string
    {
        $cosKey = ltrim($path, '/');
        if ($cosKey === '') {
            throw new \RuntimeException('empty path');
        }

        
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $cosKey)));

        if (!empty($opt->domain)) {
            $domain = trim((string)$opt->domain);
            
            if (preg_match('#^https?://#i', $domain)) {
                return rtrim($domain, '/') . '/' . $encodedKey;
            }
            return 'https://' . self::normalizeDomain($domain) . '/' . $encodedKey;
        }
        $defaultCosDomain = $opt->bucket . '.cos.' . $opt->region . '.myqcloud.com';
        return 'https://' . $defaultCosDomain . '/' . $encodedKey;
    }

    private static function makeLocalAttachmentUrl(string $path): string
    {
        $siteUrl = '';
        try {
            $options = Options::alloc();
            $siteUrl = (string)($options->siteUrl ?? '');
            $baseUrl = defined('__TYPECHO_UPLOAD_URL__') ? __TYPECHO_UPLOAD_URL__ : $siteUrl;
            $url = Common::url($path, $baseUrl);
        } catch (\Throwable $e) {
            $url = '';
        }
        if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false) {
            return $url;
        }
        
        if ($siteUrl !== '' && ($siteHost = parse_url($siteUrl, PHP_URL_HOST))) {
            $siteScheme = parse_url($siteUrl, PHP_URL_SCHEME) ?: 'https';
            $sitePort = parse_url($siteUrl, PHP_URL_PORT);
            $portStr = $sitePort ? ':' . $sitePort : '';
            return $siteScheme . '://' . $siteHost . $portStr . '/' . ltrim($path, '/');
        }
        
        if (isset($_SERVER['HTTP_HOST']) && preg_match('/^\[?[a-zA-Z0-9.\-:]+\]?(:[0-9]+)?$/', $_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $scheme . '://' . $_SERVER['HTTP_HOST'] . '/' . ltrim($path, '/');
        }
        return 'https://localhost/' . ltrim($path, '/');
    }

    

    public static function CosInit($options = null)
    {
        if (!$options) {
            $options = Plugin::getOptions();
            if (!$options) {
                throw new \RuntimeException('插件未配置');
            }
        }
        if (empty($options->secid) || empty($options->sekey)) {
            throw new \RuntimeException('SecretId/SecretKey 不能为空');
        }
        if (empty($options->region)) {
            throw new \RuntimeException('地域不能为空');
        }

        
        $sekeyFingerprint = md5((string)($options->sekey ?? ''));
        $cacheKey = md5($options->secid . '|' . $sekeyFingerprint . '|' . ($options->region ?? '') . '|' . ($options->bucket ?? ''));
        if (Plugin::$cachedCosSingletonClient !== null && Plugin::$cachedCosSingletonKey === $cacheKey) {
            return Plugin::$cachedCosSingletonClient;
        }

        self::loadSdk();

        $timeout = (float)($options->timeout ?? 5.0);
        $client = new \Qcloud\Cos\Client([
            'region'      => $options->region,
            'schema'       => 'https',
            'credentials'  => ['secretId' => $options->secid, 'secretKey' => $options->sekey],
            
            'userAgent'    => Plugin::getUserAgent(),
            
            'connect_timeout' => 3.0,
            'timeout'      => $timeout,
        ]);

        Plugin::$cachedCosSingletonClient = $client;
        Plugin::$cachedCosSingletonKey = $cacheKey;
        return $client;
    }

    private static function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);
        if (stripos($domain, 'https://') === 0) {
            $domain = substr($domain, 8);
        } elseif (stripos($domain, 'http://') === 0) {
            $domain = substr($domain, 7);
        }
        return rtrim($domain, '/');
    }

    
    public static function loadSdk(): void
    {
        static $loaded = false;
        static $lastErr = null;
        if ($loaded) {
            return;
        }
        if ($lastErr !== null) {
            throw new \RuntimeException($lastErr);
        }

        $pharPath = __DIR__ . '/phar/cos-sdk-v5-7.phar';
        $autoload = 'phar://' . $pharPath . '/vendor/autoload.php';
        if (!file_exists($pharPath)) {
            $lastErr = 'COS SDK phar 文件不存在: ' . $pharPath;
            self::debugLog('[loadSdk] ' . $lastErr, true);
            throw new \RuntimeException($lastErr);
        }
        if (!extension_loaded('phar')) {
            $lastErr = 'PHP 未启用 phar 扩展';
            self::debugLog('[loadSdk] ' . $lastErr, true);
            throw new \RuntimeException($lastErr);
        }

        $includeError = null;
        
        set_error_handler(static function ($severity, $message) use (&$includeError) {
            if ($severity & (E_WARNING | E_ERROR | E_PARSE | E_CORE_WARNING | E_COMPILE_WARNING)) {
                $includeError = $message;
            }
        });
        try {
            $includeOk = include_once $autoload;
        } finally {
            restore_error_handler();
        }

        if ($includeOk === false || $includeError !== null) {
            $lastErr = 'COS SDK autoload 加载失败: ' . ($includeError ?: 'include_once returned false');
            self::debugLog('[loadSdk] ' . $lastErr, true);
            throw new \RuntimeException($lastErr);
        }
        if (!class_exists('\\Qcloud\\Cos\\Client')) {
            $lastErr = 'COS SDK 已加载但类不存在，可能 phar 损坏';
            self::debugLog('[loadSdk] ' . $lastErr, true);
            throw new \RuntimeException($lastErr);
        }
        $loaded = true;
    }

    

    public static function debugLog(string $msg, bool $force = false): void
    {
        Logger::log($msg, $force);
    }

    
    public static function isEnabled($opt, string $key): bool
    {
        if (!$opt) {
            return false;
        }
        $val = $opt->$key ?? null;
        if (is_array($val)) {
            return in_array('open', $val, true);
        }
        return $val === 'open';
    }

    

    
    private static function cosCallWithRetry(callable $fn, int $retries = 1, string $label = 'cos'): void
    {
        for ($i = 0; $i <= $retries; $i++) {
            try {
                $fn();
                return;
            } catch (\Throwable $e) {
                
                
                if ($i >= $retries || !self::isTransientError($e)) {
                    throw $e;
                }
                self::debugLog(sprintf('[COS] %s 失败，第 %d 次重试: %s', $label, $i + 1, $e->getMessage()));
                usleep(200000);
            }
        }
    }

    
    public static function isTransientError(\Throwable $e): bool
    {
        if ($e instanceof \GuzzleHttp\Exception\ConnectException) {
            return true;
        }
        if ($e instanceof \GuzzleHttp\Exception\RequestException) {
            $response = $e->getResponse();
            if ($response) {
                $code = (int)$response->getStatusCode();
                return $code >= 500 || $code === 429;
            }
            
            return true;
        }
        if ($e instanceof \Qcloud\Cos\Exception\ServiceResponseException) {
            $code = (int)$e->getStatusCode();
            return $code >= 500 || $code === 429;
        }
        
        return false;
    }

    
    private static function cosUploadHeaders(string $contentType = ''): array
    {
        return Image::cosUploadHeaders($contentType);
    }

    
    private static function uploadToCos($cosClient, $opt, string $cosKey, $uploadfile, array $file, $mime = false): void
    {
        $fileHandle = null;
        $body = null;
        if (isset($file['tmp_name'])) {
            $fileHandle = @fopen($uploadfile, 'rb');
            if ($fileHandle === false) {
                throw new \RuntimeException('打开上传临时文件失败: ' . $uploadfile);
            }
            $body = $fileHandle;
        } else {
            $body = $uploadfile;
        }
        try {
            self::cosCallWithRetry(function () use ($cosClient, $opt, $cosKey, $body, $mime) {
                if (is_resource($body)) {
                    @rewind($body);
                }
                $cosClient->upload($opt->bucket, $cosKey, $body, self::cosUploadHeaders($mime ? (string)$mime : ''));
            }, 1, 'upload');
        } finally {
            if (is_resource($fileHandle)) {
                @fclose($fileHandle);
            }
        }
    }

    

    public static function doesBucketExist($opt): ?bool
    {
        $checkOpt = (object)[
            'secid' => $opt->secid ?? '', 'sekey' => $opt->sekey ?? '',
            'region' => $opt->region ?? '', 'bucket' => $opt->bucket ?? '', 'domain' => '',
        ];
        try {
            $cosClient = self::CosInit($checkOpt);
        } catch (\Throwable $e) {
            self::debugLog('doesBucketExist CosInit failed: ' . $e->getMessage());
            return null;
        }
        try {
            return $cosClient->doesBucketExist($opt->bucket) ? true : false;
        } catch (\Throwable $e) {
            self::debugLog('doesBucketExist SDK failed (inconclusive): ' . $e->getMessage());
            return null;
        }
    }

    

    private static function makeUploadDir(string $path): bool
    {
        $path = str_replace('\\', '/', $path);
        if (is_dir($path)) {
            return true;
        }
        return @mkdir($path, 0755, true);
    }

    private static function getUploadRootDir(): string
    {
        return defined('__TYPECHO_UPLOAD_ROOT_DIR__') ? __TYPECHO_UPLOAD_ROOT_DIR__ : __TYPECHO_ROOT_DIR__;
    }

    private static function getUploadDir(): string
    {
        $opt = Plugin::getOptions();
        $dir = '';
        
        
        
        if ($opt && isset($opt->path)) {
            $pathVal = (string)$opt->path;
            if ($pathVal !== '') {
                $dir = $pathVal;
            } else {
                
                return '';
            }
        } elseif (defined('__TYPECHO_UPLOAD_DIR__')) {
            $dir = __TYPECHO_UPLOAD_DIR__;
        } else {
            return '';
        }
        $dir = '/' . ltrim($dir, '/');
        $dir = rtrim($dir, '/');
        
        $dir = self::sanitizePathSegments($dir);
        return $dir === '' ? '' : '/' . $dir;
    }

    
    public static function sanitizePathSegments(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        for ($i = 0; $i < 10; $i++) {
            $new = preg_replace('#(?:^|/)\.\.?(?:/|$)#', '/', $path);
            $new = preg_replace('#/+#', '/', $new);
            if ($new === $path) {
                break;
            }
            $path = $new;
        }
        return trim($path, '/');
    }

    
    private static function getLocalUploadDir(): string
    {
        $opt = Plugin::getOptions();
        $raw = $opt->sync_local_path ?? '';
        
        $localPath = is_array($raw) ? '' : trim((string)$raw);
        
        if ($localPath === '' || $localPath === '.' || strtolower($localPath) === 'array') {
            $localPath = 'usr/uploads';
        }
        $localPath = self::sanitizePathSegments($localPath);
        return $localPath === '' ? Plugin::UPLOAD_DIR : '/' . ltrim($localPath, '/');
    }

    
    public static function checkLocalPathWritable(string $relPath): ?string
    {
        $rootDir = self::getUploadRootDir();
        $absPath = rtrim($rootDir, '/') . '/' . trim($relPath, '/');

        if (is_dir($absPath)) {
            if (!is_writable($absPath)) {
                return '本地存储路径不可写：' . $absPath . '。请检查目录权限，否则回退到本地上传时会失败。';
            }
            return null;
        }

        
        $testDir = $absPath . '/.cos_write_test_' . self::randomHex(4);
        if (!@mkdir($testDir, 0755, true)) {
            return '本地存储路径无法创建：' . $absPath . '。请检查父目录权限，否则回退到本地上传时会失败。';
        }
        @rmdir($testDir);
        
        $parent = dirname($testDir);
        while ($parent !== $rootDir && $parent !== '/' && @rmdir($parent)) {
            $parent = dirname($parent);
        }
        return null;
    }

    private static function getUploadSubDir(string $ext = ''): string
    {
        $opt = Plugin::getOptions();
        $template = trim((string)($opt->dir_structure ?? '{type}/{year}'), '/');
        if ($template === '') {
            return '';
        }

        $date = new Date();
        $ext = strtolower($ext);
        $replace = [
            '{year}'  => $date->year,
            '{month}' => $date->month,
            '{day}'   => $date->day,
            '{type}'  => Image::getMediaType($ext),
            '{ext}'   => $ext !== '' ? $ext : 'other',
        ];
        $subDir = str_replace(array_keys($replace), array_values($replace), $template);
        
        return self::sanitizePathSegments($subDir);
    }

    private static function getUploadFile(array $file)
    {
        return $file['tmp_name'] ?? ($file['bytes'] ?? ($file['bits'] ?? null));
    }

    
    private static function randomHex(int $length = 8): string
    {
        try {
            return bin2hex(random_bytes($length));
        } catch (\Throwable $e) {
            return bin2hex(uniqid((string)mt_rand(), true));
        }
    }

    

    
    private static function getUploadResultFile(): string
    {
        
        $siteFingerprint = substr(md5(__TYPECHO_ROOT_DIR__), 0, 8);
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typecho_cos_' . $siteFingerprint . '_last_upload.json';
    }

    
    public static function recordUploadResult(bool $success, string $message, array $extra = []): void
    {
        try {
            
            $timeStr = (new Date())->format('Y-m-d H:i:s');
            $data = [
                'time'    => $timeStr,
                'success' => $success,
                'message' => $message,
                'extra'   => $extra,
            ];
            $target = self::getUploadResultFile();
            
            $tmp = $target . '.tmp.' . self::randomHex(4);
            if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) {
                @rename($tmp, $target);
            } else {
                @unlink($tmp);
            }
        } catch (\Throwable $e) {
            
        }
    }

    
    public static function getLastUploadResult(): ?array
    {
        try {
            $file = self::getUploadResultFile();
            if (!file_exists($file)) {
                return null;
            }
            $content = @file_get_contents($file);
            if ($content === false || $content === '') {
                return null;
            }
            $data = json_decode($content, true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    
    public static function getHealthStatus(): array
    {
        
        
        
        static $cached = null;
        static $cachedAt = 0;
        $now = time();
        if ($cached !== null && ($now - $cachedAt) < 30) {
            return $cached;
        }

        $opt = Plugin::getOptions();

        
        
        try {
            self::loadSdk();
        } catch (\Throwable $e) {
            
        }

        $status = [
            'plugin_version'  => Plugin::PLUGIN_VERSION,
            'php_version'     => PHP_VERSION,
            'sdk_version'     => Plugin::getSdkVersion(),
            'phar_enabled'    => extension_loaded('phar'),
            'curl_enabled'    => extension_loaded('curl'),
            'gd_enabled'      => extension_loaded('gd'),
            'gd_version'      => '',
            'gd_webp_support' => false,
            'webp_supported'  => false,
            'webp_engine'     => 'none',
            'cwebp_path'      => null,
            
            'cwebp_installed' => false,
            'cwebp_version'   => null,
            'exec_enabled'    => function_exists('exec'),
            
            'imagick_enabled'       => extension_loaded('imagick'),
            'imagick_version'       => '',
            'imagick_webp_support'  => false,
            
            'avif_supported'  => false,
            'avif_imageavif'  => false,
            'avif_createfrom' => false,
            'avif_engine'     => 'none',
            'imagick_avif_support' => false,
            'cos_configured'  => false,
            'cos_bucket'      => '',
            'cos_region'      => '',
            'local_backup'    => false,
            'remote_sync'     => false,
            'webp_enabled'    => false,
            'last_upload'     => self::getLastUploadResult(),
        ];

        
        if ($status['gd_enabled']) {
            $gd = gd_info();
            $status['gd_version'] = $gd['GD Version'] ?? 'unknown';
            
            $status['gd_webp_support'] = !empty($gd['WebP Support']);
            if ($status['gd_webp_support']) {
                $status['webp_supported'] = true;
                $status['webp_engine'] = 'gd';
            }
            
            $gdAvif = !empty($gd['AVIF Support']) && function_exists('imageavif');
            $status['avif_supported'] = $gdAvif;
            $status['avif_imageavif'] = function_exists('imageavif');
            $status['avif_createfrom'] = function_exists('imagecreatefromavif');
            $status['avif_engine'] = $gdAvif ? 'gd' : 'none';
        }
        
        if ($status['imagick_enabled']) {
            try {
                $status['imagick_version'] = \Imagick::getVersion()['versionString'] ?? 'unknown';
                $im = new \Imagick();
                $formats = $im->queryFormats('WEBP');
                $status['imagick_webp_support'] = !empty($formats);
                
                $avifFormats = $im->queryFormats('AVIF');
                $status['imagick_avif_support'] = !empty($avifFormats);
                $im->destroy();
            } catch (\Throwable $e) {
                $status['imagick_version'] = '检测失败: ' . $e->getMessage();
            }
        }
        
        
        
        $cwebpPath = Image::getCwebpPath(true);
        if ($cwebpPath !== null) {
            $status['cwebp_installed'] = true;
            $status['cwebp_path'] = $cwebpPath;
            
            $status['cwebp_version'] = Image::getCwebpVersion(true, $cwebpPath);
        }
        
        if (!$status['webp_supported']) {
            if ($status['imagick_webp_support']) {
                $status['webp_supported'] = true;
                $status['webp_engine'] = 'imagick';
            } elseif ($status['cwebp_installed']) {
                $status['webp_supported'] = true;
                $status['webp_engine'] = 'cwebp';
            }
        }
        
        if (!$status['avif_supported'] && !empty($status['imagick_avif_support'])) {
            $status['avif_supported'] = true;
            $status['avif_engine'] = 'imagick';
        }

        
        
        if ($opt && !empty($opt->secid) && !empty($opt->sekey) && !empty($opt->bucket) && !empty($opt->region)) {
            $status['cos_configured'] = true;
            $status['cos_bucket'] = (string)$opt->bucket;
            $status['cos_region'] = (string)($opt->region ?? '');
            
            $cosPath = (string)($opt->path ?? '');
            $status['cos_path'] = $cosPath === '' ? '（根目录）' : $cosPath;
            $status['dir_structure'] = (string)($opt->dir_structure ?? '{type}/{year}');
            $status['sync_local_path'] = ltrim(self::getLocalUploadDir(), '/');
            $status['local_backup'] = self::isEnabled($opt, 'local');
            $status['remote_sync'] = self::isEnabled($opt, 'remote_sync');
            $status['local_sync'] = self::isEnabled($opt, 'local_sync');
            $status['webp_enabled'] = self::isEnabled($opt, 'webp_enable');
            $status['avif_enabled'] = self::isEnabled($opt, 'avif_enable');
        }

        $cached = $status;
        $cachedAt = $now;
        return $status;
    }
}
