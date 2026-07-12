<?php
/**
 * api/lib/image_store.php — shared reward-image upload storage.
 *
 * Used by BOTH the consumer API (api/v1/upload.php, X-Consumer-Key auth,
 * scope segment = consumer id) and the central admin (api/admin/upload.php,
 * admin-session auth, scope segment = "admin"). Validates + stores an
 * uploaded image under the webroot at
 *   public_html/uploads/<scopeSeg>/<subSafe>/<purpose>-<rand>.<ext>
 * and returns its absolute same-origin URL. The deploy mirror EXCLUDES
 * uploads/ (-X uploads/) so `mirror --delete` never wipes these.
 *
 * Returns ['url','width','height'] on success, or null with $err set.
 */

declare(strict_types=1);

if (!function_exists('rewards_store_uploaded_image')) {
    /**
     * @param array       $file    one $_FILES entry (['tmp_name','size','error',…])
     * @param string      $subId   consumer subscription scope (SUB-XXX / school id)
     * @param string      $purpose 'qr' | 'redeem' (filename prefix only)
     * @param string|int  $scopeSeg first path segment (consumer id, or 'admin')
     * @param string|null &$err     human-readable failure reason on null return
     * @return array{url:string,width:int,height:int}|null
     */
    function rewards_store_uploaded_image(array $file, string $subId, string $purpose, $scopeSeg, ?string &$err = null): ?array {
        $err = null;

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $err = 'no image uploaded'; return null;
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0)               { $err = 'empty file'; return null; }
        if ($size > 4 * 1024 * 1024)  { $err = 'image too large (max 4 MB)'; return null; }

        /* MIME from magic bytes, NOT the client-declared type. */
        $mime = '';
        if (class_exists('finfo')) {
            $fi = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string) ($fi->file($file['tmp_name']) ?: '');
        }
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($allowed[$mime])) { $err = 'only PNG, JPEG, WebP or GIF images are allowed'; return null; }
        $ext = $allowed[$mime];

        $dims = @getimagesize($file['tmp_name']);
        if ($dims === false) { $err = 'file is not a readable image'; return null; }

        $purpose = in_array($purpose, ['qr', 'redeem'], true) ? $purpose : 'img';
        $subSafe = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $subId);
        if ($subSafe === '') { $err = 'invalid sub scope'; return null; }
        $segSafe = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $scopeSeg);
        if ($segSafe === '') $segSafe = 'misc';

        /* api/lib → api → public_html */
        $uploadsRoot = dirname(__DIR__, 2) . '/uploads';
        $dir = $uploadsRoot . '/' . $segSafe . '/' . $subSafe;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $err = 'could not create upload directory'; return null;
        }

        /* One-time hardening: nothing under uploads/ ever executes. */
        $hardened = $uploadsRoot . '/.htaccess';
        if (!is_file($hardened)) {
            @file_put_contents(
                $hardened,
                "# Auto-generated — reward image uploads are static assets only.\n"
                . "php_flag engine off\n"
                . "<IfModule mod_php.c>\n  php_admin_flag engine off\n</IfModule>\n"
                . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phps\n"
                . "AddType text/plain .php .phtml .php3 .php4 .php5 .php7 .phps\n"
            );
        }

        try { $rand = bin2hex(random_bytes(12)); }
        catch (Throwable $e) { $rand = substr(hash('sha256', uniqid('', true)), 0, 24); }
        $fname = $purpose . '-' . $rand . '.' . $ext;
        $dest  = $dir . '/' . $fname;

        if (!@move_uploaded_file($file['tmp_name'], $dest)) { $err = 'could not store image'; return null; }
        @chmod($dest, 0644);

        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'www.rewards-foundry.com';
        return [
            'url'    => $proto . '://' . $host . '/uploads/' . $segSafe . '/' . $subSafe . '/' . $fname,
            'width'  => (int) ($dims[0] ?? 0),
            'height' => (int) ($dims[1] ?? 0),
        ];
    }
}
