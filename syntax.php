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

    function getType() { return 'substition'; }
    function getPType() { return 'block'; }
    function getSort() { return 192; }

    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('{{wp(?:\:[a-z-]+)?>[^}]+}}',$mode,'plugin_wikipediasnippet');
    }

    function handle($match, $state, $pos, Doku_Handler $handler) {
        $data = substr($match, 2, -2);
        list($command, $article) = explode('>', $data);

        // if no specific language was given (e.g. {{wp>Wiki}}), use configured language
        if (strpos($command, ':') === false) {
            global $conf;
            $lang = $conf['lang'];

            // correct some non-standard language codes
            $langCorrectionsFile = dirname(__FILE__).'/conf/langCorrections.conf';
            if (@file_exists($langCorrectionsFile)) {
                $langCorrections = confToHash($langCorrectionsFile);
                $lang = strtr($lang, $langCorrections);
            }

        // if different language was given (e.g. {{wp:fr>Wiki}}), use that
        } else {
            list($null, $lang) = explode(':', $command);
        }

        return trim($lang).'::'.trim($article);
    }

    function render($mode, Doku_Renderer $renderer, $data) {
        if($mode == 'xhtml') {
            if ($data) {
                list($lang, $article) = explode('::', $data);
                $wpUrl = 'http://'.$lang.'.wikipedia.org/';

                $wpContent = $this->_getWPcontent($article, $lang, $wpUrl);
                if ($wpContent) {
                    $renderer->doc .= $wpContent;
                } else {
                    // if all fails, build interwiki link
                    $renderer->doc .= '<a href="'.$wpUrl.'wiki/'.rawurlencode($article).'" class="interwiki iw_wp">'.hsc($article).'</a>';
                }
            }
            // if no parameter was given, just don't display anything
        }
    }

    /**
     * Get the content of the article from Wikipedia, create a useful snippet
     *   and create its container
     */
    function _getWPcontent($article, $articleLang, $wpUrl) {
        // config options
        $snippetLength = ($this->getConf('snippetLength')) ? '&exsentences='.$this->getConf('snippetLength') : '&exintro=';
        $useHtml = ($this->getConf('useHtml')) ? '' : '&explaintext=';
        $page = '&titles='.rawurlencode($article);

        $url = $wpUrl.'w/api.php?action=query&prop=extracts&redirects=&format=xml'.$snippetLength.$useHtml.$page;

        // fetch article data from Wikipedia
        $http = new DokuHTTPClient();
        $http->agent .= ' (DokuWiki WikipediaSnippet Plugin)';
        $data = $http->get($url);
        if(!$data) {
            msg('Error: Fetching the article from Wikipedia failed.', -1);
            if ($http->error) {
                msg($http->error, -1);
            }
            return false;
        }

        // parse XML
        $xml = simplexml_load_string($data, 'SimpleXMLElement');
        if (!$xml) {
            msg('Error: Loading the XML failed.', -1);
            foreach(libxml_get_errors() as $error) {
                msg($error->message, -1);
            }
            libxml_clear_errors();
            return false;
        }
        $title = $xml->query->pages->page['title'];
        $text = $xml->query->pages->page->extract;
        if (!$text) {
            msg('Error: Parsing the XML failed.', -1);
            $error = $xml->error;
            if ($error) {
                msg($error['code'].': '.$error['info'] ,-1);
            }
            return false;
        }

        // @todo: extracts don't seem to have any links or images; when verified, remove:
        // all relative links should point to wikipedia
        $text = str_replace('href="/', 'href="'.$wpUrl , $text);
        // make protocol relative URLs use http
        $text = str_replace('src="//', 'src="http://' , $text);
        if (!$this->getConf('useHtml')) {
            $text = '<p>'.$text.'</p>';
        }

        $articleLink = $wpUrl.'wiki/'.rawurlencode($article);
        $langParams = $this->_getLangParams($articleLang);

        // display snippet and container
        $wpContent  = '<dl class="wpsnip">'.NL;
        $wpContent .= '  <dt>'.NL;
        $wpContent .= '    <em>'.sprintf($this->getLang('from'), $wpUrl).'<span>: </span></em>';
        $wpContent .=      '<cite><strong><a href="'.$articleLink.'" class="interwiki iw_wp">'.$title.'</a></strong></cite> ';
        $wpContent .= '  </dt>'.NL;
        $wpContent .= '  <dd><blockquote '.$langParams.'>'.$text.NL.'</blockquote>'.$this->_getWPlicense($wpUrl).'</dd>'.NL;
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

    /**
     * Get lang and dir parameters to add if different from global lang and dir
     */
    function _getLangParams($articleLang) {
        global $conf;
        global $lang;

        $diffLang = '';
        $diffDir  = '';

        if ($conf['lang'] != $articleLang) {
            $diffLang = 'lang="'.$articleLang.'" xml:lang="'.$articleLang.'"';

            // check lang dir
            $lang2dirFile = dirname(__FILE__).'/conf/lang2dir.conf';
            if (@file_exists($lang2dirFile)) {
                $lang2dir = confToHash($lang2dirFile);
                $dir = strtr($articleLang, $lang2dir);
            }
            // in case lang is not listed
            if (!isset($dir) || ($dir == $articleLang)) {
                $dir = $lang['direction'];
            }

            $diffDir = ($lang['direction'] != $dir) ? 'dir="'.$dir.'"' : '';
        }

        return $diffLang.' '.$diffDir;
    }

}//class
