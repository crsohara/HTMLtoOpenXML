<?php

namespace HTMLtoOpenXML;

class Parser
{

    private $_cleaner;
    private $_processProperties;
    private $_listIndex = 1;

    const BREAK = '</w:t></w:r><w:r><w:br/><w:t>';

    /**
     * Private constructor of singleton.
     */
    public function __construct() {
        $this->_cleaner = new Scripts\HTMLCleaner;
        $this->_processProperties = new Scripts\ProcessProperties;
    }

    /**
     * Converts HTML to RTF.
     *
     * @param string $htmlCode the HTML formated input string
     * @param bool   $wrapContent
     *
     * @return string The converted string.
     */
    public function fromHTML($htmlCode, $wrapContent = true) {
        $start = 0;

        $openxml = $this->_cleaner->cleanUpHTML($htmlCode);
        if ($wrapContent) {
            $openxml = $this->getOpenXML($openxml);
        }
        $openxml = $this->processBreaks($openxml);
        $openxml = $this->_processListStyle($openxml);
        $openxml = $this->_processProperties->processPropertiesStyle(
                $openxml, $start
        );
        $openxml = $this->_removeStartSpaces($openxml);
        $openxml = $this->_removeEndSpaces($openxml);

        $openxml = $this->processSpaces($openxml);
        $openxml = $this->processStyle($openxml);

        //prd($openxml);
        return $openxml;
    }

    private function _removeEndSpaces($openxml) {
        $regex = '/<w:t><\/w:t><\/w:r><\/w:p><w:p><w:r><w:t>$/';
        if (preg_match($regex, $openxml)) {
            $openxml = preg_replace($regex, '<w:t>', $openxml);

            return $this->_removeEndSpaces($openxml);
        }

        return $openxml;
    }

    private function _removeStartSpaces($openxml) {
        $regex = '/^<w:p><w:r><w:t><\/w:t><\/w:r><\/w:p>/';
        if (preg_match($regex, $openxml)) {
            $openxml = preg_replace($regex, '', $openxml);

            return $this->_removeStartSpaces($openxml);
        }

        return $openxml;
    }

    private function getOpenXML($text) {
        $text = "<w:p><w:r><w:t>$text</w:t></w:r></w:p>";

        return $text;
    }


    /**
     * We can't process nested lists unfortunately, as we would need to write
     * this to numbering.xml. Stead we replace by dash or numbers
     *
     * @param $input
     *
     * @return string
     */
    private function _preProcessNestedLists($html) {
        $output = preg_replace_callback(
                '/<li>(.*?)<(?:ul|ol).*?>(.*?)<\/(?:ul|ol).*?><\/li>/im',
                [$this, 'preProcessNestedList'], $html
        );
        $output = $output;

        return $output;
    }

    public function preProcessNestedList($html) {

        $text = preg_replace_callback(
                '/<li.*?>(.*?)<\/li>/im', [$this, 'preProcessNestedListItems'],
                $html[2]
        );

        $output = '<li>';
        if ($html[1]) {
            $output .= $html[1] . self::BREAK;
        }
        $output .= $text;
        $escaped_break = str_replace('/', '\/', self::BREAK);
        $output = preg_replace('/' . $escaped_break . '$/', '', $output);
        $output .= '</li>';

        return $output;
    }

    public function preProcessNestedListItems($html) {

        $output = ' - ' . $html[1] . self::BREAK;

        return $output;
    }

    private function _processListStyle($input) {
        $output = preg_replace("/\n/", ' ', $input);


        $output = $this->_preProcessNestedLists($output);

        $output = preg_replace_callback(
                '/<(ul|ol).*?>(.*?)<\/(?:ul|ol)>/im', [$this, 'processList'],
                $output
        );

        return $output;
    }

    public function processList($html) {

        $output = '';

        $output .= preg_replace_callback(
                '/<li.*?>(.*?)<\/li>/im', [$this, 'processListItem'], $html[2]
        );

        $output .= '</w:t></w:r></w:p><w:p><w:r><w:t>';

        // Add a blank line after the list, otherwise it's attached
        $output .= '</w:t></w:r></w:p><w:p><w:r><w:t>';

        $this->_listIndex += 1;

        return $output;
    }

    public function processListItem($html) {

        $html = sprintf(
                "</w:t></w:r></w:p><w:p><w:pPr><w:pStyle w:val='ListParagraph'/><w:numPr><w:ilvl w:val='0'/><w:numId w:val='%d'/></w:numPr></w:pPr><w:r><w:t xml:space='preserve'>%s",
                $this->_listIndex, trim($html[1])
        );

        return $html;
    }

    private function processBreaks($input) {
        $output = preg_replace(
                "/(<\/p>)/mi", "</w:t></w:r></w:p><w:p><w:r><w:t>", $input
        );
        $output = preg_replace(
                "/(<br\s?\/?>)/mi", "</w:t></w:r></w:p><w:p><w:r><w:t>", $output
        );

        return $output;
    }

    /**
     * @param $html
     *
     * @author © Alex Moore
     *
     * @return null|string|string[]
     */
    public function minifyHtml($html) {
        $re
                = '%# Collapse whitespace everywhere but in blacklisted elements.
        (?>             # Match all whitespans other than single space.
          [^\S ]\s*     # Either one [\t\r\n\f\v] and zero or more ws,
        | \s{2,}        # or two or more consecutive-any-whitespace.
        ) # Note: The remaining regex consumes no text at all...
        (?=             # Ensure we are not in a blacklist tag.
          [^<]*+        # Either zero or more non-"<" {normal*}
          (?:           # Begin {(special normal*)*} construct
            <           # or a < starting a non-blacklist tag.
            (?!/?(?:textarea|pre|script)\b)
            [^<]*+      # more non-"<" {normal*}
          )*+           # Finish "unrolling-the-loop"
          (?:           # Begin alternation group.
            <           # Either a blacklist start tag.
            (?>textarea|pre|script)\b
          | \z          # or end of file.
          )             # End alternation group.
        )  # If we made it here, we are not in a blacklist tag.
        %Six';

        return preg_replace($re, "", $html);
    }

    private function processSpaces($input) {
        $output = preg_replace("/(&nbsp;)/mi", " ", $input);
        $output = preg_replace(
                "/(<w:t>)/mi", "<w:t xml:space='preserve'>", $output
        );

        $output = $this->minifyHtml($output);

        return $output;
    }

    private function processStyle($input) {
        $output = preg_replace(
                "/(<w:p>)/mi", "<w:p><w:pPr><w:pStyle w:val='OurStyle2'/></w:pPr>",
                $input
        );

        return $output;
    }

}
