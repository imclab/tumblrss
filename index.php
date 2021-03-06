<?php /*

 _                   _     _              
| |_ _   _ _ __ ___ | |__ | |_ __ ___ ___ 
| __| | | | '_ ` _ \| '_ \| | '__/ __/ __|
| |_| |_| | | | | | | |_) | | |  \__ \__ \
 \__|\__,_|_| |_| |_|_.__/|_|_|  |___/___/
                                          

##################################################################

Feed feeds into Tumblr.

A simple PHP script to port your feeds into Tumblr, with the 
ability to create filters for custom feed items (by source).

Written by Greg Leuch <http://www.halvfet.com>.

*/


$tumblrss = new Tumblrss();

/* Tumblr user & feed information */
include_once('./config.inc.php');

$tumblrss->Run();

class Tumblrss {
  var $sites;
  var $cache = 'cache';

  function Tumblrss() {
    if (!is_dir('./'. $this->cache)) mkdir($this->cache, 0777);
    if (!is_dir('./'. $this->cache .'/rss')) mkdir($this->cache.'/rss', 0777);
    if (!is_dir('./'. $this->cache .'/sites')) mkdir($this->cache.'/sites', 0777);
  }

  function Run() {
    foreach ($this->sites as $this->feed_title=>$this->feed_site) {
      $this->feed_last_mod = 0;

      $posts = $this->Parse($this->feed_site['url']);
      if ($posts && sizeof($posts) > 0) {
        foreach ($posts as $post) $this->Push($post);
        $this->ResetCache();
      }
    }
  }

  function Parse($url) {
    $xml = new DOMDocument();
    $xml->load($url);

    $content = $xml->getElementsByTagName((isset($this->feed_site['type']) && $this->feed_site['type'] == 'atom') ? 'entry' : 'item');
    $posts = array();
    if (!empty($content)) {
      foreach ($content as $i=>$entry) {
        $posts[$i] = array();
        $posts[$i]['tags'] = (isset($this->feed_site['tags']) ? $this->feed_site['tags'] : '');

        if ($this->feed_site['type'] == 'atom') {
          $posts[$i]['link'] = $entry->getElementsByTagName('link')->item(0)->getAttribute('href');
          $posts[$i]['source'] = $entry->getElementsByTagName('source')->item(0)->getElementsByTagName('title')->item(0)->nodeValue;
          $posts[$i]['date'] = $entry->getElementsByTagName('published')->item(0)->nodeValue;
          $posts[$i]['title'] = $entry->getElementsByTagName('title')->item(0)->childNodes->item(0)->nodeValue;
          $posts[$i]['content'] = $entry->getElementsByTagName('content')->item(0)->nodeValue;
          $posts[$i]['summary'] = $entry->getElementsByTagName('summary')->item(0)->nodeValue;
          $tags = $entry->getElementsByTagName('category');
          foreach ($tags as $tag) $posts[$i]['tags'] .= (!empty($posts[$i]['tags']) ? ',' : '') . dash($tag->getAttribute('term'));
        } else {
          // TODO : Create parser for stanard blog RSS format.
        }
      }
    }

    return (($posts && sizeof($posts) > 0) ? $posts : false);
  }

  function Check($date) {
    $date = strtotime($date);
    // For testing/debugging purposes
    // unlink('./'. $this->cache .'/sites/'. underscore($this->feed_title));
    if (!is_file('./'. $this->cache .'/sites/'. underscore($this->feed_title))) touch('./'. $this->cache .'/sites/'. underscore($this->feed_title));
    $last_mod = file_get_contents('./'. $this->cache .'/sites/'. underscore($this->feed_title));
    if ($date > $this->feed_last_mod) $this->feed_last_mod = $date;
    return ($date > $last_mod);
  }

  function ResetCache() {
    file_put_contents('./'. $this->cache .'/sites/'. underscore($this->feed_title), $this->feed_last_mod);
  }

  function Grab($url) {
    $feed = file_get_contents($url);
    return (!empty($feed) ? $feed : false);
  }

  function Filter($post, $filter) {
    $content = ((!empty($post['content'])) ? $post['content'] : ((!empty($post['summary'])) ? $post['summary'] : false));
    if (!$content) return false;

    if ($filter['type'] == 'regular') {
      // TODO : Add post type for regular.
    } elseif ($filter['type'] == 'photo') {
      $link = ((isset($filter['link'])) ? $this->FilterMatch($content, $filter['link']) : $post['link']);
      $query = array(
        'type' => 'photo',
        'source' => $this->FilterMatch($content, $filter['photo']),
        'caption' => '<p>via <a href="'. $link .'">'. $post['source'] .'</a></p>',
        'click-through-url' => $link
      );      
    } elseif ($filter['type'] == 'quote') {
      // TODO : Add post type for quote.
    } elseif ($filter['type'] == 'link') {
      // TODO : Add post type for link.
    } elseif ($filter['type'] == 'conversation') {
      // TODO : Add post type for conversation.
    } elseif ($filter['type'] == 'video') {
      // TODO : Add post type for video.
    } elseif ($filter['type'] == 'audio') {
      // Not supported since requires a file upload. 
      // (Read: not typical for a standard content RSS feeds.)
      echo "<h3 style=\"color:#cc0000;\">WARNING: Tumblrss does not support the 'audio' post type.</h3>"
      $query = false.
    }
    return $query;
  }


  function FilterMatch($content, $filter) {
    $filters = explode(",", $filter);
    $result = '';
    $html = new DOMDocument();
    $html->loadHTML($content);

    foreach ($filters as $f) {
      $search = '/^([A-Z0-9]*)(\[[A-Z0-9\_\-]*\])?(\([0-9]*\))$/im';
      list($tag, $attr, $num) = explode(",", preg_replace($search, '\1,\2,\3,', $f));
      $attr = str_replace(array("[", "]"), "", $attr);
      $num = str_replace(array("(", ")"), "", $num);
      $html = $html->getElementsByTagName($tag)->item($item);

      if (!empty($attr)) return $html->getAttribute($attr);
    }
    return $html->nodeValue;
  }

  function Push($post) {
    if ($this->Check($post['date'])) {
      foreach($this->feed_site['filters'] as $k=>$v) {
        if (eregi($k, $post['link'])) $query = $this->Filter($post, $v);
      }
      if (!isset($query)) {
        $type = 'regular';
        if (!empty($post['content'])) {
          $content = $post['content'];
          $words = explode(' ', strip_tags($content, '<p><div><b><strong><a><span>'));
          $content = '';
          for($i=0; $i<100; $i++) $content .= $words[$i] .' ';
        } elseif (!empty($post['summary'])) {
          $content = $post['summary'];
        } else {
          return false;
        }

        $query = array(
          'type' => 'regular',
          'title' => $post['title'],
          'body' => $content .'<p>via <a href="'. $post['link'] .'">'. $post['source'] .'</a></p>'
        );
      }

      $query['email'] = $this->tumblr['user'];
      $query['password'] = $this->tumblr['pass'];
      $query['generator'] = 'Tumblrss';
      $query['tags'] = $post['tags'];
      $query['date'] = $post['date'];
      if (isset($this->tumblr['group'])) $query['group'] = $this->tumblr['group'];

      $request_data = http_build_query($query);
      $c = curl_init('http://www.tumblr.com/api/write');
      curl_setopt($c, CURLOPT_POST, true);
      curl_setopt($c, CURLOPT_POSTFIELDS, $request_data);
      curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
      $result = curl_exec($c);
      $status = curl_getinfo($c, CURLINFO_HTTP_CODE);
      curl_close($c);

      if ($status == 201) {
        echo "<p>Success! The new post ID is $result.</p>";
      } else if ($status == 403) {
        echo "<p>Bad email or password.</p>";
      } else {
        echo "<p>Error: $result\n</p>";
      }
    }
  }
};

/* Miscellaneous string functions */
function dash($str) {return str_replace(' ', '-', strtolower($str));}
function underscore($str) {return str_replace(array(' ', '-'), '_', strtolower($str));}

?>