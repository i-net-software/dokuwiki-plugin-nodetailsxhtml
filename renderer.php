<?php
/**
 * Render Plugin for XHTML  without details link for internal images.
 *
 * @author i-net software <tools@inetsoftware.de>
 */

if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once DOKU_INC . 'inc/parser/xhtml.php';

/**
 * The Renderer
 */
class renderer_plugin_nodetailsxhtml extends Doku_Renderer_xhtml {

    private $acronymsExchanged = null;
    private $hasSeenHeader = false;
    private $scriptmode = false;

    private $startlevel = 0; // level to start with numbered headings (default = 2)
    private $levels = array(        '======'=>1,
                                    '====='=>2,
                                    '===='=>3,
                                    '==='=>4,
                                    '=='=>5
    );

    public $sectionLevel = 0;
    public $info = array(
                                    'cache'      => true, // may the rendered result cached?
                                    'toc'        => true, // render the TOC?
                                    'forceTOC'   => false, // shall I force the TOC?
                                    'scriptmode' => false, // In scriptmode, some tags will not be encoded => '<%', '%>'
    );

    public $headingCount = array(   1=>0,
                                    2=>0,
                                    3=>0,
                                    4=>0,
                                    5=>0
    );

    /**
     * return some info
     */
    function getInfo(){
        return confToHash(dirname(__FILE__).'/plugin.info.txt');
    }

    function canRender($format) {
        return ($format=='xhtml');
    }

    function document_start() {
        global $TOC, $ID, $INFO, $conf;

        parent::document_start();

        // Cheating in again
        $meta = p_get_metadata($ID, null, false); // 2010-10-23 This should be save to use

        if (isset($meta['toc']['toptoclevel'])) {
            $conf['toptoclevel'] = $meta['toc']['toptoclevel'];
        }
        if (isset($meta['toc']['maxtoclevel'])) {
            $conf['maxtoclevel'] = $meta['toc']['maxtoclevel'];
        }
        if (isset($meta['toc']['toptoclevel'])||isset($INFO['meta']['toc']['maxtoclevel'])) {
            $conf['tocminheads'] = 1;
        }

        $newMeta = $meta['description'];
        if ( is_array($newMeta) && !empty( $newMeta['tableofcontents'] ) && count($newMeta['tableofcontents']) > 1 ) {
            // $TOC = $this->toc = $newMeta; // 2010-08-23 doubled the TOC
            $TOC = $newMeta['tableofcontents'];
        }
    }

    function document_end() {

        parent::document_end();

        // Prepare the TOC
        global $TOC, $ID;
        $meta = array();

        $forceToc = $this->info['forceTOC'] || p_get_metadata($ID, 'internal forceTOC', false);

        // NOTOC, and no forceTOC
        if ( $this->info['toc'] === false && !$forceToc ) {
            $TOC = $this->toc = array();
            $meta['internal']['toc'] = false;
            $meta['description']['tableofcontents'] = array();
            $meta['internal']['forceTOC'] = false;

        } else if ( $forceToc || ($this->utf8_strlen(strip_tags($this->doc)) >= $this->getConf('documentlengthfortoc') && count($this->toc) > 1 ) ) {
            $TOC = $this->toc;
            // This is a little bit like cheating ... but this will force the TOC into the metadata
            $meta = array();
            $meta['internal']['toc'] = true;
            $meta['internal']['forceTOC'] = $forceToc;
            $meta['description']['tableofcontents'] = $TOC;
        }

        // allways write new metadata
        p_set_metadata($ID, $meta);

        // make sure there are no empty blocks
        $this->doc = preg_replace('#<(div|section|article) class="[^"]*?level\d[^"]*?">\s*</\1>#','',$this->doc);
    }

    private function utf8_strlen( $input ) {
        if ( class_exists('dokuwiki\Utf8\PhpString') ) {
            return dokuwiki\Utf8\PhpString::strlen( $input );
        } else {
            return utf8_strlen( $input );
        }
    }

    function header($text, $level, $pos, $returnonly = false) {
        global $conf;
        global $ID;
        global $INFO;

        if($text) {

            // Check Text for hint about a CSS style class
            $class = "";
            if ( preg_match("/^class:(.*?)>(.*?)$/", $text, $matches) ) {
                $class = ' ' . $this->_xmlEntities($matches[1]);
                $text = $matches[2];
            }

            /* There should be no class for "sectioneditX" if there is no edit perm */
            $maxLevel = $conf['maxseclevel'];
            if ( $INFO['perm'] <= AUTH_READ )
            {
                $conf['maxseclevel'] = 0;
            }

            $headingNumber = '';
            $useNumbered = p_get_metadata($ID, 'usenumberedheading', true); // 2011-02-07 This should be save to use
            if ( $this->getConf('usenumberedheading') || !empty($useNumbered) || !empty($INFO['meta']['usenumberedheading']) || isset($_REQUEST['usenumberedheading'])) {

                // increment the number of the heading
                $this->headingCount[$level]++;

                // build the actual number
                for ($i=1;$i<=5;$i++) {

                    // reset the number of the subheadings
                    if ($i>$level) {
                        $this->headingCount[$i] = 0;
                    }

                    // build the number of the heading
                    $headingNumber .= $this->headingCount[$i] . '.';
                }

                $headingNumber = preg_replace("/(\.0)+\.?$/", '', $headingNumber) . ' ';
            }

            $doc = $this->doc;
            $this->doc = "";

            parent::header($headingNumber . $text, $level, $pos);

            if ( $this->getConf('useHeadAnchorInsteadOfHeaderID') ) {
                $matches = [];
                preg_match("/id=\"(.*?)\"/", $this->doc, $matches);
                if ( count($matches) > 1 ) {
                    $this->doc = preg_replace("/id=\".*?\"/", '', $this->doc);
                    $this->doc = DOKU_LF.'<a id="'. $matches[1] .'" class="head-anchor" style="visibility:hidden"></a>'.DOKU_LF . $this->doc;
                }
            }

            if ( $this->getConf('useSectionArticle') ) {
                $this->doc = $doc . preg_replace("/(<h([1-9]))/", "<".($this->sectionLevel<1?'section':'article')." class=\"level\\2{$class}\">\\1", $this->doc);
            } else {
                $this->doc = $doc . $this->doc;
            }

            $conf['maxseclevel'] = $maxLevel;

        } else if ( $INFO['perm'] > AUTH_READ ) {

            if ( $hasSeenHeader ) $this->finishSectionEdit($pos);

            // write the header
            $name = $this->startSectionEdit($pos, array( 'target' => 'section_empty', 'name' => rand() . $level));
            if ( $this->getConf('useSectionArticle') ) {
                $this->doc .= '<'.($this->sectionLevel<1?'section':'article').' class="'.$name.'">';
            }

            $this->doc .= DOKU_LF.'<a name="'. $name .'" class="' . $name . '" ></a>'.DOKU_LF;
        }

        $hasSeenHeader = true;
    }

    public function finishSectionEdit($end = null, $hid = null) {
        global $INFO;
        if ( $INFO['perm'] > AUTH_READ )
        {
            return parent::finishSectionEdit($end, $hid);
        }
    }

    public function startSectionEdit($start, $data) {
        global $INFO;
        if ( $INFO['perm'] > AUTH_READ )
        {
            return parent::startSectionEdit($start, $data);
        }

        return "";
    }

    function section_close() {
        $this->sectionLevel--;
        $this->doc .= DOKU_LF.'</div>'.DOKU_LF;
        if ( $this->getConf('useSectionArticle') ) {
            $this->doc .= '</'.($this->sectionLevel<1?'section':'article').'>'.DOKU_LF;
        }
    }

    function section_open($level) {
        $this->sectionLevel++;
        return parent::section_open($level);
    }

    /**
     * Render an internal Wiki Link
     *
     * $search,$returnonly & $linktype are not for the renderer but are used
     * elsewhere - no need to implement them in other renderers
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    function internallink($id, $name = null, $search=null,$returnonly=false,$linktype='content') {
        global $conf;
        global $ID;
        global $INFO;

        $params = '';
        $parts = explode('?', $id, 2);
        if (count($parts) === 2) {
            $id = $parts[0];
            $params = $parts[1];
        }

        // For empty $id we need to know the current $ID
        // We need this check because _simpleTitle needs
        // correct $id and resolve_pageid() use cleanID($id)
        // (some things could be lost)
        if ($id === '') {
            $id = $ID;
        }

        // default name is based on $id as given
        $default = $this->_simpleTitle($id);

        // now first resolve and clean up the $id
        if ( class_exists('dokuwiki\File\PageResolver') ) {
            $id = (new dokuwiki\File\PageResolver($ID))->resolveId($id);
            $exists = page_exists($id);
        } else {
            resolve_pageid(getNS($ID),$id,$exists);
        }

        $name = $this->_getLinkTitle($name, $default, $isImage, $id, $linktype);
        if ( !$isImage ) {
            if ( $exists ) {
                $class='wikilink1';
            } else {
                $class='wikilink2';
                $link['rel']='nofollow';
            }
        } else {
            $class='media';
        }

        //keep hash anchor
        list($id,$hash) = explode('#',$id,2);
        if(!empty($hash)) $hash = $this->_headerToLink($hash);

        //prepare for formating
        $link['target'] = $conf['target']['wiki'];
        $link['style']  = '';
        $link['pre']    = '';
        $link['suf']    = '';
        // highlight link to current page
        if ($id == $INFO['id']) {
            $link['pre']    = '<span class="curid">';
            $link['suf']    = '</span>';
        }
        $link['more']   = '';
        $link['class']  = $class;
        $link['url']    = wl($id, $params);
        $link['name']   = $name;
        $link['title']  = $this->_getLinkTitle(null, $default, $isImage, $id, $linktype);
        //add search string
        if($search){
            ($conf['userewrite']) ? $link['url'].='?' : $link['url'].='&amp;';
            if(is_array($search)){
                $search = array_map('rawurlencode',$search);
                $link['url'] .= 's[]='.join('&amp;s[]=',$search);
            }else{
                $link['url'] .= 's='.rawurlencode($search);
            }
        }

        //keep hash
        if($hash) $link['url'].='#'.$hash;

        //output formatted
        if($returnonly){
            return $this->_formatLink($link);
        }else{
            $this->doc .= $this->_formatLink($link);
        }
    }

    function locallink($hash, $name = NULL, $returnonly = false){
        global $ID;
        $name  = $this->_getLinkTitle($name, $hash, $isImage);
        $hash  = $this->_headerToLink($hash);
        $title = $name;
        $this->doc .= '<a href="#'.$hash.'" title="'.$title.'" class="wikilink1">';
        $this->doc .= $name;
        $this->doc .= '</a>';
    }

    function acronym($acronym) {

        if ( empty($this->acronymsExchanged) ) {
            $this->acronymsExchanged = $this->acronyms;
            $this->acronyms = array();

            foreach( $this->acronymsExchanged as $key => $value ) {
                $this->acronyms[str_replace('_', ' ', $key)] = $value;
            }
        }

        parent::acronym($acronym);
    }

    function entity($entity) {

        if ( array_key_exists($entity, $this->entities) ) {
            $entity = $this->entities[$entity];
        }

        $this->doc .= $this->_xmlEntities($entity);
    }

    function _xmlEntities($string) {

        // No double encode ...
        $string = htmlspecialchars($string, ENT_QUOTES, 'UTF-8', false);
        // $string = parent::_xmlEntities($string);
        $string = htmlentities($string, 8, 'UTF-8');
        $string = $this->superentities($string);

        if ( $this->info['scriptmode'] ) {
            $string = str_replace(    array( "&lt;%", "%&gt;", "&lt;?", "?&gt;"),
            array( "<%", "%>", "<?", "?>"),
            $string);
        }

        return $string;
    }

    // Unicode-proof htmlentities. 
    // Returns 'normal' chars as chars and weirdos as numeric html entites.
    function superentities( $str ){
        // get rid of existing entities else double-escape
        $str2 = '';
        $str = html_entity_decode(stripslashes($str),ENT_QUOTES,'UTF-8'); 
        $ar = preg_split('/(?<!^)(?!$)(?!\n)/u', $str );  // return array of every multi-byte character
        foreach ($ar as $c){
            $o = ord($c);
            if ( // (strlen($c) > 1) || /* multi-byte [unicode] */
                ($o > 127) // || /* <- control / latin weirdos -> */
                // ($o <32 || $o > 126) || /* <- control / latin weirdos -> */
                // ($o >33 && $o < 40) ||/* quotes + ambersand */
                // ($o >59 && $o < 63) /* html */

            ) {
                // convert to numeric entity
                $c = mb_encode_numericentity($c,array (0x0, 0xffff, 0, 0xffff), 'UTF-8');
            }
            $str2 .= $c;
        }
        return $str2;
    }

    /**
     * Renders internal and external media
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     * @param string $src       media ID
     * @param string $title     descriptive text
     * @param string $align     left|center|right
     * @param int    $width     width of media in pixel
     * @param int    $height    height of media in pixel
     * @param string $cache     cache|recache|nocache
     * @param bool   $render    should the media be embedded inline or just linked
     * @return string
     */
    function _media($src, $title = null, $align = null, $w = null,
                    $h = null, $cache = null, $render = true) {

        list($ext, $mime) = mimetype($src);
        if(substr($mime, 0, 5) == 'image') {

            $info = @getimagesize(mediaFN($src)); //get original size
            $srcset = [];
            
            if($info !== false) {
                
                $origWidth = $info[0];
                $origHeight = $info[1];
                
                if ( !$w && !$h ) $w = $info[0];  
                if(!$h) $h = round(($w * $info[1]) / $info[0]);
                if(!$w) $w = round(($h * $info[0]) / $info[1]);
                
                // There is a two times image
                if ( 2*2/3*$w <= $origWidth ) { // If the image is at least 1.6 times as large ...
                    $srcset[] = ml($src, array('w' => 2*$w, 'h' => 2*$h, 'cache' => $cache, 'rev'=>$this->_getLastMediaRevisionAt($src))) . ' 2x';
                } else {

                    // Check for alternate image
                    $ext = strrpos($src, '.');

                    foreach ( array( '@2x.', '-2x.', '_2x.') as $extension ) {
                        $additionalSrc = substr( $src, 0, $ext) . $extension . substr($src, $ext+1);
                        $additionalInfo = @getimagesize(mediaFN($additionalSrc)); //get original size
                        if ( $additionalInfo !== false ) {
                            // Image exists
                            $srcset[] = ml($additionalSrc, array('w' => 2*$w, 'h' => 2*$h, 'cache' => $cache, 'rev'=>$this->_getLastMediaRevisionAt($srcSetURL))) . ' 2x';
                            break;
                        }
                    }
                }

                $ret = parent::_media($src, $title, $align, $w, $h, $cache, $render);
                if ( count($srcset) > 0 ) {
                    return str_replace("/>", ' srcset="' . implode(',', $srcset) . '" />', $ret );
                }
                return $ret;
            }
        } elseif(media_supportedav($mime, 'video') || media_supportedav($mime, 'audio')) {
            $ret = parent::_media($src, $title, $align, $w, $h, $cache, $render);
            return str_replace("controls=\"controls\"", ' autoplay="autoplay" loop="loop" muted="muted"', $ret );
        }

        return parent::_media($src, $title, $align, $w, $h, $cache, $render);
    }

    /**
     * helperfunction to return a basic link to a media
     *
     * used in internalmedia() and externalmedia()
     *
     * @author   Pierre Spring <pierre.spring@liip.ch>
     * @param string $src       media ID
     * @param string $title     descriptive text
     * @param string $align     left|center|right
     * @param int    $width     width of media in pixel
     * @param int    $height    height of media in pixel
     * @param string $cache     cache|recache|nocache
     * @param bool   $render    should the media be embedded inline or just linked
     * @return array associative array with link config
     */
    public function _getMediaLinkConf($src, $title, $align, $width, $height, $cache, $render) {
        $link = parent::_getMediaLinkConf( $src, $title, $align, $width, $height, $cache, $render );
        // set a marker for media links, whcih we do not want to have.
        $link['nodetails'] = true;
        return $link;
    }

    /**
     * Build a link
     *
     * Assembles all parts defined in $link returns HTML for the link
     *
     * @param array $link attributes of a link
     * @return string
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     */
    public function _formatLink($link) {
        if ( $link['nodetails'] ) {
            return $link['name'];
        }
        
        return parent::_formatLink($link);
    }
}

//Setup VIM: ex: et ts=4 enc=utf-8 :
