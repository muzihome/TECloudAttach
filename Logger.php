<?php

namespace TypechoPlugin\TECloudAttach;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Logger
{
    
    private static ?bool $debugEnabled = null;

    
    public static function log(string $msg, bool $force = false): void
    {
        if (self::$debugEnabled === null) {
            self::$debugEnabled = (defined('TYPECHO_COS_DEBUG') && TYPECHO_COS_DEBUG);
        }
        if (!$force && !self::$debugEnabled) {
            return;
        }
        if (!function_exists('error_log')) {
            return;
        }
        error_log('[TECloudAttach] ' . self::maskSensitive($msg));
    }

    
    private static function maskSensitive(string $msg): string
    {
        
        
        
        $result = preg_replace_callback(
            '/(q-ak|q-signature|q-key-time|q-sign-token|q-sign-algorithm|q-header-list|q-url-param-list|secretid|secretkey|accesskey|secid|sekey|secret_id|secret_key|access_key_id|access_key_secret)\s*(=|%3D)([^&"\'\s]+)/i',
            function ($m) {
                $name = strtolower($m[1]);
                $v = $m[3];
                
                
                if (in_array($name, ['secretkey', 'sekey', 'access_key_secret', 'accesskey'], true)) {
                    $mask = '******';
                } else {
                    $mask = (strlen($v) <= 4) ? '***' : (substr($v, 0, 2) . '***' . substr($v, -1));
                }
                return $m[1] . $m[2] . $mask;
            },
            $msg
        );
        return $result ?? $msg;
    }
}
