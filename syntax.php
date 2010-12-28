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
        return confToHash(dirname(__FILE__).'/plugin.info.txt');
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
                $wpUrl = 'http://'.$conf['lang'].'.wikipedia.org/';

                $wpContent = $this->_getWPcontent($data, $wpUrl);
                if ($wpContent) {
                    $renderer->doc .= $wpContent;
                } else {
                    // if all fails, build interwiki link
                    $renderer->doc .= '<a href="'.$wpUrl.'wiki/'.rawurlencode($data).'" class="interwiki iw_wp">'.hsc($data).'</a>';
                }
            }
            // if no parameter was given, just don't display anything
        }
    }

    /**
     * Get the content of the article from Wikipedia, create a useful snippet
     *   and create its container
     */
    function _getWPcontent($article, $wpUrl) {
        $url = $wpUrl.'w/api.php?action=parse&redirects=1&prop=text|displaytitle|revid&format=xml&page='.rawurlencode($article);

        // fetch article data from Wikipedia
        $http = new DokuHTTPClient();
        $http->agent .= ' (DokuWiki WikipediaSnippet Plugin)';
        $data = $http->get($url);
        if(!$data) return false;

        // parse XML
        $xml = simplexml_load_string($data, 'SimpleXMLElement');
        if (!$xml) return false;
        $title = $xml->parse['displaytitle'];
        $revision = $xml->parse['revid'];
        $text = $xml->parse->text;
        if (!$text) return false;

        // all relative links should point to wikipedia
        $text = str_replace('href="/', 'href="'.$wpUrl , $text);

        require_once('simplehtmldom/simple_html_dom.php');
        $html = new simple_html_dom();
        $html->load($text);

        // get only the first paragraphs by deleting everything after the TOC and/or the first headline
        $htmlFromToc = $html->find('#toc',0);
        while($htmlFromToc && $htmlFromToc->nextSibling()) {
            $htmlFromToc->outertext = "";
            $htmlFromToc = $htmlFromToc->nextSibling();
        }
        $htmlFromH2 = $html->find('h2',0);
        if ($htmlFromH2 && $htmlFromH2->parentNode()->id == 'toctitle') {
            $htmlFromH2 = $html->find('h2',1);
        }
        while($htmlFromH2 && $htmlFromH2->nextSibling()) {
            $htmlFromH2->outertext = "";
            $htmlFromH2 = $htmlFromH2->nextSibling();
        }

        // cache all external images
        $images = $html->find('img');
        foreach ($images as $img) {
            $imgSrc = $img->src;
            $img->src = ml($imgSrc);
        }

        // remove all undesired elements
        $remTables = (!$this->getConf('includeTables')) ? ', table' : '';
        $remImages = (!$this->getConf('includeImages')) ? ', a.image, div.thumb' : '';
        $remove = $html->find('script, .noprint, .editsection, .dablink, sup.reference'.$remTables.$remImages);
        foreach ($remove as $rem) {
            $rem->outertext = "";
        }

        $text = $html;

        $permalink = $wpUrl.'w/index.php?title='.rawurlencode($article).'&amp;oldid='.$revision;
        $normallink = $wpUrl.'wiki/'.rawurlencode($article);

        // display snippet and container
        $wpContent  = '<dl class="wpsnip">'.NL;
        $wpContent .= '  <dt>'.NL;
        $wpContent .= '    <em>'.sprintf($this->getLang('from'), $wpUrl).'<span>: </span></em>';
        $wpContent .=      '<cite><strong><a href="'.$normallink.'" class="interwiki iw_wp">'.$title.'</a></strong></cite> ';
        $wpContent .=      '<sup><a href="'.$permalink.'" class="perm">'.$this->getLang('permalink').'</a></sup>'.NL;
        $wpContent .= '  </dt>'.NL;
        $wpContent .= '  <dd><blockquote>'.$text.NL.'</blockquote>'.$this->_getWPlicense($wpUrl).'</dd>'.NL;
        $wpContent .= '</dl>'.NL;

        return $wpContent;
    }

    /**
     * Get license under which the content is distributed
     */
    function _getWPlicense($wpUrl) {
        $url = $wpUrl.'w/api.php?action=query&meta=siteinfo&siprop=rightsinfo&format=xml';

        // fetch license data from Wikipedia
        $http = new DokuHTTPClient();
        $http->agent .= ' (DokuWiki WikipediaSnippet Plugin)';
        $data = $http->get($url);
        if(!$data) return false;
        $xml = simplexml_load_string($data, 'SimpleXMLElement');

        if (!$xml) return false;
        $url = $xml->query->rightsinfo['url'];
        $text = $xml->query->rightsinfo['text'];

        return '<div class="wplicense"><a href="'.$url.'">'.$text.'</a></div>';
    }


}//class
