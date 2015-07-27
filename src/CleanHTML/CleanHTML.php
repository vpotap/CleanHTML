<?php namespace timgws\CleanHTML;

use DOMDocument, DOMXPath;
use HTMLPurifier_Config, HTMLPurifier;

/**
 * Clean HTML pages, get rid of unnecessary tags.
 *
 * Class CleanHTML
 * @package timgws\CleanHTML
 */
class CleanHTML {
    /**
     * @var string Tags that will always be allowed by default
     */
    private $defaultAllowedTags = 'h1,h2,h3,h4,h5,p,strong,b,ul,ol,li,hr,pre,code';

    /**
     * @var array list of all the options that will by default be initialised with.
     */
    private $defaultOptions = array (
        'images' => false,
        'italics' => false,
        'links' => false,
        'strip' => false,
        'table' => false,
    );

    /**
     * @var string blank HTML with UTF-8 encoding.
     */
    private static $blankHTML = '<!DOCTYPE html><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html; charset=utf-8">';

    /**
     * @var array When an option is set, add this to the default allowed list.
     */
    private $optionsAdd = array (
        'images' => ',img[src|alt]',
        'links' => ',a[href|target]',
        'italics' => ',em,i',
        'table' => ',table,tr,td'
    );

    /**
     * @var array the local copy of the options that have been set by the developer using this class
     */
    private $options;

    /**
     * @param array|null $options
     * @throws CleanHTMLException
     */
    public function __construct(array $options = null)
    {
        $this->options = $this->defaultOptions;

        if (is_array($options)) {
            $this->setOptions($options);
        }
    }

    /**
     * Set a list of options on the class.
     *
     * @param $settingOptions
     * @throws CleanHTMLException
     */
    public function setOptions($settingOptions)
    {
        $defaultKeys = array_keys($this->defaultOptions);
        $settingKeys = array_keys($settingOptions);

        foreach($settingKeys as $_option)
        {
            if (!in_array($_option, $defaultKeys))
                throw new CleanHTMLException("$_option does not exist as a settable option.");

            $this->options[$_option] = $settingOptions[$_option];
        }
    }

    /**
     * Get the options set on the class.
     *
     * This is used by tests to ensure that setting options works as expected.
     *
     * @return array the options that are on this class.
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Build the allowed tags string, based on the default allowed tags and options that are set.
     *
     * @return string
     */
    private function getAllowedTags()
    {
        $allowedTags = $this->defaultAllowedTags;

        foreach ($this->options as $name => $value) {
            if (isset($this->optionsAdd[$name]) && $value === true) {
                $allowedTags .= ',' . $this->optionsAdd[$name];
            }
        }

        if ($this->options['strip'] === true)
            $allowedTags = '';

        return $allowedTags;
    }

    /**
     * Create a DOMDocument from a HTML string.
     *
     * @param $html
     * @return DOMDocument
     */
    private function preCleanHTML($html)
    {
        // 0: remove duplicate spaces
        $no_spaces = preg_replace('@(\s|&nbsp;){2,}@', ' ', $html);
        $no_spaces = preg_replace("/<(\w*)>(\s|&nbsp;)/", '<\1>', $no_spaces);

        // Try and replace excel new lines as paragraphs :)
        $no_spaces = preg_replace("|(\s*)?<br />(\s*)?<br />|", "<p>", $no_spaces);

        $content = self::$blankHTML;
        $content .= preg_replace("/<(\w*)[^>]*>[\s|&nbsp;]*<\/\\1>/", '', $no_spaces);
        unset($no_spaces);

        return $content;
    }

    private function createDOMDocumentFromHTML($html, $firstRun = true)
    {
        if ($firstRun)
            $content = $this->preCleanHTML($html);

        $doc = new DOMDocument;
        @$doc->loadHTML($content);
        $doc->encoding = 'UTF-8';

        return $doc;
    }

    private function createHTMLPurifier()
    {
        $allowedTags = $this->getAllowedTags();

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.EscapeNonASCIICharacters', false);
        $config->set('CSS.AllowedProperties', array());
        $config->set('Core.Encoding', 'utf-8');
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('AutoFormat.RemoveEmpty.RemoveNbsp', true);
        $config->set('HTML.Allowed', $allowedTags);
        $purifier = new HTMLPurifier($config);

        return $purifier;
    }

    /**
     * Clean HTML.
     *
     * @param $html
     * @return mixed|string
     */
    function clean($html) {
        $cleaningFunctions = new Methods();
        $doc = $this->createDOMDocumentFromHTML($html);

        // 1: remove any of the script tags.
        $doc = $cleaningFunctions->removeScriptTags($doc);

        // 2: First clean of all the obscure tags...
        $output = self::obscureClean($doc, true);

        // 3: Send the tidy html to htmlpurifier
        $purifier = $this->createHTMLPurifier();
        $output = $purifier->purify($output);

        // 4: Cool, do one more clean to pick up any p/strong etc tags that might have
        // been missed.
        $doc = new DOMDocument;
        $content = self::$blankHTML . $output;
        @$doc->loadHTML($content);
        $doc->encoding = 'UTF-8';

        $output = self::obscureClean($doc);

        // Remove the newline character at the end of the HTML if there is one there.
        $len = strlen($output);
        if (substr($output, $len-1, 1) == "\n")
            $output = substr($output, 0, $len-1);

        return $output;
    }

    static function changeQuotes($input) {
        $quotes = array(
                "\xC2\xAB"     => '"', // « (U+00AB) in UTF-8
                "\xC2\xBB"     => '"', // » (U+00BB) in UTF-8
                "\xE2\x80\x98" => "'", // ‘ (U+2018) in UTF-8
                "\xE2\x80\x99" => "'", // ’ (U+2019) in UTF-8
                "\xE2\x80\x9A" => "'", // ‚ (U+201A) in UTF-8
                "\xE2\x80\x9B" => "'", // ‛ (U+201B) in UTF-8
                "\xE2\x80\x9C" => '"', // “ (U+201C) in UTF-8
                "\xE2\x80\x9D" => '"', // ” (U+201D) in UTF-8
                "\xE2\x80\x9E" => '"', // „ (U+201E) in UTF-8
                "\xE2\x80\x9F" => '"', // ‟ (U+201F) in UTF-8
                "\xE2\x80\xB9" => "'", // ‹ (U+2039) in UTF-8
                "\xE2\x80\xBA" => "'", // › (U+203A) in UTF-8
            );

        $output = strtr($input, $quotes);
        return $output;
    }

    /**
     * Custom functions for cleaning out elements that are inside documents that should not be allowed in.
     * @param DOMDocument $doc
     * @param bool $first
     * @return mixed|string
     */
    static function obscureClean(DOMDocument $doc, $first = false)
    {
        $cleaningFunctions = new Methods();
        $doc->encoding = 'UTF-8';

        // 1: rename h1 tags to h2 tags
        $doc = $cleaningFunctions->renameH1TagsToH2($doc);

        // 2: change short <p><strong> pairs to h2 tags
        $doc = $cleaningFunctions->changeShortBoldToH2($doc);

        // Get rid of these annoying <h2><strong> tags that get generated after
        // adding new headers. I tried to share code w/ above p/strong, didn't work
        $doc = $cleaningFunctions->removeBoldH2Tags($doc);

        // 3: remove obscure span stylings
        $doc = $cleaningFunctions->removeObscureSpanStylings($doc);

        // 4: remove obscure paragraphs inside line items (google docs)
        // NOTE: Might break. TODO: Fix me.
        $doc = $cleaningFunctions->removeObscureParagraphsInsideLineItems($doc, $first);

        return self::exportHTML($doc);
    }

    static function exportHTML(DOMDocument $doc) {
        // -- Save the contents. Strip out the added tags from loadHTML()
        $xp = new DOMXPath($doc);
        $doc->encoding = 'UTF-8';
        $everything = $xp->query("body/*"); // retrieves all elements inside body tag
        $output = '';
        if ($everything->length > 0) { // check if it retrieved anything in there
            foreach ($everything as $thing) {
                $output .= $doc->saveXML($thing) . "\n";
            }
        }

        $output = str_replace("\xc2\xa0",' ',$output); // Nasty UTF-8 &nbsp;
        $output = preg_replace("#(\n\s*){2,}#", "\n", $output); // Replace newlines with one
        $output = preg_replace("#\s\s+$#", "", $output); // Multi-spaces condensed
        return $output;
    }

    // The following functions are borrowed from WordPress... Thanks guys!
    /**
     * Clean up <pre> tags before running autop.
     * @param $pee
     * @return string
     */
    static private function cleanBeforeAutoP($pee)
    {
        if ( trim($pee) === '' )
            return '';

        $pee = $pee . "\n"; // just to make things a little easier, pad the end

        if ( strpos($pee, '<pre') !== false ) {
            $pee_parts = explode( '</pre>', $pee );
            $last_pee = array_pop($pee_parts);

            $pee = self::cleanPeeParts($pee_parts) . $last_pee;
        }

        return $pee;
    }

    static function cleanPeeParts($pee_parts)
    {
        $iteration = 0;

        $pee = '';
        foreach ( $pee_parts as $pee_part ) {
            $start = strpos($pee_part, '<pre');

            // Malformed html?
            if ( $start === false ) {
                $pee .= $pee_part;
                continue;
            }

            $name = "<pre wp-pre-tag-$iteration></pre>";
            $pre_tags[$name] = substr( $pee_part, $start ) . '</pre>';

            $pee .= substr( $pee_part, 0, $start ) . $name;
            $iteration++;
        }

        return $pee;
    }

    /**
     * Return a list of all the blocks that we are going to add a new line after
     *
     * @return string
     */
    static function allBlocks()
    {
        return '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|select|option|form|map|area|blockquote|address|math|style|p|h[1-6]|hr|fieldset|noscript|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary)';
    }

    /**
     * Add new lines after a number of different blocks
     *
     * This will ensure that the HTML we output isn't in just one long line.
     * We will also get rid of multiple new lines, as well.
     *
     * @see self::autop
     * @param string $pee the input from autop
     * @return string
     *
     */
    static private function spaceOutBlocks($pee)
    {
        $pee = preg_replace('|<br />\s*<br />|', "\n\n", $pee);
        // Space things out a little
        $allblocks = self::allBlocks();
        $pee = preg_replace('!(<' . $allblocks . '[^>]*>)!', "\n$1", $pee);
        $pee = preg_replace('!(</' . $allblocks . '>)!', "$1\n\n", $pee);
        $pee = str_replace(array("\r\n", "\r"), "\n", $pee); // cross-platform newlines
        if ( strpos($pee, '<object') !== false ) {
            $pee = self::cleanUpObjectTag($pee);
        }
        $pee = preg_replace("/\n\n+/", "\n\n", $pee); // take care of duplicates

        return $pee;
    }

    /**
     * Remove spaces that might have been put between object & param tags.
     *
     * @param string $pee
     * @return string
     */
    static private function cleanUpObjectTag($pee)
    {
        $pee = preg_replace('|\s*<param([^>]*)>\s*|', "<param$1>", $pee); // no pee inside object/embed
        $pee = preg_replace('|\s*</embed>\s*|', '</embed>', $pee);

        return $pee;
    }

    /**
      * Replaces double line-breaks with paragraph elements.
      *
      * A group of regex replaces used to identify text formatted with newlines and
      * replace double line-breaks with HTML paragraph tags. The remaining
      * line-breaks after conversion become <<br />> tags, unless $br is set to '0'
      * or 'false'.
      *
      * @since 0.71
      *
      * @from WordPress (trunk) r24026
      *
      * @param string $pee The text which has to be formatted.
      * @param bool $br Optional. If set, this will convert all remaining line-breaks after paragraphing. Default true.
      * @return string Text which has been converted into correct paragraph tags.
      */
    static function autop($pee, $br = true) {
        $pre_tags = array();

        $pee = self::cleanBeforeAutoP($pee);
        $pee = self::spaceOutBlocks($pee);

        // make paragraphs, including one at the end
        $allblocks = self::allBlocks();
        $pees = preg_split('/\n\s*\n/', $pee, -1, PREG_SPLIT_NO_EMPTY);
        $pee = '';
        foreach ( $pees as $tinkle )
            $pee .= '<p>' . trim($tinkle, "\n") . "</p>\n";
        $pee = preg_replace('|<p>\s*</p>|', '', $pee); // under certain strange conditions it could create a P of entirely whitespace
        $pee = preg_replace('!<p>([^<]+)</(div|address|form)>!', "<p>$1</p></$2>", $pee);
        $pee = preg_replace('!<p>\s*(</?' . $allblocks . '[^>]*>)\s*</p>!', "$1", $pee); // don't pee all over a tag
        $pee = preg_replace("|<p>(<li.+?)</p>|", "$1", $pee); // problem with nested lists
        $pee = preg_replace('|<p><blockquote([^>]*)>|i', "<blockquote$1><p>", $pee);
        $pee = str_replace('</blockquote></p>', '</p></blockquote>', $pee);
        $pee = preg_replace('!<p>\s*(</?' . $allblocks . '[^>]*>)!', "$1", $pee);
        $pee = preg_replace('!(</?' . $allblocks . '[^>]*>)\s*</p>!', "$1", $pee);
        if ( $br ) {
            $pee = preg_replace_callback('/<(script|style).*?<\/\\1>/s', function() {return str_replace("\n", "<WPPreserveNewline />", $matches[0]);}, $pee);
            $pee = preg_replace('|(?<!<br />)\s*\n|', "<br />\n", $pee); // optionally make line breaks
            $pee = str_replace('<WPPreserveNewline />', "\n", $pee);
        }
        $pee = preg_replace('!(</?' . $allblocks . '[^>]*>)\s*<br />!', "$1", $pee);
        $pee = preg_replace('!<br />(\s*</?(?:p|li|div|dl|dd|dt|th|pre|td|ul|ol)[^>]*>)!', '$1', $pee);
        $pee = preg_replace( "|\n</p>$|", '</p>', $pee );

        if ( !empty($pre_tags) )
            $pee = str_replace(array_keys($pre_tags), array_values($pre_tags), $pee);

        return $pee;
    }

}
