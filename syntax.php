<?php
/**
 * DokuWiki Plugin WikipediaSnippet
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Anika Henke <anika@selfthinker.org>
 */

if (!defined('DOKU_INC')) die();
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_wikipediasnippet extends DokuWiki_Syntax_Plugin {

    function getInfo() {
        return confToHash(dirname(__FILE__).'/README');
    }

    function getType() { return 'substition'; }
    function getPType() { return 'block'; }
    function getSort() { return 192; }

    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('{{wp>[^}]+}}',$mode,'plugin_wikipediasnippet');
    }

    function handle($match, $state, $pos, & $handler) {
            return trim(substr($match,5,-2));
    }

    function render($mode, &$renderer, $data) {
        if($mode == 'xhtml') {
            if ($data) {
                global $conf;
                $wp_url = 'http://'.$conf['lang'].'.wikipedia.org/';

                $wpContent = $this->_getWPcontent($data, $wp_url);
                if ($wpContent) {
                    $renderer->doc .= $wpContent;
                } else {
                    // if all fails, build interwiki link
                    $renderer->doc .= '<a href="'.$wp_url.'wiki/'.hsc($data).'" class="interwiki iw_wp">'.hsc($data).'</a>';
                }
            }
            // if no parameter was given, just don't display anything
        }
    }

    /**
     * Get the content of the article from Wikipedia, create a useful snippet
     *   and create its container
     */
    function _getWPcontent($article, $wp_url) {
        $url = $wp_url.'w/api.php?action=parse&redirects=1&prop=text|displaytitle|revid&format=xml&page='.$article;
        $xml = simplexml_load_file($url, 'SimpleXMLElement');

        if (!$xml) return false;
        $title = $xml->parse['displaytitle'];
        $revision = $xml->parse['revid'];
        $text = $xml->parse->text;

        if (!$text) return false;
        // get the first paragraphs by deleting everything after the TOC or the first headline
        $tocPos = strpos($text, '<table id="toc"');
        $h2Pos = strpos($text, '<h2');
        if ($tocPos) $text = substr($text, 0 , $tocPos);
        else if ($h2Pos) $text = substr($text, 0 , $h2Pos);
        // all relative links should point to wikipedia
        $text = str_replace('href="/', 'href="'.$wp_url , $text);
        // cache all external images
        // TODO: fetch & cache img -> ml(imgURL)

        // TODO: remove tables
        if (!$this->getConf('includeTables')) {
        }
        // TODO: remove images
        if (!$this->getConf('includeImages')) {
        }

        $article = hsc($article);
        $permalink = $wp_url.'w/index.php?title='.$article.'&amp;oldid='.$revision;
        $normallink = $wp_url.'wiki/'.$article;

        // display snippet and container
        $wpContent  = '<dl class="wpsnip">';
        $wpContent .= '  <dt>';
        $wpContent .= '    <em>'.sprintf($this->getLang('from'), $wp_url).'</em>';
        $wpContent .= '      <a href="'.$normallink.'" class="interwiki iw_wp">'.$title.'</a>';
        $wpContent .= '      <sup><a href="'.$permalink.'" class="perm">'.$this->getLang('permalink').'</a></sup>';
        $wpContent .= '  </dt>';
        $wpContent .= '  <dd>'.$text.'<div class="wplicense">'.$this->_getWPlicense($wp_url).'</div></dd>';
        $wpContent .= '</dl>';

        return $wpContent;
    }

    /**
     * Get license under which the content is distributed
     */
    function _getWPlicense($wp_url) {
        $url = $wp_url.'w/api.php?action=query&meta=siteinfo&siprop=rightsinfo&format=xml';
        $xml = simplexml_load_file($url, 'SimpleXMLElement');

        if (!$xml) return false;
        $url = $xml->query->rightsinfo['url'];
        $text = $xml->query->rightsinfo['text'];

        return '<a href="'.$url.'">'.$text.'</a>';
    }


}//class
