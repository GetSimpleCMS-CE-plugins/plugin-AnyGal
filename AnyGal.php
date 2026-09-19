<?php if (!defined('IN_GS')) { die('You cannot load this page directly.'); }

/*
Plugin Name: AnyGal
Description: Build image galleries from your own HTML templates and show them with a shortcode or a PHP call.
Version: 1.0
Author: risingisland
Author URI: https://ko-fi.com/ericmontgomery
*/

$AnyGal = basename(__FILE__, '.php');

define('ANYGAL_SLUG', $AnyGal);
define('ANYGAL_DATA', GSDATAOTHERPATH . 'AnyGal/');
define('ANYGAL_MAX_UPLOAD', 10 * 1024 * 1024); // bytes, per image

// Load translations
i18n_merge(ANYGAL_SLUG) || i18n_merge(ANYGAL_SLUG, 'en_US');

register_plugin(
	ANYGAL_SLUG,
	i18n_r(ANYGAL_SLUG . '/lang_Menu_Title'),
	'1.0',
	'risingisland',
	'https://getsimple-ce.ovh/plugins/',
	i18n_r(ANYGAL_SLUG . '/lang_Description'),
	'block',
	'anygal_admin'
);

# Ensure data folder exists and is not web-readable
if (!is_dir(ANYGAL_DATA)) {
	@mkdir(ANYGAL_DATA, 0755, true);
}
if (is_dir(ANYGAL_DATA) && !file_exists(ANYGAL_DATA . '.htaccess')) {
	@file_put_contents(ANYGAL_DATA . '.htaccess', "Deny from all\n");
}

# Add a link in the admin navigation
add_action('nav-tab', 'createNavTab', array('block', ANYGAL_SLUG, anygal_t('Icon_Menu') . anygal_t('Tab_Title')));

# =====================
# SMALL HELPERS
# =====================

// Translated string for this plugin
function anygal_t($key) {
	return i18n_r(ANYGAL_SLUG . '/lang_' . $key);
}

// HTML-escape for output
function anygal_h($s) {
	return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Only accept plain strings from request arrays (ignore ?x[]=y style input)
function anygal_str($v) {
	return is_string($v) ? $v : '';
}

// Template text from a form field: unify line endings and trim
function anygal_tpl($v) {
	return trim(str_replace("\r\n", "\n", anygal_str($v)));
}

// Slugs: lowercase letters, numbers, hyphens and underscores only
function anygal_slug($str) {
	$str = strtolower(trim((string)$str));
	$str = str_replace(' ', '-', $str);
	$str = preg_replace('/[^a-z0-9\-_]/', '', $str);
	return trim($str, '-_');
}

// $SITEURL can say http:// on an https site (proxies, live servers), so correct it
function anygal_site_url() {
	global $SITEURL;
	$url   = (string)$SITEURL;
	$https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
		|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
		|| (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
	if ($https) {
		$url = preg_replace('~^http://~i', 'https://', $url);
	}
	return $url;
}

# =====================
# STORAGE (one JSON file per gallery)
# =====================

function anygal_path($slug) {
	$slug = anygal_slug($slug);
	return $slug === '' ? '' : ANYGAL_DATA . $slug . '.json';
}

// Returns the gallery array, or null if it does not exist
function anygal_get($slug) {
	$file = anygal_path($slug);
	if ($file === '' || !is_file($file)) {
		return null;
	}
	$data = json_decode((string)file_get_contents($file), true);
	if (!is_array($data)) {
		return null;
	}
	$data['slug']     = anygal_slug($slug);
	$data['has_data'] = !empty($data['has_data']);
	$data['wrapper']  = (string)($data['wrapper'] ?? '');
	$data['item']     = (string)($data['item'] ?? '');
	$data['images']   = anygal_normalize_images($data['images'] ?? []);
	return $data;
}

// Every image gets a string id, a clean relative file path, an optional data field, a title and a description
function anygal_normalize_images($raw) {
	$images = [];
	$seen   = [];
	if (!is_array($raw)) {
		return $images;
	}
	foreach (array_values($raw) as $i => $img) {
		if (!is_array($img)) {
			continue;
		}
		$file = anygal_clean_file($img['file'] ?? '');
		if ($file === '') {
			continue;
		}
		// Images written by hand may have no id: derive a stable one (it is stored the next time the gallery is saved)
		$id = (isset($img['id']) && is_string($img['id']) && $img['id'] !== '') ? $img['id'] : substr(sha1($i . '|' . $file), 0, 8);
		while (isset($seen[$id])) {
			$id = substr(sha1($id . '|' . $i), 0, 8);
		}
		$seen[$id] = true;
		$images[]  = [
			'id'    => $id,
			'file'  => $file,
			'data'  => (string)($img['data'] ?? ''),
			'title' => (string)($img['title'] ?? ''),
			'desc'  => (string)($img['desc'] ?? ''),
		];
	}
	return $images;
}

// All galleries, keyed by slug and sorted
function anygal_list() {
	$out = [];
	foreach ((array)glob(ANYGAL_DATA . '*.json') as $file) {
		$slug    = basename($file, '.json');
		$gallery = anygal_get($slug);
		if ($gallery) {
			$out[$slug] = $gallery;
		}
	}
	ksort($out);
	return $out;
}

// Write to a temp file, then rename over the real one so a failed write cannot leave half a gallery behind
function anygal_save($slug, $data) {
	$file = anygal_path($slug);
	if ($file === '' || !is_dir(ANYGAL_DATA)) {
		return false;
	}
	$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($json === false) {
		return false;
	}
	$tmp = $file . '.tmp';
	if (file_put_contents($tmp, $json, LOCK_EX) === false) {
		return false;
	}
	if (!@rename($tmp, $file)) {
		$ok = file_put_contents($file, $json, LOCK_EX) !== false;
		@unlink($tmp);
		return $ok;
	}
	return true;
}

function anygal_delete($slug) {
	$file = anygal_path($slug);
	if ($file === '' || !is_file($file)) {
		return false;
	}
	$ok = @unlink($file);
	@unlink($file . '.lock');
	return $ok;
}

// Read-modify-write under an exclusive lock
function anygal_mutate($slug, $fn) {
	$file = anygal_path($slug);
	if ($file === '' || !is_file($file)) {
		return false;
	}
	$lock = @fopen($file . '.lock', 'c');
	if (!$lock) {
		return false;
	}
	flock($lock, LOCK_EX);
	$result  = false;
	$gallery = anygal_get($slug);
	if ($gallery) {
		$changed = $fn($gallery);
		if (is_array($changed)) {
			$changed['updated'] = date('Y-m-d H:i:s');
			$result = anygal_save($slug, $changed) ? $changed : false;
		}
	}
	flock($lock, LOCK_UN);
	fclose($lock);
	return $result;
}

# =====================
# IMAGE URLS
# =====================

function anygal_clean_file($file) {
	$file  = str_replace('\\', '/', (string)$file);
	$parts = [];
	foreach (explode('/', $file) as $p) {
		if ($p === '' || $p === '.' || $p === '..') {
			continue;
		}
		$parts[] = $p;
	}
	return implode('/', $parts);
}

function anygal_encode_path($path) {
	return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function anygal_image_url($file) {
	return anygal_site_url() . 'data/uploads/' . anygal_encode_path($file);
}

function anygal_thumb_url($file) {
	$parts = explode('/', $file);
	$base  = array_pop($parts);
	$sub   = count($parts) ? implode('/', $parts) . '/' : '';
	if (is_file(GSTHUMBNAILPATH . $sub . 'thumbnail.' . $base)) {
		return anygal_site_url() . 'data/thumbs/' . anygal_encode_path($sub . 'thumbnail.' . $base);
	}
	return anygal_image_url($file);
}

# =====================
# UPLOADS: LISTING, VALIDATION, THUMBNAILS
# =====================

function anygal_image_exts() {
	return ['jpg', 'jpeg', 'png', 'gif', 'webp'];
}

function anygal_is_image_name($name) {
	return in_array(strtolower(pathinfo((string)$name, PATHINFO_EXTENSION)), anygal_image_exts(), true);
}

function anygal_upload_path($file) {
	$file = anygal_clean_file($file);
	if ($file === '' || !anygal_is_image_name($file)) {
		return '';
	}
	$full = GSDATAUPLOADPATH . $file;
	if (!is_file($full)) {
		return '';
	}
	$root = realpath(GSDATAUPLOADPATH);
	$real = realpath($full);
	if ($root === false || $real === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
		return '';
	}
	return $full;
}

function anygal_list_uploads() {
	$root = rtrim(GSDATAUPLOADPATH, '/\\');
	$out  = [];
	if (!is_dir($root)) {
		return $out;
	}
	try {
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) {
			if (!$f->isFile()) {
				continue;
			}
			$rel = anygal_clean_file(substr($f->getPathname(), strlen($root)));
			if ($rel === '' || !anygal_is_image_name($rel) || strpos('/' . $rel, '/.') !== false) {
				continue;
			}
			$out[] = [
				'file'  => $rel,
				'url'   => anygal_image_url($rel),
				'thumb' => anygal_thumb_url($rel),
				'mtime' => $f->getMTime(),
			];
		}
	} catch (Exception $e) {
	}
	usort($out, function ($a, $b) {
		return $b['mtime'] - $a['mtime'];
	});
	return $out;
}

function anygal_memory_ok($w, $h) {
	$limit = trim((string)ini_get('memory_limit'));
	if ($limit === '' || $limit === '-1') {
		return true;
	}
	$n    = (float)$limit;
	$unit = strtolower(substr($limit, -1));
	if ($unit === 'g') {
		$n *= 1073741824;
	} elseif ($unit === 'm') {
		$n *= 1048576;
	} elseif ($unit === 'k') {
		$n *= 1024;
	}
	return ($w * $h * 5 + memory_get_usage(true)) < $n;
}

function anygal_ensure_thumb($file) {
	$parts = explode('/', $file);
	$base  = array_pop($parts);
	$sub   = count($parts) ? implode('/', $parts) . '/' : '';
	$thumb = GSTHUMBNAILPATH . $sub . 'thumbnail.' . $base;
	if (is_file($thumb)) {
		return true;
	}
	if (!function_exists('genStdThumb') && defined('GSADMININCPATH') && is_file(GSADMININCPATH . 'imagemanipulation.php')) {
		require_once GSADMININCPATH . 'imagemanipulation.php';
	}
	if (!function_exists('genStdThumb')) {
		return false;
	}
	$size = @getimagesize(GSDATAUPLOADPATH . $file);
	if (!$size || !anygal_memory_ok($size[0], $size[1])) {
		return false;
	}
	try {
		@genStdThumb($sub, $base);
	} catch (\Throwable $e) {
		return false;
	}
	return is_file($thumb);
}

function anygal_admin_image($img) {
	return [
		'id'    => $img['id'],
		'file'  => $img['file'],
		'data'  => (string)($img['data'] ?? ''),
		'title' => $img['title'],
		'desc'  => $img['desc'],
		'url'   => anygal_image_url($img['file']),
		'thumb' => anygal_thumb_url($img['file']),
	];
}

function anygal_new_id($taken) {
	do {
		try {
			$id = bin2hex(random_bytes(4));
		} catch (\Throwable $e) {
			$id = substr(sha1(uniqid('', true)), 0, 8);
		}
	} while (in_array($id, $taken, true));
	return $id;
}

function anygal_limit($s, $max) {
	if (function_exists('mb_substr')) {
		return mb_substr($s, 0, $max, 'UTF-8');
	}
	return preg_match('/^.{0,' . (int)$max . '}/su', $s, $m) ? $m[0] : substr($s, 0, $max);
}

# =====================
# RENDERING
# =====================

function anygal_render($slug) {
	$gallery = anygal_get($slug);
	if (!$gallery) {
		return '';
	}

	$items = '';
	foreach ($gallery['images'] as $img) {
		if (!is_array($img)) {
			continue;
		}
		$file = anygal_clean_file($img['file'] ?? '');
		if ($file === '') {
			continue;
		}
		$title = (string)($img['title'] ?? '');
		$desc  = (string)($img['desc'] ?? '');
		$data  = (string)($img['data'] ?? '');
		$alt   = ($title !== '') ? $title : pathinfo($file, PATHINFO_FILENAME);

		$items .= strtr($gallery['item'], [
			'{{img-full}}'  => anygal_h(anygal_image_url($file)),
			'{{img-thumb}}' => anygal_h(anygal_thumb_url($file)),
			'{{img-data}}'  => anygal_h($data),
			'{{img-title}}' => anygal_h($title),
			'{{img-desc}}'  => anygal_h($desc),
			'{{img-alt}}'   => anygal_h($alt),
		]) . "\n";
	}

	return str_replace('{{img-tpl}}', $items, $gallery['wrapper']);
}

# =====================
# SHORTCODE
# =====================

add_filter('content', 'anygal_shortcode');

function anygal_shortcode($content) {
	$q   = '(?:"|&quot;|\'|&#0?39;)?';
	$tag = '\[get-anygal\s*=\s*' . $q . '([a-zA-Z0-9\-_]+)' . $q . '\s*\]';
	$cb  = function ($m) { return anygal_render($m[1]); };

	$content = preg_replace_callback('~<p[^>]*>\s*' . $tag . '\s*</p>~i', $cb, $content) ?? $content;
	return preg_replace_callback('~' . $tag . '~i', $cb, $content) ?? $content;
}

# =====================
# TEMPLATE FUNCTION
# =====================

if (!function_exists('get_anygal')) {
	function get_anygal($slug) {
		echo anygal_render($slug);
	}
}

# =====================
# AJAX (image manager)
# =====================

function anygal_is_admin() {
	global $USR;
	return !empty($USR) && function_exists('cookie_check') && cookie_check();
}

function anygal_move_upload($from, $to) {
	return move_uploaded_file($from, $to);
}

function anygal_json($data, $code = 200) {
	while (ob_get_level()) {
		ob_end_clean();
	}
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}

function anygal_fail($code, $key) {
	return [$code, ['ok' => false, 'message' => anygal_t($key)]];
}

function anygal_ajax() {
	$overflow = empty($_POST) && empty($_FILES) && !empty($_SERVER['CONTENT_LENGTH']);
	list($code, $data) = anygal_ajax_handle(
		anygal_is_admin(),
		isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
		anygal_str($_GET['anygal_action'] ?? ''),
		$_POST,
		$_FILES,
		'anygal_move_upload',
		$overflow
	);
	anygal_json($data, $code);
}

function anygal_ajax_handle($is_admin, $method, $action, $post, $files, $mover, $overflow = false) {
	if (!$is_admin) {
		return anygal_fail(403, 'Session_Expired');
	}
	$res = anygal_ajax_dispatch($method, $action, $post, $files, $mover, $overflow);
	$res[1]['nonce'] = get_nonce('anygal_ajax', 'load.php');
	return $res;
}

function anygal_ajax_dispatch($method, $action, $post, $files, $mover, $overflow) {
	if ($action === 'list_uploads') {
		return [200, ['ok' => true, 'files' => anygal_list_uploads()]];
	}
	if ($method !== 'POST') {
		return anygal_fail(405, 'Ajax_Error');
	}
	if ($overflow) {
		return anygal_fail(413, 'err_Upload_Post_Max');
	}
	if (!check_nonce(anygal_str($post['nonce'] ?? ''), 'anygal_ajax', 'load.php')) {
		return anygal_fail(403, 'Session_Expired');
	}

	$slug = anygal_slug(anygal_str($post['gallery'] ?? ''));

	switch ($action) {

		case 'upload':
			$target_folder = anygal_clean_file(anygal_str($post['folder'] ?? ''));
			list($added, $errors) = anygal_handle_upload($files, $mover, $target_folder);
			return [200, ['ok' => true, 'files' => $added, 'errors' => $errors]];

		case 'add_images':
			$req = json_decode(anygal_str($post['files'] ?? ''), true);
			if (!is_array($req)) {
				return anygal_fail(400, 'Ajax_Error');
			}
			$valid = [];
			foreach (array_slice($req, 0, 200) as $f) {
				$f = anygal_clean_file(is_string($f) ? $f : '');
				if ($f !== '' && anygal_upload_path($f) !== '' && !in_array($f, $valid, true)) {
					$valid[] = $f;
				}
			}
			if (!$valid) {
				return anygal_fail(400, 'err_No_Valid_Images');
			}
			foreach ($valid as $f) {
				anygal_ensure_thumb($f);
			}
			$added   = [];
			$gallery = anygal_mutate($slug, function ($g) use ($valid, &$added) {
				$taken = array_column($g['images'], 'id');
				foreach ($valid as $f) {
					$rec         = ['id' => anygal_new_id($taken), 'file' => $f, 'data' => '', 'title' => '', 'desc' => ''];
					$taken[]     = $rec['id'];
					$g['images'][] = $rec;
					$added[]     = $rec;
				}
				return $g;
			});
			if (!$gallery) {
				return anygal_fail(404, 'err_Not_Found');
			}
			return [200, ['ok' => true, 'images' => array_map('anygal_admin_image', $added)]];

		case 'update_image':
			$id    = anygal_str($post['id'] ?? '');
			$data  = anygal_limit(trim(anygal_str($post['data'] ?? '')), 2000);
			$title = anygal_str($post['title'] ?? '');
			$title = anygal_limit(trim(preg_replace('/\s+/u', ' ', $title) ?? $title), 300);
			$desc  = anygal_limit(trim(str_replace("\r\n", "\n", anygal_str($post['desc'] ?? ''))), 5000);
			$found = false;
			$gallery = anygal_mutate($slug, function ($g) use ($id, $data, $title, $desc, &$found) {
				foreach ($g['images'] as $i => $img) {
					if ($img['id'] === $id) {
						$g['images'][$i]['data']  = $data;
						$g['images'][$i]['title'] = $title;
						$g['images'][$i]['desc']  = $desc;
						$found = true;
					}
				}
				return $found ? $g : false;
			});
			if (!$gallery) {
				return $found ? anygal_fail(500, 'err_Save_Failed') : anygal_fail(404, 'err_Image_Not_Found');
			}
			return [200, ['ok' => true, 'data' => $data, 'title' => $title, 'desc' => $desc]];

		case 'remove_image':
			$id    = anygal_str($post['id'] ?? '');
			$found = false;
			$gallery = anygal_mutate($slug, function ($g) use ($id, &$found) {
				$keep = [];
				foreach ($g['images'] as $img) {
					if ($img['id'] === $id) {
						$found = true;
					} else {
						$keep[] = $img;
					}
				}
				$g['images'] = $keep;
				return $found ? $g : false;
			});
			if (!$gallery) {
				return $found ? anygal_fail(500, 'err_Save_Failed') : anygal_fail(404, 'err_Image_Not_Found');
			}
			return [200, ['ok' => true]];

		case 'reorder':
			$order = json_decode(anygal_str($post['order'] ?? ''), true);
			if (!is_array($order)) {
				return anygal_fail(400, 'Ajax_Error');
			}
			$gallery = anygal_mutate($slug, function ($g) use ($order) {
				$by = [];
				foreach ($g['images'] as $img) {
					$by[$img['id']] = $img;
				}
				$new = [];
				foreach ($order as $id) {
					if (is_string($id) && isset($by[$id])) {
						$new[] = $by[$id];
						unset($by[$id]);
					}
				}
				foreach ($by as $img) {
					$new[] = $img;
				}
				$g['images'] = $new;
				return $g;
			});
			if (!$gallery) {
				return anygal_fail(404, 'err_Not_Found');
			}
			return [200, ['ok' => true]];
	}

	return anygal_fail(400, 'Ajax_Error');
}

function anygal_handle_upload($files, $mover, $subfolder = '') {
	$added  = [];
	$errors = [];
	if (!isset($files['file']) || !is_array($files['file']) || !isset($files['file']['name'])) {
		return [$added, [anygal_t('err_Upload_Failed')]];
	}

	$subfolder = anygal_clean_file($subfolder);
	$target_dir = GSDATAUPLOADPATH;
	if ($subfolder !== '') {
		$target_dir .= $subfolder . '/';
		if (!is_dir($target_dir)) {
			@mkdir($target_dir, 0755, true);
		}
	}

	$f = $files['file'];
	foreach (['name', 'tmp_name', 'error', 'size'] as $k) {
		if (!isset($f[$k])) {
			return [$added, [anygal_t('err_Upload_Failed')]];
		}
		$f[$k] = (array)$f[$k];
	}
	$types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];

	foreach ($f['name'] as $i => $orig) {
		$orig = (string)$orig;
		$tmp  = (string)($f['tmp_name'][$i] ?? '');
		$err  = (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE);
		$size = (int)($f['size'][$i] ?? 0);

		if ($err !== UPLOAD_ERR_OK) {
			$big = ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE);
			$errors[] = $orig . ' - ' . anygal_t($big ? 'err_Upload_Size' : 'err_Upload_Failed');
			continue;
		}
		if ($size > ANYGAL_MAX_UPLOAD) {
			$errors[] = $orig . ' - ' . anygal_t('err_Upload_Size');
			continue;
		}
		$info = @getimagesize($tmp);
		if (!$info || !isset($types[$info[2]]) || (function_exists('validate_safe_file') && !validate_safe_file($tmp, $orig))) {
			$errors[] = $orig . ' - ' . anygal_t('err_Upload_Type');
			continue;
		}
		$ext  = $types[$info[2]];
		$name = pathinfo($orig, PATHINFO_FILENAME);
		if (function_exists('to7bit')) {
			$name = to7bit($name);
		}
		$name = function_exists('clean_img_name') ? clean_img_name($name) : preg_replace('/[^a-zA-Z0-9\-_]/', '', str_replace(' ', '-', $name));
		$name = trim((string)$name, '-.');
		if ($name === '') {
			$name = 'image';
		}
		$base  = $name . '.' . $ext;
		$count = 1;
		while (file_exists($target_dir . $base)) {
			$base = $count . '-' . $name . '.' . $ext;
			$count++;
		}
		$dest = $target_dir . $base;
		if (!call_user_func($mover, $tmp, $dest)) {
			$errors[] = $orig . ' - ' . anygal_t('err_Upload_Failed');
			continue;
		}
		@chmod($dest, defined('GSCHMOD') ? GSCHMOD : 0644);
		if (function_exists('exec_action')) {
			exec_action('file-uploaded');
		}

		$relative_file = ($subfolder !== '' ? $subfolder . '/' : '') . $base;
		anygal_ensure_thumb($relative_file);

		$added[] = [
			'file'  => $relative_file,
			'url'   => anygal_image_url($relative_file),
			'thumb' => anygal_thumb_url($relative_file),
			'mtime' => time(),
		];
	}
	return [$added, $errors];
}

if (isset($_GET['id'], $_GET['anygal_action']) && $_GET['id'] === ANYGAL_SLUG) {
	anygal_ajax();
}

# =====================
# ADMIN PAGE
# =====================

function anygal_admin() {
	$notice  = '';
	$error   = '';
	$view    = 'list';
	$form    = null;
	$editing = false;

	$default_wrapper = "<div class=\"anygal\">\n{{img-tpl}}\n</div>";
	$default_item    = '<a href="{{img-full}}" title="{{img-title}}"><img src="{{img-thumb}}" alt="{{img-alt}}"></a>';

	if (isset($_GET['delete'])) {
		$slug = anygal_slug(anygal_str($_GET['delete']));
		if (!check_nonce(anygal_str($_GET['nonce'] ?? ''), 'anygal_delete', 'load.php')) {
			$error = anygal_t('err_Nonce');
		} elseif ($slug !== '' && anygal_delete($slug)) {
			$notice = anygal_t('Gallery_deleted');
		}
	}

	if (isset($_POST['anygal_save'])) {
		$mode     = (anygal_str($_POST['mode'] ?? '') === 'edit') ? 'edit' : 'new';
		$slug     = anygal_slug(anygal_str($_POST['slug'] ?? ''));
		$has_data = !empty($_POST['has_data']);
		$wrapper  = anygal_tpl($_POST['wrapper'] ?? '');
		$item     = anygal_tpl($_POST['item'] ?? '');
		$existing = ($slug !== '') ? anygal_get($slug) : null;

		$view    = 'form';
		$editing = ($mode === 'edit');
		$form    = [
			'slug'     => $slug,
			'has_data' => $has_data,
			'wrapper'  => $wrapper,
			'item'     => $item,
			'images'   => $existing ? $existing['images'] : [],
		];

		if (!check_nonce(anygal_str($_POST['nonce'] ?? ''), 'anygal_save', 'load.php')) {
			$error = anygal_t('err_Nonce');
		} elseif ($slug === '') {
			$error = anygal_t('err_Slug_Required');
		} elseif ($mode === 'new' && $existing) {
			$error = anygal_t('err_Slug_Exists');
		} elseif ($mode === 'edit' && !$existing) {
			$error = anygal_t('err_Not_Found');
		} elseif (strpos($wrapper, '{{img-tpl}}') === false) {
			$error = anygal_t('err_Wrapper_Tpl');
		} elseif ($item === '') {
			$error = anygal_t('err_Item_Empty');
		} else {
			if ($mode === 'edit') {
				$data = anygal_mutate($slug, function ($g) use ($wrapper, $item, $has_data) {
					$g['wrapper']  = $wrapper;
					$g['item']     = $item;
					$g['has_data'] = $has_data;
					return $g;
				});
			} else {
				$now  = date('Y-m-d H:i:s');
				$data = [
					'slug'     => $slug,
					'has_data' => $has_data,
					'wrapper'  => $wrapper,
					'item'     => $item,
					'images'   => [],
					'created'  => $now,
					'updated'  => $now,
				];
				$data = anygal_save($slug, $data) ? $data : false;
			}
			if ($data) {
				$notice  = anygal_t('Gallery_saved');
				$editing = true;
				$form    = $data;
			} else {
				$error = anygal_t('err_Save_Failed');
			}
		}
	}

	if ($view !== 'form') {
		if (isset($_GET['add'])) {
			$view    = 'form';
			$editing = false;
			$form    = ['slug' => '', 'has_data' => false, 'wrapper' => $default_wrapper, 'item' => $default_item, 'images' => []];
		} elseif (isset($_GET['edit'])) {
			$gallery = anygal_get(anygal_str($_GET['edit']));
			if ($gallery) {
				$view    = 'form';
				$editing = true;
				$form    = $gallery;
			} else {
				$error = anygal_t('err_Not_Found');
			}
		}
	}

	anygal_admin_css();

	if ($view === 'form') {
		anygal_view_form($form, $editing, $notice, $error);
	} else {
		anygal_view_list(anygal_list(), $notice, $error);
	}

	anygal_footer();
}

function anygal_view_messages($notice, $error) {
	if ($notice !== '') {
		echo '<div class="anygal-msg anygal-msg-ok">✓ ' . $notice . '</div>';
	}
	if ($error !== '') {
		echo '<div class="anygal-msg anygal-msg-err">✕ ' . $error . '</div>';
	}
}

function anygal_view_list($galleries, $notice, $error) {
	$del_nonce = get_nonce('anygal_delete', 'load.php');
	?>
	<div class="anygal-admin">
		<div class="anygal-header">
			<h3><?= anygal_t('Icon') . anygal_t('Page_Title') ?> <br> <span class="anygal-sub"><?= anygal_t('Description') ?></span></h3>
			<a href="load.php?id=<?= ANYGAL_SLUG ?>&amp;add" class="anygal-btn">+ <?= anygal_t('New_Gallery') ?></a>
		</div>

		<?php anygal_view_messages($notice, $error); ?>

		<?php if (empty($galleries)): ?>
			<div class="anygal-empty"><p>📝 <?= anygal_t('No_galleries') ?></p></div>
		<?php else: ?>
			<table class="anygal-table">
				<thead>
					<tr>
						<th><?= anygal_t('Slug') ?></th>
						<th><?= anygal_t('Images') ?></th>
						<th><?= anygal_t('Shortcode') ?></th>
						<th><?= anygal_t('Actions') ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($galleries as $slug => $g): ?>
					<tr>
						<td><strong><?= anygal_h($slug) ?></strong></td>
						<td><?= count($g['images']) ?></td>
						<td><code>[get-anygal="<?= anygal_h($slug) ?>"]</code></td>
						<td class="anygal-actions">
							<a href="load.php?id=<?= ANYGAL_SLUG ?>&amp;edit=<?= anygal_h(urlencode($slug)) ?>"><?= anygal_t('Edit') ?></a>
							<a href="load.php?id=<?= ANYGAL_SLUG ?>&amp;delete=<?= anygal_h(urlencode($slug)) ?>&amp;nonce=<?= anygal_h($del_nonce) ?>"
							   class="delete"
							   onclick="return confirm(<?= anygal_h(json_encode(anygal_t('Are_you_sure') . ' "' . $slug . '"? ' . anygal_t('Delete_note'))) ?>)"><?= anygal_t('Delete') ?></a>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<details class="anygal-details">
			<summary><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="1.2em" height="1.2em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><path fill="#9566ff" d="M12 1.333C6.119 1.333 1.333 6.12 1.333 12S6.12 22.667 12 22.667S22.667 17.88 22.667 12S17.88 1.333 12 1.333M13 17a1 1 0 0 1-2 0v-4.333h-.667a1 1 0 0 1 0-2h1c.92 0 1.667.747 1.667 1.666zm-1-8a1.335 1.335 0 0 1-1.333-1.333c0-.735.597-1.334 1.333-1.334A1.335 1.335 0 0 1 12 9"/></svg> <?= anygal_t('Usage_Instructions') ?></summary>
			<div class="anygal-details-body">
				<p><strong><?= anygal_t('In_page') ?>:</strong></p>
				<pre>[get-anygal="<b>your-gallery-slug</b>"]</pre>
				<p><strong><?= anygal_t('In_templates') ?>:</strong></p>
				<pre>&lt;?php get_anygal('<b>your-gallery-slug</b>'); ?&gt;</pre>
				<p><strong><?= anygal_t('How_it_works') ?>:</strong></p>
				<ul>
					<li><?= anygal_t('How_1') ?></li>
					<li><?= anygal_t('How_2') ?></li>
					<li><?= anygal_t('How_3') ?></li>
					<li><?= anygal_t('How_4') ?></li>
				</ul>
			</div>
		</details>
	</div>
	<?php
}

function anygal_js_lang() {
	$map = [
		'add_images'     => 'Add_Images',
		'browse'         => 'Browse',
		'upload'         => 'Upload',
		'close'          => 'Close',
		'cancel'         => 'Cancel',
		'search'         => 'Search_images',
		'empty_folder'   => 'Empty_folder',
		'add_selected'   => 'Add_selected',
		'uploads_root'   => 'Uploads_root',
		'choose_files'   => 'Choose_files',
		'upload_hint'    => 'Upload_hint',
		'remove'         => 'Remove',
		'confirm_remove' => 'Confirm_remove',
		'move_up'        => 'Move_up',
		'move_down'      => 'Move_down',
		'drag'           => 'Drag_to_reorder',
		'data_field'     => 'Img_Data',
		'title'          => 'Img_Title',
		'desc'           => 'Img_Desc',
		'saving'         => 'Saving',
		'saved'          => 'Saved',
		'ajax_error'     => 'Ajax_Error',
		'too_large'      => 'err_Upload_Size',
	];
	$out = [];
	foreach ($map as $js => $key) {
		$out[$js] = anygal_t($key, ($js === 'data_field' ? 'Data / Link' : $key));
	}
	return $out;
}

function anygal_admin_js() {
	$file = __DIR__ . '/' . ANYGAL_SLUG . '/assets/admin.js';
	if (is_file($file)) {
		echo '<script>' . file_get_contents($file) . '</script>';
	}
}

function anygal_view_form($form, $editing, $notice, $error) {
	$action = 'load.php?id=' . ANYGAL_SLUG . ($editing ? '&amp;edit=' . anygal_h(urlencode($form['slug'])) : '&amp;add');
	$start  = ($editing && $notice === '' && $error === '') ? 'images' : 'templates';
	?>
	<div class="anygal-admin">
		<div class="anygal-header">
			<h3><?= $editing ? '<svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="1.2em" height="1.2em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><g fill="#9566ff"><path d="M22 12.698c-.002 1.47-.013 2.718-.096 3.743c-.097 1.19-.296 2.184-.74 3.009a4.2 4.2 0 0 1-.73.983c-.833.833-1.893 1.21-3.237 1.39C15.884 22 14.2 22 12.053 22h-.106c-2.148 0-3.83 0-5.144-.177c-1.343-.18-2.404-.557-3.236-1.39c-.738-.738-1.12-1.656-1.322-2.795c-.2-1.12-.236-2.512-.243-4.241Q1.999 12.737 2 12v-.054c0-2.148 0-3.83.177-5.144c.18-1.343.557-2.404 1.39-3.236s1.893-1.21 3.236-1.39c1.168-.157 2.67-.175 4.499-.177a.697.697 0 1 1 0 1.396c-1.855.002-3.234.018-4.313.163c-1.189.16-1.906.464-2.436.994S3.72 5.8 3.56 6.99C3.397 8.2 3.395 9.788 3.395 12v.784l.932-.814a2.14 2.14 0 0 1 2.922.097l3.99 3.99a1.86 1.86 0 0 0 2.385.207l.278-.195a2.79 2.79 0 0 1 3.471.209l2.633 2.37c.265-.557.423-1.288.507-2.32c.079-.972.09-2.152.091-3.63a.698.698 0 0 1 1.396 0" opacity=".5"/><path fill-rule="evenodd" d="M17.5 11c-2.121 0-3.182 0-3.841-.659S13 8.621 13 6.5s0-3.182.659-3.841S15.379 2 17.5 2s3.182 0 3.841.659S22 4.379 22 6.5s0 3.182-.659 3.841S19.621 11 17.5 11m2.212-6.712a.983.983 0 0 1 0 1.39l-.058.058a.24.24 0 0 1-.211.067a1.6 1.6 0 0 1-.81-.436a1.6 1.6 0 0 1-.436-.81a.24.24 0 0 1 .067-.211l.058-.058a.983.983 0 0 1 1.39 0M17.35 8.04a3 3 0 0 1-.296.279a1.6 1.6 0 0 1-.303.187c-.09.043-.188.076-.381.14l-1.021.34a.265.265 0 0 1-.335-.335l.34-1.02c.064-.194.097-.291.14-.382q.077-.163.187-.303c.062-.08.134-.152.279-.296l1.799-1.799c.043-.043.118-.023.138.035a1.98 1.98 0 0 0 1.217 1.217c.058.02.078.095.035.138z" clip-rule="evenodd"/></g></svg> ' . anygal_t('Edit_Gallery') : '<svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="1.2em" height="1.2em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><g fill="#9566ff"><path fill-rule="evenodd" d="M17.5 11c-2.121 0-3.182 0-3.841-.659S13 8.621 13 6.5s0-3.182.659-3.841S15.379 2 17.5 2s3.182 0 3.841.659S22 4.379 22 6.5s0 3.182-.659 3.841S19.621 11 17.5 11m.75-6.5a.75.75 0 0 0-1.5 0v1.25H15.5a.75.75 0 0 0 0 1.5h1.25V8.5a.75.75 0 0 0 1.5 0V7.25h1.25a.75.75 0 0 0 0-1.5h-1.25z" clip-rule="evenodd"/><path d="M22 12.698c-.002 1.47-.013 2.718-.096 3.743c-.097 1.19-.296 2.184-.74 3.009a4.2 4.2 0 0 1-.73.983c-.833.833-1.893 1.21-3.237 1.39C15.884 22 14.2 22 12.053 22h-.106c-2.148 0-3.83 0-5.144-.177c-1.343-.18-2.404-.557-3.236-1.39c-.738-.738-1.12-1.656-1.322-2.795c-.2-1.12-.236-2.512-.243-4.241Q1.999 12.737 2 12v-.054c0-2.148 0-3.83.177-5.144c.18-1.343.557-2.404 1.39-3.236s1.893-1.21 3.236-1.39c1.168-.157 2.67-.175 4.499-.177a.697.697 0 1 1 0 1.396c-1.855.002-3.234.018-4.313.163c-1.189.16-1.906.464-2.436.994S3.72 5.8 3.56 6.99C3.397 8.2 3.395 9.788 3.395 12v.784l.932-.814a2.14 2.14 0 0 1 2.922.097l3.99 3.99a1.86 1.86 0 0 0 2.385.207l.278-.195a2.79 2.79 0 0 1 3.471.209l2.633 2.37c.265-.557.423-1.288.507-2.32c.079-.972.09-2.152.091-3.63a.698.698 0 0 1 1.396 0" opacity=".5"/></g></svg> ' . anygal_t('New_Gallery') ?></h3>
			<a href="load.php?id=<?= ANYGAL_SLUG ?>" class="anygal-btn"><?= anygal_t('Back') ?></a>
		</div>

		<?php anygal_view_messages($notice, $error); ?>

		<?php if ($editing): ?>
		<div class="anygal-usage">
			<p><strong><?= anygal_t('In_page') ?>:</strong> <code>[get-anygal="<?= anygal_h($form['slug']) ?>"]</code></p>
			<p><strong><?= anygal_t('In_templates') ?>:</strong> <code>&lt;?php get_anygal('<?= anygal_h($form['slug']) ?>'); ?&gt;</code></p>
		</div>
		<?php endif; ?>

		<div id="anygal-root" data-start="<?= $start ?>">

			<?php if ($editing): ?>
			<div class="anygal-tabs">
				<button type="button" class="anygal-tab" data-tab="images"><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="1.5em" height="1.5em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><g fill="#9566ff"><path d="M17.29 11.968a1.33 1.33 0 0 1-1.322 1.338a1.33 1.33 0 0 1-1.323-1.338a1.33 1.33 0 0 1 1.323-1.337c.73 0 1.323.599 1.323 1.337"/><path fill-rule="evenodd" d="M18.132 7.408c-.849-.12-1.942-.12-3.305-.12H9.173c-1.363 0-2.456 0-3.305.12c-.877.125-1.608.392-2.152 1.02c-.543.628-.71 1.396-.716 2.293c-.006.866.139 1.962.319 3.328l.365 2.772c.141 1.068.255 1.933.432 2.61c.185.704.457 1.288.968 1.74c.51.453 1.12.649 1.834.74c.687.089 1.55.089 2.615.089h4.934c1.065 0 1.928 0 2.615-.088c.715-.092 1.323-.288 1.834-.74c.511-.453.783-1.037.968-1.741c.177-.677.291-1.542.432-2.61l.365-2.772c.18-1.366.325-2.462.319-3.328c-.007-.897-.172-1.665-.716-2.293s-1.275-.895-2.152-1.02M6.052 8.732c-.726.104-1.094.292-1.34.577c-.248.285-.384.679-.39 1.421c-.005.761.126 1.764.315 3.195l.05.379l.371-.272c.96-.703 2.376-.668 3.288.095l3.384 2.833c.32.268.871.318 1.269.084l.235-.139c1.125-.662 2.634-.592 3.672.19l1.832 1.38c.09-.495.171-1.104.273-1.875l.352-2.675c.189-1.43.32-2.434.314-3.195c-.005-.742-.141-1.136-.388-1.42c-.247-.286-.615-.474-1.342-.578c-.745-.106-1.745-.107-3.172-.107h-5.55c-1.427 0-2.427.001-3.172.107" clip-rule="evenodd"/><path d="M6.88 4.5c-1.252 0-2.279.84-2.621 1.954l-.02.07c.358-.12.73-.2 1.108-.253c.972-.139 2.201-.139 3.629-.139h6.202c1.428 0 2.657 0 3.63.139c.377.053.75.132 1.108.253l-.02-.07c-.343-1.114-1.37-1.954-2.62-1.954z" opacity=".7"/><path d="M8.859 2h6.282c.209 0 .37 0 .51.015a2.62 2.62 0 0 1 2.159 1.672H6.19a2.62 2.62 0 0 1 2.159-1.672c.14-.015.3-.015.51-.015" opacity=".4"/></g></svg> <?= anygal_t('Tab_Images') ?> <span class="anygal-tab-count">(<?= count($form['images']) ?>)</span></button>
				<button type="button" class="anygal-tab" data-tab="templates"><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="1.5em" height="1.5em" viewBox="0 0 24 24"><path d="M0 0h24v24H0z" fill="none"/><g fill="#9566ff"><path d="M14 2.75c1.907 0 3.262.002 4.29.14c1.005.135 1.585.389 2.008.812s.677 1.003.812 2.009c.138 1.028.14 2.382.14 4.289a.75.75 0 0 0 1.5 0v-.056c0-1.838 0-3.294-.153-4.433c-.158-1.172-.49-2.121-1.238-2.87c-.749-.748-1.698-1.08-2.87-1.238c-1.14-.153-2.595-.153-4.433-.153H14a.75.75 0 0 0 0 1.5m-4.056-1.5H10a.75.75 0 0 1 0 1.5c-1.907 0-3.261.002-4.29.14c-1.005.135-1.585.389-2.008.812S3.025 4.705 2.89 5.71c-.138 1.029-.14 2.383-.14 4.29a.75.75 0 0 1-1.5 0v-.056c0-1.838 0-3.294.153-4.433c.158-1.172.49-2.121 1.238-2.87c.749-.748 1.698-1.08 2.87-1.238c1.14-.153 2.595-.153 4.433-.153M22 13.25a.75.75 0 0 1 .75.75v.056c0 1.838 0 3.294-.153 4.433c-.158 1.172-.49 2.121-1.238 2.87c-.749.748-1.698 1.08-2.87 1.238c-1.14.153-2.595.153-4.433.153H14a.75.75 0 0 1 0-1.5c1.907 0 3.262-.002 4.29-.14c1.005-.135 1.585-.389 2.008-.812s.677-1.003.812-2.009c.138-1.027.14-2.382.14-4.289a.75.75 0 0 1 .75-.75M2.75 14a.75.75 0 0 0-1.5 0v.056c0 1.838 0 3.294.153 4.433c.158 1.172.49 2.121 1.238 2.87c.749.748 1.698 1.08 2.87 1.238c1.14.153 2.595.153 4.433.153H10a.75.75 0 0 0 0-1.5c-1.907 0-3.261-.002-4.29-.14c-1.005-.135-1.585-.389-2.008-.812s-.677-1.003-.812-2.009c-.138-1.027-.14-2.382-.14-4.289" opacity=".5"/><path d="M5.527 5.527C5 6.054 5 6.903 5 8.6c0 1.131 0 1.697.351 2.049C5.703 11 6.27 11 7.4 11h1.2c1.131 0 1.697 0 2.049-.351C11 10.297 11 9.73 11 8.6V7.4c0-1.131 0-1.697-.351-2.049C10.297 5 9.73 5 8.6 5c-1.697 0-2.546 0-3.073.527m0 12.946C5 17.946 5 17.097 5 15.4c0-1.131 0-1.697.351-2.049C5.703 13 6.27 13 7.4 13h1.2c1.131 0 1.697 0 2.049.351c.351.352.351.918.351 2.049v1.2c0 1.131 0 1.697-.351 2.048C10.297 19 9.73 19 8.6 19c-1.697 0-2.546 0-3.073-.527M13 7.4c0-1.131 0-1.697.351-2.049C13.704 5 14.27 5 15.4 5c1.697 0 2.546 0 3.073.527S19 6.903 19 8.6c0 1.131 0 1.697-.352 2.049c-.35.351-.917.351-2.048.351h-1.2c-1.131 0-1.697 0-2.049-.351C13 10.297 13 9.73 13 8.6zm.352 11.249C13 18.297 13 17.73 13 16.6v-1.2c0-1.131 0-1.697.351-2.049C13.704 13 14.27 13 15.4 13h1.2c1.131 0 1.697 0 2.048.351c.352.352.352.918.352 2.049c0 1.697 0 2.546-.527 3.073S17.097 19 15.4 19c-1.131 0-1.697 0-2.049-.352"/></g></svg> <?= anygal_t('Tab_Templates') ?></button>
			</div>

			<div class="anygal-panel" data-panel="images"<?= $start === 'images' ? '' : ' hidden' ?>>
				<div id="anygal-images" class="anygal-images"
					data-ajax="<?= anygal_h('load.php?id=' . ANYGAL_SLUG) ?>"
					data-nonce="<?= anygal_h(get_nonce('anygal_ajax', 'load.php')) ?>"
					data-gallery="<?= anygal_h($form['slug']) ?>"
					data-has-data="<?= !empty($form['has_data']) ? '1' : '0' ?>"
					data-max="<?= (int)ANYGAL_MAX_UPLOAD ?>"
					data-images="<?= anygal_h(json_encode(array_map('anygal_admin_image', $form['images']))) ?>"
					data-lang="<?= anygal_h(json_encode(anygal_js_lang())) ?>">
					<div class="anygal-img-bar">
						<button type="button" class="anygal-btn anygal-img-add">+ <?= anygal_t('Add_Images') ?></button>
					</div>
					<div class="anygal-msg anygal-msg-err anygal-img-error" hidden></div>
					<p class="anygal-empty anygal-img-empty"><?= anygal_t('No_images') ?></p>
					<div class="anygal-img-list"></div>
				</div>
			</div>
			<?php endif; ?>

			<div class="anygal-panel" data-panel="templates"<?= $start === 'templates' ? '' : ' hidden' ?>>
				<?php if (!$editing): ?>
					<p class="anygal-hint anygal-save-first">💡 <?= anygal_t('Save_first') ?></p>
				<?php endif; ?>

				<form method="post" action="<?= $action ?>" class="anygal-form">
					<input type="hidden" name="nonce" value="<?= anygal_h(get_nonce('anygal_save', 'load.php')) ?>">
					<input type="hidden" name="mode" value="<?= $editing ? 'edit' : 'new' ?>">

					<div class="anygal-group">
						<label for="anygal-slug"><?= anygal_t('Gallery_Slug') ?>:</label>
						<input type="text" id="anygal-slug" name="slug" value="<?= anygal_h($form['slug']) ?>"
							<?= $editing ? 'readonly class="anygal-readonly"' : 'required' ?>
							placeholder="<?= anygal_h(anygal_t('Slug_Placeholder')) ?>">
						<small class="anygal-hint"><?= $editing ? anygal_t('cannot_be_changed') : anygal_t('Only_letters') ?></small>
					</div>

					<div class="anygal-group anygal-group-check">
						<label for="anygal-has-data">
							<input type="checkbox" id="anygal-has-data" name="has_data" value="1" <?= !empty($form['has_data']) ? 'checked' : '' ?>>
							<?= anygal_t('Enable_Data', 'Enable image data field') ?>
						</label>
						<small class="anygal-hint"><?= anygal_t('Data_Hint', 'Displays an extra data field above the title for each image.') ?></small>
					</div>

					<div class="anygal-group">
						<label for="anygal-wrapper"><?= anygal_t('Wrapper_Template') ?>:</label>
						<textarea id="anygal-wrapper" name="wrapper" rows="6"><?= anygal_h($form['wrapper']) ?></textarea>
						<small class="anygal-hint"><?= anygal_t('Wrapper_Hint') ?></small>
					</div>

					<div class="anygal-group">
						<label for="anygal-item"><?= anygal_t('Item_Template') ?>:</label>
						<textarea id="anygal-item" name="item" rows="10"><?= anygal_h($form['item']) ?></textarea>
						<small class="anygal-hint"><?= anygal_t('Item_Hint') ?></small>

						<div class="anygal-ph">
							<strong><?= anygal_t('Placeholders') ?>:</strong>
							<ul>
								<li><code>{{img-full}}</code> - <?= anygal_t('ph_full') ?></li>
								<li><code>{{img-thumb}}</code> - <?= anygal_t('ph_thumb') ?></li>
								<li><code>{{img-data}}</code> - <?= anygal_t('ph_data', 'Extra image data field') ?></li>
								<li><code>{{img-title}}</code> - <?= anygal_t('ph_title') ?></li>
								<li><code>{{img-desc}}</code> - <?= anygal_t('ph_desc') ?></li>
								<li><code>{{img-alt}}</code> - <?= anygal_t('ph_alt') ?></li>
							</ul>
						</div>
					</div>

					<div class="anygal-group anygal-buttons">
						<button type="submit" name="anygal_save" class="anygal-btn anygal-btn-save"><?= anygal_t('Save') ?></button>
						<a href="load.php?id=<?= ANYGAL_SLUG ?>" class="anygal-btn anygal-btn-cancel"><?= anygal_t('Cancel') ?></a>
					</div>
				</form>
			</div>
		</div>
	</div>
	<?php
	anygal_admin_js();
}

function anygal_admin_css() {
	?>
	<style>
		#maincontent {width: 100% !important;}
		#sidebar {display: none !important;}

		.anygal-admin {background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,.1);}
		.anygal-admin .anygal-header {display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:15px;border-bottom:2px solid #f0f0f0;}
		.anygal-admin .anygal-header h3 {margin:0;color:#333;font-size:24px;}
		.anygal-admin .anygal-sub {color:#666;font-size:.65em;font-weight:400;}

		.anygal-admin .anygal-btn {display:inline-block;padding:10px 20px;background:#2271b1;color:#fff !important;text-decoration:none!important;border-radius:4px;border:none;cursor:pointer;font-size:14px;transition:background .2s;}
		.anygal-admin .anygal-btn:hover {background:#135e96;}
		.anygal-admin .anygal-btn-save {background:#23AD48;font-weight:bold}
		.anygal-admin .anygal-btn-save:hover {background:#008a24;}
		.anygal-admin .anygal-btn-cancel {background:#dcdcde;color:#2c3338 !important;margin-left:10px;}
		.anygal-admin .anygal-btn-cancel:hover {background:#c3c4c7;}

		.anygal-admin .anygal-msg {padding:10px 14px;margin:0 0 20px;border-radius:4px;border:1px solid;}
		.anygal-admin .anygal-msg-ok {background:#d4edda;border-color:#c3e6cb;color:#155724;}
		.anygal-admin .anygal-msg-err {background:#ffd8d8;border-color:#f5b5b5;color:#a00000;}

		.anygal-admin .anygal-form {background:#f9f9f9;padding:25px;border-radius:6px;border:1px solid #e0e0e0;}
		.anygal-admin .anygal-group {margin-bottom:20px;}
		.anygal-admin .anygal-group-check label {display:inline-flex;align-items:center;gap:8px;font-weight:600;cursor:pointer;color:#333;}
		.anygal-admin .anygal-group-check input[type="checkbox"] {width:auto !important;margin:0;cursor:pointer;}
		.anygal-admin .anygal-group label {display:block;margin-bottom:8px;font-weight:600;color:#333;font-size:14px;}
		.anygal-admin .anygal-group input[type="text"],
		.anygal-admin .anygal-group textarea {width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box;}
		.anygal-admin .anygal-group textarea {font-family:'Courier New',monospace;resize:vertical;}
		.anygal-admin .anygal-group input:focus,
		.anygal-admin .anygal-group textarea:focus {outline:none;border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;}
		.anygal-admin .anygal-readonly {background:#e9ecef;}
		.anygal-admin .anygal-hint {display:block;margin-top:5px;color:#666;font-size:12px;font-style:italic;}
		.anygal-admin .anygal-buttons {margin-bottom:0;}
		.anygal-admin .CodeMirror {border:1px solid #ddd;height:auto;min-height:150px;font-size:14px;font-family:'Consolas','Monaco','Courier New',monospace;}
		.anygal-admin .CodeMirror-scroll {min-height:150px;max-height:500px;}

		.anygal-admin code {background:#f3f3f3;padding:3px;color:royalblue;}
		.anygal-admin .anygal-usage {background:#fff;border-left:4px solid #2271b1;padding:8px 16px;margin-bottom:20px;border-radius:4px;}
		.anygal-admin .anygal-usage p {margin:8px 0;}
		.anygal-admin .anygal-ph {margin-top:12px;font-size:13px;}
		.anygal-admin .anygal-ph ul {margin:6px 0 0 20px;}

		.anygal-admin .anygal-table {width:100%;border-collapse:collapse;background:#fff;}
		.anygal-admin .anygal-table th {background:#f9f9f9;padding:12px;text-align:left;border-bottom:2px solid #ddd;font-weight:600;color:#333;}
		.anygal-admin .anygal-table td {padding:12px;border-bottom:1px solid #eee;}
		.anygal-admin .anygal-table tr:hover {background:#f9f9f9;}
		.anygal-admin .anygal-actions a {color:#fff !important;text-decoration:none !important;margin-right:10px;background-color:#FFAE34;padding:8px 12px;border-radius:5px;font-weight:600 !important;}
		.anygal-admin .anygal-actions a.delete {background-color:#FF3434;}
		.anygal-admin .anygal-empty {text-align:center;padding:40px;color:#666;font-style:italic;}

		.anygal-admin .anygal-details {margin-top:30px;border:1px solid #ddd;border-radius:4px;overflow:hidden;}
		.anygal-admin .anygal-details summary {background:#f5f5f5;padding:12px 15px;cursor:pointer;font-weight:600;font-size:13px;color:#333;}
		.anygal-admin .anygal-details summary:hover {background:#e9e9e9;}
		.anygal-admin .anygal-details-body {padding:15px 20px;background:#fff;}
		.anygal-admin .anygal-details-body pre {background:#f3f3f3;padding:12px;border-radius:4px;overflow-x:auto;font-size:13px;border:1px solid #ccc;}
		.anygal-admin .anygal-details-body ul {margin-left:20px;}

		.anygal-admin [hidden] {display:none !important;}
		.anygal-admin .anygal-save-first {margin:0 0 15px;font-style:normal;font-size:13px;}

		.anygal-admin .anygal-tabs {display:flex;gap:6px;margin-bottom:20px;border-bottom:2px solid #f0f0f0;}
		.anygal-admin .anygal-tab {background:none;border:none;border-bottom:3px solid transparent;margin-bottom:-2px;padding:10px 18px;font-size:14px;font-weight:600;color:#555;cursor:pointer;}
		.anygal-admin .anygal-tab:hover {color:#2271b1;}
		.anygal-admin .anygal-tab.active {color:#2271b1;border-bottom-color:#2271b1;}

		.anygal-admin .anygal-img-bar {margin-bottom:16px;}
		.anygal-admin .anygal-img-list {display:flex;flex-direction:column;gap:10px;}
		.anygal-admin .anygal-row {display:flex;gap:12px;align-items:flex-start;background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:10px;}
		.anygal-admin .anygal-row.dragging {opacity:.4;}
		.anygal-admin .anygal-handle {cursor:grab;font-size:20px;line-height:1;color:#999;padding:4px 2px;user-select:none;}
		.anygal-admin .anygal-handle:active {cursor:grabbing;}
		.anygal-admin .anygal-row-thumb {width:96px;height:72px;object-fit:cover;border-radius:4px;background:#e9e9e9;flex:none;}
		.anygal-admin .anygal-row-main {flex:1;min-width:0;}
		.anygal-admin .anygal-row-file {font-size:11px;color:#888;margin-bottom:5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
		.anygal-admin .anygal-row-main input[type="text"],
		.anygal-admin .anygal-row-main textarea {width:100%;box-sizing:border-box;padding:7px 9px;border:1px solid #ddd;border-radius:4px;font-size:13px;margin-bottom:5px;}
		.anygal-admin .anygal-row-status {font-size:11px;color:#2e7d32;min-height:14px;display:block;}
		.anygal-admin .anygal-row-tools {display:flex;flex-direction:column;gap:4px;}
		.anygal-admin .anygal-row-tools button {width:30px;height:28px;border:1px solid #ccc;background:#fff;border-radius:4px;cursor:pointer;font-size:12px;}
		.anygal-admin .anygal-row-tools button:hover {background:#eef4fa;border-color:#2271b1;}
		.anygal-admin .anygal-row-tools button.danger:hover {background:#ffe3e3;border-color:#FF3434;color:#c00;}

		.anygal-modal {position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100000;display:flex;align-items:center;justify-content:center;padding:20px;}
		.anygal-modal [hidden] {display:none !important;}
		.anygal-dialog {background:#fff;border-radius:8px;width:100%;max-width:920px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,.35);}
		.anygal-dlg-head {display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #eee;font-size:16px;}
		.anygal-x {background:none;border:none;font-size:26px;line-height:1;cursor:pointer;color:#666;}
		.anygal-dlg-tabs {display:flex;gap:4px;padding:0 18px;border-bottom:1px solid #eee;}
		.anygal-dlg-tab {background:none;border:none;border-bottom:3px solid transparent;padding:10px 14px;font-size:14px;font-weight:600;color:#555;cursor:pointer;}
		.anygal-dlg-tab.active {color:#2271b1;border-bottom-color:#2271b1;}
		.anygal-dlg-pane {padding:16px 18px;overflow:auto;flex:1;min-height:260px;}
		.anygal-dlg-bar {display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap;}
		.anygal-crumbs {font-size:13px;}
		.anygal-crumb {background:none;border:none;color:#2271b1;cursor:pointer;padding:2px 3px;font-size:13px;}
		.anygal-sep {color:#aaa;}
		.anygal-search {padding:7px 10px;border:1px solid #ddd;border-radius:4px;font-size:13px;min-width:200px;}
		.anygal-grid {display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;}
		.anygal-tile {position:relative;display:flex;flex-direction:column;align-items:stretch;padding:0;border:2px solid #e3e3e3;border-radius:6px;background:#fafafa;cursor:pointer;overflow:hidden;text-align:left;}
		.anygal-tile:hover {border-color:#9cc3e6;}
		.anygal-tile img {width:100%;height:90px;object-fit:cover;display:block;background:#e9e9e9;}
		.anygal-tile-name {font-size:11px;padding:4px 6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#444;}
		.anygal-tile.selected {border-color:#2271b1;box-shadow:0 0 0 2px rgba(34,113,177,.35);}
		.anygal-tick {display:none;position:absolute;top:5px;right:5px;width:22px;height:22px;line-height:22px;text-align:center;background:#2271b1;color:#fff;border-radius:50%;font-size:13px;}
		.anygal-tile.selected .anygal-tick {display:block;}
		.anygal-tile-folder {justify-content:center;align-items:center;min-height:118px;font-size:13px;gap:4px;}
		.anygal-tile-folder .anygal-folder-icon {font-size:34px;}
		.anygal-drop {display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;border:2px dashed #b5c6d6;border-radius:8px;min-height:170px;cursor:pointer;background:#f7fafd;color:#456;font-weight:600;text-align:center;padding:20px;}
		.anygal-drop.over {background:#e4f0fb;border-color:#2271b1;}
		.anygal-drop input {display:none;}
		.anygal-drop small {font-weight:400;color:#667;}
		.anygal-up-status {margin-top:14px;font-size:13px;}
		.anygal-up-line.ok {color:#2e7d32;}
		.anygal-up-line.err {color:#b00020;}
		.anygal-dlg-foot {display:flex;justify-content:flex-end;align-items:center;gap:10px;padding:12px 18px;border-top:1px solid #eee;}
		.anygal-dlg-info {margin-right:auto;font-size:13px;color:#666;}
		.anygal-dlg-info.err {color:#b00020;}
		.anygal-dialog .anygal-btn {display:inline-block;padding:9px 18px;background:#2271b1;color:#fff !important;border-radius:4px;border:none;cursor:pointer;font-size:14px;}
		.anygal-dialog .anygal-btn[disabled] {opacity:.5;cursor:not-allowed;}
		.anygal-dialog .anygal-btn-cancel {background:#dcdcde;color:#2c3338 !important;}
		.anygal-dialog .anygal-err {color:#b00020;}
	</style>
	<?php
}

function anygal_footer() {
	?>
	<style>
	.donateButton{box-shadow:inset 0px 1px 0px 0px #fce2c1;background:linear-gradient(to bottom, #ffc477 5%, #fb9e25 100%);background-color:#ffc477;border-radius:15px;border:1px solid #eeb44f;display:inline-block; cursor:pointer;color:#ffffff!important;font-family:Arial;font-size:15px;font-weight:bold;padding:5px 10px;text-decoration:none!important;text-shadow:0px 1px 0px #cc9f52}.donateButton:hover{background:linear-gradient(to bottom, #fb9e25 5%, #ffc477 100%);background-color:#fb9e25}.donateButton:active{position:relative;top:1px}
	</style>
	<footer id="paypal">
		<p style="margin:20px 0 0">Made with <span class="credit-icon">❤️</span> especially for "<b><?php global $USR; echo anygal_h($USR); ?></b>". Is this plugin useful to you?
		<a href="https://getsimple-ce.ovh/donate" target="_blank" class="donateButton"><b>Buy Us A Coffee </b><svg xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" fill-opacity="0" d="M17 14v4c0 1.66 -1.34 3 -3 3h-6c-1.66 0 -3 -1.34 -3 -3v-4Z"><animate fill="freeze" attributeName="fill-opacity" begin="0.8s" dur="0.5s" values="0;1"/></path><g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"><path stroke-dasharray="48" stroke-dashoffset="48" d="M17 9v9c0 1.66 -1.34 3 -3 3h-6c-1.66 0 -3 -1.34 -3 -3v-9Z"><animate fill="freeze" attributeName="stroke-dashoffset" dur="0.6s" values="48;0"/></path><path stroke-dasharray="14" stroke-dashoffset="14" d="M17 9h3c0.55 0 1 0.45 1 1v3c0 0.55 -0.45 1 -1 1h-3"><animate fill="freeze" attributeName="stroke-dashoffset" begin="0.6s" dur="0.2s" values="14;0"/></path><mask id="lineMdCoffeeHalfEmptyFilledLoop0"><path stroke="#fff" d="M8 0c0 2-2 2-2 4s2 2 2 4-2 2-2 4 2 2 2 4M12 0c0 2-2 2-2 4s2 2 2 4-2 2-2 4M16 0c0 2-2 2-2 4s2 2 2 4-2 2-2 4"><animateMotion calcMode="linear" dur="3s" path="M0 0v-8" repeatCount="indefinite"/></path></mask><rect width="24" height="0" y="7" fill="currentColor" mask="url(#lineMdCoffeeHalfEmptyFilledLoop0)"><animate fill="freeze" attributeName="y" begin="0.8s" dur="0.6s" values="7;2"/><animate fill="freeze" attributeName="height" begin="0.8s" dur="0.6s" values="0;5"/></rect></g></svg></a></p>
	</footer>
	<?php
}
