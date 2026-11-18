<?php
/* =====================================================================
   CORE::EDITOR  (server version)
   Put this file in any folder on your server and open it in a browser:
       https://your-site.com/editor.php
   It starts in the folder where this file is, and you can go UP to
   see the rest of the server. ZIP files open like folders.

   PASSWORD: empty = no login. To protect it, write a password below,
   for example:  $PASSWORD = 'MySecret123';
   ===================================================================== */

$PASSWORD = '';
$ROOT     = '';   // empty = whole server. To limit access, put a folder path, e.g. '/home/user'

/* ===================================================================== */

session_start();
error_reporting(0);
@set_time_limit(300);
@ini_set('memory_limit', '512M');
$BASE   = realpath(__DIR__);
$SELF   = basename(__FILE__);
$IGNORE = ['node_modules', '.git', '.next', '__pycache__', '.venv', '.idea', '.gradle', '.dart_tool', 'vendor'];
$MAX    = 3 * 1024 * 1024;
$IMGX   = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jfif' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'ico' => 'image/x-icon', 'svg' => 'image/svg+xml', 'avif' => 'image/avif'];
$MEDIA  = ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg', 'mov' => 'video/quicktime', 'mkv' => 'video/x-matroska', '3gp' => 'video/3gpp',
           'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'flac' => 'audio/flac', 'opus' => 'audio/ogg', 'weba' => 'audio/webm',
           'pdf' => 'application/pdf'];
$NOSNIFF = ['js','mjs','ts','tsx','jsx','html','htm','css','scss','less','json','md','txt','py','java','kt','dart','c','cpp','h','cs','go','rs','rb','swift','xml','yml','yaml','sql','sh','bat','ini','env','log','csv','vue','conf','toml','lock','gradle','properties','map','url','lnk',
            'zip','rar','7z','gz','tar','exe','msi','dll','apk','iso','doc','docx','xls','xlsx','ppt','pptx','avi','mp4','m4v','webm','mov','mkv','mp3','wav','ogg','m4a','aac','flac','pdf','woff','woff2','ttf','otf','eot','psd','ai'];
$TEXTX  = ['php','js','mjs','ts','tsx','jsx','html','htm','css','scss','less','json','md','txt','py','java','kt','dart','c','cpp','h','cs','go','rs','rb','swift','xml','yml','yaml','sql','sh','bat','ini','env','log','csv','vue','htaccess','conf','toml','lock','gradle','properties','twig','tpl','map'];
const ZSEP = '::';

/* ---------------- helpers ---------------- */
function out($data, $code = 200) {
    while (ob_get_level()) @ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function fail($msg, $code = 400) { out(['error' => $msg], $code); }
function norm($p) { return str_replace('\\', '/', $p); }
function ext($n) { return strtolower(pathinfo($n, PATHINFO_EXTENSION)); }
function ms($t) { return $t ? $t * 1000 : null; }
function validName($n) { return $n !== '' && $n !== '.' && $n !== '..' && !preg_match('#[/\\\\\x00]#', $n) && strpos($n, ZSEP) === false && strlen($n) < 250; }
function joinP($dir, $name) { return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name; }
function inRoot($full) {
    global $ROOT;
    if ($ROOT === '') return true;
    $r = realpath($ROOT);
    return $r && ($full === $r || strpos($full, rtrim($r, '/\\') . DIRECTORY_SEPARATOR) === 0);
}
function topRoot() { global $ROOT; return $ROOT !== '' ? norm(realpath($ROOT)) : '/'; }
function parentOf($full) {
    global $ROOT;
    $par = dirname($full);
    if ($par === $full) return null;
    if ($ROOT !== '' && $full === realpath($ROOT)) return null;
    return norm($par);
}
function sniffMime($b, $allowSvg = true) {
    if (strncmp($b, "\x89PNG", 4) === 0) return 'image/png';
    if (strncmp($b, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
    if (strncmp($b, 'GIF8', 4) === 0) return 'image/gif';
    if (strncmp($b, 'RIFF', 4) === 0 && substr($b, 8, 4) === 'WEBP') return 'image/webp';
    if (strncmp($b, "\x00\x00\x01\x00", 4) === 0) return 'image/x-icon';
    if (strncmp($b, 'BM', 2) === 0 && strlen($b) > 26 && substr($b, 6, 4) === "\0\0\0\0") return 'image/bmp';
    if (substr($b, 4, 8) === 'ftypavif') return 'image/avif';
    if (!$allowSvg) return null;
    $h = ltrim(substr($b, 0, 300));
    if (stripos($h, '<svg') === 0 || (stripos($h, '<?xml') === 0 && stripos($b, '<svg') !== false)) return 'image/svg+xml';
    return null;
}
function mimeOf($name, $head) {
    global $IMGX;
    $e = ext($name);
    if (isset($IMGX[$e])) return $IMGX[$e];
    global $TEXTX;
    return sniffMime($head, !in_array($e, $TEXTX));
}
function kindOf($mime) { return strpos($mime, 'video/') === 0 ? 'video' : (strpos($mime, 'audio/') === 0 ? 'audio' : 'pdf'); }
function freeName($dir, $name, $tag = ' (copy)', $exists = null) {
    $exists = $exists ?: function ($p) { return file_exists($p); };
    $p = joinP($dir, $name);
    if (!$exists($p)) return $p;
    $dot = strrpos($name, '.');
    $base = ($dot > 0) ? substr($name, 0, $dot) : $name;
    $ext  = ($dot > 0) ? substr($name, $dot) : '';
    for ($i = 1; $i < 1000; $i++) {
        $p = joinP($dir, $base . $tag . ($i > 1 ? " $i" : '') . $ext);
        if (!$exists($p)) return $p;
    }
    return false;
}
function rrmdir($d) {
    if (is_link($d) || !is_dir($d)) return @unlink($d);
    foreach (scandir($d) as $n) { if ($n === '.' || $n === '..') continue; if (!rrmdir(joinP($d, $n))) return false; }
    return @rmdir($d);
}
function rcopy($a, $b) {
    if (is_file($a)) return @copy($a, $b);
    if (!@mkdir($b)) return false;
    foreach (scandir($a) as $n) { if ($n === '.' || $n === '..') continue; if (!rcopy(joinP($a, $n), joinP($b, $n))) return false; }
    return true;
}

/* ---------------- path resolving (normal + inside ZIP) ---------------- */
function resolveP($p) {
    $p = trim((string)$p);
    global $BASE;
    $i = strpos($p, ZSEP);
    if ($i === false) {
        $full = realpath($p === '' ? $BASE : $p);
        if ($full === false || !inRoot($full)) return false;
        return ['t' => 'fs', 'p' => $full];
    }
    $zip = realpath(substr($p, 0, $i));
    if ($zip === false || !inRoot($zip) || !is_file($zip)) return false;
    $inner = trim(norm(substr($p, $i + strlen(ZSEP))), '/');
    if (strpos('/' . $inner . '/', '/../') !== false) return false;
    return ['t' => 'zip', 'zip' => $zip, 'in' => $inner];
}
function asZip($r) { return ($r && $r['t'] === 'fs' && is_file($r['p']) && ext($r['p']) === 'zip') ? ['t' => 'zip', 'zip' => $r['p'], 'in' => ''] : $r; }
function zp($zip, $inner) { return norm($zip) . ZSEP . $inner; }
function zipOpen($zip, $write = false) {
    if (!class_exists('ZipArchive')) fail('ZIP support is not available on this server (PHP zip extension missing)', 500);
    $za = new ZipArchive();
    $r = $za->open($zip);
    if ($r !== true) fail('Could not open ZIP file (damaged or not a ZIP)', 500);
    if ($write && !is_writable($zip)) fail('The server does not allow writing to this ZIP file', 403);
    return $za;
}
function zIsDir($za, $in) {
    if ($in === '') return true;
    if ($za->locateName($in . '/') !== false) return true;
    $pre = $in . '/';
    for ($i = 0; $i < $za->numFiles; $i++) { if (strpos(norm($za->getNameIndex($i)), $pre) === 0) return true; }
    return false;
}
function zIsFile($za, $in) { return $in !== '' && $za->locateName($in) !== false && substr($in, -1) !== '/'; }
function zEntries($za, $in) {   // all entry names at or under $in
    $outp = []; $pre = $in === '' ? '' : $in . '/';
    for ($i = 0; $i < $za->numFiles; $i++) {
        $n = $za->getNameIndex($i); $nn = norm($n);
        if ($nn === $in || $nn === $pre || ($pre !== '' && strpos($nn, $pre) === 0) || $pre === '') $outp[] = $n;
    }
    return $outp;
}
function zExists($za, $in) { return zIsFile($za, $in) || zIsDir($za, $in); }

/* collect a source (file or folder, normal or zip) as a list of entries */
function collect($r) {
    $list = [];
    if ($r['t'] === 'fs') {
        $base = $r['p'];
        if (is_file($base)) return [['rel' => '', 'dir' => false, 'file' => $base]];
        $list[] = ['rel' => '', 'dir' => true];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($it as $f) $list[] = ['rel' => norm(substr($f->getPathname(), strlen(rtrim($base, '/\\')) + 1)), 'dir' => $f->isDir(), 'file' => $f->getPathname()];
        return $list;
    }
    $za = zipOpen($r['zip']); $in = $r['in'];
    if (zIsFile($za, $in)) { $c = $za->getFromName($in); $za->close(); return [['rel' => '', 'dir' => false, 'data' => $c]]; }
    $list[] = ['rel' => '', 'dir' => true]; $pre = $in === '' ? '' : $in . '/';
    for ($i = 0; $i < $za->numFiles; $i++) {
        $n = norm($za->getNameIndex($i));
        if ($pre !== '' && strpos($n, $pre) !== 0) continue;
        $rel = rtrim(substr($n, strlen($pre)), '/'); if ($rel === '') continue;
        if (substr($n, -1) === '/') $list[] = ['rel' => $rel, 'dir' => true];
        else {
            $list[] = ['rel' => $rel, 'dir' => false, 'data' => $za->getFromIndex($i)];
            $parts = explode('/', $rel); array_pop($parts); $acc = '';
            foreach ($parts as $pp) { $acc = $acc === '' ? $pp : $acc . '/' . $pp; $list[] = ['rel' => $acc, 'dir' => true]; }
        }
    }
    $za->close();
    return $list;
}
/* write collected entries into a destination folder (normal or zip) under name $name */
function writeTo($dst, $name, $list) {
    if ($dst['t'] === 'fs') {
        $target = joinP($dst['p'], $name);
        foreach ($list as $e) {
            $p = $e['rel'] === '' ? $target : joinP($target, str_replace('/', DIRECTORY_SEPARATOR, $e['rel']));
            if ($e['dir']) { if (!is_dir($p) && !@mkdir($p, 0777, true)) return false; continue; }
            if (!is_dir(dirname($p))) @mkdir(dirname($p), 0777, true);
            $ok = isset($e['file']) ? @copy($e['file'], $p) : (@file_put_contents($p, $e['data']) !== false);
            if (!$ok) return false;
        }
        return norm($target);
    }
    $za = zipOpen($dst['zip'], true);
    $target = ($dst['in'] === '' ? '' : $dst['in'] . '/') . $name;
    foreach ($list as $e) {
        $p = $e['rel'] === '' ? $target : $target . '/' . $e['rel'];
        if ($e['dir']) { $za->addEmptyDir($p); continue; }
        if (isset($e['file'])) $za->addFile($e['file'], $p); else $za->addFromString($p, $e['data']);
    }
    $ok = $za->close();
    return $ok ? zp($dst['zip'], $target) : false;
}
function freeIn($dst, $name, $tag) {
    if ($dst['t'] === 'fs') { $p = freeName($dst['p'], $name, $tag); return $p ? basename($p) : false; }
    $za = zipOpen($dst['zip']); $pre = $dst['in'] === '' ? '' : $dst['in'] . '/';
    $ex = function ($p) use ($za, $pre) { return zExists($za, $pre . basename(norm($p))); };
    $p = freeName('x', $name, $tag, $ex); $za->close();
    return $p ? basename(norm($p)) : false;
}
function removeAny($r) {
    if ($r['t'] === 'fs') return rrmdir($r['p']);
    $za = zipOpen($r['zip'], true);
    foreach (zEntries($za, $r['in']) as $n) $za->deleteName($n);
    return $za->close();
}

/* ---------------- auth ---------------- */
$noPass = ($PASSWORD === '');
if ($noPass) { $_SESSION['ce_ok'] = true; }
if (empty($_SESSION['ce_token'])) $_SESSION['ce_token'] = bin2hex(random_bytes(16));
if (!$noPass && isset($_GET['logout'])) { session_destroy(); header('Location: ' . $SELF); exit; }
$loginError = '';
if (!$noPass && isset($_POST['login_pass'])) {
    if (hash_equals($PASSWORD, (string)$_POST['login_pass'])) {
        session_regenerate_id(true);
        $_SESSION['ce_ok'] = true;
        $_SESSION['ce_token'] = bin2hex(random_bytes(16));
        header('Location: ' . $SELF); exit;
    }
    sleep(2);
    $loginError = 'Wrong password';
}
$loggedIn = !empty($_SESSION['ce_ok']);

/* ---------------- API ---------------- */
if (isset($_GET['api'])) {
    if (!$loggedIn) fail('Please log in', 401);
    $api = $_GET['api'];
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    if ($isPost && !hash_equals($_SESSION['ce_token'], (string)($_POST['token'] ?? ''))) fail('Session expired - reload the page', 403);
    session_write_close();   // let many requests (thumbnails) run in parallel

    /* ---- list a folder (or a folder inside a ZIP) ---- */
    if ($api === 'list') {
        $r = resolveP($_GET['path'] ?? '');
        if (!$r) fail('Folder not found', 404);
        if ($r['t'] === 'fs' && is_file($r['p']) && ext($r['p']) === 'zip') $r = ['t' => 'zip', 'zip' => $r['p'], 'in' => ''];
        if ($r['t'] === 'zip') {
            $za = zipOpen($r['zip']); $in = $r['in'];
            if (!zIsDir($za, $in)) fail('Folder not found inside ZIP', 404);
            $pre = $in === '' ? '' : $in . '/'; $dirs = []; $files = []; $seen = [];
            for ($i = 0; $i < $za->numFiles; $i++) {
                $st = $za->statIndex($i); $n = norm($st['name']);
                if ($pre !== '' && strpos($n, $pre) !== 0) continue;
                $rest = substr($n, strlen($pre)); if ($rest === '' || $rest === false) continue;
                $sl = strpos($rest, '/');
                if ($sl === false) {
                    $files[] = ['name' => $rest, 'path' => zp($r['zip'], $pre . $rest), 'size' => $st['size'], 'date' => ms($st['mtime']), 'hidden' => $rest[0] === '.', 'img' => isset($IMGX[ext($rest)])];
                } else {
                    $dn = substr($rest, 0, $sl);
                    if (!isset($seen[$dn])) { $seen[$dn] = 1; $dirs[] = ['name' => $dn, 'path' => zp($r['zip'], $pre . $dn), 'date' => ms($st['mtime']), 'hidden' => $dn[0] === '.']; }
                }
            }
            $za->close();
            $cmp = function ($a, $b) { return strcasecmp($a['name'], $b['name']); };
            usort($dirs, $cmp); usort($files, $cmp);
            $name = $in === '' ? basename($r['zip']) : basename($in);
            $parent = $in === '' ? norm(dirname($r['zip'])) : zp($r['zip'], (strpos($in, '/') === false ? '' : dirname($in)));
            out(['path' => zp($r['zip'], $in), 'name' => $name, 'display' => norm($r['zip']) . ($in === '' ? '' : ' > ' . str_replace('/', ' > ', $in)),
                 'parent' => $parent, 'isRoot' => false, 'isZip' => true, 'writable' => is_writable($r['zip']), 'dirs' => $dirs, 'files' => $files]);
        }
        $dir = $r['p'];
        if (!is_dir($dir)) fail('Folder not found', 404);
        $list = @scandir($dir);
        if ($list === false) fail('No permission to open this folder', 403);
        $dirs = []; $files = []; $sniffed = 0;
        foreach ($list as $n) {
            if ($n === '.' || $n === '..') continue;
            $p = joinP($dir, $n);
            $item = ['name' => $n, 'path' => norm($p), 'date' => ms(@filemtime($p)), 'hidden' => $n[0] === '.'];
            if (is_dir($p)) { $dirs[] = $item; continue; }
            $item['size'] = @filesize($p);
            $e = ext($n);
            if ($e === 'zip') $item['zip'] = true;
            if (isset($IMGX[$e])) $item['img'] = true;
            elseif (isset($MEDIA[$e])) $item['media'] = kindOf($MEDIA[$e]);
            elseif (!in_array($e, $NOSNIFF) && $item['size'] > 8 && $sniffed < 400) {
                $sniffed++; $fh = @fopen($p, 'rb');
                if ($fh) { $head = fread($fh, 320); fclose($fh); if (sniffMime($head, !in_array($e, $TEXTX))) $item['img'] = true; }
            }
            $files[] = $item;
        }
        $cmp = function ($a, $b) { return strcasecmp($a['name'], $b['name']); };
        usort($dirs, $cmp); usort($files, $cmp);
        $name = basename($dir); if ($name === '') $name = '/';
        out(['path' => norm($dir), 'name' => $name, 'parent' => parentOf($dir), 'isRoot' => parentOf($dir) === null, 'isZip' => false, 'writable' => is_writable($dir), 'dirs' => $dirs, 'files' => $files]);
    }

    /* ---- deep search (normal folders) ---- */
    if ($api === 'search') {
        $q = strtolower(trim($_GET['q'] ?? ''));
        $r = asZip(resolveP($_GET['in'] ?? ''));
        $res = []; $partial = false;
        if ($q !== '' && $r && $r['t'] === 'zip') {
            $za = zipOpen($r['zip']); $pre = $r['in'] === '' ? '' : $r['in'] . '/';
            for ($i = 0; $i < $za->numFiles && count($res) < 300; $i++) {
                $n = rtrim(norm($za->getNameIndex($i)), '/');
                if ($pre !== '' && strpos($n, $pre) !== 0) continue;
                if (strpos(strtolower(basename($n)), $q) !== false) $res[] = ['name' => basename($n), 'path' => zp($r['zip'], $n), 'dir' => substr($za->getNameIndex($i), -1) === '/'];
            }
            $za->close();
        } elseif ($q !== '' && $r && is_dir($r['p'])) {
            $start = microtime(true);
            $it = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($r['p'], FilesystemIterator::SKIP_DOTS),
                    function ($f) use ($IGNORE) { return !($f->isDir() && (in_array($f->getFilename(), $IGNORE) || in_array(norm($f->getPathname()), ['/proc', '/sys', '/dev', '/run']))); }
                ),
                RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($it as $f) {
                if (microtime(true) - $start > 8) { $partial = true; break; }
                if (strpos(strtolower($f->getFilename()), $q) !== false) {
                    $res[] = ['name' => $f->getFilename(), 'path' => norm($f->getPathname()), 'dir' => $f->isDir(), 'zip' => !$f->isDir() && ext($f->getFilename()) === 'zip'];
                    if (count($res) >= 300) { $partial = true; break; }
                }
            }
        }
        out(['items' => $res, 'partial' => $partial]);
    }

    /* ---- read a file (normal or inside ZIP) ---- */
    if ($api === 'read') {
        $r = resolveP($_GET['path'] ?? '');
        if (!$r) fail('File not found', 404);
        if ($r['t'] === 'zip') {
            $za = zipOpen($r['zip']);
            if (!zIsFile($za, $r['in'])) fail('File not found inside ZIP', 404);
            $st = $za->statName($r['in']); $size = $st['size']; $date = ms($st['mtime']);
            if (isset($MEDIA[ext($r['in'])])) { $za->close(); out(['kind' => kindOf($MEDIA[ext($r['in'])]), 'mime' => $MEDIA[ext($r['in'])], 'size' => $size, 'date' => $date]); }
            if ($size > 20 * 1024 * 1024) { $za->close(); out(['kind' => 'big', 'size' => $size, 'date' => $date]); }
            $c = $za->getFromName($r['in']); $za->close(); $writable = is_writable($r['zip']); $name = $r['in'];
        } else {
            $f = $r['p'];
            if (!is_file($f)) fail('File not found', 404);
            if (!is_readable($f)) fail('No permission to read this file', 403);
            $size = filesize($f); $date = ms(filemtime($f)); $name = $f; $writable = is_writable($f);
            $fh = fopen($f, 'rb'); $head = fread($fh, 320); fclose($fh);
            if (isset($MEDIA[ext($f)])) out(['kind' => kindOf($MEDIA[ext($f)]), 'mime' => $MEDIA[ext($f)], 'size' => $size, 'date' => $date]);
            if (mimeOf($f, $head)) out(['kind' => 'img', 'mime' => mimeOf($f, $head), 'size' => $size, 'date' => $date]);
            if (ext($f) === 'zip') out(['kind' => 'zip', 'size' => $size, 'date' => $date]);
            if ($size > $MAX) out(['kind' => 'big', 'size' => $size, 'date' => $date]);
            $c = file_get_contents($f);
        }
        $m = mimeOf($name, substr($c, 0, 320));
        if ($m) out(['kind' => 'img', 'mime' => $m, 'size' => $size, 'date' => $date]);
        if (strlen($c) > $MAX) out(['kind' => 'big', 'size' => $size, 'date' => $date]);
        if (strpos(substr($c, 0, 8000), "\0") !== false) out(['kind' => 'bin', 'size' => $size, 'date' => $date]);
        if (substr($c, 0, 3) === "\xEF\xBB\xBF") $c = substr($c, 3);
        if (!mb_check_encoding($c, 'UTF-8')) $c = mb_convert_encoding($c, 'UTF-8', 'Windows-1252');
        out(['kind' => 'text', 'content' => $c, 'size' => $size, 'date' => $date, 'writable' => $writable]);
    }

    /* ---- raw image / thumbnail / download ---- */
    if ($api === 'raw' || $api === 'thumb' || $api === 'download') {
        $r = resolveP($_GET['path'] ?? '');
        $data = null; $file = null; $name = '';
        if ($r && $r['t'] === 'zip') {
            $za = zipOpen($r['zip']);
            if (zIsFile($za, $r['in'])) { $data = $za->getFromName($r['in']); $name = basename($r['in']); }
            $za->close();
        } elseif ($r && is_file($r['p'])) { $file = $r['p']; $name = basename($file); }
        while (ob_get_level()) @ob_end_clean();
        if ($data === null && $file === null) { http_response_code(404); header('Content-Type: text/plain'); echo 'File not found'; exit; }
        $size = $file ? filesize($file) : strlen($data);
        if ($api === 'download') {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
            header('Content-Length: ' . $size);
            if ($file) readfile($file); else echo $data;
            exit;
        }
        if ($file) { $fh = fopen($file, 'rb'); $head = fread($fh, 320); fclose($fh); } else $head = substr($data, 0, 320);
        $mime = mimeOf($name, $head);
        if (!$mime) { http_response_code(415); header('Content-Type: text/plain'); echo 'Not an image'; exit; }
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        if ($mime === 'image/svg+xml') header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
        if ($api === 'thumb' && $mime !== 'image/svg+xml' && function_exists('imagecreatefromstring') && $size < 40 * 1024 * 1024) {
            $cdir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'core_editor_thumbs';
            if (!is_dir($cdir)) @mkdir($cdir, 0777, true);
            $cf = $cdir . DIRECTORY_SEPARATOR . md5($_GET['path'] . '|' . $size . '|' . ($file ? filemtime($file) : '')) . '.png';
            header('Cache-Control: private, max-age=604800');
            if (is_file($cf)) { header('Content-Type: image/png'); header('Content-Length: ' . filesize($cf)); readfile($cf); exit; }
            if ($size < 60 * 1024) { header('Content-Type: ' . $mime); header('Content-Length: ' . $size); if ($file) readfile($file); else echo $data; exit; }
            $im = @imagecreatefromstring($file ? file_get_contents($file) : $data);
            if ($im) {
                $w = imagesx($im); $h = imagesy($im); $s = 120 / max($w, $h);
                if ($s < 1) {
                    $tw = max(1, (int)round($w * $s)); $th = max(1, (int)round($h * $s));
                    $t = imagecreatetruecolor($tw, $th); imagealphablending($t, false); imagesavealpha($t, true);
                    imagecopyresampled($t, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
                    @imagepng($t, $cf);
                    header('Content-Type: image/png');
                    if (is_file($cf)) { header('Content-Length: ' . filesize($cf)); readfile($cf); } else imagepng($t);
                    exit;
                }
            }
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        if ($file) readfile($file); else echo $data;
        exit;
    }

    /* ---- stream video / audio / pdf (supports seeking) ---- */
    if ($api === 'media') {
        $r = resolveP($_GET['path'] ?? '');
        $data = null; $file = null; $name = '';
        if ($r && $r['t'] === 'zip') { $za = zipOpen($r['zip']); if (zIsFile($za, $r['in'])) { $data = $za->getFromName($r['in']); $name = basename($r['in']); } $za->close(); }
        elseif ($r && is_file($r['p'])) { $file = $r['p']; $name = basename($file); }
        while (ob_get_level()) @ob_end_clean();
        if ($data === null && $file === null) { http_response_code(404); header('Content-Type: text/plain'); echo 'File not found'; exit; }
        $size = $file ? filesize($file) : strlen($data);
        $mime = $MEDIA[ext($name)] ?? 'application/octet-stream';
        $start = 0; $end = $size - 1;
        header('Accept-Ranges: bytes');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"');
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
            if ($m[1] === '') $start = max(0, $size - (int)$m[2]);
            else { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $size - 1); }
            if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Length: ' . ($end - $start + 1));
        if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;
        if ($file) {
            $fh = fopen($file, 'rb'); fseek($fh, $start); $left = $end - $start + 1;
            while ($left > 0 && !feof($fh) && !connection_aborted()) { $chunk = fread($fh, (int)min(1048576, $left)); echo $chunk; @flush(); $left -= strlen($chunk); }
            fclose($fh);
        } else echo substr($data, $start, $end - $start + 1);
        exit;
    }

    /* ---- download a folder (or zip-folder) as ZIP ---- */
    if ($api === 'zipdl') {
        $r = resolveP($_GET['path'] ?? '');
        if (!$r) { http_response_code(404); echo 'Not found'; exit; }
        $list = collect($r);
        $root = $r['t'] === 'fs' ? (basename($r['p']) ?: 'root') : ($r['in'] === '' ? basename($r['zip'], '.zip') : basename($r['in']));
        $tmp = tempnam(sys_get_temp_dir(), 'cez');
        if (!class_exists('ZipArchive')) { http_response_code(500); echo 'ZIP is not available on this server'; exit; }
        $zip = new ZipArchive(); $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($list as $e) {
            $p = $root . ($e['rel'] === '' ? '' : '/' . $e['rel']);
            if ($e['dir']) $zip->addEmptyDir($p); elseif (isset($e['file'])) $zip->addFile($e['file'], $p); else $zip->addFromString($p, $e['data']);
        }
        $zip->close();
        while (ob_get_level()) @ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $root) . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp); @unlink($tmp); exit;
    }

    if ($api === 'info') {
        $r = resolveP($_GET['path'] ?? ''); $d = $r && $r['t'] === 'fs' ? $r['p'] : $BASE;
        out(['php' => PHP_VERSION, 'os' => PHP_OS, 'server' => $_SERVER['SERVER_SOFTWARE'] ?? '', 'user' => get_current_user(),
             'free' => @disk_free_space($d), 'total' => @disk_total_space($d), 'zip' => class_exists('ZipArchive'), 'gd' => function_exists('imagecreatefromstring')]);
    }

    if (!$isPost) fail('Bad request');

    /* ---- save ---- */
    if ($api === 'save') {
        $r = resolveP($_POST['path'] ?? ''); $content = $_POST['content'] ?? '';
        if (!$r) fail('File not found', 404);
        if ($r['t'] === 'zip') {
            $za = zipOpen($r['zip'], true);
            if (!$za->addFromString($r['in'], $content) || !$za->close()) fail('Save into ZIP failed', 500);
            clearstatcache(); out(['ok' => true, 'size' => strlen($content), 'date' => ms(time())]);
        }
        $f = $r['p'];
        if (!is_file($f)) fail('File not found', 404);
        if (!is_writable($f)) fail('The server does not allow writing to this file', 403);
        if (file_put_contents($f, $content) === false) fail('Save failed', 500);
        clearstatcache();
        out(['ok' => true, 'size' => filesize($f), 'date' => ms(filemtime($f))]);
    }

    /* ---- new file / folder ---- */
    if ($api === 'mkfile' || $api === 'mkdir') {
        $r = resolveP($_POST['dir'] ?? ''); $n = trim((string)($_POST['name'] ?? '')); $content = $_POST['content'] ?? '';
        if (!$r) fail('Folder not found', 404);
        if (!validName($n)) fail('Invalid name');
        if ($r['t'] === 'fs' && is_file($r['p']) && ext($r['p']) === 'zip') $r = ['t' => 'zip', 'zip' => $r['p'], 'in' => ''];
        if ($r['t'] === 'zip') {
            $za = zipOpen($r['zip'], true); $p = ($r['in'] === '' ? '' : $r['in'] . '/') . $n;
            if (zExists($za, $p)) fail('Something with this name already exists');
            $ok = $api === 'mkdir' ? $za->addEmptyDir($p) : $za->addFromString($p, $content);
            if (!$ok || !$za->close()) fail('Could not create it inside ZIP', 500);
            out(['ok' => true, 'path' => zp($r['zip'], $p)]);
        }
        $d = $r['p']; if (!is_dir($d)) fail('Folder not found', 404);
        $p = joinP($d, $n);
        if (file_exists($p)) fail('Something with this name already exists');
        $ok = $api === 'mkdir' ? @mkdir($p) : (@file_put_contents($p, $content) !== false);
        if (!$ok) fail('Could not create it (permission?)', 500);
        out(['ok' => true, 'path' => norm($p)]);
    }

    /* ---- rename ---- */
    if ($api === 'rename') {
        $r = resolveP($_POST['path'] ?? ''); $n = trim((string)($_POST['name'] ?? ''));
        if (!$r) fail('Not found', 404);
        if (!validName($n)) fail('Invalid name');
        if ($r['t'] === 'zip') {
            if ($r['in'] === '') fail('Rename the ZIP file from its parent folder');
            $za = zipOpen($r['zip'], true); $in = $r['in'];
            $par = strpos($in, '/') === false ? '' : dirname($in) . '/'; $np = $par . $n;
            if (zExists($za, $np)) fail('Something with this name already exists');
            if (zIsFile($za, $in)) $za->renameName($in, $np);
            else foreach (zEntries($za, $in) as $e) $za->renameName($e, $np . substr(norm($e), strlen($in)));
            if (!$za->close()) fail('Rename inside ZIP failed', 500);
            out(['ok' => true, 'path' => zp($r['zip'], $np)]);
        }
        $f = $r['p']; $np = joinP(dirname($f), $n);
        if (file_exists($np)) fail('Something with this name already exists');
        if (!@rename($f, $np)) fail('Rename failed (permission?)', 500);
        out(['ok' => true, 'path' => norm($np)]);
    }

    /* ---- duplicate ---- */
    if ($api === 'dup') {
        $r = resolveP($_POST['path'] ?? '');
        if (!$r) fail('Not found', 404);
        if ($r['t'] === 'fs') {
            $np = freeName(dirname($r['p']), basename($r['p']));
            if (!$np || !rcopy($r['p'], $np)) fail('Copy failed (permission?)', 500);
            out(['ok' => true, 'path' => norm($np)]);
        }
        $par = strpos($r['in'], '/') === false ? '' : dirname($r['in']);
        $dst = ['t' => 'zip', 'zip' => $r['zip'], 'in' => $par];
        $nm = freeIn($dst, basename($r['in']), ' (copy)');
        $res = writeTo($dst, $nm, collect($r));
        if (!$res) fail('Copy failed', 500);
        out(['ok' => true, 'path' => $res]);
    }

    /* ---- delete ---- */
    if ($api === 'delete') {
        $r = resolveP($_POST['path'] ?? '');
        if (!$r) fail('Not found', 404);
        if ($r['t'] === 'fs') {
            $f = $r['p'];
            if ($f === $BASE || $f === realpath(__FILE__) || strpos($BASE . DIRECTORY_SEPARATOR, rtrim($f, '/\\') . DIRECTORY_SEPARATOR) === 0) fail('You cannot delete the editor itself or a folder that contains it');
        } elseif ($r['in'] === '') fail('Delete the ZIP file from its parent folder');
        if (!removeAny($r)) fail('Delete failed (permission?)', 500);
        out(['ok' => true]);
    }

    /* ---- paste (copy / move), works between folders and ZIPs ---- */
    if ($api === 'paste') {
        $dst = resolveP($_POST['dest'] ?? ''); $mode = ($_POST['mode'] ?? 'copy') === 'move' ? 'move' : 'copy';
        $items = json_decode($_POST['items'] ?? '[]', true);
        if (!$dst || !is_array($items)) fail('Bad paste target');
        if ($dst['t'] === 'fs' && is_file($dst['p']) && ext($dst['p']) === 'zip') $dst = ['t' => 'zip', 'zip' => $dst['p'], 'in' => ''];
        if ($dst['t'] === 'fs' && !is_dir($dst['p'])) fail('Paste target is not a folder');
        $done = 0; $errs = [];
        foreach ($items as $src) {
            $s = resolveP($src);
            if (!$s) { $errs[] = basename($src) . ' (not found)'; continue; }
            $name = $s['t'] === 'fs' ? basename($s['p']) : ($s['in'] === '' ? basename($s['zip']) : basename($s['in']));
            if ($s['t'] === 'fs' && $dst['t'] === 'fs') {
                $from = rtrim($s['p'], '/\\'); $to = rtrim($dst['p'], '/\\');
                if ($to === $from || strpos($to . DIRECTORY_SEPARATOR, $from . DIRECTORY_SEPARATOR) === 0) { $errs[] = "$name (cannot paste a folder into itself)"; continue; }
                if ($mode === 'move' && dirname($from) === $to) { $done++; continue; }
                $target = freeName($to, $name, $mode === 'copy' ? ' (copy)' : ' (2)');
                $ok = $mode === 'move' ? (@rename($from, $target) || (rcopy($from, $target) && rrmdir($from))) : rcopy($from, $target);
                if ($ok) $done++; else $errs[] = $name;
                continue;
            }
            if ($s['t'] === 'zip' && $dst['t'] === 'zip' && $s['zip'] === $dst['zip'] && ($dst['in'] === $s['in'] || strpos($dst['in'] . '/', $s['in'] . '/') === 0) && $s['in'] !== '') { $errs[] = "$name (cannot paste into itself)"; continue; }
            $nm = freeIn($dst, $name, $mode === 'copy' ? ' (copy)' : ' (2)');
            $res = $nm ? writeTo($dst, $nm, collect($s)) : false;
            if (!$res) { $errs[] = $name; continue; }
            if ($mode === 'move' && !($s['t'] === 'zip' && $s['in'] === '')) removeAny($s);
            $done++;
        }
        if (!$done && $errs) fail('Paste failed: ' . implode(', ', $errs));
        out(['ok' => true, 'count' => $done, 'failed' => $errs]);
    }

    /* ---- extract a ZIP next to it ---- */
    if ($api === 'extract') {
        $r = resolveP($_POST['path'] ?? '');
        if (!$r || $r['t'] !== 'fs' || !is_file($r['p'])) fail('ZIP file not found', 404);
        $za = zipOpen($r['p']); $dir = dirname($r['p']);
        if (!is_writable($dir)) fail('The server does not allow writing to this folder', 403);
        $target = freeName($dir, basename($r['p'], '.' . pathinfo($r['p'], PATHINFO_EXTENSION)), ' (2)');
        if (!$target || !@mkdir($target)) fail('Could not create folder for extracting', 500);
        $ok = $za->extractTo($target); $za->close();
        if (!$ok) fail('Extract failed', 500);
        out(['ok' => true, 'path' => norm($target)]);
    }

    /* ---- compress file / folder into a new ZIP ---- */
    if ($api === 'compress') {
        $r = resolveP($_POST['path'] ?? '');
        if (!$r || $r['t'] !== 'fs') fail('Not found', 404);
        if (!class_exists('ZipArchive')) fail('ZIP is not available on this server', 500);
        $src = rtrim($r['p'], '/\\'); $dir = dirname($src);
        if (!is_writable($dir)) fail('The server does not allow writing to this folder', 403);
        $zipPath = freeName($dir, basename($src) . '.zip', ' (2)');
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) fail('Could not create ZIP', 500);
        foreach (collect($r) as $e) {
            $p = basename($src) . ($e['rel'] === '' ? '' : '/' . $e['rel']);
            if ($e['dir']) $zip->addEmptyDir($p); else $zip->addFile($e['file'], $p);
        }
        if (!$zip->close()) fail('Could not write ZIP', 500);
        out(['ok' => true, 'path' => norm($zipPath)]);
    }

    /* ---- upload (into a folder or into a ZIP) ---- */
    if ($api === 'upload') {
        $r = resolveP($_POST['dir'] ?? '');
        if (!$r) fail('Folder not found', 404);
        if (empty($_FILES['files'])) fail('No files received (maybe too big? server limit is ' . ini_get('upload_max_filesize') . ')');
        $F = $_FILES['files']; $n = 0; $errs = [];
        if ($r['t'] === 'fs' && is_file($r['p']) && ext($r['p']) === 'zip') $r = ['t' => 'zip', 'zip' => $r['p'], 'in' => ''];
        if ($r['t'] === 'fs' && !is_writable($r['p'])) fail('The server does not allow writing to this folder', 403);
        for ($i = 0; $i < count($F['name']); $i++) {
            $name = basename($F['name'][$i]);
            if ($F['error'][$i] !== UPLOAD_ERR_OK || !validName($name)) { $errs[] = $name; continue; }
            if ($r['t'] === 'fs') {
                $p = freeName($r['p'], $name, ' (new)');
                if ($p && move_uploaded_file($F['tmp_name'][$i], $p)) $n++; else $errs[] = $name;
            } else {
                $nm = freeIn($r, $name, ' (new)');
                if ($nm && writeTo($r, $nm, [['rel' => '', 'dir' => false, 'file' => $F['tmp_name'][$i]]])) $n++; else $errs[] = $name;
            }
        }
        if (!$n) fail('Upload failed: ' . implode(', ', $errs));
        out(['ok' => true, 'count' => $n, 'failed' => $errs]);
    }

    fail('Bad request');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>CORE::EDITOR</title>
<?php if (!$loggedIn): ?><style>
html,body{height:100%;margin:0;background:#050001;color:#ffd6dc;font-family:Consolas,"Courier New",monospace}
.center{height:100%;display:flex;align-items:center;justify-content:center}
.card{background:#140205;padding:30px;width:360px;max-width:92%;border:1px solid #ff1f3d;box-shadow:0 0 22px rgba(255,31,61,.45)}
.card h2{font-weight:400;margin:0 0 18px;color:#ff1f3d}
.card input{width:100%;box-sizing:border-box;padding:9px;margin-bottom:12px;background:#000;border:1px solid #7a0f1c;color:#fff;font:inherit;font-size:14px;outline:none}
.card button{width:100%;padding:9px;background:transparent;color:#ff5468;border:1px solid #ff1f3d;font:inherit;cursor:pointer}
.card button:hover{background:#ff1f3d;color:#000}.err{background:#ff1f3d;color:#000;padding:4px 8px;margin-bottom:10px}
</style><?php else: ?><style id="themeCss"></style><?php endif; ?>
</head>
<body>
<?php if (!$loggedIn): ?>
  <div class="center"><form class="card" method="post">
    <h2>&gt; ACCESS_REQUIRED_</h2>
    <?php if ($loginError): ?><div class="err"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
    <input type="password" name="login_pass" placeholder="enter password..." autofocus>
    <button type="submit">[ LOGIN ]</button>
  </form></div>
<?php else: ?>
<div id="space">
  <canvas id="radar"></canvas>
  <div id="stage">
    <svg id="lines" width="1" height="1"></svg>
    <div id="core" title="Click = parent folder | Right-click = menu">
      <div id="coreSpin1"></div><div id="coreSpin2"></div>
      <div id="coreTxt"><div id="cTag" style="display:none">ZIP</div><div id="cName">...</div><div id="cInfo"></div><div id="cUp"></div></div>
    </div>
  </div>
</div>

<div class="hud" id="hudTL">
  <div class="cTL"></div>
  <div class="hud-w" title="Drag to change width"></div>
  <div class="brand" title="Drag to move this panel">&#9673; CORE::EDITOR <span class="cur">_</span><span class="hud-min" id="tlMin" title="Collapse / expand">&#9662;</span><span id="clock"></span></div>
  <div class="row">
    <button id="backBtn" title="Back (Alt+Left)">&#9664;</button><button id="fwdBtn" title="Forward (Alt+Right)">&#9654;</button><button id="upBtn" title="Parent folder (Backspace)">&#9650; UP</button><button id="homeBtn" title="Home folder (where this file is)">&#8962;</button><button id="rootBtn" title="Top level">ROOT</button><button id="refreshBtn" title="Refresh (F5)">&#8635;</button><button id="hidBtn" title="Show / hide hidden files" class="on">.H</button><button id="favBtn" title="Favorites &amp; recent files">&#9733;</button>
  </div>
  <div class="row">
    <button id="zIn" title="Zoom in (+)">+</button><button id="zOut" title="Zoom out (-)">&#8722;</button><button id="newBtn" title="Create new file / folder (Ctrl+N)">&#10010; NEW</button><button id="uploadBtn" title="Upload files (or drag &amp; drop)">&#8679; UPLOAD</button><button id="themeBtn" title="Change theme">&#9680;</button><button id="fxBtn" title="Animations on / off (off = faster)" class="on">FX</button><button id="helpBtn" title="Help &amp; shortcuts">?</button>
  </div>
  <div class="row" id="pasteRow"><button id="pasteBtn" title="Paste into the current folder (Ctrl+V)">&#9112; PASTE</button> <button id="pasteX" title="Clear clipboard">&#10005;</button></div>
  <div class="row"><input id="pathInput" title="Type a path and press Enter"></div>
  <div class="row"><input id="search" title="Type to filter the circle. Enter = deep search in all sub-folders"></div>
  <div id="results" class="list"></div>
  <div id="quick" class="list"></div>
  <div id="sys"></div>
</div>

<div class="hud" id="hudBL">
  <div class="cBL"></div>
  <div class="hud-w" title="Drag to change width"></div>
  <span class="hud-min" id="blMin" title="Collapse / expand" style="position:absolute;right:8px;top:6px;z-index:2">&#9662;</span>
  <div id="blHead" title="Drag to move this panel">&#9673; INFO</div>
  <div id="info">Hover a circle to see details. &nbsp;<b>?</b> = help</div>
  <div id="help">
    <b>CENTER</b> = current folder (click = go up) &nbsp; <b>NEW</b> = create<br>
    <b>BIG CIRCLE</b> = folder &nbsp; <b>STRIPED</b> = ZIP (opens like a folder)<br>
    <b>PHOTO</b> = image &nbsp; <b>BLUE</b> = video / audio / pdf &nbsp; <b>SMALL</b> = file<br>
    <b>CLICK</b> file = open &nbsp; <b>SHIFT+CLICK</b> = open in NEW pod<br>
    <b>RIGHT-CLICK</b> = copy / cut / rename / delete / zip / more<br>
    Hover a circle + Ctrl+C / Ctrl+X, then Ctrl+V to paste here<br>
    Pods: drag title to move, drag ANY edge to resize<br>
    Drag pod to screen edge = half screen, top = full screen<br>
    Orbs on the left of a pod = its open files<br>
    Minimap on the right = click / drag to jump<br>
    Ctrl+S save &nbsp; Ctrl+Shift+S save all &nbsp; Ctrl+F find / replace<br>
    Ctrl+G line &nbsp; Ctrl+D duplicate line &nbsp; Ctrl+/ comment<br>
    Ctrl+wheel = font size &nbsp; Wheel on image = zoom<br>
    Alt+&larr;/&rarr; back/forward &nbsp; F5 refresh &nbsp; Esc minimize
  </div>
</div>

<div class="hud" id="creator">
  <div class="cTL"></div>
  <div class="brand">&#10010; CREATE <span class="hud-min" id="crClose" title="Close (Esc)">&#10005;</span></div>
  <div class="row"><button id="crFile" class="on">&#9643; FILE</button><button id="crDir">&#9635; FOLDER</button><span id="crWhere"></span></div>
  <div class="lbl">NAME</div>
  <div class="row" style="margin-top:3px"><input id="crName" spellcheck="false"></div>
  <div class="lbl" id="crTplLbl">START WITH</div>
  <div id="crTpl"></div>
  <div class="row"><button id="crGo">CREATE &#9656;</button> <button id="crGoOpen" title="Create and open in a new pod">CREATE + NEW POD</button></div>
</div>

<div id="dock"></div>
<div id="snap"></div>
<div id="toast"></div>
<div id="menu"></div>
<div id="dropzone">DROP FILES TO UPLOAD</div>
<input type="file" id="upInput" multiple style="display:none">

<script>
window.__CE = <?= json_encode(['self' => $SELF, 'token' => $_SESSION['ce_token'], 'home' => norm($BASE), 'root' => topRoot(),
  'docroot' => norm((string)realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), 'origin' => ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')], JSON_UNESCAPED_SLASHES) ?>;
var CSS_TEMPLATE = "* { box-sizing: border-box; margin: 0; padding: 0; }\nhtml, body { width: 100%; height: 100%; overflow: hidden; background: @BG; color: @T; font-family: Consolas, \"Fira Code\", \"Courier New\", monospace; font-size: 13px; }\nbutton { background: transparent; color: @A2; border: 1px solid @A; padding: 3px 8px; cursor: pointer; font-family: Consolas, \"Courier New\", monospace; font-size: 12px; letter-spacing: 1px; text-transform: uppercase; }\nbutton:hover { background: @A; color: #000; box-shadow: 0 0 10px @A; }\nbutton.on { background: @K; color: #fff; }\nbutton.off { opacity: .3; }\ninput { background: #000; border: 1px solid @D; color: @T; font-family: Consolas, \"Courier New\", monospace; font-size: 12px; padding: 4px 7px; outline: none; }\ninput:focus { border-color: @A; box-shadow: 0 0 8px rgba(@RGB,.6); }\n\n/* ===== space ===== */\n#radar { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }\n#space { position: absolute; top: 0; left: 0; right: 0; bottom: 0; overflow: hidden; cursor: move;\n  background-image: linear-gradient(rgba(@RGB,.045) 1px, transparent 1px), linear-gradient(90deg, rgba(@RGB,.045) 1px, transparent 1px); background-size: 40px 40px; }\n#stage { position: absolute; left: 50%; top: 50%; width: 0; height: 0; transition: left .4s, top .4s; }\n#lines { position: absolute; left: 0; top: 0; overflow: visible; pointer-events: none; }\n.ring { position: absolute; border-radius: 50%; border: 1px dashed rgba(@RGB,.33); pointer-events: none; will-change: transform; }\n.ring.r1 { animation: spin 50s linear infinite; }\n.ring.r2 { animation: spinR 70s linear infinite; }\n@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }\n@keyframes spinR { from { transform: rotate(0deg); } to { transform: rotate(-360deg); } }\n@keyframes pulse { 0% { box-shadow: 0 0 25px rgba(@RGB,.55); } 50% { box-shadow: 0 0 60px rgba(@RGB,.95); } 100% { box-shadow: 0 0 25px rgba(@RGB,.55); } }\n@keyframes blink { 0% { opacity: 1; } 50% { opacity: 0; } 100% { opacity: 1; } }\n@keyframes boot { 0% { transform: scale(.92, .02); opacity: .2; } 60% { transform: scale(1.01, 1); opacity: 1; } 100% { transform: scale(1, 1); opacity: 1; } }\n@keyframes scan { 0% { top: 0; opacity: .9; } 100% { top: 100%; opacity: 0; } }\n@keyframes sig { 0% { opacity: .25; } 50% { opacity: 1; } 100% { opacity: .25; } }\n\n#core { position: absolute; left: -95px; top: -95px; width: 190px; height: 190px; border-radius: 50%; border: 2px solid @A; cursor: pointer; z-index: 5;\n  background: radial-gradient(circle, @K 0%, #000 72%); animation: pulse 2.5s ease-in-out infinite; text-align: center; }\n#core:hover { background: radial-gradient(circle, @D 0%, #000 72%); }\n#core.zipmode { border-style: dashed; border-width: 3px; }\n#coreSpin1 { position: absolute; left: -14px; top: -14px; right: -14px; bottom: -14px; border-radius: 50%; border: 2px solid transparent; border-top-color: @A; border-bottom-color: @A; animation: spin 3s linear infinite; pointer-events: none; }\n#coreSpin2 { position: absolute; left: -27px; top: -27px; right: -27px; bottom: -27px; border-radius: 50%; border: 1px dashed rgba(@RGB,.6); animation: spinR 14s linear infinite; pointer-events: none; }\n#coreTxt { position: absolute; left: 16px; right: 16px; top: 50%; transform: translateY(-50%); }\n#cTag { font-size: 10px; color: #000; background: @A; display: inline-block; padding: 0 6px; margin-bottom: 4px; letter-spacing: 2px; }\n#cName { font-size: 16px; font-weight: bold; color: #fff; text-shadow: 0 0 8px @A; word-wrap: break-word; max-height: 60px; overflow: hidden; }\n#cInfo { font-size: 11px; color: @A2; margin-top: 6px; }\n#cUp { font-size: 10px; color: @A2; opacity: .8; margin-top: 7px; letter-spacing: 1px; }\n.coreBtn { position: absolute; width: 40px; height: 40px; line-height: 36px; border-radius: 50%; border: 2px solid @A; background: #000; color: @A; text-align: center; font-size: 22px; font-weight: bold; cursor: pointer; z-index: 6; box-shadow: 0 0 12px rgba(@RGB,.6); }\n.coreBtn:hover { background: @A; color: #000; }\n#coreAdd { left: 62px; top: 62px; }\n#corePaste { left: -102px; top: 62px; font-size: 12px; line-height: 13px; padding-top: 6px; display: none; }\n\n.nd { position: absolute; left: 0; top: 0; width: 84px; margin-left: -42px; margin-top: -30px; text-align: center; cursor: pointer; z-index: 3; opacity: 0;\n  transform: scale(.2); transition: left .55s ease-out, top .55s ease-out, opacity .4s, transform .55s; }\n.nd.in { opacity: 1; transform: scale(1); }\n.nd.dim { opacity: .14; }\n.nd.cut { opacity: .35; }\n.nd.match .b { border-color: #fff; box-shadow: 0 0 16px #fff; }\n.nd .b { width: 50px; height: 50px; line-height: 47px; margin: 5px auto 0 auto; border-radius: 50%; border: 1.5px solid @A2; background-color: @K; background-image: radial-gradient(circle at 35% 30%, rgba(@RGB,.35), rgba(@RGB,.08) 70%); color: #fff; font-size: 11px; font-weight: bold; overflow: hidden; background-size: cover; background-position: center; text-shadow: 0 0 6px @A; }\n.nd.dir .b { width: 60px; height: 60px; line-height: 56px; margin-top: 0; border: 2px solid @A; box-shadow: 0 0 14px rgba(@RGB,.55); background-image: radial-gradient(circle at 35% 30%, rgba(@RGB,.55), rgba(@RGB,.15) 72%); }\n.fold { display: inline-block; position: relative; width: 28px; height: 19px; margin-top: 4px; vertical-align: middle; background: #fff; border-radius: 1px 3px 3px 3px; box-shadow: 0 0 10px @A; }\n.fold:before { content: \"\"; position: absolute; left: 0; top: -5px; width: 12px; height: 6px; background: #fff; border-radius: 2px 3px 0 0; }\n.fold:after { content: \"\"; position: absolute; left: 0; right: 0; top: 4px; height: 2px; background: @A; opacity: .6; }\n.nd.dir:hover .fold, .nd.dir:hover .fold:before { background: #000; box-shadow: none; }\n.nd.zip .b { width: 58px; height: 58px; line-height: 50px; margin-top: 1px; border: 3px double @A; color: #fff; font-size: 12px; background-image: repeating-linear-gradient(45deg, rgba(@RGB,.55) 0, rgba(@RGB,.55) 5px, rgba(@RGB,.12) 5px, rgba(@RGB,.12) 10px); }\n.nd.media .b { border-color: #6fd3ff; color: #e6f8ff; text-shadow: 0 0 6px #6fd3ff; background-image: radial-gradient(circle at 35% 30%, rgba(111,211,255,.45), rgba(111,211,255,.1) 70%); }\n.nd.img .b { border-color: #ffb347; color: #fff3df; text-shadow: 0 0 6px #ffb347; background-image: radial-gradient(circle at 35% 30%, rgba(255,179,71,.45), rgba(255,179,71,.1) 70%); }\n.nd.fast { transition: none; }\n.nd.thumb .b { color: transparent; border-width: 2px; }\n.nd.drv .b { font-size: 13px; }\n.nd.hid { opacity: .5; }\n.nd .l { margin-top: 4px; font-size: 11px; color: @T; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; background: rgba(0,0,0,.8); padding: 0 3px; }\n.nd:hover { z-index: 8; }\n.nd:hover .b { background-color: @A; color: #000; box-shadow: 0 0 14px @A; }\n.nd.thumb:hover .b { transform: scale(1.5); transition: transform .2s; }\n.nd:hover .l { color: #fff; overflow: visible; }\n.nd.open .b { border-color: #fff; box-shadow: 0 0 0 3px #000, 0 0 0 5px #fff; }\n\n/* ===== HUD ===== */\n.hud { position: absolute; z-index: 7500; background: rgba(0,0,0,.88); border: 1px solid @D; padding: 10px 12px; }\n.cTL { position: absolute; left: -1px; top: -1px; width: 12px; height: 12px; border-left: 2px solid @A; border-top: 2px solid @A; }\n.cBL { position: absolute; left: -1px; bottom: -1px; width: 12px; height: 12px; border-left: 2px solid @A; border-bottom: 2px solid @A; }\n#hudTL { top: 12px; left: 12px; width: 392px; min-width: 300px; }\n.hud-w { position: absolute; right: -5px; top: 10px; bottom: 10px; width: 9px; cursor: e-resize; z-index: 3; }\n.hud-w:hover { background: rgba(@RGB,.25); }\n.hud-min { float: right; cursor: pointer; color: @A2; padding: 0 5px; margin-left: 8px; border: 1px solid @D; font-weight: normal; letter-spacing: 0; text-shadow: none; line-height: 15px; }\n.hud-min:hover { background: @A; color: #000; }\n#hudTL.collapsed .row, #hudTL.collapsed .list, #hudTL.collapsed #sys { display: none; }\n#hudTL.collapsed .brand { margin-bottom: 0; }\n#hudBL.collapsed #info, #hudBL.collapsed #help { display: none; }\n#blHead { display: none; color: @A; letter-spacing: 2px; }\n#hudBL.collapsed #blHead { display: block; }\n.brand, #blHead { cursor: move; }\n.brand { color: @A; font-weight: bold; letter-spacing: 3px; text-shadow: 0 0 8px @A; margin-bottom: 4px; }\n.brand .cur { animation: blink 1s step-end infinite; }\n#clock { float: right; letter-spacing: 1px; font-weight: normal; color: @A2; text-shadow: none; }\n.row { margin-top: 6px; white-space: nowrap; }\n.row button { margin-right: 2px; }\n.row input { width: 100%; }\n#sys { margin-top: 6px; font-size: 10px; color: @M; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }\n.list { max-height: 260px; overflow: auto; margin-top: 6px; display: none; border-top: 1px solid @K; }\n.list div { padding: 4px 6px; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; border-left: 2px solid transparent; }\n.list div:hover { background: @K; border-left-color: @A; color: #fff; }\n.list div.h { color: @A; cursor: default; font-size: 11px; letter-spacing: 2px; margin-top: 4px; }\n.list div.h:hover { background: transparent; border-left-color: transparent; color: @A; }\n.list small { color: @M; margin-left: 6px; }\n.list .rm { float: right; color: @M; padding: 0 4px; }\n.list .rm:hover { background: @A; color: #000; }\n#hudBL { bottom: 12px; left: 12px; width: 392px; min-width: 260px; font-size: 11px; line-height: 1.65; color: @M; }\n#hudBL b { color: @A2; font-weight: normal; }\n#help { border-top: 1px solid @K; padding-top: 6px; margin-top: 6px; display: none; }\n#info { min-height: 18px; color: @T; word-wrap: break-word; }\n#info .k2 { color: @A; display: inline-block; width: 74px; }\n#toast { position: absolute; top: 14px; left: 50%; width: 520px; margin-left: -260px; text-align: center; z-index: 9500; background: @A; color: #000; padding: 5px 14px; font-weight: bold; display: none; box-shadow: 0 0 18px @A; }\n#dropzone { position: absolute; left: 0; top: 0; right: 0; bottom: 0; z-index: 9400; display: none; background: rgba(0,0,0,.75); border: 3px dashed @A; color: @A; font-size: 28px; text-align: center; padding-top: 40vh; letter-spacing: 3px; }\n\n/* ===== creator ===== */\n#creator { left: 50%; top: 50%; width: 460px; margin-left: -230px; margin-top: -170px; display: none; z-index: 9200; box-shadow: 0 0 40px rgba(@RGB,.45); border-color: @A; }\n#creator .brand { cursor: default; }\n#crTpl { margin-top: 8px; }\n#crTpl span { display: inline-block; margin: 0 4px 5px 0; padding: 3px 9px; border: 1px solid @D; color: @A2; cursor: pointer; }\n#crTpl span:hover { border-color: @A; color: #fff; }\n#crTpl span.on { background: @A; color: #000; border-color: @A; }\n#crWhere { color: @M; font-size: 11px; margin-left: 8px; }\n#creator .lbl { color: @M; font-size: 11px; margin-top: 8px; letter-spacing: 2px; }\n\n/* context menu */\n#menu { position: absolute; z-index: 9300; display: none; min-width: 240px; background: #050505; border: 1px solid @A; box-shadow: 0 0 18px rgba(@RGB,.5); padding: 4px 0; }\n#menu div { padding: 6px 14px; cursor: pointer; white-space: nowrap; }\n#menu div:hover { background: @A; color: #000; }\n#menu div.sep { padding: 0; height: 1px; background: @K; margin: 4px 0; cursor: default; }\n#menu div.ttl { color: @A2; font-size: 11px; cursor: default; border-bottom: 1px solid @K; margin-bottom: 4px; overflow: hidden; text-overflow: ellipsis; max-width: 340px; }\n#menu div.ttl:hover { background: transparent; color: @A2; }\n#menu div.sep:hover { background: @K; }\n#menu small { float: right; color: @M; margin-left: 20px; }\n#menu div:hover small { color: #000; }\n\n/* dock */\n#dock { position: absolute; left: 50%; bottom: 0; z-index: 8000; transform: translateX(-50%); background: rgba(0,0,0,.92); border: 1px solid @D; border-bottom: none; padding: 4px 8px; white-space: nowrap; display: none; max-width: 70%; overflow: hidden; }\n#dock .chip { display: inline-block; padding: 2px 10px; margin: 0 2px; border: 1px solid @D; color: @M; cursor: pointer; max-width: 170px; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }\n#dock .chip.act { color: #fff; border-color: @A; }\n#dock .chip.mn { border-style: dashed; }\n#dock .chip:hover { background: @A; color: #000; }\n#dock button { vertical-align: middle; }\n#snap { position: absolute; z-index: 7990; display: none; border: 2px solid @A; background: rgba(@RGB,.12); box-shadow: inset 0 0 40px rgba(@RGB,.3); pointer-events: none; }\n\n/* ===== editor \"pods\" (unique windows) ===== */\n.win { position: absolute; z-index: 100; background: rgba(2,2,2,.97); border: 1px solid rgba(@RGB,.35); min-width: 380px; min-height: 220px; }\n.win.focus { border-color: rgba(@RGB,.7); box-shadow: 0 0 40px rgba(@RGB,.28), inset 0 0 60px rgba(@RGB,.04); }\n.win.boot { animation: boot .32s ease-out; }\n.win.max { left: 0 !important; top: 0 !important; width: 100% !important; height: 100% !important; }\n.win.mn { display: none; }\n.cb { position: absolute; width: 18px; height: 18px; border-color: @A; border-style: solid; border-width: 0; z-index: 5; pointer-events: none; }\n.cb.tl { left: -2px; top: -2px; border-left-width: 3px; border-top-width: 3px; }\n.cb.tr { right: -2px; top: -2px; border-right-width: 3px; border-top-width: 3px; }\n.cb.bl { left: -2px; bottom: -2px; border-left-width: 3px; border-bottom-width: 3px; }\n.cb.br { right: -2px; bottom: -2px; border-right-width: 3px; border-bottom-width: 3px; }\n.w-scan { position: absolute; left: 0; right: 0; height: 2px; background: @A; box-shadow: 0 0 14px @A; z-index: 9; pointer-events: none; display: none; }\n.win.boot .w-scan { display: block; animation: scan .5s ease-in forwards; }\n.rz { position: absolute; z-index: 6; }\n.rz.n { left: 14px; right: 14px; top: -5px; height: 9px; cursor: n-resize; }\n.rz.s { left: 14px; right: 14px; bottom: -5px; height: 9px; cursor: s-resize; }\n.rz.e { top: 14px; bottom: 14px; right: -5px; width: 9px; cursor: e-resize; }\n.rz.w { top: 14px; bottom: 14px; left: -5px; width: 9px; cursor: w-resize; }\n.rz.nw { left: -6px; top: -6px; width: 18px; height: 18px; cursor: nw-resize; }\n.rz.ne { right: -6px; top: -6px; width: 18px; height: 18px; cursor: ne-resize; }\n.rz.sw { left: -6px; bottom: -6px; width: 18px; height: 18px; cursor: sw-resize; }\n.rz.se { right: -6px; bottom: -6px; width: 18px; height: 18px; cursor: se-resize; }\n.rz:hover { background: rgba(@RGB,.3); }\n.win.max .rz, .win.max .cb { display: none; }\n\n.w-head { position: absolute; left: 0; right: 0; top: 0; height: 40px; cursor: move; overflow: hidden;\n  background: linear-gradient(180deg, rgba(@RGB,.14), rgba(@RGB,0)); border-bottom: 1px dashed rgba(@RGB,.35); }\n.w-id { position: absolute; left: 8px; top: 6px; width: 30px; height: 28px; line-height: 28px; text-align: center; color: #000; background: @A; font-weight: bold; font-size: 12px; }\n.w-name { margin-left: 48px; padding-top: 4px; height: 36px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }\n.w-name b { color: #fff; font-size: 15px; letter-spacing: 1px; text-shadow: 0 0 8px rgba(@RGB,.8); }\n.w-name small { display: block; color: @M; font-size: 10px; letter-spacing: 2px; margin-top: -1px; }\n.w-name .dot { color: #ffd166; }\n.w-sig { display: inline-block; height: 14px; margin-right: 8px; vertical-align: middle; }\n.w-sig i { display: inline-block; width: 3px; margin-right: 2px; background: @A; vertical-align: bottom; animation: sig 1.6s ease-in-out infinite; }\n.w-sig i.a { height: 4px; } .w-sig i.b { height: 7px; animation-delay: .2s; } .w-sig i.c { height: 10px; animation-delay: .4s; } .w-sig i.d { height: 13px; animation-delay: .6s; }\n.w-btns { float: right; margin: 8px 8px 0 10px; white-space: nowrap; cursor: default; }\n.w-btns button { padding: 1px 6px; margin-left: 2px; border-color: rgba(@RGB,.5); min-width: 26px; height: 24px; }\n.w-btns button.b-save { border-color: @A; color: #fff; }\n\n.w-rail { position: absolute; left: 0; top: 40px; bottom: 0; width: 52px; border-right: 1px dashed rgba(@RGB,.3); overflow-x: hidden; overflow-y: auto; padding-top: 8px; padding-bottom: 20px; text-align: center; background: rgba(@RGB,.03); scrollbar-width: none; -ms-overflow-style: none; }\n.w-rail::-webkit-scrollbar { display: none; width: 0; }\n.orb .ol { position: absolute; left: -6px; right: -6px; top: 38px; line-height: 12px; font-size: 9px; font-weight: normal; color: @M; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; background: transparent !important; }\n.orb.active .ol { color: #fff; }\n.orb { position: relative; width: 38px; height: 38px; line-height: 34px; margin: 0 auto 22px auto; border-radius: 50%; border: 2px solid rgba(@RGB,.45); color: @A2; font-size: 10px; font-weight: bold; cursor: pointer; background: #000; }\n.orb:hover { border-color: @A; color: #fff; }\n.orb.active { border-color: @A; color: #000; background: @A; box-shadow: 0 0 14px @A; }\n.orb.dirty:after { content: \"\"; position: absolute; right: -3px; top: -3px; width: 10px; height: 10px; border-radius: 50%; background: #ffd166; box-shadow: 0 0 6px #ffd166; }\n.orb .ox { position: absolute; left: -5px; top: -6px; width: 15px; height: 15px; line-height: 13px; border-radius: 50%; background: #000; border: 1px solid @A; color: @A; font-size: 9px; display: none; }\n.orb:hover .ox { display: block; }\n.orb .ox:hover { background: @A; color: #000; }\n.orb .on2 { display: none; }\n.w-tip { position: absolute; z-index: 20; background: #000; border: 1px solid @A; color: #fff; padding: 2px 8px; font-size: 11px; white-space: nowrap; pointer-events: none; display: none; }\n\n.w-body { position: absolute; left: 52px; right: 0; top: 40px; bottom: 0; }\n.w-find { position: absolute; left: 0; right: 0; top: 0; height: 34px; padding: 5px 8px; background: rgba(@RGB,.06); border-bottom: 1px dashed rgba(@RGB,.3); display: none; white-space: nowrap; overflow: hidden; }\n.w-find input { width: 24%; min-width: 70px; margin-right: 3px; }\n.w-find button { padding: 2px 6px; }\n.w-finfo { color: @M; margin-left: 6px; }\n.w-crumb { position: absolute; left: 0; right: 0; top: 0; height: 24px; line-height: 24px; padding: 0 12px; color: @M; font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; letter-spacing: 1px; }\n.w-crumb span { color: @A; }\n.w-crumb i { color: @A2; font-style: normal; padding: 0 5px; }\n.w-wrap { position: absolute; left: 0; right: 0; top: 24px; bottom: 24px; overflow: hidden; font-size: 14px; line-height: 20px; background: #000; border-top: 1px solid rgba(@RGB,.18); }\n.w-body.finding .w-find { display: block; }\n.w-body.finding .w-crumb { top: 34px; }\n.w-body.finding .w-wrap { top: 58px; }\n.w-band { position: absolute; left: 0; right: 74px; height: 20px; background: rgba(@RGB,.09); border-left: 3px solid @A; pointer-events: none; display: none; z-index: 0; }\n.w-gut { position: absolute; left: 0; top: 0; bottom: 0; width: 58px; overflow: hidden; padding: 8px 12px 8px 0; text-align: right; color: @G; font-family: Consolas, \"Courier New\", monospace; font-size: inherit; line-height: inherit; white-space: pre; margin: 0; z-index: 1; }\n.w-view, .w-code { position: absolute; left: 58px; right: 74px; top: 0; bottom: 0; margin: 0; padding: 8px 14px; overflow: auto; font-family: Consolas, \"Courier New\", monospace; font-size: inherit; line-height: inherit; white-space: pre; word-wrap: normal; tab-size: 4; z-index: 1; border-left: 1px dashed rgba(@RGB,.22); }\n.w-view { color: @T; cursor: text; background: transparent; }\n.w-code { width: auto; height: auto; border-top: none; border-right: none; border-bottom: none; outline: none; resize: none; background: transparent; color: #fff; z-index: 2; }\n.w-wrap.live .w-view { pointer-events: none; overflow: hidden; padding-right: 400px; padding-bottom: 200px; }\n.w-wrap.live .w-code { color: transparent; caret-color: @A; }\n.w-wrap.nomap .w-view, .w-wrap.nomap .w-code { right: 0; }\n.w-wrap.nomap .w-band { right: 0; }\n.w-wrap.nomap .w-map, .w-wrap.nomap .w-mapv { display: none; }\n.w-code::selection { background: rgba(@RGB,.4); }\n.w-view::selection { background: rgba(@RGB,.4); }\n.w-map { position: absolute; right: 0; top: 0; bottom: 0; width: 74px; height: 100%; cursor: pointer; border-left: 1px dashed rgba(@RGB,.3); background: rgba(@RGB,.03); z-index: 2; }\n.w-mapv { position: absolute; right: 0; width: 74px; background: rgba(@RGB,.18); border-top: 1px solid @A; border-bottom: 1px solid @A; pointer-events: none; z-index: 3; }\n.w-prev { position: absolute; left: 0; right: 0; top: 0; bottom: 0; display: none; }\n.pv-bar { position: absolute; left: 0; right: 0; top: 0; height: 34px; padding: 5px 8px; white-space: nowrap; overflow: hidden; border-bottom: 1px dashed rgba(@RGB,.3); background: rgba(@RGB,.05); }\n.pv-bar button { padding: 2px 7px; }\n.pv-info { color: @A2; margin-left: 8px; font-size: 11px; }\n.pv-stage { position: absolute; left: 0; right: 0; top: 34px; bottom: 0; overflow: auto; text-align: center; background-color: #0a0a0a;\n  background-image: linear-gradient(45deg, #141414 25%, transparent 25%), linear-gradient(-45deg, #141414 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #141414 75%), linear-gradient(-45deg, transparent 75%, #141414 75%);\n  background-size: 20px 20px; background-position: 0 0, 0 10px, 10px -10px, -10px 0; }\n.pv-stage img { margin: 20px auto; display: block; box-shadow: 0 0 0 1px rgba(@RGB,.5), 0 0 30px rgba(@RGB,.25); }\n.pv-stage video { max-width: calc(100% - 30px); max-height: calc(100% - 30px); margin: 15px auto; display: block; background: #000; box-shadow: 0 0 0 1px rgba(@RGB,.5), 0 0 30px rgba(@RGB,.25); }\n.pv-stage iframe { position: absolute; left: 0; top: 0; width: 100%; height: 100%; border: 0; background: #fff; }\n.pv-stage.plain { background: #000; }\n.pv-audio { position: absolute; left: 0; right: 0; top: 50%; margin-top: -110px; text-align: center; }\n.pv-disc { width: 150px; height: 150px; margin: 0 auto 26px auto; border-radius: 50%; border: 3px solid @A; background: repeating-radial-gradient(circle, @K 0, @K 4px, #000 4px, #000 8px); box-shadow: 0 0 30px rgba(@RGB,.6); position: relative; }\n.pv-disc:after { content: \"\"; position: absolute; left: 50%; top: 50%; width: 34px; height: 34px; margin: -17px 0 0 -17px; border-radius: 50%; background: @A; }\n.pv-disc.spin { animation: spin 3s linear infinite; }\n.pv-audio audio { width: 80%; max-width: 520px; }\n.pv-msg { position: absolute; left: 0; right: 0; top: 30%; text-align: center; color: @A2; line-height: 1.8; }\n.w-empty { position: absolute; left: 0; right: 0; top: 38%; text-align: center; color: @M; letter-spacing: 2px; }\n.w-stat { position: absolute; left: 0; right: 0; bottom: 0; height: 24px; line-height: 23px; padding: 0 12px; font-size: 11px; color: @A2; background: #000; border-top: 1px solid rgba(@RGB,.5); white-space: nowrap; overflow: hidden; letter-spacing: 1px; }\n.w-stat b { color: #fff; font-weight: normal; }\n.w-stat .r { float: right; margin-left: 12px; }\n.w-stat .s-mode { color: #000; background: @A; padding: 0 6px; }\n.w-stat .s-msg { color: @A; }\n.k { color: @SK; } .s { color: @SS; } .c { color: @SC; font-style: italic; } .n { color: @SN; } .f { color: @SF; } .t { color: @ST; } .tg { color: @SK; } .at { color: @SS; }\n\n/* ===== scrollbars (dark, thin, theme colored) ===== */\n* { scrollbar-width: thin; scrollbar-color: @D #000; }\n::-webkit-scrollbar { width: 10px; height: 10px; background: #000; }\n::-webkit-scrollbar-track { background: #000; }\n::-webkit-scrollbar-corner { background: #000; }\n::-webkit-scrollbar-thumb { background: @D; border-radius: 6px; border: 2px solid #000; }\n::-webkit-scrollbar-thumb:hover { background: @A; }\n::-webkit-scrollbar-button { display: none; width: 0; height: 0; }\nhtml, body, div, pre, textarea { scrollbar-face-color: @D; scrollbar-track-color: #000; scrollbar-arrow-color: @A; scrollbar-shadow-color: #000; scrollbar-highlight-color: #000; scrollbar-3dlight-color: #000; scrollbar-darkshadow-color: #000; scrollbar-base-color: #000; }\n\n/* paste row + FX off */\n#pasteRow { display: none; }\n#pasteRow button { text-transform: none; max-width: 300px; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }\nbody.nofx .ring, body.nofx #coreSpin1, body.nofx #coreSpin2, body.nofx #core, body.nofx .w-sig i, body.nofx .brand .cur, body.nofx .win.boot, body.nofx .w-scan { animation: none !important; }\nbody.nofx .nd { transition: none !important; }\nbody.nofx #stage { transition: none; }\n";
/* ---------------- FS adapter: PHP server ---------------- */
var FS = (function () {
  var SELF = window.__CE.self, TOKEN = window.__CE.token;
  function req(method, params, body, cb, onProgress) {
    var x = new XMLHttpRequest(), qs = [];
    for (var k in params) { if (params.hasOwnProperty(k)) qs.push(encodeURIComponent(k) + "=" + encodeURIComponent(params[k])); }
    x.open(method, SELF + "?" + qs.join("&"), true);
    if (onProgress && x.upload) x.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(Math.round(e.loaded * 100 / e.total)); };
    x.onreadystatechange = function () {
      if (x.readyState !== 4) return;
      if (x.status === 401) { location.reload(); return; }
      var j = null;
      try { j = JSON.parse(x.responseText); } catch (e) { cb(x.status ? "Server error (" + x.status + ")" : "Network error"); return; }
      if (j && j.error) cb(j.error); else cb(null, j);
    };
    if (body && !(window.FormData && body instanceof FormData)) {
      var parts = []; body.token = TOKEN;
      for (var b in body) { if (body.hasOwnProperty(b)) parts.push(encodeURIComponent(b) + "=" + encodeURIComponent(body[b])); }
      x.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
      x.send(parts.join("&"));
    } else x.send(body || null);
  }
  function post(api, body, cb) { req("POST", { api: api }, body, cb); }
  function url(api, p) { return SELF + "?api=" + api + "&path=" + encodeURIComponent(p); }
  function download(p, api) { var a = document.createElement("a"); a.href = url(api || "download", p); a.setAttribute("download", ""); document.body.appendChild(a); a.click(); document.body.removeChild(a); }
  function pathOnly(cb) { return function (e, r) { cb(e, r && r.path); }; }
  return {
    home: window.__CE.home,
    root: window.__CE.root,
    sep: "/",
    caps: { upload: true, download: true, shell: false, drives: false, recycle: false, zipEdit: true, thumbs: true, compress: true },
    zipPath: function (p) { return p + "::"; },
    base: function (p) {
      var i = p.indexOf("::");
      if (i >= 0) { var inner = p.substring(i + 2).replace(/\/+$/, ""); if (!inner) p = p.substring(0, i); else return inner.split("/").pop(); }
      var s = p.replace(/\/+$/, "").split("/"); return s.pop() || "/";
    },
    dirOf: function (p) {
      var i = p.indexOf("::");
      if (i >= 0) {
        var inner = p.substring(i + 2), zip = p.substring(0, i);
        if (!inner) return this.dirOf(zip);
        var k = inner.lastIndexOf("/"); return zip + "::" + (k > 0 ? inner.substring(0, k) : "");
      }
      var d = p.replace(/\/[^\/]*$/, ""); if (/^[A-Za-z]:$/.test(d)) d += "/"; return d || "/";
    },
    rel: function (p, from) { var pre = from.replace(/\/+$/, ""); pre += /::$/.test(pre) ? "" : "/"; return p.indexOf(pre) === 0 ? p.substring(pre.length) : p; },
    fromDisplay: function (v) { var m = /^(.*?\.zip)\s*>\s*(.*)$/i.exec(v); return m ? m[1] + "::" + m[2].split(/\s*>\s*/).join("/") : v; },
    list: function (p, cb) { req("GET", { api: "list", path: p }, null, cb); },
    read: function (p, cb) { req("GET", { api: "read", path: p }, null, cb); },
    write: function (p, content, cb) { post("save", { path: p, content: content }, cb); },
    mkfile: function (d, n, content, cb) { post("mkfile", { dir: d, name: n, content: content || "" }, pathOnly(cb)); },
    mkdir: function (d, n, cb) { post("mkdir", { dir: d, name: n }, pathOnly(cb)); },
    rename: function (p, n, cb) { post("rename", { path: p, name: n }, pathOnly(cb)); },
    dup: function (p, cb) { post("dup", { path: p }, cb); },
    del: function (p, isDir, cb) { post("delete", { path: p }, cb); },
    paste: function (items, dest, mode, cb) { post("paste", { items: JSON.stringify(items), dest: dest, mode: mode }, function (e, r) { cb(e, r && r.count); }); },
    extract: function (p, cb) { post("extract", { path: p }, pathOnly(cb)); },
    compress: function (p, cb) { post("compress", { path: p }, pathOnly(cb)); },
    search: function (d, qv, cb) { req("GET", { api: "search", in: d, q: qv }, null, function (e, r) { cb(e, r ? r.items : [], r && r.partial); }); },
    imgUrl: function (p) { return url("raw", p); },
    thumbUrl: function (it) { return url("thumb", it.path) + "&v=" + (it.date || 0) + "-" + (it.size || 0); },
    mediaUrl: function (p) { return url("media", p); },
    webUrl: function (p) {
      var dr = (window.__CE.docroot || "").replace(/\/+$/, ""); if (!dr || p.indexOf("::") >= 0) return null;
      if (p.toLowerCase().indexOf(dr.toLowerCase() + "/") !== 0) return null;
      var rel = p.substring(dr.length + 1).split("/"); for (var i = 0; i < rel.length; i++) rel[i] = encodeURIComponent(rel[i]);
      return window.__CE.origin + "/" + rel.join("/");
    },
    canRun: function (p) { return !!this.webUrl(p) && /\.(html?|php|svg|txt|json|xml|css|js|pdf|png|jpe?g|gif|webp|mp4|mp3)$/i.test(p); },
    run: function (p) { var u = this.webUrl(p); if (u) window.open(u, "_blank"); },
    download: function (p) { download(p); },
    upload: function (d, files, onProgress, cb) {
      var fd = new FormData(); fd.append("token", TOKEN); fd.append("dir", d);
      for (var i = 0; i < files.length; i++) fd.append("files[]", files[i]);
      req("POST", { api: "upload" }, fd, function (e, r) { cb(e, r && r.count); }, onProgress);
    },
    extraMenu: function (it, cwd, data) {
      if (!it) return [["Download this folder as ZIP", function () { download(cwd, "zipdl"); }]];
      if (it.dir) return [["Download as ZIP", function () { download(it.path, "zipdl"); }]];
      return [["Download", function () { download(it.path); }]];
    },
    sysInfo: function (cb) {
      req("GET", { api: "info", path: window.__CE.home }, null, function (e, r) {
        if (e || !r) return;
        var gb = function (b) { return b ? (b / 1073741824).toFixed(1) + " GB" : "?"; };
        cb("PHP " + r.php + " · " + r.os + " · user " + r.user + " · disk free " + gb(r.free) + " / " + gb(r.total) + (r.zip ? "" : " · NO ZIP SUPPORT") + (r.gd ? "" : " · no GD (thumbs = full images)"));
      });
    },
    store: {
      get: function () { try { return JSON.parse(localStorage.getItem("coreEditor") || "null"); } catch (e) { return null; } },
      set: function (o) { try { localStorage.setItem("coreEditor", JSON.stringify(o)); } catch (e) {} }
    }
  };
})();

/* =====================================================================
   CORE::EDITOR  -  shared UI (ES5, works in IE11/HTA and all browsers)
   Needs a global FS adapter object (php-adapter.js / hta-adapter.js)
   ===================================================================== */
(function () {
"use strict";

var IMGX = ["png","jpg","jpeg","jfif","gif","bmp","ico","webp","svg","avif"];
var MAX_NODES = 400;
var LIVE = !!(window.CSS && CSS.supports && CSS.supports("caret-color", "red"));

var THEMES = [
  { name: "RED",    A: "#ff1f3d", A2: "#ff5468", D: "#7a0f1c", K: "#2a0309", T: "#ffd6dc", RGB: "255,31,61",  BG: "#050001", M: "#a04050", G: "#6a1a26", SK: "#ff4d6d", SS: "#ffb36b", SC: "#7a3440", SN: "#ffd166", SF: "#ff9aab", ST: "#ff7a45" },
  { name: "MATRIX", A: "#00ff41", A2: "#4dff7c", D: "#0b5c22", K: "#03240c", T: "#c8ffd6", RGB: "0,255,65",   BG: "#000300", M: "#2f9a52", G: "#145c2a", SK: "#00ffd5", SS: "#d4ff00", SC: "#2a6b3c", SN: "#ff9d00", SF: "#7dffa0", ST: "#00c3ff" },
  { name: "CYAN",   A: "#00e5ff", A2: "#5cf0ff", D: "#0b5560", K: "#032328", T: "#d4fbff", RGB: "0,229,255",  BG: "#000305", M: "#3a8a96", G: "#155660", SK: "#ff5cf0", SS: "#ffe066", SC: "#2f6a73", SN: "#ff9f43", SF: "#7af7ff", ST: "#8c9bff" },
  { name: "PURPLE", A: "#b84dff", A2: "#cf85ff", D: "#4b1a6b", K: "#1c0629", T: "#f0dcff", RGB: "184,77,255", BG: "#030005", M: "#8a5aa8", G: "#4a2a5e", SK: "#ff5ca8", SS: "#7dffb0", SC: "#5c3a73", SN: "#ffd166", SF: "#e0a8ff", ST: "#66d9ff" },
  { name: "GOLD",   A: "#ffb000", A2: "#ffc94d", D: "#6b4a00", K: "#2a1c00", T: "#fff1d1", RGB: "255,176,0",  BG: "#040200", M: "#a8873a", G: "#5e4a17", SK: "#ff6b4d", SS: "#a8ff60", SC: "#6b5a2e", SN: "#66e0ff", SF: "#ffe08a", ST: "#ff9f1c" }
];

var TEMPLATES = [
  { n: "Blank", ext: "", c: "" },
  { n: "HTML", ext: "html", c: "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <title>New Page</title>\n    <link rel=\"stylesheet\" href=\"style.css\">\n</head>\n<body>\n    <h1>Hello!</h1>\n\n    <script src=\"script.js\"><\/script>\n</body>\n</html>\n" },
  { n: "PHP", ext: "php", c: "<\?php\n// New PHP file\n\n$name = \"World\";\necho \"Hello, \" . htmlspecialchars($name) . \"!\";\n" },
  { n: "JavaScript", ext: "js", c: "// New JavaScript file\n\ndocument.addEventListener('DOMContentLoaded', function () {\n    console.log('Ready!');\n});\n" },
  { n: "CSS", ext: "css", c: "/* New stylesheet */\n\n* {\n    box-sizing: border-box;\n}\n\nbody {\n    margin: 0;\n    font-family: system-ui, sans-serif;\n}\n" },
  { n: "Python", ext: "py", c: "# New Python file\n\ndef main():\n    print(\"Hello!\")\n\n\nif __name__ == \"__main__\":\n    main()\n" },
  { n: "JSON", ext: "json", c: "{\n    \"name\": \"new\",\n    \"version\": \"1.0.0\"\n}\n" },
  { n: "Markdown", ext: "md", c: "# Title\n\nWrite something here.\n" },
  { n: "Text", ext: "txt", c: "" },
  { n: ".htaccess", ext: "", name: ".htaccess", c: "# Apache settings\nOptions -Indexes\n" }
];

/* ---------------- tiny helpers ---------------- */
function $(id) { return document.getElementById(id); }
function q(el, sel) { return el.querySelector(sel); }
function esc(s) { return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;"); }
function ext(n) { var i = n.lastIndexOf("."); return i <= 0 ? "" : n.substring(i + 1).toLowerCase(); }
function inArr(a, v) { for (var i = 0; i < a.length; i++) { if (a[i] === v) return true; } return false; }
function hasC(el, c) { return (" " + (el.getAttribute("class") || "") + " ").indexOf(" " + c + " ") >= 0; }
function addC(el, c) { if (!hasC(el, c)) el.setAttribute("class", ((el.getAttribute("class") || "") + " " + c).replace(/^\s+/, "")); }
function remC(el, c) { el.setAttribute("class", (" " + (el.getAttribute("class") || "") + " ").replace(" " + c + " ", " ").replace(/^\s+|\s+$/g, "")); }
function tgC(el, c, on) { if (on) addC(el, c); else remC(el, c); }
function stopEv(e) { if (e && e.stopPropagation) e.stopPropagation(); }
function prevent(e) { if (e && e.preventDefault) e.preventDefault(); }
function trim(s) { return String(s).replace(/^\s+|\s+$/g, ""); }
function fmtSize(b) { if (b === undefined || b === null || b === "" || isNaN(b)) return "-"; b = Number(b); if (b < 1024) return b + " B"; if (b < 1048576) return (b / 1024).toFixed(1) + " KB"; if (b < 1073741824) return (b / 1048576).toFixed(1) + " MB"; return (b / 1073741824).toFixed(2) + " GB"; }
function pad(n, w) { n = String(n); w = w || 2; while (n.length < w) n = "0" + n; return n; }
function fmtDate(ms) { if (!ms) return "-"; var x = new Date(ms); if (isNaN(x.getTime())) return "-"; return pad(x.getDate()) + "-" + pad(x.getMonth() + 1) + "-" + x.getFullYear() + " " + pad(x.getHours()) + ":" + pad(x.getMinutes()); }
function badge(n) { var e = ext(n); if (!e || e.length > 4) return "◆"; return e.toUpperCase(); }
function isImgName(n) { return inArr(IMGX, ext(n)); }
var LANGS = { js: "JavaScript", mjs: "JavaScript", jsx: "JSX", ts: "TypeScript", tsx: "TSX", html: "HTML", htm: "HTML", css: "CSS", scss: "SCSS", less: "LESS", json: "JSON", md: "Markdown", py: "Python", java: "Java", kt: "Kotlin", kts: "Kotlin", dart: "Dart", php: "PHP", c: "C", cpp: "C++", h: "C Header", cs: "C#", go: "Go", rs: "Rust", rb: "Ruby", swift: "Swift", xml: "XML", yml: "YAML", yaml: "YAML", sql: "SQL", sh: "Shell", bat: "Batch", cmd: "Batch", ps1: "PowerShell", hta: "HTA", ini: "INI", env: "ENV", gradle: "Gradle", vue: "Vue", txt: "Text", lua: "Lua", toml: "TOML", htaccess: "Apache" };
function langName(n) { return LANGS[ext(n)] || (n.charAt(0) === "." ? "Config" : "Plain Text"); }
function commentFor(lang) {
  if (inArr(["py","sh","yml","yaml","rb","toml","env","ini","conf","ps1","htaccess","gitignore","properties","r"], lang)) return ["# ", ""];
  if (inArr(["sql","lua"], lang)) return ["-- ", ""];
  if (inArr(["bat","cmd"], lang)) return ["REM ", ""];
  if (inArr(["html","htm","xml","vue","svg","hta","md"], lang)) return ["<\!-- ", " -->"];
  if (inArr(["css"], lang)) return ["/* ", " */"];
  return ["// ", ""];
}
var toastT;
function toast(t) { var e = $("toast"); e.innerHTML = esc(t); e.style.display = "block"; clearTimeout(toastT); toastT = setTimeout(function () { e.style.display = "none"; }, 2800); }
function W() { return document.documentElement.clientWidth || document.body.clientWidth; }
function H() { return document.documentElement.clientHeight || document.body.clientHeight; }

/* ---------------- settings ---------------- */
var settings = { theme: 0, favs: [], recent: [], hidden: true, fs: 14, hud: {}, map: true, fx: true };
(function () { try { var s = FS.store.get(); if (s) for (var k in s) { if (s.hasOwnProperty(k)) settings[k] = s[k]; } } catch (e) {} if (!settings.hud) settings.hud = {}; })();
function saveSettings() { try { FS.store.set(settings); } catch (e) {} }

/* ---------------- theme ---------------- */
var CUR = THEMES[0];
function applyTheme(i) {
  settings.theme = ((i % THEMES.length) + THEMES.length) % THEMES.length;
  CUR = THEMES[settings.theme];
  var css = CSS_TEMPLATE, keys = ["RGB","SK","SS","SC","SN","SF","ST","BG","A2","A","D","K","T","M","G"];
  for (var j = 0; j < keys.length; j++) css = css.split("@" + keys[j]).join(CUR[keys[j]]);
  var st = $("themeCss");
  try { st.textContent = css; } catch (e) { try { st.styleSheet.cssText = css; } catch (e2) {} }
  for (var w = 0; w < wins.length; w++) drawMap(wins[w]);
  radarDirty = true;
}

/* ---------------- state ---------------- */
var S = { cwd: null, parent: null, data: null, hist: [], histPos: -1, zoom: 1, fit: 1, panX: 0, panY: 0, maxR: 120, hover: null };
var CB = null;               // clipboard: { mode: "copy"|"cut", items: [{path,name,dir}] }
var drag = null, justDragged = false;
var docs = {};               // path -> doc
var wins = [], activeWin = null, zTop = 100, winSeq = 0;

function readOnlyHere() { return S.data && S.data.isZip && !FS.caps.zipEdit; }
function atDrives() { return S.data && S.data.isRoot && FS.caps.drives; }

/* ================= CIRCLE ================= */
function layout(n) {
  var out = [], r = 200, i = 0, ringNo = 0;
  while (i < n) {
    var cap = Math.max(6, Math.floor(2 * Math.PI * r / 92)), cnt = Math.min(cap, n - i);
    var off = (ringNo % 2) * (Math.PI / cnt) - Math.PI / 2;
    for (var k = 0; k < cnt; k++) { var a = off + k * 2 * Math.PI / cnt; out.push({ x: Math.cos(a) * r, y: Math.sin(a) * r, r: r }); }
    i += cnt; r += 100; ringNo++;
  }
  return out;
}
function area() {
  var w = W(), h = H(), minLeft = w, maxRight = 0, any = false;
  for (var i = 0; i < wins.length; i++) {
    var x = wins[i];
    if (hasC(x.el, "mn")) continue;
    if (hasC(x.el, "max")) return { cx: w / 2, cy: h / 2, w: w, h: h };
    any = true; minLeft = Math.min(minLeft, x.el.offsetLeft); maxRight = Math.max(maxRight, x.el.offsetLeft + x.el.offsetWidth);
  }
  var L = 0, R = w;
  if (any && minLeft > w * 0.33) R = minLeft;
  else if (any && maxRight < w * 0.67) L = maxRight;
  var hud = $("hudTL");   // keep the circle out from under the control panel
  if (hud && !hasC(hud, "collapsed") && hud.offsetLeft < w * 0.3) { var hr = hud.offsetLeft + hud.offsetWidth + 12; if (hr > L && R - hr > 300) L = hr; }
  return { cx: (L + R) / 2, cy: h / 2, w: R - L, h: h };
}
function applyTransform() {
  var a = area(), st = $("stage");
  st.style.left = a.cx + "px"; st.style.top = a.cy + "px";
  st.style.transform = "translate(" + S.panX + "px," + S.panY + "px) scale(" + (S.fit * S.zoom) + ")";
}
function relayout() { var a = area(), s = Math.min(a.w, a.h) / 2 - 30; S.fit = Math.min(1.1, Math.max(.2, s / (S.maxR + 60))); applyTransform(); }

function render(data) {
  S.data = data;
  var stage = $("stage"), svg = $("lines"), i;
  thumbGen++; thumbQ = [];
  var old = stage.querySelectorAll(".nd, .ring");
  for (i = 0; i < old.length; i++) old[i].parentNode.removeChild(old[i]);
  while (svg.firstChild) svg.removeChild(svg.firstChild);
  var items = [];
  for (i = 0; i < data.dirs.length; i++) { if (settings.hidden || !data.dirs[i].hidden) { data.dirs[i].dir = true; items.push(data.dirs[i]); } }
  for (i = 0; i < data.files.length; i++) { if (settings.hidden || !data.files[i].hidden) { var f = data.files[i]; if (!f.img && isImgName(f.name)) f.img = true; if (!f.zip && ext(f.name) === "zip" && !data.isZip) f.zip = true; items.push(f); } }
  var shown = items.slice(0, MAX_NODES), pos = layout(shown.length);
  S.maxR = pos.length ? pos[pos.length - 1].r : 120;
  var seen = {}, rIdx = 0;
  for (i = 0; i < pos.length; i++) {
    var r = pos[i].r; if (seen[r]) continue; seen[r] = 1;
    var ring = document.createElement("div");
    ring.className = "ring" + (rIdx < 2 ? (rIdx % 2 ? " r2" : " r1") : "");
    ring.style.width = ring.style.height = (r * 2) + "px"; ring.style.left = ring.style.top = (-r) + "px";
    stage.insertBefore(ring, $("core")); rIdx++;
  }
  S.zoom = 1; S.panX = 0; S.panY = 0; relayout();
  var nd = 0, nf = 0, nz = 0;
  for (i = 0; i < items.length; i++) { if (items[i].dir) nd++; else { nf++; if (items[i].zip) nz++; } }
  tgC($("core"), "zipmode", !!data.isZip);
  $("cTag").style.display = data.isZip ? "inline-block" : "none";
  $("cName").innerHTML = esc(data.name);
  $("cInfo").innerHTML = atDrives() ? nd + " drives" : nd + " folders &middot; " + nf + " files";
  $("cUp").innerHTML = data.parent ? "&#9650; CLICK = UP" : "&#9673; TOP LEVEL";
  $("pathInput").value = data.display || data.path;
  tgC($("upBtn"), "off", !data.parent);
  tgC($("backBtn"), "off", S.histPos <= 0);
  tgC($("fwdBtn"), "off", S.histPos >= S.hist.length - 1);
  updatePasteBtn();
  if (items.length > MAX_NODES) toast("Too many items - showing first " + MAX_NODES + ". Use search.");
  else if (!items.length) toast(data.isZip ? "This part of the ZIP is empty" : "This folder is empty");
  for (i = 0; i < shown.length; i++) makeNode(shown[i], pos[i], i, svg, stage);
  applyFilter(); markNodes();
}
function lineStyle(hot) { return hot ? "stroke:" + CUR.A + ";stroke-width:2" : "stroke:rgba(" + CUR.RGB + ",.22);stroke-width:1"; }
function makeNode(it, p, i, svg, stage) {
  var ln = document.createElementNS("http://www.w3.org/2000/svg", "line");
  ln.setAttribute("x1", 0); ln.setAttribute("y1", 0); ln.setAttribute("x2", p.x); ln.setAttribute("y2", p.y);
  ln.setAttribute("style", lineStyle(false));
  svg.appendChild(ln);
  var nd = document.createElement("div");
  var thumb = it.img && FS.caps.thumbs ? FS.thumbUrl(it) : null;
  if (!it.dir && !it.media) it.media = mediaKind(it.name);
  nd.className = "nd " + (it.dir ? "dir" : (it.zip ? "zip" : "file")) + (it.img ? " img" : "") + (it.media ? " media" : "") + (it.drive ? " drv" : "") + (it.hidden ? " hid" : "") + (i >= 60 ? " fast" : "");
  nd.setAttribute("data-path", it.path);
  nd.setAttribute("data-name", it.name.toLowerCase());
  nd.title = it.path;
  var face = it.drive ? esc(it.name.substring(0, 2)) : (it.dir ? '<i class="fold"></i>' : (it.zip ? "ZIP" : esc(badge(it.name))));
  var label = it.drive ? (it.label ? it.label + " (" + it.name.substring(0, 2) + ")" : "Drive " + it.name.substring(0, 2)) : it.name;
  nd.innerHTML = '<div class="b">' + face + '</div><div class="l">' + esc(label) + '</div>';
  if (thumb) queueThumb(nd, thumb);
  nd.onmouseover = function () { ln.setAttribute("style", lineStyle(true)); S.hover = it; showInfo(it); };
  nd.onmouseout = function () { ln.setAttribute("style", lineStyle(false)); if (S.hover === it) S.hover = null; };
  nd.onmousedown = function (e) { if (e.button === 1) prevent(e); };
  nd.onmouseup = function (e) { if (e.button === 1 && !it.dir && !it.zip && !justDragged) { prevent(e); openFile(it.path, it.name, true); } };
  nd.onclick = function (e) {
    stopEv(e); if (justDragged) return;
    if (it.dir) { if (it.drive && !it.ready) { toast("Drive is not ready"); return; } goTo(it.path); }
    else if (it.zip) goTo(FS.zipPath(it.path));
    else openFile(it.path, it.name, e.shiftKey || e.ctrlKey);
  };
  nd.oncontextmenu = function (e) { prevent(e); stopEv(e); showMenu(e, it); return false; };
  if (i >= 60 || !settings.fx) { nd.style.left = p.x + "px"; nd.style.top = p.y + "px"; addC(nd, "in"); stage.appendChild(nd); return; }
  stage.appendChild(nd);
  setTimeout(function () { nd.style.left = p.x + "px"; nd.style.top = p.y + "px"; addC(nd, "in"); }, 20 + i * 10);
}
/* thumbnails load a few at a time so the page stays fast */
var thumbQ = [], thumbActive = 0, thumbGen = 0;
function queueThumb(nd, url) { thumbQ.push({ nd: nd, url: url, gen: thumbGen }); pumpThumbs(); }
function pumpThumbs() {
  while (thumbActive < 4 && thumbQ.length) {
    var t = thumbQ.shift(); if (t.gen !== thumbGen) continue;
    thumbActive++;
    (function (t) {
      var im = new Image(), fin = false;
      var done = function (ok) {
        if (fin) return; fin = true; thumbActive--;
        if (ok && t.gen === thumbGen) { try { q(t.nd, ".b").style.backgroundImage = "url(\"" + t.url.replace(/"/g, "%22") + "\")"; addC(t.nd, "thumb"); } catch (e) {} }
        pumpThumbs();
      };
      im.onload = function () { done(true); }; im.onerror = function () { done(false); };
      setTimeout(function () { done(false); }, 15000);
      im.src = t.url;
    })(t);
  }
}
var VIDX = ["mp4","m4v","webm","ogv","mov","mkv","3gp"], AUDX = ["mp3","wav","ogg","oga","m4a","aac","flac","opus","weba","wma"];
function mediaKind(n) { var e = ext(n); return inArr(VIDX, e) ? "video" : (inArr(AUDX, e) ? "audio" : (e === "pdf" ? "pdf" : null)); }
function kindLabel(d) { return d.kind === "text" ? langName(d.name) : ({ img: "IMAGE", video: "VIDEO", audio: "AUDIO", pdf: "PDF", big: "LARGE FILE", bin: "BINARY" })[d.kind] || "FILE"; }
function markNodes() {
  var nodes = $("stage").querySelectorAll(".nd"), cut = {};
  if (CB && CB.mode === "cut") for (var c = 0; c < CB.items.length; c++) cut[CB.items[c].path] = 1;
  for (var i = 0; i < nodes.length; i++) {
    var p = nodes[i].getAttribute("data-path");
    tgC(nodes[i], "open", !!docs[p]); tgC(nodes[i], "cut", !!cut[p]);
  }
}
function showInfo(it) {
  var h;
  if (it.drive) {
    h = '<span class="k2">DRIVE</span>' + esc(it.name) + (it.label ? " &nbsp;" + esc(it.label) : "") + "<br>";
    h += it.ready ? '<span class="k2">FREE</span>' + fmtSize(it.free) + " of " + fmtSize(it.total) : '<span class="k2">STATUS</span>Not ready';
  } else {
    var kind = it.dir ? "FOLDER" : (it.zip ? "ZIP" : (it.img ? "IMAGE" : (it.media ? String(it.media).toUpperCase() : "FILE")));
    h = '<span class="k2">' + kind + "</span>" + esc(it.name) + (it.hidden ? " (hidden)" : "") + "<br>";
    if (!it.dir) h += '<span class="k2">TYPE</span>' + (it.zip ? "ZIP archive - click to open" : (it.img ? "Image" : (it.media ? "Media - click to play / view" : esc(langName(it.name))))) + " &nbsp; " + fmtSize(it.size) + "<br>";
    h += '<span class="k2">MODIFIED</span>' + fmtDate(it.date);
  }
  $("info").innerHTML = h;
}

/* ---------------- navigation ---------------- */
function goTo(p, noHist, cb) {
  FS.list(p, function (err, data) {
    if (err) { toast("✕ " + FS.base(String(p)) + ": " + err + (/permission/i.test(err) ? " (system folder - blocked by Windows / server)" : "")); $("pathInput").value = S.data ? (S.data.display || S.data.path) : ""; if (cb) cb(false); return; }
    S.cwd = data.path; S.parent = data.parent;
    if (!noHist) { S.hist = S.hist.slice(0, S.histPos + 1); if (S.hist[S.hist.length - 1] !== S.cwd) S.hist.push(S.cwd); S.histPos = S.hist.length - 1; }
    $("search").value = ""; $("results").style.display = "none";
    render(data);
    if (cb) cb(true);
  });
}
function refresh() { if (S.cwd !== null) goTo(S.cwd, true); }
function goBack() { if (S.histPos > 0) { S.histPos--; goTo(S.hist[S.histPos], true); } }
function goFwd() { if (S.histPos < S.hist.length - 1) { S.histPos++; goTo(S.hist[S.histPos], true); } }
function goUp() { if (S.parent) goTo(S.parent); }

/* ---------------- filter + deep search ---------------- */
function applyFilter() {
  var qv = trim($("search").value.toLowerCase()), nodes = $("stage").querySelectorAll(".nd");
  for (var i = 0; i < nodes.length; i++) {
    var ok = !qv || nodes[i].getAttribute("data-name").indexOf(qv) >= 0;
    tgC(nodes[i], "dim", !ok); tgC(nodes[i], "match", !!qv && ok);
  }
}
function deepSearch(qv) {
  var box = $("results");
  $("quick").style.display = "none";
  box.style.display = "block"; box.innerHTML = "<div>Searching...</div>";
  FS.search(S.cwd, qv, function (err, res, partial) {
    if (err) { box.innerHTML = "<div>" + esc(err) + "</div>"; return; }
    box.innerHTML = res.length ? '<div class="h">' + res.length + " RESULTS" + (partial ? " (partial)" : "") + "</div>" : "<div>Nothing found</div>";
    for (var k = 0; k < res.length; k++) {
      (function (f) {
        var el = document.createElement("div");
        el.innerHTML = (f.dir ? "&#9635; " : (f.zip ? "[ZIP] " : "")) + esc(f.name) + "<small>" + esc(FS.rel(f.path, S.cwd)) + "</small>";
        el.title = f.path + "  (Shift+click = new pod)";
        el.onclick = function (e) { if (f.dir) goTo(f.path); else if (f.zip) goTo(FS.zipPath(f.path)); else openFile(f.path, f.name, e.shiftKey); };
        box.appendChild(el);
      })(res[k]);
    }
  });
}

/* ---------------- favorites / recent ---------------- */
function addRecent(path, name) {
  for (var i = settings.recent.length - 1; i >= 0; i--) { if (settings.recent[i].path === path) settings.recent.splice(i, 1); }
  settings.recent.unshift({ path: path, name: name });
  if (settings.recent.length > 15) settings.recent.length = 15;
  saveSettings();
}
function showQuick(force) {
  var box = $("quick");
  if (box.style.display === "block" && !force) { box.style.display = "none"; return; }
  $("results").style.display = "none";
  box.innerHTML = "";
  var add = document.createElement("div");
  add.innerHTML = "&#9733; Pin current folder"; add.onclick = function (e) { stopEv(e); pinFolder(); showQuick(true); };
  box.appendChild(add);
  var h1 = document.createElement("div"); h1.className = "h"; h1.innerHTML = "FAVORITE FOLDERS"; box.appendChild(h1);
  if (!settings.favs.length) { var e1 = document.createElement("div"); e1.innerHTML = "<small>none yet</small>"; box.appendChild(e1); }
  for (var i = 0; i < settings.favs.length; i++) {
    (function (f, idx) {
      var d = document.createElement("div");
      d.innerHTML = '<span class="rm" title="Remove">&#10005;</span>&#9635; ' + esc(f.name) + "<small>" + esc(f.path) + "</small>";
      d.title = f.path;
      d.onclick = function () { box.style.display = "none"; goTo(f.path); };
      d.firstChild.onclick = function (e) { stopEv(e); settings.favs.splice(idx, 1); saveSettings(); showQuick(true); };
      box.appendChild(d);
    })(settings.favs[i], i);
  }
  var h2 = document.createElement("div"); h2.className = "h"; h2.innerHTML = "RECENT FILES"; box.appendChild(h2);
  if (!settings.recent.length) { var e2 = document.createElement("div"); e2.innerHTML = "<small>none yet</small>"; box.appendChild(e2); }
  for (var j = 0; j < settings.recent.length; j++) {
    (function (f) {
      var d = document.createElement("div");
      d.innerHTML = esc(f.name) + "<small>" + esc(f.path) + "</small>"; d.title = f.path + "  (Shift+click = new pod)";
      d.onclick = function (e) { box.style.display = "none"; openFile(f.path, f.name, e.shiftKey); };
      box.appendChild(d);
    })(settings.recent[j]);
  }
  box.style.display = "block";
}
function pinFolder(path) {
  path = path || S.cwd; if (path === null || (atDrives() && path === S.cwd)) { toast("Open a folder first"); return; }
  for (var i = 0; i < settings.favs.length; i++) { if (settings.favs[i].path === path) { toast("Already pinned"); return; } }
  settings.favs.push({ path: path, name: FS.base(path) }); saveSettings(); toast("Pinned: " + FS.base(path));
}

/* ================= CLIPBOARD (copy / cut / paste files) ================= */
function clip(mode, it) {
  if (!it || it.drive) return;
  CB = { mode: mode, items: [{ path: it.path, name: it.name, dir: !!it.dir }] };
  toast((mode === "cut" ? "Cut: " : "Copied: ") + it.name + "  - open a folder and press Ctrl+V or the paste button");
  updatePasteBtn(); markNodes();
}
function updatePasteBtn() {
  $("pasteRow").style.display = CB ? "block" : "none";
  if (CB) $("pasteBtn").innerHTML = "&#9112; " + (CB.mode === "cut" ? "MOVE HERE: " : "PASTE HERE: ") + esc(CB.items[0].name);
}
function paste() {
  if (!CB) { toast("Nothing to paste - right-click a file and choose Copy or Cut"); return; }
  if (atDrives()) { toast("Open a folder first"); return; }
  if (readOnlyHere()) { toast("Files inside a ZIP are read-only here - extract it first"); return; }
  var paths = []; for (var i = 0; i < CB.items.length; i++) paths.push(CB.items[i].path);
  var mode = CB.mode;
  toast((mode === "cut" ? "Moving" : "Pasting") + "...");
  FS.paste(paths, S.cwd, mode === "cut" ? "move" : "copy", function (err, n) {
    if (err) { toast("✕ " + err); return; }
    toast("✔ " + (mode === "cut" ? "Moved " : "Pasted ") + n + " item(s)");
    if (mode === "cut") { for (var j = 0; j < paths.length; j++) closeDocsUnder(paths[j]); CB = null; }
    refresh();
  });
}
function closeDocsUnder(p) {
  for (var d in docs) { if (docs.hasOwnProperty(d) && isUnder(d, p)) { docs[d].dirty = false; closeDocEverywhere(d); } }
}
function isUnder(child, parent) { return child === parent || child.indexOf(parent + FS.sep) === 0 || child.indexOf(parent + "::") === 0 || child.indexOf(parent + "\\") === 0 || child.indexOf(parent + "/") === 0; }

/* ================= CONTEXT MENU ================= */
function showMenu(e, it) {
  var m = $("menu"), items = [], ro = readOnlyHere();
  if (!it && atDrives()) return;
  if (it && it.drive) {
    items = [["Open", function () { goTo(it.path); }], ["Pin to favorites", function () { pinFolder(it.path); }]];
  } else if (it && it.dir) {
    items = [["Open", function () { goTo(it.path); }], ["Pin to favorites", function () { pinFolder(it.path); }], "-"];
    if (!ro) items.push(["New file inside", function () { openCreator(false, it.path); }], ["New folder inside", function () { openCreator(true, it.path); }], "-");
    items.push(["Copy", function () { clip("copy", it); }, "Ctrl+C"]);
    if (!ro) items.push(["Cut", function () { clip("cut", it); }, "Ctrl+X"], ["Rename", function () { renameItem(it); }, "F2"], ["Duplicate", function () { duplicateItem(it); }]);
    if (!S.data.isZip && FS.caps.compress) items.push(["Compress to ZIP", function () { compressItem(it); }]);
    if (!ro) items.push(["Delete", function () { deleteItem(it); }, "Del"]);
    items.push("-", ["Copy path", function () { copyText(it.path); }]);
  } else if (it && it.zip) {
    items = [["Open ZIP (browse inside)", function () { goTo(FS.zipPath(it.path)); }], ["Extract here", function () { extractItem(it); }], "-",
      ["Copy", function () { clip("copy", it); }, "Ctrl+C"]];
    if (!ro) items.push(["Cut", function () { clip("cut", it); }, "Ctrl+X"], ["Rename", function () { renameItem(it); }], ["Duplicate", function () { duplicateItem(it); }], ["Delete", function () { deleteItem(it); }]);
    items.push("-", ["Copy path", function () { copyText(it.path); }]);
  } else if (it) {
    items = [["Open", function () { openFile(it.path, it.name, false); }, "Click"], ["Open in new pod", function () { openFile(it.path, it.name, true); }, "Shift+Click"], "-",
      ["Copy", function () { clip("copy", it); }, "Ctrl+C"]];
    if (!ro) items.push(["Cut", function () { clip("cut", it); }, "Ctrl+X"], ["Rename", function () { renameItem(it); }, "F2"], ["Duplicate", function () { duplicateItem(it); }]);
    if (!S.data.isZip && FS.caps.compress) items.push(["Compress to ZIP", function () { compressItem(it); }]);
    if (!ro) items.push(["Delete", function () { deleteItem(it); }, "Del"]);
    items.push("-", ["Copy path", function () { copyText(it.path); }], ["Copy name", function () { copyText(it.name); }]);
  } else {
    if (!ro) items.push(["New file", function () { openCreator(false); }, "Ctrl+N"], ["New folder", function () { openCreator(true); }]);
    if (CB && !ro) items.push([(CB.mode === "cut" ? "Move here: " : "Paste here: ") + CB.items[0].name, function () { paste(); }, "Ctrl+V"]);
    if (FS.caps.upload && (!S.data.isZip || FS.caps.zipEdit)) items.push(["Upload files here", function () { $("upInput").click(); }]);
    items.push("-", ["Pin to favorites", function () { pinFolder(); }], ["Refresh", function () { refresh(); }, "F5"], ["Copy path", function () { copyText(S.cwd); }]);
  }
  var extra = FS.extraMenu ? FS.extraMenu(it, S.cwd, S.data) : [];
  if (extra.length) { items.push("-"); for (var x = 0; x < extra.length; x++) items.push(extra[x]); }
  m.innerHTML = '<div class="ttl">' + esc(it ? it.name : (S.data ? S.data.name : "")) + (ro ? " (read-only ZIP)" : "") + "</div>";
  for (var i = 0; i < items.length; i++) {
    var d = document.createElement("div");
    if (items[i] === "-") { if (i > 0 && items[i - 1] !== "-") { d.className = "sep"; m.appendChild(d); } continue; }
    d.innerHTML = esc(items[i][0]) + (items[i][2] ? "<small>" + esc(items[i][2]) + "</small>" : "");
    d.onclick = (function (fn) { return function (ev) { stopEv(ev); hideMenu(); fn(); }; })(items[i][1]);
    m.appendChild(d);
  }
  m.style.display = "block";
  var x0 = e.clientX, y0 = e.clientY;
  if (x0 + m.offsetWidth > W()) x0 = W() - m.offsetWidth - 4;
  if (y0 + m.offsetHeight > H()) y0 = Math.max(4, H() - m.offsetHeight - 4);
  m.style.left = x0 + "px"; m.style.top = y0 + "px";
}
function hideMenu() { $("menu").style.display = "none"; }
function copyText(t) {
  var done = false;
  try { if (window.clipboardData) { window.clipboardData.setData("Text", t); done = true; } } catch (e) {}
  if (!done) { try { var ta = document.createElement("textarea"); ta.value = t; document.body.appendChild(ta); ta.select(); done = document.execCommand("copy"); document.body.removeChild(ta); } catch (e2) {} }
  if (done) toast("Copied: " + t); else prompt("Copy this:", t);
}
function validName(n) { return n && !/[\\\/:*?"<>|\u0000]/.test(n) && n !== "." && n !== ".."; }

function renameItem(it) {
  var n = prompt("New name:", it.name);
  if (n === null || n === it.name) return; n = trim(n);
  if (!validName(n)) { toast("Invalid name"); return; }
  for (var p in docs) { if (docs.hasOwnProperty(p) && isUnder(p, it.path) && docs[p].dirty) { toast("Save open files first"); return; } }
  FS.rename(it.path, n, function (err, np) {
    if (err) { toast("✕ " + err); return; }
    var moved = [];
    for (var p2 in docs) { if (docs.hasOwnProperty(p2) && isUnder(p2, it.path)) moved.push(p2); }
    for (var i = 0; i < moved.length; i++) {
      var d = docs[moved[i]], npath = np + moved[i].substring(it.path.length);
      delete docs[moved[i]]; d.path = npath; d.name = FS.base(npath); docs[npath] = d;
      for (var w = 0; w < wins.length; w++) {
        var ww = wins[w];
        for (var t = 0; t < ww.tabs.length; t++) { if (ww.tabs[t] === moved[i]) ww.tabs[t] = npath; }
        if (ww.active === moved[i]) ww.active = npath;
        if (ww.vs[moved[i]]) { ww.vs[npath] = ww.vs[moved[i]]; delete ww.vs[moved[i]]; }
        renderWin(ww);
      }
    }
    toast("Renamed to " + n); refresh();
  });
}
function duplicateItem(it) { FS.dup(it.path, function (err) { if (err) toast("✕ " + err); else { toast("Copy created"); refresh(); } }); }
function deleteItem(it) {
  if (!confirm('Delete "' + it.name + '"?' + (it.dir ? "\n\nEverything inside this folder will be deleted too!" : "") + (FS.caps.recycle && !S.data.isZip ? "\n\n(It goes to the Recycle Bin)" : "\n\nThis cannot be undone."))) return;
  closeDocsUnder(it.path);
  FS.del(it.path, !!it.dir, function (err) { if (err) toast("✕ " + err); else { toast("Deleted: " + it.name); refresh(); } });
}
function extractItem(it) {
  toast("Extracting " + it.name + "...");
  FS.extract(it.path, function (err, np) { if (err) { toast("✕ " + err); return; } toast("✔ Extracted to " + FS.base(np)); refresh(); });
}
function compressItem(it) {
  toast("Compressing " + it.name + "...");
  FS.compress(it.path, function (err, np) { if (err) { toast("✕ " + err); return; } toast("✔ Created " + FS.base(np)); refresh(); });
}

/* ================= CREATOR (new file / folder) ================= */
var CR = { dir: false, where: null, tpl: 0 };
function openCreator(isDir, where) {
  if (atDrives() && !where) { toast("Open a drive or folder first"); return; }
  if (readOnlyHere()) { toast("Files inside a ZIP are read-only here - extract it first"); return; }
  CR.dir = !!isDir; CR.where = where || S.cwd; CR.tpl = 0;
  $("crWhere").innerHTML = "in: " + esc(FS.base(CR.where));
  $("crName").value = isDir ? "new-folder" : "untitled.txt";
  renderCreator();
  $("creator").style.display = "block";
  try { $("crName").focus(); var v = $("crName").value, dot = v.lastIndexOf("."); $("crName").setSelectionRange(0, dot > 0 && !isDir ? dot : v.length); } catch (e) {}
}
function closeCreator() { $("creator").style.display = "none"; }
function renderCreator() {
  tgC($("crFile"), "on", !CR.dir); tgC($("crDir"), "on", CR.dir);
  $("crTpl").style.display = $("crTplLbl").style.display = CR.dir ? "none" : "block";
  var box = $("crTpl"); box.innerHTML = "";
  for (var i = 0; i < TEMPLATES.length; i++) {
    (function (t, idx) {
      var s = document.createElement("span");
      s.innerHTML = esc(t.n); if (idx === CR.tpl) s.className = "on";
      s.onclick = function () {
        CR.tpl = idx;
        var v = trim($("crName").value), dot = v.lastIndexOf("."), base = dot > 0 ? v.substring(0, dot) : (v.charAt(0) === "." ? "untitled" : v);
        if (t.name) $("crName").value = t.name; else if (t.ext) $("crName").value = (base || "untitled") + "." + t.ext;
        renderCreator();
      };
      box.appendChild(s);
    })(TEMPLATES[i], i);
  }
}
function doCreate(newPod) {
  var n = trim($("crName").value);
  if (!validName(n)) { toast("Invalid name"); return; }
  var where = CR.where;
  if (CR.dir) {
    FS.mkdir(where, n, function (err) { if (err) { toast("✕ " + err); return; } closeCreator(); toast("Folder created: " + n); refresh(); });
    return;
  }
  var tpl = TEMPLATES[CR.tpl], content = tpl.c || "";
  FS.mkfile(where, n, content, function (err, path) {
    if (err) { toast("✕ " + err); return; }
    closeCreator(); toast("File created: " + n);
    if (where === S.cwd) refresh();
    openFile(path, n, newPod);
  });
}

/* ---------------- upload (server only) ---------------- */
function uploadFiles(list) {
  if (!FS.caps.upload || !list || !list.length) return;
  if (atDrives()) { toast("Open a folder first"); return; }
  if (readOnlyHere()) { toast("This ZIP is read-only here"); return; }
  toast("Uploading " + list.length + " file(s)...");
  FS.upload(S.cwd, list, function (pct) { toast("Uploading... " + pct + "%"); }, function (err, n) {
    if (err) toast("✕ " + err); else { toast("✔ Uploaded " + n + " file(s)"); refresh(); }
  });
}

/* ================= DOCUMENTS ================= */
function openFile(path, name, newWin, intoWin) {
  var go = function (doc) {
    var w = intoWin || ((newWin || !activeWin) ? createWin() : activeWin);
    if (hasC(w.el, "mn")) restoreWin(w);
    winAddTab(w, doc.path);
    focusWin(w);
    addRecent(doc.path, doc.name);
    markNodes();
  };
  if (docs[path]) { go(docs[path]); return; }
  FS.read(path, function (err, r) {
    if (err) { toast("✕ " + err); return; }
    if (r.kind === "zip") { goTo(FS.zipPath(path)); return; }
    var doc = { path: path, name: name || FS.base(path), kind: r.kind, mime: r.mime, size: r.size, date: r.date, writable: r.writable !== false, dirty: false, url: r.url || null };
    if (r.kind === "text") { doc.eol = r.content.indexOf("\r\n") >= 0 ? "CRLF" : "LF"; doc.content = r.content.replace(/\r\n/g, "\n"); }
    docs[path] = doc;
    go(doc);
  });
}
function docViews(path) { var out = []; for (var i = 0; i < wins.length; i++) { if (inArr(wins[i].tabs, path)) out.push(wins[i]); } return out; }
function maybeDropDoc(path) { if (docs[path] && !docViews(path).length) delete docs[path]; markNodes(); }
function closeDocEverywhere(path) { var vs = docViews(path); for (var i = 0; i < vs.length; i++) winCloseTab(vs[i], path, true); }
function saveDoc(doc, cb) {
  if (!doc || doc.kind !== "text") { if (cb) cb(); return; }
  for (var i = 0; i < wins.length; i++) { if (wins[i].active === doc.path) storeView(wins[i]); }
  if (!doc.writable) { toast("This file is read-only"); if (cb) cb(); return; }
  var out = doc.eol === "CRLF" ? doc.content.replace(/\n/g, "\r\n") : doc.content;
  FS.write(doc.path, out, function (err, r) {
    if (err) { alert("Save failed: " + err); if (cb) cb(); return; }
    doc.dirty = false; if (r) { doc.size = r.size; doc.date = r.date; }
    var vs = docViews(doc.path);
    for (var j = 0; j < vs.length; j++) { renderRail(vs[j]); renderHead(vs[j]); if (vs[j].active === doc.path) renderStat(vs[j]); flashMsg(vs[j], "SAVED"); }
    renderDock();
    toast("✔ SAVED: " + doc.name);
    if (cb) cb();
  });
}
function saveAll() {
  var list = []; for (var p in docs) { if (docs.hasOwnProperty(p) && docs[p].dirty) list.push(docs[p]); }
  if (!list.length) { toast("Nothing to save"); return; }
  var i = 0; (function next() { if (i < list.length) saveDoc(list[i++], next); else toast("✔ Saved " + list.length + " file(s)"); })();
}
function anyDirty() { for (var p in docs) { if (docs.hasOwnProperty(p) && docs[p].dirty) return true; } return false; }

/* ================= PODS (editor windows) ================= */
var WIN_HTML =
  '<span class="cb tl"></span><span class="cb tr"></span><span class="cb bl"></span><span class="cb br"></span><div class="w-scan"></div>' +
  '<div class="rz n"></div><div class="rz s"></div><div class="rz e"></div><div class="rz w"></div><div class="rz nw"></div><div class="rz ne"></div><div class="rz sw"></div><div class="rz se"></div>' +
  '<div class="w-head"><div class="w-id"></div>' +
  '<div class="w-btns"><span class="w-sig"><i class="a"></i><i class="b"></i><i class="c"></i><i class="d"></i></span><button class="b-edit" title="Edit / view (Ctrl+E)">EDIT</button><button class="b-run" title="Run / open in browser">&#9654;</button><button class="b-split" title="Open this file in another pod">&#10697;</button><button class="b-find" title="Find &amp; replace (Ctrl+F)">&#8981;</button><button class="b-map" title="Minimap on / off">&#9636;</button><button class="b-fs" title="Font size (Ctrl+wheel)">A&plusmn;</button><button class="b-save" title="Save (Ctrl+S)">SAVE</button><button class="b-min" title="Minimize (Esc)">&#9601;</button><button class="b-max" title="Maximize">&#9634;</button><button class="b-close" title="Close pod">&#10005;</button></div><div class="w-name"></div></div>' +
  '<div class="w-rail"></div><div class="w-tip"></div>' +
  '<div class="w-body"><div class="w-find"><input class="f-q" title="Enter = next, Shift+Enter = previous"><input class="f-r"><button class="f-prev" title="Previous">&#9650;</button><button class="f-next" title="Next">&#9660;</button><button class="f-case" title="Match case">Aa</button><button class="f-rep" title="Replace">REPL</button><button class="f-all" title="Replace all">ALL</button><button class="f-x" title="Close">&#10005;</button><span class="w-finfo"></span></div>' +
  '<div class="w-crumb"></div><div class="w-wrap"><div class="w-band"></div><pre class="w-gut"></pre><pre class="w-view"></pre><textarea class="w-code" wrap="off" spellcheck="false" autocapitalize="off" autocomplete="off"></textarea><canvas class="w-map"></canvas><div class="w-mapv"></div>' +
  '<div class="w-prev"><div class="pv-bar"><button class="pv-fit" title="Fit to pod">FIT</button><button class="pv-one" title="Real size">1:1</button><button class="pv-in" title="Zoom in">+</button><button class="pv-out" title="Zoom out">&#8722;</button><button class="pv-prev" title="Previous image in folder">&#9664;</button><button class="pv-next" title="Next image in folder">&#9654;</button><button class="pv-dl" title="Download">&#8681;</button><button class="pv-os" title="Open with Windows app">APP</button><span class="pv-info"></span></div><div class="pv-stage"></div><div class="pv-msg"></div></div>' +
  '<div class="w-empty">&#9673; POD EMPTY &#9673;<br><br>click a file in the circle</div></div>' +
  '<div class="w-stat"><span class="s-pos"></span><span class="s-msg"></span><span class="r s-mode"></span><span class="r s-eol"></span><span class="r s-lang"></span><span class="r s-size"></span></div></div>';

function createWin() {
  var el = document.createElement("div");
  el.className = "win boot";
  el.innerHTML = WIN_HTML;
  document.body.appendChild(el);
  setTimeout(function () { remC(el, "boot"); }, 600);
  var w = { id: ++winSeq, el: el, tabs: [], active: null, vs: {}, fs: settings.fs || 14, findCase: false, lastLines: -1, hlT: null, mapT: null };
  var p = function (c) { return q(el, "." + c); };
  w.head = p("w-head"); w.nameEl = p("w-name"); w.rail = p("w-rail"); w.tip = p("w-tip"); w.body = p("w-body"); w.crumb = p("w-crumb"); w.wrap = p("w-wrap");
  w.band = p("w-band"); w.gut = p("w-gut"); w.view = p("w-view"); w.code = p("w-code"); w.map = p("w-map"); w.mapv = p("w-mapv");
  w.prev = p("w-prev"); w.pvStage = p("pv-stage"); w.pvInfo = p("pv-info"); w.pvMsg = p("pv-msg"); w.empty = p("w-empty");
  w.fq = p("f-q"); w.fr = p("f-r"); w.finfo = p("w-finfo");
  w.fq.setAttribute("placeholder", "find..."); w.fr.setAttribute("placeholder", "replace with...");
  p("w-id").innerHTML = "P" + w.id;
  if (LIVE) { addC(w.wrap, "live"); p("b-edit").style.display = "none"; }
  tgC(w.wrap, "nomap", !settings.map);
  if (!FS.caps.download) p("pv-dl").style.display = "none";
  if (!FS.caps.shell) p("pv-os").style.display = "none";

  var vw = W(), vh = H();
  var ww = Math.min(760, Math.max(420, Math.round(vw * 0.42))), wh = Math.min(640, Math.max(280, Math.round(vh * 0.8)));
  var visible = 0; for (var i = 0; i < wins.length; i++) { if (!hasC(wins[i].el, "mn")) visible++; }
  var off = (visible % 6) * 32;
  el.style.width = ww + "px"; el.style.height = wh + "px";
  el.style.left = Math.max(10, vw - ww - 16 - off) + "px"; el.style.top = Math.max(10, vh - wh - 16 - off) + "px";
  wins.push(w);

  el.addEventListener("mousedown", function () { focusWin(w); }, true);
  w.head.onmousedown = function (e) {
    var t = e.target || e.srcElement;
    if (t.tagName === "BUTTON" || hasC(el, "max")) return;
    drag = { type: "move", w: w, sx: e.clientX, sy: e.clientY, ox: el.offsetLeft, oy: el.offsetTop }; prevent(e);
  };
  w.head.ondblclick = function (e) { var t = e.target || e.srcElement; if (t.tagName !== "BUTTON") toggleMax(w); };
  var hs = el.querySelectorAll(".rz");
  for (var hi = 0; hi < hs.length; hi++) {
    hs[hi].onmousedown = function (e) {
      if (hasC(el, "max")) return;
      drag = { type: "resize", dir: trim(this.getAttribute("class").replace("rz", "")), w: w, sx: e.clientX, sy: e.clientY, ox: el.offsetLeft, oy: el.offsetTop, ow: el.offsetWidth, oh: el.offsetHeight };
      prevent(e); stopEv(e);
    };
  }
  var onWrapWheel = function (e) {
    var d = docs[w.active], dl = e.deltaY !== undefined ? e.deltaY : -(e.wheelDelta || 0);
    if (d && d.kind === "img") { prevent(e); stopEv(e); zoomImg(w, dl < 0 ? 1.15 : 1 / 1.15); return; }
    if (!e.ctrlKey) return;
    prevent(e); stopEv(e); setFont(w, w.fs + (dl < 0 ? 1 : -1));
  };
  w.wrap.addEventListener("wheel", onWrapWheel, false);
  w.wrap.addEventListener("mousewheel", onWrapWheel, false);
  p("b-min").onclick = function () { minWin(w); };
  p("b-max").onclick = function () { toggleMax(w); };
  p("b-close").onclick = function () { closeWin(w); };
  p("b-save").onclick = function () { saveDoc(docs[w.active]); };
  p("b-edit").onclick = function () { toggleMode(w); };
  p("b-split").onclick = function () { if (!w.active) return; var nw = createWin(); winAddTab(nw, w.active); focusWin(nw); };
  p("b-run").onclick = function () { var d = docs[w.active]; if (!d) return; if (d.dirty) saveDoc(d, function () { FS.run(d.path); }); else FS.run(d.path); };
  w.rail.addEventListener("wheel", function (e) { w.rail.scrollTop += (e.deltaY || 0); prevent(e); stopEv(e); }, false);
  p("b-find").onclick = function () { openFind(w); };
  p("b-map").onclick = function () { settings.map = !settings.map; saveSettings(); for (var k = 0; k < wins.length; k++) { tgC(wins[k].wrap, "nomap", !settings.map); drawMap(wins[k]); updateBand(wins[k]); } };
  p("b-fs").onclick = function (e) { setFont(w, e.shiftKey ? w.fs - 1 : (w.fs >= 26 ? 10 : w.fs + 1)); };
  p("f-x").onclick = function () { closeFind(w); };
  p("f-next").onclick = function () { findNext(w, false); };
  p("f-prev").onclick = function () { findNext(w, true); };
  p("f-case").onclick = function () { w.findCase = !w.findCase; tgC(p("f-case"), "on", w.findCase); countMatches(w); };
  p("f-rep").onclick = function () { replaceOne(w); };
  p("f-all").onclick = function () { replaceAll(w); };
  w.fq.onkeydown = function (e) { if (e.keyCode === 13) { prevent(e); findNext(w, e.shiftKey); } if (e.keyCode === 27) closeFind(w); };
  w.fq.onkeyup = function (e) { if (e.keyCode !== 13) countMatches(w); };
  w.fr.onkeydown = function (e) { if (e.keyCode === 13) { prevent(e); replaceOne(w); } if (e.keyCode === 27) closeFind(w); };
  p("pv-fit").onclick = function () { setImgZoom(w, "fit"); };
  p("pv-one").onclick = function () { setImgZoom(w, 1); };
  p("pv-in").onclick = function () { zoomImg(w, 1.25); };
  p("pv-out").onclick = function () { zoomImg(w, 0.8); };
  p("pv-prev").onclick = function () { stepImage(w, -1); };
  p("pv-next").onclick = function () { stepImage(w, 1); };
  p("pv-dl").onclick = function () { if (w.active && FS.download) FS.download(w.active); };
  p("pv-os").onclick = function () { if (w.active && FS.openWith) FS.openWith(w.active); };

  w.view.ondblclick = function () { if (!LIVE) toggleMode(w, true); };
  w.view.onscroll = function () { if (!LIVE) { w.gut.scrollTop = w.view.scrollTop; updateMapView(w); } };
  w.code.onscroll = function () { w.gut.scrollTop = w.code.scrollTop; if (LIVE) { w.view.scrollTop = w.code.scrollTop; w.view.scrollLeft = w.code.scrollLeft; } updateBand(w); updateMapView(w); };
  w.code.onkeyup = function () { updatePos(w); };
  w.code.onclick = function () { updatePos(w); };
  w.code.oninput = function () { onCodeInput(w); };
  w.code.onkeydown = function (e) { editorKeys(w, e); };
  w.code.onfocus = function () { updateBand(w); };
  // minimap click / drag
  w.map.onmousedown = function (e) { drag = { type: "map", w: w }; mapJump(w, e); prevent(e); stopEv(e); };
  applyFont(w);
  renderWin(w);
  renderDock();
  relayout();
  return w;
}
function applyFont(w) { w.wrap.style.fontSize = w.fs + "px"; w.wrap.style.lineHeight = lineH(w) + "px"; w.band.style.height = lineH(w) + "px"; }
function lineH(w) { return Math.round(w.fs * 1.43); }
function setFont(w, n) {
  storeView(w);
  w.fs = Math.max(9, Math.min(30, n)); applyFont(w);
  settings.fs = w.fs; saveSettings();
  renderWin(w);
  flashMsg(w, "FONT " + w.fs + "PX");
}
function flashMsg(w, t) { var m = q(w.el, ".s-msg"); m.innerHTML = " &nbsp;&#9656; " + esc(t); clearTimeout(w.msgT); w.msgT = setTimeout(function () { m.innerHTML = ""; }, 2500); }
function focusWin(w) {
  if (activeWin && activeWin !== w) remC(activeWin.el, "focus");
  activeWin = w; addC(w.el, "focus");
  if (parseInt(w.el.style.zIndex || 0, 10) < zTop) w.el.style.zIndex = ++zTop;
  renderDock();
}
function minWin(w) { storeView(w); addC(w.el, "mn"); if (activeWin === w) { remC(w.el, "focus"); activeWin = topVisibleWin(); if (activeWin) focusWin(activeWin); } renderDock(); relayout(); }
function restoreWin(w) { remC(w.el, "mn"); addC(w.el, "boot"); setTimeout(function () { remC(w.el, "boot"); }, 600); focusWin(w); renderWin(w); relayout(); }
function toggleMax(w) { tgC(w.el, "max", !hasC(w.el, "max")); relayout(); setTimeout(function () { drawMap(w); updateBand(w); }, 30); }
function topVisibleWin() { var best = null, bz = -1; for (var i = 0; i < wins.length; i++) { var z = parseInt(wins[i].el.style.zIndex || 0, 10); if (!hasC(wins[i].el, "mn") && z > bz) { bz = z; best = wins[i]; } } return best; }
function closeWin(w, force) {
  storeView(w);
  if (!force) {
    var lost = [];
    for (var i = 0; i < w.tabs.length; i++) { var d = docs[w.tabs[i]]; if (d && d.dirty && docViews(d.path).length === 1) lost.push(d.name); }
    if (lost.length && !confirm("Unsaved changes in: " + lost.join(", ") + "\nClose anyway?")) return false;
  }
  var tabs = w.tabs.slice(0); w.tabs = [];
  for (var j = 0; j < tabs.length; j++) maybeDropDoc(tabs[j]);
  w.el.parentNode.removeChild(w.el);
  for (var k = 0; k < wins.length; k++) { if (wins[k] === w) { wins.splice(k, 1); break; } }
  if (activeWin === w) { activeWin = topVisibleWin(); if (activeWin) focusWin(activeWin); }
  renderDock(); relayout(); markNodes();
  return true;
}
function closeAllWins() {
  var lost = []; for (var p in docs) { if (docs.hasOwnProperty(p) && docs[p].dirty) lost.push(docs[p].name); }
  if (lost.length && !confirm("Unsaved changes in: " + lost.join(", ") + "\nClose all anyway?")) return;
  while (wins.length) closeWin(wins[0], true);
}
function tileWins() {
  var vis = []; for (var i = 0; i < wins.length; i++) { remC(wins[i].el, "mn"); remC(wins[i].el, "max"); vis.push(wins[i]); }
  if (!vis.length) return;
  var vw = W(), vh = H(), x0 = Math.round(vw * 0.36), aw = vw - x0 - 8, ah = vh - 40;
  if (aw < 500) { x0 = 8; aw = vw - 16; }
  var n = vis.length, cols = Math.ceil(Math.sqrt(n)), rows = Math.ceil(n / cols), cw = Math.floor(aw / cols), ch = Math.floor(ah / rows);
  for (var k = 0; k < n; k++) {
    var c = k % cols, r = Math.floor(k / cols), el = vis[k].el;
    el.style.left = (x0 + c * cw) + "px"; el.style.top = (8 + r * ch) + "px"; el.style.width = (cw - 8) + "px"; el.style.height = (ch - 8) + "px";
    renderWin(vis[k]);
  }
  renderDock(); relayout();
}
function renderDock() {
  var d = $("dock");
  if (!wins.length) { d.style.display = "none"; return; }
  d.innerHTML = "";
  for (var i = 0; i < wins.length; i++) {
    (function (w) {
      var c = document.createElement("span"), doc = docs[w.active];
      c.className = "chip" + (w === activeWin && !hasC(w.el, "mn") ? " act" : "") + (hasC(w.el, "mn") ? " mn" : "");
      c.innerHTML = "P" + w.id + ": " + esc(doc ? doc.name : "empty") + (doc && doc.dirty ? " &#9679;" : "") + (w.tabs.length > 1 ? " +" + (w.tabs.length - 1) : "");
      c.title = hasC(w.el, "mn") ? "Restore pod" : "Focus pod (click again = minimize)";
      c.onclick = function () { if (hasC(w.el, "mn")) restoreWin(w); else if (activeWin === w) minWin(w); else focusWin(w); };
      d.appendChild(c);
    })(wins[i]);
  }
  var t = document.createElement("button"); t.innerHTML = "TILE"; t.title = "Arrange all pods"; t.onclick = tileWins; d.appendChild(t);
  var sa = document.createElement("button"); sa.innerHTML = "SAVE ALL"; sa.title = "Save all files (Ctrl+Shift+S)"; sa.onclick = saveAll; d.appendChild(sa);
  var ca = document.createElement("button"); ca.innerHTML = "CLOSE ALL"; ca.onclick = closeAllWins; d.appendChild(ca);
  d.style.display = "block";
}

/* ---------------- orbs (open files of a pod) ---------------- */
function winAddTab(w, path) { if (!inArr(w.tabs, path)) w.tabs.push(path); setActive(w, path); }
function setActive(w, path) { storeView(w); w.active = path; renderWin(w); renderDock(); }
function winCloseTab(w, path, force) {
  var doc = docs[path];
  if (!force && doc && doc.dirty && docViews(path).length === 1 && !confirm(doc.name + " has unsaved changes. Close anyway?")) return;
  if (w.active === path) storeView(w);
  var i = -1; for (var k = 0; k < w.tabs.length; k++) { if (w.tabs[k] === path) i = k; }
  if (i < 0) return;
  w.tabs.splice(i, 1); delete w.vs[path];
  if (w.active === path) w.active = w.tabs.length ? w.tabs[Math.max(0, i - 1)] : null;
  maybeDropDoc(path);
  if (!w.tabs.length && wins.length > 1) { closeWin(w, true); return; }
  renderWin(w); renderDock();
}
function orbFace(d) { if (d.kind === "img") return "IMG"; var e = ext(d.name); return e ? e.substring(0, 4).toUpperCase() : (d.kind === "text" ? "TXT" : "FILE"); }
function renderRail(w) {
  w.rail.innerHTML = "";
  for (var i = 0; i < w.tabs.length; i++) {
    (function (path) {
      var d = docs[path]; if (!d) return;
      var o = document.createElement("div");
      o.className = "orb" + (path === w.active ? " active" : "") + (d.dirty ? " dirty" : "");
      o.innerHTML = esc(orbFace(d)) + '<span class="ox" title="Close">&#10005;</span><span class="ol">' + esc(d.name) + "</span>";
      o.onclick = function () { setActive(w, path); };
      o.onmouseup = function (e) { if (e.button === 1) { prevent(e); winCloseTab(w, path); } };
      o.onmouseover = function () { w.tip.innerHTML = esc(d.name) + (d.dirty ? " &#9679; unsaved" : ""); w.tip.style.display = "block"; w.tip.style.left = "56px"; w.tip.style.top = (o.offsetTop + 50) + "px"; };
      o.onmouseout = function () { w.tip.style.display = "none"; };
      q(o, ".ox").onclick = function (e) { stopEv(e); w.tip.style.display = "none"; winCloseTab(w, path); };
      w.rail.appendChild(o);
    })(w.tabs[i]);
  }
}
function renderHead(w) {
  var d = docs[w.active];
  if (!d) { w.nameEl.innerHTML = "<b>EMPTY POD</b><small>NO SIGNAL</small>"; return; }
  var kind = kindLabel(d);
  w.nameEl.innerHTML = "<b>" + esc(d.name) + "</b>" + (d.dirty ? ' <span class="dot">&#9679;</span>' : "") + "<small>" + esc(kind.toUpperCase()) + " &middot; " + w.tabs.length + " OPEN" + (d.writable ? "" : " &middot; READ-ONLY") + "</small>";
}
function crumbHtml(path) {
  var parts = path.replace(/::/g, "\u0001").split(/[\\\/]/), out = [];
  for (var i = 0; i < parts.length; i++) { if (parts[i] === "") continue; out.push(esc(parts[i].replace(/\u0001/g, " [ZIP] "))); }
  return "<span>&#9698;</span> " + out.join("<i>&#9656;</i>");
}

/* ---------------- pod content ---------------- */
function vstate(w, path) { if (!w.vs[path]) w.vs[path] = { top: 0, left: 0, mode: LIVE ? "edit" : "view", s: 0, e: 0, zoom: "fit" }; return w.vs[path]; }
function storeView(w) {
  var d = docs[w.active]; if (!d || d.kind !== "text") return;
  var v = vstate(w, w.active);
  if (LIVE || v.mode === "edit") { v.top = w.code.scrollTop; v.left = w.code.scrollLeft; try { v.s = w.code.selectionStart; v.e = w.code.selectionEnd; } catch (e) {} }
  else { v.top = w.view.scrollTop; v.left = w.view.scrollLeft; }
}
function lineNums(n) { var a = [], wd = String(n).length < 3 ? 3 : String(n).length; for (var i = 1; i <= n; i++) a.push(pad(i, wd)); return a.join("\n") + "\n\n\n"; }
function renderStat(w) {
  var d = docs[w.active], p = function (c) { return q(w.el, "." + c); };
  p("s-lang").innerHTML = d ? esc(kindLabel(d)).toUpperCase() : "";
  p("s-size").innerHTML = d ? fmtSize(d.size) + " &middot; " + fmtDate(d.date) : "";
  p("s-eol").innerHTML = d && d.eol ? d.eol : "";
  p("s-mode").innerHTML = d && d.kind === "text" ? (d.writable ? (LIVE ? "EDIT" : (vstate(w, d.path).mode === "edit" ? "EDIT" : "VIEW")) : "READ") : (d ? "PREVIEW" : "IDLE");
  if (!d || d.kind !== "text") p("s-pos").innerHTML = d ? "" : "NO SIGNAL";
}
function renderWin(w) {
  renderRail(w); renderHead(w);
  var d = docs[w.active], v;
  w.gut.style.display = w.view.style.display = w.code.style.display = w.prev.style.display = w.map.style.display = w.mapv.style.display = w.band.style.display = "none";
  w.empty.style.display = d ? "none" : "block";
  w.crumb.innerHTML = d ? crumbHtml(d.path) : "";
  q(w.el, ".b-edit").style.display = !LIVE && d && d.kind === "text" ? "" : "none";
  q(w.el, ".b-run").style.display = d && FS.canRun && FS.canRun(d.path) ? "" : "none";
  q(w.el, ".b-save").style.display = d && d.kind !== "text" ? "none" : "";
  renderStat(w);
  if (!d) return;
  if (d.kind !== "text") { renderPreview(w, d); return; }
  v = vstate(w, d.path);
  w.code.readOnly = !d.writable;
  w.gut.style.display = "block";
  if (settings.map) { w.map.style.display = "block"; w.mapv.style.display = "block"; }
  var n = d.content.split("\n").length; w.lastLines = n; w.gut.innerHTML = lineNums(n);
  if (LIVE) {
    w.view.style.display = "block"; w.code.style.display = "block";
    w.view.innerHTML = highlight(d.content, ext(d.name)) + "\n\n\n";
    if (w.code.value !== d.content) w.code.value = d.content;
    w.code.scrollTop = v.top; w.code.scrollLeft = v.left; w.view.scrollTop = v.top; w.view.scrollLeft = v.left; w.gut.scrollTop = v.top;
    try { w.code.setSelectionRange(v.s, v.e); } catch (e) {}
    updatePos(w);
  } else if (v.mode === "edit") {
    w.code.style.display = "block"; w.code.value = d.content;
    w.code.scrollTop = v.top; w.gut.scrollTop = v.top;
    try { w.code.setSelectionRange(v.s, v.e); } catch (e2) {}
    updatePos(w);
  } else {
    w.view.style.display = "block";
    w.view.innerHTML = highlight(d.content, ext(d.name)) + "\n\n\n";
    w.view.scrollTop = v.top; w.gut.scrollTop = v.top;
    q(w.el, ".s-pos").innerHTML = "LINES <b>" + n + "</b>";
  }
  q(w.el, ".b-edit").innerHTML = v.mode === "edit" ? "VIEW" : "EDIT";
  drawMap(w); updateBand(w);
}
function toggleMode(w, forceEdit) {
  var d = docs[w.active]; if (LIVE || !d || d.kind !== "text") return;
  storeView(w);
  var v = vstate(w, d.path); v.mode = forceEdit ? "edit" : (v.mode === "edit" ? "view" : "edit");
  renderWin(w);
  if (v.mode === "edit") try { w.code.focus(); } catch (e) {}
}
function editing(w) { var d = docs[w.active]; return d && d.kind === "text" && (LIVE || vstate(w, d.path).mode === "edit"); }
function scroller(w) { return editing(w) ? w.code : w.view; }
function updatePos(w) {
  var c = w.code, pos = 0; try { pos = c.selectionStart; } catch (e) {}
  var before = c.value.substring(0, pos).replace(/\r\n/g, "\n").split("\n");
  var sel = 0; try { sel = Math.abs(c.selectionEnd - c.selectionStart); } catch (e2) {}
  w.curLine = before.length;
  q(w.el, ".s-pos").innerHTML = "LN <b>" + pad(before.length, 3) + "</b> &#8725; COL <b>" + pad(before[before.length - 1].length + 1, 3) + "</b>" + (sel ? " &#8725; SEL <b>" + sel + "</b>" : "");
  updateBand(w);
}
function updateBand(w) {
  if (!editing(w) || !w.curLine) { w.band.style.display = "none"; return; }
  var top = 8 + (w.curLine - 1) * lineH(w) - w.code.scrollTop;
  if (top < -lineH(w) || top > w.wrap.offsetHeight) { w.band.style.display = "none"; return; }
  w.band.style.display = "block"; w.band.style.top = top + "px";
}
function onCodeInput(w) {
  var d = docs[w.active]; if (!d) return;
  d.content = w.code.value.replace(/\r\n/g, "\n");
  if (!d.dirty) { d.dirty = true; var vs = docViews(d.path); for (var i = 0; i < vs.length; i++) { renderRail(vs[i]); renderHead(vs[i]); } renderDock(); }
  var n = d.content.split("\n").length;
  if (n !== w.lastLines) { w.lastLines = n; w.gut.innerHTML = lineNums(n); w.gut.scrollTop = w.code.scrollTop; }
  if (LIVE) {
    clearTimeout(w.hlT);
    var doIt = function () { w.view.innerHTML = highlight(d.content, ext(d.name)) + "\n\n\n"; w.view.scrollTop = w.code.scrollTop; w.view.scrollLeft = w.code.scrollLeft; };
    if (d.content.length < 60000) doIt(); else w.hlT = setTimeout(doIt, 200);
  }
  clearTimeout(w.mapT); w.mapT = setTimeout(function () { drawMap(w); }, 250);
  updatePos(w);
  syncOthers(w, d);
}
var syncT = null;
function syncOthers(src, d) {
  clearTimeout(syncT);
  syncT = setTimeout(function () {
    for (var i = 0; i < wins.length; i++) {
      var w = wins[i];
      if (w === src || w.active !== d.path || hasC(w.el, "mn")) continue;
      var sc = scroller(w), st = sc.scrollTop, sl = sc.scrollLeft, ss = 0, se = 0;
      try { ss = w.code.selectionStart; se = w.code.selectionEnd; } catch (e) {}
      renderWin(w);
      sc = scroller(w); sc.scrollTop = st; sc.scrollLeft = sl; w.gut.scrollTop = st;
      if (LIVE) { w.view.scrollTop = st; w.view.scrollLeft = sl; }
      try { w.code.setSelectionRange(ss, se); } catch (e2) {}
      updateMapView(w);
    }
  }, 120);
}

/* ---------------- minimap ---------------- */
function drawMap(w) {
  var d = docs[w.active];
  if (!settings.map || !d || d.kind !== "text" || !w.map.getContext || w.map.style.display === "none") return;
  var cv = w.map, ctx = cv.getContext("2d"), cw = cv.offsetWidth || 74, ch = cv.offsetHeight || 300;
  cv.width = cw; cv.height = ch;
  var lines = d.content.split("\n"), n = lines.length, sc = Math.min(3, ch / Math.max(n, 1));
  w.mapScale = sc;
  ctx.clearRect(0, 0, cw, ch);
  var step = sc < 0.5 ? Math.ceil(0.5 / sc) : 1, cm = commentFor(ext(d.name))[0].replace(/\s+$/, "");
  for (var i = 0; i < n; i += step) {
    var ln = lines[i], t = trim(ln); if (!t) continue;
    var ind = ln.length - ln.replace(/^\s+/, "").length;
    var x = 6 + Math.min(30, ind * 0.7), wd = Math.min(cw - x - 4, t.length * 0.7);
    ctx.fillStyle = t.indexOf(cm) === 0 ? CUR.SC : (/^(function|class|def |public|private|const|var|let|<\w)/.test(t) ? CUR.A : "rgba(" + CUR.RGB + ",.55)");
    ctx.fillRect(x, i * sc, Math.max(2, wd), Math.max(1, sc * 0.75));
  }
  updateMapView(w);
}
function updateMapView(w) {
  var d = docs[w.active];
  if (!settings.map || !d || d.kind !== "text" || !w.mapScale) { w.mapv.style.display = "none"; return; }
  var sc = scroller(w), lh = lineH(w), s = w.mapScale;
  w.mapv.style.display = "block";
  w.mapv.style.top = Math.max(0, (sc.scrollTop / lh) * s) + "px";
  w.mapv.style.height = Math.max(8, (sc.clientHeight / lh) * s) + "px";
}
function mapJump(w, e) {
  var r = w.map.getBoundingClientRect(), y = e.clientY - r.top, s = w.mapScale || 1, sc = scroller(w);
  sc.scrollTop = Math.max(0, (y / s) * lineH(w) - sc.clientHeight / 2);
  w.gut.scrollTop = sc.scrollTop; if (LIVE) w.view.scrollTop = sc.scrollTop;
  updateMapView(w); updateBand(w);
}

/* ---------------- image viewer ---------------- */
function imgButtons(w, on) { var b = ["pv-fit","pv-one","pv-in","pv-out","pv-prev","pv-next"]; for (var i = 0; i < b.length; i++) q(w.el, "." + b[i]).style.display = on ? "" : "none"; }
function mediaFail(w, d, what) {
  w.pvMsg.innerHTML = "&#9888; This " + what + " can't be played here (format / codec not supported by this browser)." + (FS.caps.download ? '<br><br><button onclick="CoreUI.download()">&#8681; DOWNLOAD</button>' : "") + (FS.caps.shell ? '<br><br><button onclick="CoreUI.openWith()">OPEN WITH WINDOWS APP</button>' : "");
}
function renderPreview(w, d) {
  w.prev.style.display = "block";
  var bar = q(w.el, ".pv-bar"), v = vstate(w, d.path), murl = d.url || (FS.mediaUrl ? FS.mediaUrl(d.path) : FS.imgUrl(d.path));
  remC(w.pvStage, "plain");
  if (d.kind === "video" || d.kind === "audio" || d.kind === "pdf") {
    bar.style.display = "block"; w.pvStage.style.display = "block"; w.pvMsg.innerHTML = ""; imgButtons(w, false);
    w.pvInfo.innerHTML = d.kind.toUpperCase() + " &middot; " + fmtSize(d.size);
    if (d.kind === "video") {
      addC(w.pvStage, "plain");
      w.pvStage.innerHTML = '<video controls preload="metadata"></video>';
      var vd = q(w.pvStage, "video");
      vd.onloadedmetadata = function () { w.pvInfo.innerHTML = "VIDEO &middot; " + vd.videoWidth + " &times; " + vd.videoHeight + " &middot; " + fmtTime(vd.duration) + " &middot; " + fmtSize(d.size); };
      vd.onerror = function () { w.pvStage.innerHTML = ""; mediaFail(w, d, "video"); };
      vd.src = murl;
    } else if (d.kind === "audio") {
      addC(w.pvStage, "plain");
      w.pvStage.innerHTML = '<div class="pv-audio"><div class="pv-disc"></div><audio controls preload="metadata"></audio><div class="pv-info" style="margin-top:12px">' + esc(d.name) + '</div></div>';
      var au = q(w.pvStage, "audio"), disc = q(w.pvStage, ".pv-disc");
      au.onplay = function () { addC(disc, "spin"); }; au.onpause = function () { remC(disc, "spin"); };
      au.onloadedmetadata = function () { w.pvInfo.innerHTML = "AUDIO &middot; " + fmtTime(au.duration) + " &middot; " + fmtSize(d.size); };
      au.onerror = function () { w.pvStage.innerHTML = ""; mediaFail(w, d, "audio file"); };
      au.src = murl;
    } else {
      w.pvStage.innerHTML = '<iframe title="PDF"></iframe>';
      q(w.pvStage, "iframe").src = murl;
    }
    return;
  }
  imgButtons(w, true);
  if (d.kind === "img") {
    bar.style.display = "block"; w.pvStage.style.display = "block"; w.pvMsg.innerHTML = "";
    var url = d.url || FS.imgUrl(d.path);
    w.pvStage.innerHTML = '<img alt="">';
    var img = q(w.pvStage, "img");
    img.onload = function () { d.iw = img.naturalWidth; d.ih = img.naturalHeight; applyImgZoom(w); };
    img.onerror = function () { w.pvStage.innerHTML = ""; w.pvMsg.innerHTML = "&#9888; This image can't be displayed here.<br>(The format may not be supported" + (FS.caps.shell ? " - try APP to open it in Windows" : "") + ")"; };
    img.src = url;
    applyImgZoom(w);
  } else {
    bar.style.display = "none"; w.pvStage.style.display = "none";
    var dl = FS.caps.download ? '<br><br><button onclick="CoreUI.download()">&#8681; DOWNLOAD</button>' : "";
    var op = FS.caps.shell ? '<br><br><button onclick="CoreUI.openWith()">OPEN WITH WINDOWS APP</button>' : "";
    w.pvMsg.innerHTML = '<div style="font-size:40px">&#9670;</div>' + (d.kind === "big" ? "This file is too big to edit here (over 3 MB)." : "This is not a text file, so it can't be shown here.") + "<br>SIZE " + fmtSize(d.size) + dl + op;
  }
  void v;
}
function fmtTime(s) { if (!s || isNaN(s) || !isFinite(s)) return "--:--"; s = Math.round(s); var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60; return (h ? h + ":" + pad(m) : m) + ":" + pad(x); }
function applyImgZoom(w) {
  var d = docs[w.active]; if (!d || d.kind !== "img") return;
  var img = q(w.pvStage, "img"); if (!img) return;
  var v = vstate(w, d.path);
  if (v.zoom === "fit") { img.style.width = "auto"; img.style.height = "auto"; img.style.maxWidth = "calc(100% - 40px)"; img.style.maxHeight = "calc(100% - 40px)"; }
  else { img.style.maxWidth = "none"; img.style.maxHeight = "none"; if (d.iw) { img.style.width = Math.round(d.iw * v.zoom) + "px"; img.style.height = Math.round(d.ih * v.zoom) + "px"; } }
  w.pvInfo.innerHTML = (d.iw ? d.iw + " &times; " + d.ih + " px &middot; " : "") + fmtSize(d.size) + " &middot; " + (v.zoom === "fit" ? "FIT" : Math.round(v.zoom * 100) + "%");
}
function setImgZoom(w, z) { var d = docs[w.active]; if (!d) return; vstate(w, d.path).zoom = z; applyImgZoom(w); }
function zoomImg(w, f) {
  var d = docs[w.active]; if (!d || d.kind !== "img") return;
  var v = vstate(w, d.path), img = q(w.pvStage, "img"), cur = v.zoom;
  if (cur === "fit") cur = img && d.iw ? img.offsetWidth / d.iw : 1;
  setImgZoom(w, Math.max(0.05, Math.min(20, cur * f)));
}
function stepImage(w, dir) {
  var d = docs[w.active]; if (!d) return;
  var folder = FS.dirOf(d.path);
  FS.list(folder, function (err, data) {
    if (err) { toast("✕ " + err); return; }
    var imgs = [];
    for (var i = 0; i < data.files.length; i++) { var f = data.files[i]; if (f.img || isImgName(f.name)) imgs.push(f); }
    if (imgs.length < 2) { toast("No other images in this folder"); return; }
    var idx = 0; for (var j = 0; j < imgs.length; j++) { if (imgs[j].path === d.path) idx = j; }
    var nx = imgs[(idx + dir + imgs.length) % imgs.length], old = d.path;
    openFile(nx.path, nx.name, false, w);
    setTimeout(function () { if (old !== nx.path && docs[old] && !docs[old].dirty) winCloseTab(w, old, true); }, 50);
  });
}

/* ---------------- editing helpers ---------------- */
function insertText(w, s, e, text) {
  var c = w.code; c.focus();
  try { c.setSelectionRange(s, e); } catch (er) {}
  var ok = false;
  try { ok = document.execCommand && document.execCommand("insertText", false, text); } catch (er2) { ok = false; }
  if (!ok || c.value.substring(s, s + text.length) !== text) {
    var v = c.value; c.value = v.substring(0, s) + text + v.substring(e);
    c.setSelectionRange(s + text.length, s + text.length);
    onCodeInput(w);
  }
}
function lineStart(v, pos) { return v.lastIndexOf("\n", pos - 1) + 1; }
function lineEnd(v, pos) { var i = v.indexOf("\n", pos); if (i < 0) i = v.length; if (v.charAt(i - 1) === "\r") i--; return i; }
function selLines(c) {
  var v = c.value, s = c.selectionStart, e = c.selectionEnd;
  if (e > s && v.charAt(e - 1) === "\n") e--;
  return { v: v, s: lineStart(v, s), e: lineEnd(v, e), nl: v.indexOf("\r\n") >= 0 ? "\r\n" : "\n" };
}
function editorKeys(w, e) {
  var c = w.code, k = e.keyCode, d = docs[w.active];
  if (!d || c.readOnly) return;
  if (k === 9) {
    prevent(e);
    var s = c.selectionStart, en = c.selectionEnd;
    if (s === en && !e.shiftKey) { insertText(w, s, en, "    "); return; }
    var L = selLines(c), lines = L.v.substring(L.s, L.e).split(/\r?\n/);
    for (var i = 0; i < lines.length; i++) lines[i] = e.shiftKey ? lines[i].replace(/^( {1,4}|\t)/, "") : "    " + lines[i];
    var block = lines.join(L.nl); insertText(w, L.s, L.e, block); c.setSelectionRange(L.s, L.s + block.length);
    return;
  }
  if (k === 13 && !e.ctrlKey && !e.shiftKey) {
    var v = c.value, p = c.selectionStart, ls = lineStart(v, p), ind = /^[ \t]*/.exec(v.substring(ls, p))[0];
    var before = v.charAt(p - 1), after = v.charAt(c.selectionEnd), nl = v.indexOf("\r\n") >= 0 ? "\r\n" : "\n";
    prevent(e);
    if (before && "{[(".indexOf(before) >= 0 && after && "}])".indexOf(after) >= 0) {
      insertText(w, p, c.selectionEnd, nl + ind + "    " + nl + ind);
      var cp = p + nl.length + ind.length + 4; c.setSelectionRange(cp, cp);
    } else insertText(w, p, c.selectionEnd, nl + ind + (before && "{[(:".indexOf(before) >= 0 ? "    " : ""));
    updatePos(w);
    return;
  }
  if (e.ctrlKey && (k === 191 || k === 111)) {
    prevent(e);
    var cm = commentFor(ext(d.name)), L2 = selLines(c), ls2 = L2.v.substring(L2.s, L2.e).split(/\r?\n/), all = true, j;
    for (j = 0; j < ls2.length; j++) { if (trim(ls2[j]) && trim(ls2[j]).indexOf(trim(cm[0])) !== 0) all = false; }
    for (j = 0; j < ls2.length; j++) {
      if (!trim(ls2[j])) continue;
      var lead = /^\s*/.exec(ls2[j])[0], rest = ls2[j].substring(lead.length);
      if (all) {
        rest = rest.indexOf(cm[0]) === 0 ? rest.substring(cm[0].length) : rest.substring(trim(cm[0]).length);
        if (cm[1]) { var ci = rest.lastIndexOf(trim(cm[1])); if (ci >= 0) rest = rest.substring(0, ci).replace(/\s+$/, ""); }
        ls2[j] = lead + rest;
      } else ls2[j] = lead + cm[0] + rest + cm[1];
    }
    var blk = ls2.join(L2.nl); insertText(w, L2.s, L2.e, blk); c.setSelectionRange(L2.s, L2.s + blk.length);
    return;
  }
  if (e.ctrlKey && k === 68) {
    prevent(e);
    var L3 = selLines(c), b3 = L3.v.substring(L3.s, L3.e), col = c.selectionStart - L3.s;
    insertText(w, L3.e, L3.e, L3.nl + b3);
    var np = L3.e + L3.nl.length + Math.min(col, b3.length); c.setSelectionRange(np, np); updatePos(w);
    return;
  }
}

/* ---------------- find & replace ---------------- */
function ensureEdit(w) { var d = docs[w.active]; if (!LIVE && d && vstate(w, d.path).mode !== "edit") toggleMode(w, true); }
function openFind(w) {
  var d = docs[w.active]; if (!d || d.kind !== "text") return;
  addC(w.body, "finding");
  try { var sel = w.code.value.substring(w.code.selectionStart, w.code.selectionEnd); if (sel && sel.indexOf("\n") < 0 && w.code.style.display !== "none") w.fq.value = sel; } catch (e) {}
  w.fq.focus(); try { w.fq.select(); } catch (e2) {}
  countMatches(w); setTimeout(function () { drawMap(w); }, 20);
}
function closeFind(w) { remC(w.body, "finding"); w.finfo.innerHTML = ""; setTimeout(function () { drawMap(w); }, 20); }
function countMatches(w) {
  var d = docs[w.active], qv = w.fq.value; if (!d || !qv) { w.finfo.innerHTML = ""; return 0; }
  var hay = w.findCase ? d.content : d.content.toLowerCase(), nd = w.findCase ? qv : qv.toLowerCase(), n = 0, p = -1;
  while ((p = hay.indexOf(nd, p + 1)) >= 0) { n++; if (n > 99999) break; }
  w.finfo.innerHTML = n ? n + " found" : "not found"; return n;
}
function findNext(w, back) {
  var qv = w.fq.value, d = docs[w.active]; if (!qv || !d) return false;
  ensureEdit(w);
  var c = w.code, v = c.value, hay = w.findCase ? v : v.toLowerCase(), nd = w.findCase ? qv : qv.toLowerCase();
  var idx = back ? hay.lastIndexOf(nd, c.selectionStart - 1) : hay.indexOf(nd, c.selectionEnd);
  if (idx < 0) idx = back ? hay.lastIndexOf(nd) : hay.indexOf(nd);
  var n = countMatches(w);
  if (idx < 0) return false;
  c.focus(); c.setSelectionRange(idx, idx + qv.length);
  var line = v.substring(0, idx).split("\n").length;
  c.scrollTop = Math.max(0, (line - 6) * lineH(w)); w.gut.scrollTop = c.scrollTop; if (LIVE) w.view.scrollTop = c.scrollTop;
  updatePos(w); updateMapView(w); w.fq.focus();
  w.finfo.innerHTML = n + " found";
  return true;
}
function replaceOne(w) {
  var qv = w.fq.value; if (!qv) return;
  ensureEdit(w);
  var c = w.code, sel = c.value.substring(c.selectionStart, c.selectionEnd);
  if (w.findCase ? sel === qv : sel.toLowerCase() === qv.toLowerCase()) { var s = c.selectionStart; insertText(w, s, c.selectionEnd, w.fr.value); c.setSelectionRange(s + w.fr.value.length, s + w.fr.value.length); }
  findNext(w, false);
}
function replaceAll(w) {
  var qv = w.fq.value, d = docs[w.active]; if (!qv || !d) return;
  ensureEdit(w);
  var c = w.code, v = c.value, out = "", i = 0, n = 0, hay = w.findCase ? v : v.toLowerCase(), nd = w.findCase ? qv : qv.toLowerCase(), p;
  while ((p = hay.indexOf(nd, i)) >= 0) { out += v.substring(i, p) + w.fr.value; i = p + qv.length; n++; }
  if (!n) { w.finfo.innerHTML = "not found"; return; }
  out += v.substring(i);
  insertText(w, 0, v.length, out);
  toast("Replaced " + n + " time(s)"); countMatches(w);
}
function gotoLine(w) {
  var d = docs[w.active]; if (!d || d.kind !== "text") return;
  var n = parseInt(prompt("Go to line:", "1"), 10); if (!n) return;
  ensureEdit(w);
  var c = w.code, v = c.value, lines = v.split("\n"), pos = 0;
  n = Math.max(1, Math.min(n, lines.length));
  for (var i = 0; i < n - 1; i++) pos += lines[i].length + 1;
  c.focus(); c.setSelectionRange(pos, pos + lines[n - 1].replace(/\r$/, "").length);
  c.scrollTop = Math.max(0, (n - 6) * lineH(w)); w.gut.scrollTop = c.scrollTop; if (LIVE) w.view.scrollTop = c.scrollTop;
  updatePos(w); updateMapView(w);
}

/* ---------------- syntax colors ---------------- */
var KW = "abstract|async|await|break|case|catch|class|const|continue|def|default|delete|do|echo|elif|else|enum|export|extends|false|final|finally|for|foreach|from|function|fun|if|implements|import|in|include|instanceof|interface|is|lambda|let|namespace|new|None|null|nil|package|pass|private|protected|public|require|require_once|return|self|static|super|switch|this|throw|true|True|False|try|typeof|use|val|var|void|while|with|yield|int|float|double|string|bool|boolean|char|long|struct|fn|mut|pub|impl|func|go|type|override|required|late|dynamic|print|and|or|not|as|set|goto|call|rem|elseif|endif|then|end|local";
function highlight(src, lang) {
  if (src.length > 400000) return esc(src);
  if (inArr(["html","htm","xml","svg","vue","hta"], lang)) return hlMarkup(src);
  if (inArr(["txt","md","csv","log",""], lang)) return esc(src);
  var hash = inArr(["py","sh","yml","yaml","rb","toml","env","htaccess","ini","conf","ps1","gitignore","properties","r"], lang);
  var cm = hash ? "(#[^\\n]*)" : (lang === "php" ? "(\\/\\/[^\\n]*|\\/\\*[\\s\\S]*?\\*\\/|#[^\\n]*)" : (lang === "bat" || lang === "cmd" ? "((?:^|\\n)[ \\t]*(?:rem|::)[^\\n]*)" : (lang === "sql" || lang === "lua" ? "(--[^\\n]*)" : "(\\/\\/[^\\n]*|\\/\\*[\\s\\S]*?\\*\\/)")));
  var re = new RegExp(cm + "|(\"(?:\\\\.|[^\"\\\\\\n])*\"|'(?:\\\\.|[^'\\\\\\n])*'|`(?:\\\\.|[^`\\\\])*`)|\\b(\\d+(?:\\.\\d+)?)\\b|\\b(" + KW + ")\\b|\\b([A-Z][A-Za-z0-9_]*)\\b|\\b([a-zA-Z_$][\\w$]*)(?=\\s*\\()", (lang === "bat" || lang === "cmd" || lang === "sql") ? "gi" : "g");
  var o = "", last = 0, m;
  while ((m = re.exec(src))) {
    if (m[0] === "") { re.lastIndex++; continue; }
    o += esc(src.substring(last, m.index));
    o += '<span class="' + (m[1] ? "c" : m[2] ? "s" : m[3] ? "n" : m[4] ? "k" : m[5] ? "t" : "f") + '">' + esc(m[0]) + "</span>";
    last = re.lastIndex;
  }
  return o + esc(src.substring(last));
}
function hlMarkup(src) {
  return esc(src).replace(/(&lt;!--[\s\S]*?--&gt;)/g, '<span class="c">$1</span>')
    .replace(/(&lt;\/?)([\w:-]+)([^&]*?)(\/?&gt;)/g, function (a, o, tag, at, c) {
      return o + '<span class="tg">' + tag + "</span>" + at.replace(/([\w:@-]+)(=)(&quot;[^&]*?&quot;|'[^']*')/g, '<span class="at">$1</span>$2<span class="s">$3</span>') + c;
    });
}

/* ---------------- window snapping ---------------- */
function snapZone(x, y) { if (y <= 4) return "max"; if (x <= 6) return "left"; if (x >= W() - 7) return "right"; return null; }
function snapRect(z) { var w = W(), h = H(); if (z === "left") return [0, 0, Math.floor(w / 2), h]; if (z === "right") return [Math.ceil(w / 2), 0, Math.floor(w / 2), h]; if (z === "max") return [0, 0, w, h]; return null; }
function showSnap(z) {
  var s = $("snap"), r = snapRect(z);
  if (!r) { s.style.display = "none"; return; }
  s.style.left = r[0] + "px"; s.style.top = r[1] + "px"; s.style.width = (r[2] - 4) + "px"; s.style.height = (r[3] - 4) + "px"; s.style.display = "block";
}
function applySnap(w, z) {
  if (z === "max") { addC(w.el, "max"); return; }
  var r = snapRect(z); remC(w.el, "max");
  w.el.style.left = r[0] + "px"; w.el.style.top = r[1] + "px"; w.el.style.width = r[2] + "px"; w.el.style.height = r[3] + "px";
}

/* ---------------- movable / resizable / collapsible HUD panels ---------------- */
function startHudDrag(e, el) {
  if (e.button !== 0) return;
  drag = { type: "hud", el: el, sx: e.clientX, sy: e.clientY, ox: el.offsetLeft, oy: el.offsetTop };
  prevent(e); stopEv(e);
}
function setupHud(el, handle, minBtn) {
  var key = el.id;
  if (settings.hud[key + "W"]) el.style.width = settings.hud[key + "W"] + "px";
  if (settings.hud[key + "C"]) addC(el, "collapsed");
  handle.onmousedown = function (e) { var t = e.target || e.srcElement; if (t === minBtn) return; startHudDrag(e, el); };
  handle.ondblclick = function () { el.style.left = ""; el.style.top = ""; el.style.bottom = ""; };
  q(el, ".hud-w").onmousedown = function (e) { drag = { type: "hudw", el: el, sx: e.clientX, sy: e.clientY, ow: el.offsetWidth }; prevent(e); stopEv(e); };
  minBtn.onmousedown = function (e) { stopEv(e); };
  minBtn.onclick = function (e) {
    stopEv(e);
    var c = !hasC(el, "collapsed"); tgC(el, "collapsed", c);
    minBtn.innerHTML = c ? "&#9652;" : "&#9662;";
    settings.hud[key + "C"] = c; saveSettings(); relayout();
  };
  if (hasC(el, "collapsed")) minBtn.innerHTML = "&#9652;";
}

/* ================= GLOBAL EVENTS ================= */
function bind() {
  $("core").onclick = function (e) { stopEv(e); if (!justDragged) goUp(); };
  $("core").oncontextmenu = function (e) { prevent(e); stopEv(e); showMenu(e, null); return false; };
  $("core").onmouseover = function () { S.hover = null; if (S.data) $("info").innerHTML = '<span class="k2">' + (S.data.isZip ? "IN ZIP" : "CURRENT") + "</span>" + esc(S.data.display || S.data.path) + "<br>" + $("cInfo").innerHTML; };
  $("pasteBtn").onclick = function () { paste(); };
  $("pasteX").onclick = function () { CB = null; updatePasteBtn(); markNodes(); toast("Clipboard cleared"); };
  $("fxBtn").onclick = function () { settings.fx = !settings.fx; saveSettings(); applyFx(); if (S.data) render(S.data); toast(settings.fx ? "Animations ON" : "Animations OFF (faster)"); };
  applyFx();
  $("upBtn").onclick = goUp; $("backBtn").onclick = goBack; $("fwdBtn").onclick = goFwd;
  $("homeBtn").onclick = function () { goTo(FS.home); };
  $("rootBtn").onclick = function () { goTo(FS.root); };
  $("rootBtn").innerHTML = FS.caps.drives ? "PC" : "ROOT";
  $("refreshBtn").onclick = function () { refresh(); toast("Refreshed"); };
  $("hidBtn").onclick = function () { settings.hidden = !settings.hidden; saveSettings(); tgC($("hidBtn"), "on", settings.hidden); if (S.data) render(S.data); toast(settings.hidden ? "Showing hidden files" : "Hidden files are hidden"); };
  tgC($("hidBtn"), "on", settings.hidden);
  $("favBtn").onclick = function () { showQuick(); };
  setupHud($("hudTL"), q($("hudTL"), ".brand"), $("tlMin"));
  setupHud($("hudBL"), $("blHead"), $("blMin"));
  $("hudBL").onmousedown = function (e) { var t = e.target || e.srcElement; if (t === $("hudBL") || t === $("info")) startHudDrag(e, $("hudBL")); };
  $("zIn").onclick = function () { S.zoom = Math.min(4, S.zoom * 1.25); applyTransform(); };
  $("zOut").onclick = function () { S.zoom = Math.max(.25, S.zoom / 1.25); applyTransform(); };
  $("newBtn").onclick = function () { openCreator(false); };
  $("themeBtn").onclick = function () { applyTheme(settings.theme + 1); saveSettings(); if (S.data) render(S.data); toast("Theme: " + CUR.name); };
  $("helpBtn").onclick = function () { var h = $("help"), on = h.style.display !== "block"; h.style.display = on ? "block" : "none"; tgC($("helpBtn"), "on", on); if (on) remC($("hudBL"), "collapsed"); };
  // creator
  $("crClose").onclick = closeCreator;
  $("crFile").onclick = function () { CR.dir = false; if (/^new-folder/.test($("crName").value)) $("crName").value = "untitled.txt"; renderCreator(); };
  $("crDir").onclick = function () { CR.dir = true; if (/^untitled/.test($("crName").value)) $("crName").value = "new-folder"; renderCreator(); };
  $("crGo").onclick = function () { doCreate(false); };
  $("crGoOpen").onclick = function () { doCreate(true); };
  $("crName").onkeydown = function (e) { if (e.keyCode === 13) { prevent(e); doCreate(e.shiftKey); } if (e.keyCode === 27) closeCreator(); };
  $("creator").onmousedown = function (e) { stopEv(e); };
  if (FS.caps.upload) {
    $("uploadBtn").onclick = function () { $("upInput").click(); };
    $("upInput").onchange = function () { uploadFiles(this.files); this.value = ""; };
    var dz = $("dropzone"), dragCount = 0;
    document.addEventListener("dragenter", function (e) { if (e.dataTransfer && inArr(Array.prototype.slice.call(e.dataTransfer.types || []), "Files")) { dragCount++; dz.style.display = "block"; } }, false);
    document.addEventListener("dragleave", function () { dragCount--; if (dragCount <= 0) { dragCount = 0; dz.style.display = "none"; } }, false);
    document.addEventListener("dragover", function (e) { prevent(e); }, false);
    document.addEventListener("drop", function (e) { prevent(e); dragCount = 0; dz.style.display = "none"; if (e.dataTransfer && e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files); }, false);
  } else $("uploadBtn").style.display = "none";

  $("pathInput").onkeydown = function (e) { if (e.keyCode === 13) { prevent(e); var v = trim($("pathInput").value); goTo(v === "" || /^this pc$/i.test(v) ? FS.root : FS.fromDisplay ? FS.fromDisplay(v) : v); } };
  $("search").onkeyup = function (e) {
    if (e.keyCode === 13) { var qv = trim($("search").value); if (qv) deepSearch(qv); return; }
    if (e.keyCode === 27) { $("search").value = ""; $("results").style.display = "none"; }
    if (!$("search").value) $("results").style.display = "none";
    applyFilter();
  };
  $("search").setAttribute("placeholder", "> filter... (Enter = deep search)");
  $("pathInput").setAttribute("placeholder", "path...");
  $("crName").setAttribute("placeholder", "name...");

  var space = $("space");
  var onWheel = function (e) { var dl = e.deltaY !== undefined ? e.deltaY : -(e.wheelDelta || 0); S.zoom = Math.min(4, Math.max(.25, S.zoom * (dl < 0 ? 1.12 : .89))); applyTransform(); prevent(e); };
  space.addEventListener("wheel", onWheel, false);
  space.addEventListener("mousewheel", onWheel, false);
  space.onselectstart = function () { return false; };
  space.onmousedown = function (e) {
    if (e.button !== 0) return;
    var t = e.target || e.srcElement;
    while (t && t !== space) { if (t.id === "core" || hasC(t, "nd")) return; t = t.parentNode; }
    drag = { type: "pan", sx: e.clientX, sy: e.clientY, ox: S.panX, oy: S.panY, moved: false };
  };
  document.addEventListener("mousemove", function (e) {
    if (!drag) return;
    var dx = e.clientX - drag.sx, dy = e.clientY - drag.sy;
    if (drag.type === "pan") {
      if (!drag.moved && Math.abs(dx) + Math.abs(dy) > 8) { drag.moved = true; $("stage").style.transition = "none"; }
      if (drag.moved) { S.panX = drag.ox + dx; S.panY = drag.oy + dy; applyTransform(); }
    } else if (drag.type === "move") {
      var nx = Math.max(-drag.w.el.offsetWidth + 120, Math.min(W() - 80, drag.ox + dx)), ny = Math.max(0, Math.min(H() - 40, drag.oy + dy));
      drag.w.el.style.left = nx + "px"; drag.w.el.style.top = ny + "px";
      drag.snap = snapZone(e.clientX, e.clientY); showSnap(drag.snap);
    } else if (drag.type === "resize") {
      var d = drag, el = d.w.el, L = d.ox, T = d.oy, Wd = d.ow, Ht = d.oh, MINW = 380, MINH = 220;
      if (d.dir.indexOf("e") >= 0) Wd = Math.max(MINW, Math.min(W() - L, d.ow + dx));
      if (d.dir.indexOf("s") >= 0) Ht = Math.max(MINH, Math.min(H() - T, d.oh + dy));
      if (d.dir.indexOf("w") >= 0) { Wd = Math.max(MINW, d.ow - dx); L = d.ox + d.ow - Wd; if (L < 0) { Wd += L; L = 0; } }
      if (d.dir.indexOf("n") >= 0) { Ht = Math.max(MINH, d.oh - dy); T = d.oy + d.oh - Ht; if (T < 0) { Ht += T; T = 0; } }
      el.style.left = L + "px"; el.style.top = T + "px"; el.style.width = Wd + "px"; el.style.height = Ht + "px";
      updateBand(d.w);
    } else if (drag.type === "hud") {
      drag.el.style.left = Math.max(0, Math.min(W() - 60, drag.ox + dx)) + "px"; drag.el.style.top = Math.max(0, Math.min(H() - 24, drag.oy + dy)) + "px"; drag.el.style.bottom = "auto";
    } else if (drag.type === "hudw") {
      drag.el.style.width = Math.max(drag.el.id === "hudTL" ? 300 : 260, Math.min(760, drag.ow + dx)) + "px";
    } else if (drag.type === "map") mapJump(drag.w, e);
    prevent(e);
  }, false);
  document.addEventListener("mouseup", function () {
    if (!drag) return;
    var d = drag; drag = null;
    if (d.type === "pan") { $("stage").style.transition = ""; if (d.moved) { justDragged = true; setTimeout(function () { justDragged = false; }, 40); } }
    else if (d.type === "move") { showSnap(null); if (d.snap) { applySnap(d.w, d.snap); drawMap(d.w); } relayout(); }
    else if (d.type === "resize") { drawMap(d.w); updateBand(d.w); relayout(); }
    else if (d.type === "hudw") { settings.hud[d.el.id + "W"] = d.el.offsetWidth; saveSettings(); relayout(); }
    else if (d.type === "hud") relayout();
  }, false);
  document.addEventListener("click", function (e) {
    hideMenu();
    var t = e.target || e.srcElement, inHud = false;
    while (t) { if (t.id === "hudTL") { inHud = true; break; } t = t.parentNode; }
    if (!inHud) $("quick").style.display = "none";
  }, false);
  document.oncontextmenu = function (e) { var t = e.target || e.srcElement; if (t && (t.tagName === "TEXTAREA" || t.tagName === "INPUT")) return true; prevent(e); return false; };
  window.onresize = function () { relayout(); for (var i = 0; i < wins.length; i++) drawMap(wins[i]); };

  document.addEventListener("keydown", function (e) {
    var k = e.keyCode, t = e.target || e.srcElement, tag = t.tagName, typing = tag === "INPUT" || tag === "TEXTAREA", w = activeWin;
    if (e.ctrlKey && e.shiftKey && k === 83) { prevent(e); saveAll(); return; }
    if (e.ctrlKey && k === 83) { prevent(e); if (w) saveDoc(docs[w.active]); return; }
    if (e.ctrlKey && k === 70) { if (w) { prevent(e); openFind(w); } return; }
    if (e.ctrlKey && k === 72) { if (w) { prevent(e); openFind(w); w.fr.focus(); } return; }
    if (e.ctrlKey && k === 71) { if (w) { prevent(e); gotoLine(w); } return; }
    if (e.ctrlKey && k === 69) { if (w) { prevent(e); toggleMode(w); } return; }
    if (e.ctrlKey && k === 87) { if (w && w.active) { prevent(e); winCloseTab(w, w.active); } return; }
    if (e.ctrlKey && k === 78) { prevent(e); openCreator(false); return; }
    if (e.ctrlKey && k === 80) { prevent(e); $("search").focus(); return; }
    if (k === 116) { prevent(e); refresh(); return; }
    if (e.altKey && k === 37) { prevent(e); goBack(); return; }
    if (e.altKey && k === 39) { prevent(e); goFwd(); return; }
    if (k === 27) { hideMenu(); $("quick").style.display = "none"; if ($("creator").style.display === "block") { closeCreator(); return; } if (!typing && w && !hasC(w.el, "mn")) minWin(w); return; }
    if (typing) return;
    if (e.ctrlKey && k === 67 && S.hover) { prevent(e); clip("copy", S.hover); return; }
    if (e.ctrlKey && k === 88 && S.hover && !readOnlyHere()) { prevent(e); clip("cut", S.hover); return; }
    if (e.ctrlKey && k === 86) { prevent(e); paste(); return; }
    if (k === 113 && S.hover && !readOnlyHere()) { prevent(e); renameItem(S.hover); return; }
    if (k === 46 && S.hover && !readOnlyHere() && !S.hover.drive) { prevent(e); deleteItem(S.hover); return; }
    if (k === 8) { prevent(e); goUp(); }
    else if (k === 107 || k === 187) { S.zoom = Math.min(4, S.zoom * 1.25); applyTransform(); }
    else if (k === 109 || k === 189) { S.zoom = Math.max(.25, S.zoom / 1.25); applyTransform(); }
    else if (k === 48 || k === 96) { S.zoom = 1; S.panX = S.panY = 0; applyTransform(); }
  }, false);
  window.onbeforeunload = function () { for (var i = 0; i < wins.length; i++) storeView(wins[i]); if (anyDirty()) return "You have unsaved files."; };
}

/* ---------------- radar (cached background + light sweep) ---------------- */
var radarDirty = true;
function applyFx() {
  tgC(document.body, "nofx", !settings.fx);
  tgC($("fxBtn"), "on", settings.fx);
  radarDirty = true;
}
function radar() {
  var cv = $("radar"); if (!cv.getContext) return; var ctx = cv.getContext("2d");
  var bg = document.createElement("canvas"), bgx = bg.getContext("2d"), key = "", ang = 0, last = 0;
  var raf = window.requestAnimationFrame || function (f) { return setTimeout(function () { f(new Date().getTime()); }, 40); };
  function size() { cv.width = cv.offsetWidth; cv.height = cv.offsetHeight; bg.width = cv.width; bg.height = cv.height; radarDirty = true; }
  size(); window.addEventListener("resize", size, false);
  function paintBg(cx, cy, sc) {
    var R = Math.max(W(), H()) * 1.2, i, rgb = CUR.RGB;
    bgx.clearRect(0, 0, bg.width, bg.height);
    var g = bgx.createRadialGradient(cx, cy, 0, cx, cy, 500 * sc + 200);
    g.addColorStop(0, "rgba(" + rgb + ",0.10)"); g.addColorStop(1, "rgba(" + rgb + ",0)");
    bgx.fillStyle = g; bgx.fillRect(0, 0, bg.width, bg.height);
    bgx.strokeStyle = "rgba(" + rgb + ",0.07)"; bgx.lineWidth = 1;
    for (i = 1; i * 90 * sc < R && i < 60; i++) { bgx.beginPath(); bgx.arc(cx, cy, i * 90 * sc, 0, Math.PI * 2); bgx.stroke(); }
    bgx.beginPath(); bgx.moveTo(cx - R, cy); bgx.lineTo(cx + R, cy); bgx.moveTo(cx, cy - R); bgx.lineTo(cx, cy + R); bgx.stroke();
  }
  function frame(now) {
    raf(frame);
    if (document.hidden || anyMax()) return;
    var animate = settings.fx;
    now = now || new Date().getTime();
    if (animate && now - last < 40) return;
    var a = area(), cx = Math.round(a.cx + S.panX), cy = Math.round(a.cy + S.panY), sc = S.fit * S.zoom;
    var k = cx + "|" + cy + "|" + sc.toFixed(3) + "|" + cv.width + "|" + cv.height + "|" + settings.theme;
    if (k !== key || radarDirty) { key = k; radarDirty = false; paintBg(cx, cy, sc); if (!animate) { ctx.clearRect(0, 0, cv.width, cv.height); ctx.drawImage(bg, 0, 0); } }
    if (!animate) return;
    last = now;
    ctx.clearRect(0, 0, cv.width, cv.height);
    ctx.drawImage(bg, 0, 0);
    var R = Math.max(W(), H()) * 1.2, rgb = CUR.RGB;
    for (var i = 0; i < 10; i++) {
      ctx.beginPath(); ctx.moveTo(cx, cy); ctx.arc(cx, cy, R, ang - (i + 1) * 0.05, ang - i * 0.05); ctx.closePath();
      ctx.fillStyle = "rgba(" + rgb + "," + (0.13 * (1 - i / 10)).toFixed(3) + ")"; ctx.fill();
    }
    ctx.strokeStyle = "rgba(" + rgb + ",0.7)"; ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(cx + Math.cos(ang) * R, cy + Math.sin(ang) * R); ctx.stroke();
    ang += 0.035;
  }
  raf(frame);
}
function anyMax() { for (var i = 0; i < wins.length; i++) { if (hasC(wins[i].el, "max") && !hasC(wins[i].el, "mn")) return true; } return false; }
function clock() { var d = new Date(); $("clock").innerHTML = pad(d.getHours()) + ":" + pad(d.getMinutes()) + ":" + pad(d.getSeconds()); }

/* ---------------- public (for inline buttons) ---------------- */
window.CoreUI = {
  download: function () { if (activeWin && activeWin.active && FS.download) FS.download(activeWin.active); },
  openWith: function () { if (activeWin && activeWin.active && FS.openWith) FS.openWith(activeWin.active); }
};

/* ---------------- start ---------------- */
applyTheme(settings.theme || 0);
bind();
radar();
clock(); setInterval(clock, 1000);
if (FS.sysInfo) FS.sysInfo(function (t) { $("sys").innerHTML = esc(t); });
goTo(FS.home, false, function (ok) { if (!ok) goTo(FS.root); });
})();

</script>
<?php endif; ?>
</body>
</html>
