<?php

namespace TypechoPlugin\TECloudAttach;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Image
{
    
    public const MEDIA_TYPE_MAP = [
        
        'jpg' => 'images', 'jpeg' => 'images', 'png' => 'images', 'gif' => 'images',
        'bmp' => 'images', 'webp' => 'images', 'svg' => 'images', 'ico' => 'images',
        'tiff' => 'images', 'tif' => 'images', 'avif' => 'images', 'heic' => 'images',
        'heif' => 'images', 'raw' => 'images', 'psd' => 'images', 'ai' => 'images',
        
        'mp4' => 'videos', 'avi' => 'videos', 'mov' => 'videos', 'wmv' => 'videos',
        'flv' => 'videos', 'mkv' => 'videos', 'webm' => 'videos', 'm4v' => 'videos',
        'mpg' => 'videos', 'mpeg' => 'videos', '3gp' => 'videos', 'rmvb' => 'videos',
        
        'mp3' => 'audios', 'wav' => 'audios', 'ogg' => 'audios', 'flac' => 'audios',
        'aac' => 'audios', 'm4a' => 'audios', 'wma' => 'audios', 'ape' => 'audios',
        'opus' => 'audios', 'mid' => 'audios', 'midi' => 'audios',
        
        'pdf' => 'documents', 'doc' => 'documents', 'docx' => 'documents',
        'xls' => 'documents', 'xlsx' => 'documents', 'ppt' => 'documents',
        'pptx' => 'documents', 'txt' => 'documents', 'md' => 'documents',
        'rtf' => 'documents', 'odt' => 'documents', 'ods' => 'documents',
        'odp' => 'documents', 'csv' => 'documents', 'epub' => 'documents',
        'mobi' => 'documents',
        
        'js' => 'code', 'css' => 'code', 'html' => 'code', 'htm' => 'code',
        'php' => 'code', 'json' => 'code', 'xml' => 'code', 'yaml' => 'code',
        'yml' => 'code', 'sql' => 'code', 'sh' => 'code', 'py' => 'code',
        'java' => 'code', 'c' => 'code', 'cpp' => 'code', 'h' => 'code',
        'hpp' => 'code', 'ts' => 'code', 'vue' => 'code', 'jsx' => 'code',
        'tsx' => 'code', 'go' => 'code', 'rs' => 'code', 'rb' => 'code',
        'pl' => 'code', 'lua' => 'code', 'swift' => 'code', 'kt' => 'code',
        'scala' => 'code', 'r' => 'code', 'm' => 'code', 'mm' => 'code',
        
        'zip' => 'archives', 'rar' => 'archives', '7z' => 'archives',
        'tar' => 'archives', 'gz' => 'archives', 'bz2' => 'archives',
        'xz' => 'archives', 'z' => 'archives', 'tgz' => 'archives',
        'tbz2' => 'archives',
        
        'ttf' => 'fonts', 'otf' => 'fonts', 'woff' => 'fonts',
        'woff2' => 'fonts', 'eot' => 'fonts',
    ];

    

    
    public static function getMediaType(string $ext): string
    {
        return self::MEDIA_TYPE_MAP[$ext] ?? 'other';
    }

    

    
    public static function hasGdWebpSupport(): bool
    {
        static $supported = null;
        if ($supported === null) {
            $supported = function_exists('gd_info') && !empty(gd_info()['WebP Support']);
        }
        return $supported;
    }

    
    public static function isWebpSupported(): bool
    {
        
        if (function_exists('imagewebp') && function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng')) {
            if (self::hasGdWebpSupport()) {
                return true;
            }
        }
        
        
        if (self::isImagickWebpSupported()) {
            return true;
        }
        
        return self::isCwebpAvailable();
    }

    
    public static function isImagickWebpSupported(): bool
    {
        static $supported = null;
        if ($supported === null) {
            $supported = false;
            if (extension_loaded('imagick')) {
                try {
                    $im = new \Imagick();
                    $formats = $im->queryFormats('WEBP');
                    $supported = !empty($formats);
                    $im->destroy();
                } catch (\Throwable $e) {
                    $supported = false;
                }
            }
        }
        return $supported;
    }

    
    public static function isCwebpAvailable(): bool
    {
        return self::getCwebpPath() !== null;
    }

    
    public static function getCwebpPath(bool $refresh = false): ?string
    {
        static $cachedPath = null;
        static $checked = false;
        if ($checked && !$refresh) {
            return $cachedPath;
        }
        $checked = true;
        $cachedPath = null;

        if (!function_exists('exec') || !function_exists('escapeshellarg')) {
            return null;
        }
        
        $paths = ['/usr/bin/cwebp', '/usr/local/bin/cwebp', '/opt/homebrew/bin/cwebp'];
        foreach ($paths as $p) {
            if (file_exists($p) && is_executable($p)) {
                $cachedPath = $p;
                return $cachedPath;
            }
        }
        
        $output = [];
        $retCode = 0;
        @exec('command -v cwebp 2>/dev/null || which cwebp 2>/dev/null', $output, $retCode);
        if ($retCode === 0 && !empty($output)) {
            $found = trim($output[0]);
            if ($found !== '' && file_exists($found) && is_executable($found)) {
                $cachedPath = $found;
                return $cachedPath;
            }
        }
        return null;
    }

    
    public static function getCwebpVersion(bool $refresh = false, ?string $knownPath = null): ?string
    {
        $path = $knownPath ?? self::getCwebpPath($refresh);
        if ($path === null || !function_exists('exec') || !function_exists('escapeshellarg')) {
            return null;
        }
        
        static $cachedVersion = null;
        static $cachedPath = null;
        if (!$refresh && $cachedVersion !== null && $cachedPath === $path) {
            return $cachedVersion;
        }
        $output = [];
        $retCode = 0;
        @exec(escapeshellarg($path) . ' -version 2>/dev/null', $output, $retCode);
        if ($retCode === 0 && !empty($output)) {
            $version = trim((string)($output[0] ?? ''));
            if ($version !== '') {
                if (!$refresh) {
                    $cachedVersion = $version;
                    $cachedPath = $path;
                }
                return $version;
            }
        }
        return null;
    }

    
    public static function isConvertibleImage(string $ext, $opt): bool
    {
        
        $webpVal = $opt->webp_enable ?? 'close';
        $webpEnabled = is_array($webpVal) ? in_array('open', $webpVal, true) : ($webpVal === 'open');
        if (!$webpEnabled) {
            return false;
        }
        if (!self::isWebpSupported()) {
            return false;
        }
        if ($ext === '' || $ext === 'webp' || $ext === 'gif') {
            return false;
        }
        $formats = trim((string)($opt->webp_formats ?? 'jpg,jpeg,png,avif,tiff,tif'));
        if ($formats === '') {
            return false;
        }
        $list = array_map('trim', explode(',', strtolower($formats)));
        return in_array($ext, $list, true);
    }

    
    public static function getWebpPath(string $path): string
    {
        $info = pathinfo($path);
        $dir = $info['dirname'] === '.' ? '' : $info['dirname'];
        $name = $info['filename'] ?? '';
        return ($dir !== '' ? $dir . '/' : '') . $name . '.webp';
    }

    
    public static function cosUploadHeaders(string $contentType = ''): array
    {
        $headers = [
            'ACL'          => 'public-read',
            'CacheControl' => 'public, max-age=31536000, immutable',
            'Expires'      => gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT',
        ];
        if ($contentType !== '') {
            $headers['ContentType'] = $contentType;
        }
        return $headers;
    }

    
    public static function convertToWebp(string $srcPath, string $destPath, int $quality, ?int $maxPixels = null): bool
    {
        if (!file_exists($srcPath)) {
            return false;
        }

        $info = @getimagesize($srcPath);
        if ($info === false) {
            Logger::log('[WebP] getimagesize failed, not a valid image: ' . $srcPath);
            return false;
        }
        $mime = $info['mime'] ?? '';

        
        
        if ($maxPixels === null) {
            $maxPixels = self::estimateSafePixelLimit();
        }
        if (($info[0] ?? 0) * ($info[1] ?? 0) > $maxPixels) {
            Logger::log('[WebP] skip oversized image (max ' . $maxPixels . 'px): ' . ($info[0] ?? 0) . 'x' . ($info[1] ?? 0));
            return false;
        }

        
        if ($mime === 'image/gif') {
            return false;
        }
        
        if ($mime === 'image/webp') {
            $copied = @copy($srcPath, $destPath);
            if (!$copied) {
                Logger::log('[WebP] copy source (already webp) failed: ' . $srcPath . ' -> ' . $destPath, true);
            }
            return $copied;
        }

        
        
        $gdSupported = function_exists('imagewebp') && self::hasGdWebpSupport();
        
        
        $gdCanDecode = ($mime === 'image/jpeg') || ($mime === 'image/png') || ($mime === 'image/bmp' && function_exists('imagecreatefrombmp'));
        if ($gdSupported && $gdCanDecode) {
            $img = null;
            if ($mime === 'image/jpeg') {
                $img = @imagecreatefromjpeg($srcPath);
            } elseif ($mime === 'image/bmp' && function_exists('imagecreatefrombmp')) {
                $img = @imagecreatefrombmp($srcPath);
            } else {
                $img = @imagecreatefrompng($srcPath);
                if ($img) {
                    imagepalettetotruecolor($img);
                    
                    
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                }
            }
            if ($img) {
                $result = @imagewebp($img, $destPath, $quality);
                imagedestroy($img);
                if ($result && file_exists($destPath) && filesize($destPath) > 0) {
                    return true;
                }
                Logger::log('[WebP] GD imagewebp() failed, falling back to cwebp. src=' . $srcPath);
                @unlink($destPath);
            } else {
                Logger::log('[WebP] GD imagecreatefrom* failed, falling back to cwebp. src=' . $srcPath . ' mime=' . $mime);
            }
        }

        
        
        if (extension_loaded('imagick')) {
            
            $imagickCanWebp = true;
            try {
                $imCheck = new \Imagick();
                $fmtList = $imCheck->queryFormats('WEBP');
                $imCheck->destroy();
                $imagickCanWebp = !empty($fmtList);
            } catch (\Throwable $e) {
                $imagickCanWebp = true;
            }
            if ($imagickCanWebp) {
                try {
                    $im = new \Imagick($srcPath);
                    $im->setImageFormat('webp');
                    $im->setImageCompressionQuality($quality);
                    $im->writeImage($destPath);
                    $im->destroy();
                    if (file_exists($destPath) && filesize($destPath) > 0) {
                        return true;
                    }
                    Logger::log('[WebP] Imagick writeImage produced empty file, falling back. src=' . $srcPath . ' mime=' . $mime);
                    @unlink($destPath);
                } catch (\Throwable $e) {
                    Logger::log('[WebP] Imagick conversion failed, falling back: ' . get_class($e) . ': ' . $e->getMessage() . ' src=' . $srcPath . ' mime=' . $mime);
                    @unlink($destPath);
                }
            } else {
                Logger::log('[WebP] Imagick does not support WebP encoding, skipping Imagick branch. src=' . $srcPath);
            }
        }

        
        if (self::isCwebpAvailable()) {
            return self::convertToWebpViaCwebp($srcPath, $destPath, $quality, $mime);
        }

        
        $cwebpOk = self::isCwebpAvailable();
        $gdCanDecode = ($mime === 'image/jpeg') || ($mime === 'image/png') || ($mime === 'image/bmp' && function_exists('imagecreatefrombmp'));
        $reason = 'mime=' . $mime . ' GD_support=' . ($gdSupported ? 'yes' : 'no')
            . ' GD_can_decode=' . ($gdCanDecode ? 'yes' : 'no')
            . ' cwebp=' . ($cwebpOk ? 'yes' : 'no');
        if ($mime === 'image/tiff' && !$cwebpOk) {
            $reason .= ' [TIFF 需安装 cwebp，GD 不支持 TIFF 解码]';
        } elseif ($mime === 'image/bmp' && !function_exists('imagecreatefrombmp')) {
            $reason .= ' [BMP 需 PHP 7.2+ GD imagecreatefrombmp]';
        } elseif (!in_array($mime, ['image/jpeg', 'image/png', 'image/bmp', 'image/tiff'], true)) {
            $reason .= ' [该格式 GD/cwebp 均不支持输入]';
        }
        Logger::log('[WebP] 无法转换: ' . $reason . ' src=' . $srcPath);
        return false;
    }

    
    private static function estimateSafePixelLimit(): int
    {
        $val = trim((string)ini_get('memory_limit'));
        if ($val !== '' && $val !== '-1') {
            $unit = strtolower(substr($val, -1));
            $num = (float)$val;
            if (ctype_alpha($unit)) {
                $num = (float)substr($val, 0, -1);
            } else {
                $unit = '';
            }
            switch ($unit) {
                case 'g': $num *= 1024 * 1024 * 1024;
                    break;
                case 'm': $num *= 1024 * 1024;
                    break;
                case 'k': $num *= 1024;
                    break;
            }
            if ($num > 0) {
                $pixels = (int)floor($num / 4 / 6); 
                if ($pixels > 50000000) {
                    $pixels = 50000000; 
                }
                if ($pixels > 100000) {
                    return $pixels;
                }
            }
        }
        return 20000000;
    }

    
    private static function convertToWebpViaCwebp(string $srcPath, string $destPath, int $quality, string $mime): bool
    {
        $cwebp = self::getCwebpPath();
        if ($cwebp === null) {
            Logger::log('[WebP] cwebp not available');
            return false;
        }
        
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/tiff', 'image/webp'], true)) {
            Logger::log('[WebP] cwebp does not support mime type: ' . $mime);
            return false;
        }
        $quality = max(0, min(100, $quality));
        $cmd = escapeshellarg($cwebp) . ' -q ' . escapeshellarg((string)$quality)
            . ' ' . escapeshellarg($srcPath)
            . ' -o ' . escapeshellarg($destPath)
            . ' 2>&1';
        $output = [];
        $retCode = 0;
        @exec($cmd, $output, $retCode);
        if ($retCode === 0 && file_exists($destPath) && filesize($destPath) > 0) {
            return true;
        }
        Logger::log('[WebP] cwebp failed (code=' . $retCode . '): ' . implode(' ', array_slice($output, -3)));
        @unlink($destPath);
        return false;
    }

    
    public static function getSourceFilePath(array $file, $uploadfile): ?string
    {
        if (isset($file['tmp_name']) && is_string($file['tmp_name']) && file_exists($file['tmp_name'])) {
            return $file['tmp_name'];
        }
        
        if (is_string($uploadfile) && $uploadfile !== '') {
            
            $tmp = self::tempPath('cos_src_', 'tmp');
            if (@file_put_contents($tmp, $uploadfile) !== false) {
                return $tmp;
            }
            
            @unlink($tmp);
        }
        return null;
    }

    
    public static function tempPath(string $prefix = 'cos_', string $ext = 'tmp'): string
    {
        try {
            $rand = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $rand = bin2hex(uniqid((string)mt_rand(), true));
        }
        
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . $rand . '.' . $ext;
    }

    
    public static function uploadWebpToCos($cosClient, $opt, string $webpRelPath, string $webpAbsPath): bool
    {
        $cosKey = ltrim($webpRelPath, '/');
        try {
            if (!file_exists($webpAbsPath)) {
                Logger::log('[WebP] uploadWebpToCos: source file not exists: ' . $webpAbsPath, true);
                return false;
            }
            $fileSize = filesize($webpAbsPath);
            if ($fileSize === false || $fileSize === 0) {
                Logger::log('[WebP] uploadWebpToCos: source file empty or invalid: ' . $webpAbsPath . ' size=' . var_export($fileSize, true), true);
                return false;
            }
            $fp = @fopen($webpAbsPath, 'rb');
            if (!$fp) {
                Logger::log('[WebP] uploadWebpToCos: fopen failed: ' . $webpAbsPath, true);
                return false;
            }
            try {
                $uploadFn = function () use ($cosClient, $opt, $cosKey, $fp) {
                    if (is_resource($fp)) {
                        @rewind($fp);
                    }
                    $cosClient->upload(
                        $opt->bucket,
                        $cosKey,
                        $fp,
                        self::cosUploadHeaders('image/webp')
                    );
                };
                
                try {
                    $uploadFn();
                } catch (\Throwable $firstErr) {
                    if (!Action::isTransientError($firstErr)) {
                        throw $firstErr;
                    }
                    Logger::log('[WebP] uploadWebpToCos 第1次失败，重试: ' . $firstErr->getMessage(), true);
                    usleep(200000);
                    $uploadFn();
                }
                Logger::log('[WebP] uploadWebpToCos: SUCCESS key=' . $cosKey . ' size=' . $fileSize);
                return true;
            } finally {
                if (is_resource($fp)) {
                    @fclose($fp);
                }
            }
        } catch (\Throwable $e) {
            Logger::log('[WebP] uploadWebpToCos FAILED: key=' . $cosKey . ' file=' . $webpAbsPath . ' error=' . get_class($e) . ': ' . $e->getMessage(), true);
            return false;
        }
    }

    

    
    public static function hasGdAvifSupport(): bool
    {
        static $supported = null;
        if ($supported === null) {
            $supported = function_exists('gd_info') && !empty(gd_info()['AVIF Support']);
        }
        return $supported;
    }

    
    public static function isImagickAvifSupported(): bool
    {
        static $supported = null;
        if ($supported === null) {
            $supported = false;
            if (extension_loaded('imagick')) {
                try {
                    $im = new \Imagick();
                    $formats = $im->queryFormats('AVIF');
                    $supported = !empty($formats);
                    $im->destroy();
                } catch (\Throwable $e) {
                    $supported = false;
                }
            }
        }
        return $supported;
    }

    
    public static function isAvifSupported(): bool
    {
        if (function_exists('imageavif') && self::hasGdAvifSupport()) {
            return true;
        }
        return self::isImagickAvifSupported();
    }

    
    public static function isConvertibleAvif(string $ext, $opt): bool
    {
        $avifVal = $opt->avif_enable ?? 'close';
        $avifEnabled = is_array($avifVal) ? in_array('open', $avifVal, true) : ($avifVal === 'open');
        if (!$avifEnabled) {
            return false;
        }
        if (!self::isAvifSupported()) {
            return false;
        }
        if ($ext === '' || $ext === 'avif' || $ext === 'gif') {
            return false;
        }
        $formats = trim((string)($opt->avif_formats ?? 'jpg,jpeg,png,webp,tiff,tif'));
        if ($formats === '') {
            return false;
        }
        $list = array_map('trim', explode(',', strtolower($formats)));
        return in_array($ext, $list, true);
    }

    
    public static function getAvifPath(string $path): string
    {
        $info = pathinfo($path);
        $dir = $info['dirname'] === '.' ? '' : $info['dirname'];
        $name = $info['filename'] ?? '';
        return ($dir !== '' ? $dir . '/' : '') . $name . '.avif';
    }

    
    public static function convertToAvif(string $srcPath, string $destPath, int $quality, ?int $maxPixels = null): bool
    {
        if (!file_exists($srcPath)) {
            return false;
        }

        $info = @getimagesize($srcPath);
        if ($info === false) {
            Logger::log('[AVIF] getimagesize failed, not a valid image: ' . $srcPath);
            return false;
        }
        $mime = $info['mime'] ?? '';
        $hybridAttempted = false;

        
        if ($maxPixels === null) {
            $maxPixels = self::estimateSafePixelLimit();
        }
        if (($info[0] ?? 0) * ($info[1] ?? 0) > $maxPixels) {
            Logger::log('[AVIF] skip oversized image (max ' . $maxPixels . 'px): ' . ($info[0] ?? 0) . 'x' . ($info[1] ?? 0));
            return false;
        }

        
        if ($mime === 'image/gif') {
            return false;
        }
        
        if ($mime === 'image/avif') {
            $copied = @copy($srcPath, $destPath);
            if (!$copied) {
                Logger::log('[AVIF] copy source (already avif) failed: ' . $srcPath . ' -> ' . $destPath, true);
            }
            return $copied;
        }

        
        $gdSupported = function_exists('imageavif') && self::hasGdAvifSupport();
        
        $gdCanDecode = ($mime === 'image/jpeg') || ($mime === 'image/png')
            || ($mime === 'image/bmp' && function_exists('imagecreatefrombmp'))
            || ($mime === 'image/webp' && function_exists('imagecreatefromwebp'));
        if ($gdSupported && $gdCanDecode) {
            $img = null;
            if ($mime === 'image/jpeg') {
                $img = @imagecreatefromjpeg($srcPath);
            } elseif ($mime === 'image/bmp' && function_exists('imagecreatefrombmp')) {
                $img = @imagecreatefrombmp($srcPath);
            } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
                $img = @imagecreatefromwebp($srcPath);
                if ($img) {
                    imagepalettetotruecolor($img);
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                }
            } else {
                $img = @imagecreatefrompng($srcPath);
                if ($img) {
                    imagepalettetotruecolor($img);
                    
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                }
            }
            if ($img) {
                $result = @imageavif($img, $destPath, $quality);
                imagedestroy($img);
                if ($result && file_exists($destPath) && filesize($destPath) > 0) {
                    return true;
                }
                Logger::log('[AVIF] GD imageavif() failed, falling back to Imagick. src=' . $srcPath);
                @unlink($destPath);
            } else {
                Logger::log('[AVIF] GD imagecreatefrom* failed, falling back to Imagick. src=' . $srcPath . ' mime=' . $mime);
            }
        }

        
        
        if ($gdSupported && !$gdCanDecode && extension_loaded('imagick')) {
            $hybridAttempted = true;
            $hybridTmp = self::tempPath('cos_avif_hybrid_', 'png');
            try {
                $im = new \Imagick($srcPath);
                $im->setImageFormat('png');
                $im->writeImage($hybridTmp);
                $im->destroy();
                if (file_exists($hybridTmp) && filesize($hybridTmp) > 0) {
                    $img = @imagecreatefrompng($hybridTmp);
                    if ($img) {
                        imagepalettetotruecolor($img);
                        imagealphablending($img, false);
                        imagesavealpha($img, true);
                        $result = @imageavif($img, $destPath, $quality);
                        imagedestroy($img);
                        @unlink($hybridTmp);
                        if ($result && file_exists($destPath) && filesize($destPath) > 0) {
                            return true;
                        }
                        Logger::log('[AVIF] hybrid (Imagick decode + GD encode) failed. src=' . $srcPath . ' mime=' . $mime);
                        @unlink($destPath);
                    } else {
                        @unlink($hybridTmp);
                        Logger::log('[AVIF] hybrid: GD imagecreatefrompng failed on decoded temp. src=' . $srcPath);
                    }
                }
            } catch (\Throwable $e) {
                @unlink($hybridTmp);
                Logger::log('[AVIF] hybrid decode exception: ' . $e->getMessage() . ' src=' . $srcPath);
            }
        }

        
        if (extension_loaded('imagick') && self::isImagickAvifSupported()) {
            try {
                $im = new \Imagick($srcPath);
                $im->setImageFormat('avif');
                $im->setImageCompressionQuality($quality);
                $im->writeImage($destPath);
                $im->destroy();
                if (file_exists($destPath) && filesize($destPath) > 0) {
                    return true;
                }
                Logger::log('[AVIF] Imagick writeImage produced empty file. src=' . $srcPath . ' mime=' . $mime);
                @unlink($destPath);
            } catch (\Throwable $e) {
                Logger::log('[AVIF] Imagick conversion failed: ' . get_class($e) . ': ' . $e->getMessage() . ' src=' . $srcPath . ' mime=' . $mime);
                @unlink($destPath);
            }
        }

        
        $reason = 'mime=' . $mime . ' GD_avif=' . ($gdSupported ? 'yes' : 'no')
            . ' Imagick_avif=' . (self::isImagickAvifSupported() ? 'yes' : 'no')
            . ' hybrid=' . ($hybridAttempted ? 'attempted' : 'skipped');
        if ($mime === 'image/tiff') {
            $reason .= ' [TIFF 需 Imagick 编译 libheif，GD 不支持 TIFF 解码]';
        } elseif (!in_array($mime, ['image/jpeg', 'image/png', 'image/bmp', 'image/tiff'], true)) {
            $reason .= ' [该格式 GD/Imagick 均不支持 AVIF 输入]';
        }
        Logger::log('[AVIF] 无法转换: ' . $reason . ' src=' . $srcPath);
        return false;
    }

    
    public static function uploadAvifToCos($cosClient, $opt, string $avifRelPath, string $avifAbsPath): bool
    {
        $cosKey = ltrim($avifRelPath, '/');
        try {
            if (!file_exists($avifAbsPath)) {
                Logger::log('[AVIF] uploadAvifToCos: source file not exists: ' . $avifAbsPath, true);
                return false;
            }
            $fileSize = filesize($avifAbsPath);
            if ($fileSize === false || $fileSize === 0) {
                Logger::log('[AVIF] uploadAvifToCos: source file empty or invalid: ' . $avifAbsPath . ' size=' . var_export($fileSize, true), true);
                return false;
            }
            $fp = @fopen($avifAbsPath, 'rb');
            if (!$fp) {
                Logger::log('[AVIF] uploadAvifToCos: fopen failed: ' . $avifAbsPath, true);
                return false;
            }
            try {
                $uploadFn = function () use ($cosClient, $opt, $cosKey, $fp) {
                    if (is_resource($fp)) {
                        @rewind($fp);
                    }
                    $cosClient->upload(
                        $opt->bucket,
                        $cosKey,
                        $fp,
                        self::cosUploadHeaders('image/avif')
                    );
                };
                
                try {
                    $uploadFn();
                } catch (\Throwable $firstErr) {
                    if (!Action::isTransientError($firstErr)) {
                        throw $firstErr;
                    }
                    Logger::log('[AVIF] uploadAvifToCos 第1次失败，重试: ' . $firstErr->getMessage(), true);
                    usleep(200000);
                    $uploadFn();
                }
                Logger::log('[AVIF] uploadAvifToCos: SUCCESS key=' . $cosKey . ' size=' . $fileSize);
                return true;
            } finally {
                if (is_resource($fp)) {
                    @fclose($fp);
                }
            }
        } catch (\Throwable $e) {
            Logger::log('[AVIF] uploadAvifToCos FAILED: key=' . $cosKey . ' file=' . $avifAbsPath . ' error=' . get_class($e) . ': ' . $e->getMessage(), true);
            return false;
        }
    }

    

    
    public static function sanitizeFileName(string $name): string
    {
        
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        $name = str_replace(['"', '<', '>'], '', $name);
        $name = str_replace('\\', '/', $name);
        return basename($name);
    }

    
    public static function getExtension(string $name): string
    {
        $base = basename($name);
        
        if ($base !== '' && $base[0] === '.') {
            return '';
        }
        $ext = pathinfo($base, PATHINFO_EXTENSION);
        return $ext !== '' ? strtolower($ext) : '';
    }
}
