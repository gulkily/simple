<?php

define("HTML_ROOT", "./");
// the web root needs to be writable

define("CACHE_PATH", "./cache");
// the cache path also needs to be writable

define("ACCESS_LOG", "../logs/access.log");
// the access log needs to be readable

ini_set("default_charset", 'utf-8');
// black magic, look away, human.

define("NO_UNICODE", 1); //feature tk
// this is a test feature
// may or may not provide extra security
// will strip unicode from all articles

define("NO_FUNNY_STUFF", 0); //feature tk
// this is a test feature
// may or may not provide extra security
// will strip everything but the very basic ascii chars

function sanitize_string($string) {
// this does a semi-exclusive sanitize
// it uses either php's unicode's definition of alphanumeric or just ascii alphanumeric
// to set it to be more restrictive, use the NO_UNICODE flag (tk)

    if (NO_UNICODE) {
    // this is more secure, but the effects may be gruesome
        if (NO_FUNNY_STUFF) {
            // this will make direct speech out of anything
            $result = preg_replace("/[^a-zA-Z0-9_. ]+/", "", $string);
        } else {
            $result = preg_replace("/[^a-zA-Z0-9_ ^-_=+;:]+/", "", $string);
        }
    } else {
        $result = preg_replace("/[^[:alnum:][:space:];,!-:'\"“”‘’=+_()\?]+/u", "*", $string);
        // i am not good at regex, this is where the exploits are
    }

    return $result;
}

function get_alpha($string) {
    return preg_replace("/[^a-zA-Z0-9_. ]+/", "", $string);
}

function process_access_log($path = "../logs/access.log") {
    //$path = ACCESS_LOG;

    $vote_box = array();

    if ($path) {
        $file = fopen($path, 'r');

        if ($file) {
            $file_length = filesize($path);

            if ($file_length > 0) {

                while (($line = fgets($file)) !== false) {
                    // process the line read.

                    $bits = preg_split('/[\. \/]/' , $line);
                    // @todo replace the regex engine with something simpler

                    $ip = $bits[0];

                    $item = null;
                    $voting_entities = array();

                    foreach ($bits as $bit) {

                        if ($bit === $ip) {
                            $bit = hash_it($ip);
                        } else {
                            $bit = get_alpha($bit);
                        }

                        if (is_hash($bit) && item_exists($bit)) {
                            $item = $bit;
                        } else {
                            if (strlen($bit) >= 5) {
                                $voting_entities [] = $bit;
                            }
                        }
                    }

                    if ($item && count($voting_entities)) {
                        foreach ($voting_entities as $voter) {
                            $vote_box[$item][$voter] = 1;
                        }
                    }
                }
            }

            fclose($file);
        }
    }

//    print_r($vote_box);

    if (count($vote_box)) {
        enter_votes($vote_box);
    }
}

process_access_log();

function enter_votes($vote_box) {
    $envelope_name = 'envelope/' . time();

    put_cache($envelope_name . '.prelim', $vote_box);

    //print_r($vote_box);
    foreach ($vote_box as $item => $votes) {
        if (count($votes) > 0) {
            $vote_count = intval(count($votes));

            if ($vote_count) {
                if (is_hash($item) && item_exists($item)) {
                    $item = get_item($item);
                    if (isset($item['score'])) {
                        $score = intval($item['votes']);
                    } else {
                        $score = 0;
                    }

                    $item['score'] = $score + $vote_count;

                    save_item($item);
                }
            }
        }
    }

}

function get_cache_filename($cache_name) {
    return CACHE_PATH . (substr($cache_name,0,1)=='/'?'':'/') . $cache_name;
}

function get_html_filename($file_name) {
    return HTML_ROOT . (substr($file_name,0,1) == '/' ? '' : '/') . $file_name;
}

function write_file($file_name, $file_content) {
    $filename = get_html_filename($file_name);

    $tmp = getmypid().'.tmp';
    $file = @fopen($filename.$tmp, 'w');

    if ($file) {
        fwrite($file, $file_content);
        fclose($file);
        rename($filename.$tmp, $filename);
    }
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
            $file_contents = fread($file, $file_length);
            $results_array = unserialize($file_contents);
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

    //print_r($items);

    exit;

    if ($items) {

        foreach ($items as $item_cache_path) {
            if (isset($item['timestamp']) && isset($item['sha1'])) {
                $item = read_cache($item_cache_path);

                $items_date[$item['timestamp'] . $item['sha1']] = $item;

                save_item($item);
            }
        }

        ksort($items_date);

        while (count($items_date) > 100) {
            array_pop($items_date);
        }

        put_cache('index/items_date', $items_date);
    }
}

function hash_it($string) {
    return sha1($string);
}

function is_hash($string) {
    return (bool) preg_match('/^[0-9a-f]{40}$/i', $string);
}

function save_item($item) {
    $item['text'] = (string) $item['text'];
    if (!isset($item['text'])) {
        $item['text'] = sanitize_string('Hello world! No text was passed when creating this item, so this text has been substituted.');
    } else {
        $item['text'] = sanitize_string($item['text']);
    }

    $item_hash = hash_it($item['text']);

    if (isset($params['item_source'])) {
        $item_source = hash_it($params['item_source']);
    } else {
        $item_source = hash_it("localhost");
    }

    $item_lines = explode("\n", trim($item['text']));
    if (count($item_lines) >= 1) {
        $item['title'] = trim($item_lines[0]);
    } else {
        trigger_error("Warning: Tried to save an item, but could  not generate title");
        $item['title'] = sanitize_string("(no title could be generated)");
    }

    if (strlen($item['title']) > 255) {
        $item['title'] = substr($item['title'], 0, 250) . "[...]";
    }

    //$item['text'] = $item['text'];
    $item['source'] = $item_source;
    $item['sha1'] = $item_hash;
    $item['title'] = $item['title'];

    if (isset($item['parent_hash']) && is_hash($item['parent_hash'])) {
        $item['parent_hash'] = $item['parent_hash'];
    } else {
        unset($item['hash']);
    }

    print_r($item);

    put_cache('item/' . $item_hash,  $item);

    return $item_hash;
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
    return htmlspecialchars(trim($string));
    //return $string;
}

function template_header($title, $meta = array()) {

    $links = array(
        './' => 'Home',
        './?action=edit' => 'Submit',
        './?action=about' => 'About',
    );

    $html = "";

    $html .= '<html><head><title>';
    $html .= html_escape(sanitize_string($title));
    $html .= '</title>';

    if (count($meta)) {
        foreach ($meta as $httpequiv => $content) {
            $html .= '<meta http-equiv="'.$httpequiv.'" content="'.$content.'">';
        }
    }

    $html .= '</head><body>';
    $html .= '<h1>' . html_escape(sanitize_string($title)) . '</h1>';

    $html .= '<hr color="black" size="4">';

    $html .= '<strong>';
    $comma = 0;
    foreach ($links as $url => $text) {
        if ($comma) $html .= ' | '; else $comma = 1;
        $html .= '<a href="'.$url.'">'.$text.'</a>';
    }
    $html .= '</strong>';
    $html .= '<hr color="black" size="4">';

    return $html;
}

function template_footer() {
    $html = '';

    $html .= '</body></html>';

    return $html;
}

function template_submit_form($item = null) {
    $html = '';
    
    $html .= '<form action="./" method="post">';
    $html .= '<p><textarea name="text" cols="80" rows="24" id="text" tabindex="1">';
    if ($item) {
        $html .= trim(html_escape($item['text']));
    }
    $html .= '</textarea></p>';
    if ($item) {
        $html .= '<input type=hidden name=parent_hash value='.$item['sha1'].'>';
    }
    $html .= '<p><input type="submit" value="Submit" tabindex="2"></p>';
    $html .= '</form>';

    return $html;
}

function template_item($item) {
    $html = '';
    
    $html .= '<p>';
    $html .= trim(nl2br(html_escape(sanitize_string($item['text']))));
    $html .= '</p>';
    $html .= '<p>';
    $html .= '<a href="' . $item['sha1'] . '.html">' . $item['sha1'] . '</a>';
    $html .= '</p>';

    return $html;
}

////////////////////////////////////
// the fun begins here
///////////////////////////////////

build_items_index();

$items = get_items();

$html_index = template_header('index');

foreach ($items as $item) {
    // do an item page
    $html  = template_header($item['sha1']); //@todo change this to title later
    $html .= template_item($item);
    $html .= template_footer();

    write_file($item['sha1'] . '.html', $html);

    /////////////

    // add to the index buffer
    $html_index .= template_item($item);
    $html_index .= '<hr color="black" size="1">';
}

$html_index .= template_footer("<p>To submit a text use GET /?text=.</p>");
$html_index .= template_footer("<p>To add your node, use GET /?addnode=youraddress</p>");
$html_index .= template_footer("<p>To vote, share the link.</p>");
//$html_index .= template_footer("<!-- To contribute, try one of these: -->");

write_file('index.html', $html_index);

$json_items = json_encode($items);

write_file('items.json', $json_items);

exit;

/*
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
} elseif ($action === 'edit') {
    template_header('Add new item');

    $item_hash = $_GET['item'];
    if (is_hash($item_hash) && item_exists($item_hash)) {
        $item = get_item($item_hash);
        template_submit_form($item);
    } else {
        template_submit_form();
    }

    template_footer();
} else {
    build_items_index();
    //@todo this should not be called on every pageload

    $items = get_items();

    if ($_SERVER['SERVER_NAME']) {
        $page_title = htmlspecialchars(sanitize_string($_SERVER['SERVER_NAME']));
    } else {
        $page_title = 'index';
    }

    template_header($page_title);

    foreach ($items as $item) {
        template_item($item);
        echo('<hr color="black" size="1">');
    }

    template_footer();
}

*/