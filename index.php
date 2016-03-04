<?php

define("CACHE_PATH", "./cache");

header("Content-Type: text/html; charset=utf-8");
ini_set("default_charset", 'utf-8');

if ($_SERVER['REMOTE_ADDR'] == '::1' || $_SERVER['REMOTE_ADDR'] == '127.0.0.1') {
    $admin_mode = 1;
} else {
    $admin_mode = 0;
}

function sanitize_string($string) {
    $result = preg_replace("/[^[:alnum:][:space:];,!-:'\"]+/u", "x", $string);

    return $result;
}

function get_cache_filename($cache_name) {
    return CACHE_PATH . (substr($cache_name,0,1)=='/'?'':'/') . $cache_name;
}

function put_cache($cache_name, $object, $nest_level = 0) {
    if ($nest_level > 10) {
        trigger_error('put_cache() went more than 10 levels deep.', E_USER_ERROR);
    }

    $filename = get_cache_filename($cache_name);

    if (is_dir($filename)) {
        trigger_error('put_cache() we have a problem, the file we are trying to create is already a directory', E_USER_ERROR);
        // @todo
    }

    $tmp = getmypid().'.tmp';

    $object_s = serialize($object);
    $file = @fopen($filename.$tmp, 'w');

    if (!$file) {
        // if we don't have a handle, we probably need to create some directories
        $path = $filename;
        while (!$file && $path != '') {
            $path = explode('/', $path);
            array_pop($path);
            $path = implode('/', $path);

            // clobber any files while creating directories
            if (file_exists($path)) {
                unlink($path);
            }

            mkdir($path, 0777);
            $file = @fopen($filename.$tmp, 'w');
        }

        if (!$file) {
            // now that we have a directory and a file handle, try again
            put_cache($cache_name, $object, $nest_level+1);
        }
    }

    if ($file) {
        fwrite($file, $object_s);
        fclose($file);
        rename($filename.$tmp, $filename);
    }
}

function get_cache($cache_name) {
    if ($cache_name) {
        $filename = get_cache_filename($cache_name);

        return read_cache($filename);
    } else {
        trigger_error('get_cache() $cache_name should never be false here.', E_USER_ERROR);

        return null;
    }
}

function read_cache($filename) {
    if (file_exists($filename)) {
        $file_length = filesize($filename);
        $file = fopen($filename, 'r');

        if ($file_length > 0) {
            $results_array = unserialize(fread($file, $file_length));
            fclose($file);

            $results_array['timestamp'] = filemtime($filename);

            return $results_array;
        } else {
            return array();
        }
    } else {
        return null;
    }
}

function build_items_index() {
    $items = glob(get_cache_filename('item/*'));

    foreach ($items as $item_cache_path) {
        $item = read_cache($item_cache_path);

        $items_date[$item['timestamp'] . $item['sha1']] = $item;
    }

    ksort($items_date);

    while (count($items_date) > 100) {
        array_pop($items_date);
    }

    put_cache('index/items_date', $items_date);
}

function hash_it($string) {
    return sha1($string);
}

function is_hash($string) {
    return (bool) preg_match('/^[0-9a-f]{40}$/i', $string);
}

function save_item($item_text, $item_source = "localhost") {
    $item_hash = hash_it($item_text);

    if (get_cache('item/' . $item_hash)) {
        return $item_hash;
    } else {
        $item_source = hash_it($item_source);

        $item['text'] = $item_text;
        $item['source'] = $item_source;
        $item['sha1'] = $item_hash;

        put_cache('item/' . $item_hash,  $item);

        return $item_hash;
    }
}

function delete_item($item_hash) {
    if (is_hash($item_hash)) {
        $item = get_item($item_hash);

        if (count($item)) {
            $cache_filename = get_cache_filename('item/' . $item_hash);

            unlink($cache_filename);
        }
    }
}

function get_item($item_hash) {
    if (!is_hash($item_hash)) {
        trigger_error('get_item() called with an invalid hash', E_USER_ERROR);

        return null;
    } else {
        $item = get_cache('item/' . $item_hash);

        return $item;
    }
}

function item_exists($item_hash) {
    if (!is_hash($item_hash)) {
        return false;
    } else {
        if (file_exists(get_cache_filename('item/' . $item_hash))) {
            return true;
        } else {
            return false;
        }
    }
}

function get_items() {
    $items = get_cache('index/items_date');

    return $items;
}

function html_escape($string) {
    //return htmlspecialchars(trim($string));
    return $string;
}

function template_header($title, $meta = array()) {
    echo('<html><head><title>');
    echo(html_escape($title));
    echo('</title>');
    if (count($meta)) {
        foreach ($meta as $httpequiv => $content) {
            echo('<meta http-equiv="'.$httpequiv.'" content="'.$content.'">');
        }
    }
    echo('</head><body>');
    echo('<h1>' . html_escape($title) . '</h1>');
    echo('<a href="./">Home</a>');
}

function template_footer() {
    echo('</body></html>');
}

function template_submit_form() {
    echo('<form action="./" method="post">');
    echo('<p><textarea name="text" cols="80" rows="5" id="text" tabindex="1"></textarea></p>');
    echo('<p><input type="submit" value="Submit" tabindex="2"></p>');
    echo('</form>');
}

function template_item($item) {
    echo('<p>');
    echo(trim(nl2br(html_escape($item['text']))));
    echo('</p>');
    echo('<p>');
    echo('<a href="./?action=item&amp;item=' . $item['sha1'] . '">' . $item['sha1'] . '</a>');
    echo('</p>');
}

if (isset($_POST) && count($_POST)) {
    if ($_POST['text']) {
        $text = $_POST['text'];

        $url_regex = '/((https:\/\/|ftp:\/\/|ftp\.|http:\/\/|www\.)[a-zA-Z0-9\.\/\?=%&\-,;~_#:@\'()\[\]`*\|\+\^\{\}]+[a-zA-Z0-9\/#])/';
        $url_parse_regex = '/^((http[s]?|ftp):\/)?\/?([^:\/\s]+)((\/\w+)*\/)([\w\-\.]+[^#?\s]+)(.*)?(#[\w\-]+)?$/';

        preg_match_all($url_regex, $text, $url_matches);
        if (count($url_matches[0])) {
            $action = 'index';
        } else {
            $text = sanitize_string($text);

            $item_hash = save_item($text);
            $action = 'item_new';
        }
    }
}

if (!isset($action) && isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'item':
        case 'feed':
            $action = $_GET['action'];
            break;
        default:
            unset($action);
    }
}

if (!isset($action)) {
    $action = 'index';
}

if ($action === 'item') {
    $item_hash = $_GET['item'];

    if (is_hash($item_hash)) {
        $item = get_item($item_hash);

        if ($item) {
            template_header($item_hash);
            template_item($item);
            template_footer();
        }
    }
} elseif ($action === 'item_new') {
    if (isset($item_hash) && is_hash($item_hash)) {
        $new_item_url = './?action=item&item=' . $item_hash;
        $link = '<a href="' . $new_item_url . '">' . $item_hash . '</a>';

        $meta = array('REFRESH' => '0;URL='.$new_item_url);

        template_header('Item created! Redirecting you to ' . $item_hash, $meta);
	    echo('<p>');
        echo($link);
	    echo('</p>');
        template_footer();
    }
} elseif ($action === 'feed') {
    $items = get_items();

    header('Content-Type: application/json');

    $client = $_SERVER['REMOTE_ADDR'];
    $client_hash = hash_it($client);

    put_cache('node/' . $client_hash, array($client));

    echo(json_encode($items));
} else {
    build_items_index();
    //@todo this should not be called on every pageload

    $items = get_items();

    template_header('index');

    foreach ($items as $item) {
        template_item($item);
        echo('<hr>');
    }

    template_submit_form();
    template_footer();
}
