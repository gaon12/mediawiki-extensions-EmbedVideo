<?php
/**
 * Wrapper class for encapsulating EmbedVideo related parser methods
 */
class EmbedVideo
{
    protected $initialized = false;

    /**
     * Sets up parser functions.
     */
    function setup()
    {
        # Setup parser hooks. ev is the primary hook, evp is supported for
        # legacy purposes
        global $wgParser, $wgVersion;
        $hookEV =  version_compare($wgVersion, '1.7', '<') ? '#ev' : 'ev';
        $hookEVP = version_compare($wgVersion, '1.7', '<') ? '#evp' : 'evp';
        $wgParser->setFunctionHook($hookEV, array($this, 'parserFunction_ev'));
        $wgParser->setFunctionHook($hookEVP, array($this, 'parserFunction_evp'));
    }

    /**
     * Adds magic words for parser functions.
     * @param Array $magicWords
     * @param $langCode
     * @return Boolean Always true
     */
    function parserFunctionMagic(&$magicWords, $langCode='en')
    {
        $magicWords['ev'] = array(0, 'ev');
        $magicWords['evp'] = array(0, 'evp');
        return true;
    }

    /**
     * Embeds video of the chosen service, legacy support for 'evp' version of
     * the tag
     * @param Parser $parser Instance of running Parser.
     * @param String $service Which online service has the video.
     * @param String $id Identifier of the chosen service
     * @param String $width Width of video (optional)
     * @return String Encoded representation of input params (to be processed later)
     */
    function parserFunction_evp($parser, $service = null, $id = null, $desc = null,
        $align = null, $width = null)
    {
        return $this->parserFunction_ev($parser, $service, $id, $width, $align, $desc);
    }

    /**
     * Embeds video of the chosen service
     * @param Parser $parser Instance of running Parser.
     * @param String $service Which online service has the video.
     * @param String $id Identifier of the chosen service
     * @param String $width Width of video (optional)
     * @param String $desc description to show (optional, unused)
     * @param String $align alignment of the video (optional, unused)
     * @return String Encoded representation of input params (to be processed later)
     */
    function parserFunction_ev($parser, $service = null, $id = null, $width = null,
        $align = null, $desc = null)
    {
        global $wgScriptPath;

        # Initialize things once
        if (!$this->initialized) {
            $this->VerifyWidthMinAndMax();
            # Add system messages
            wfLoadExtensionMessages('embedvideo');
            $this->initialized = true;
        }

        # Get the name of the host
        if ($service === null || $id === null)
            return $this->errMissingParams($service, $id);

        $service = trim($service);
        $id = trim($id);

        $entry = $this->getServiceEntry($service);
        if (!$entry)
            return $this->errBadService($service);

        if (!$this->sanitizeWidth($width))
            return $this->errBadWidth($width);
        $height = $this->getHeight($width);

        $hasalign = ($align !== null);
        if ($hasalign)
            $desc = $this->getDescriptionMarkup($desc);

        # If the service has an ID pattern specified, verify the id number
        if (!$this->verifyID($entry, $id))
            return $this->errBadID($service, $id);

        # if the service has it's own custom extern declaration, use that instead
        $clause = $entry['extern'];
        if (isset($clause)) {
            $parser->disableCache();
            $clause = wfMsgReplaceArgs($clause, array($wgScriptPath, $id, $width, $height));
            if ($hasalign)
                $clause = $this->generateAlignExternClause($clause, $align, $desc, $width, $height);
            return array($clause, 'noparse' => true, 'isHTML' => true);
        }

        # Build URL and output embedded flash object
        $url = wfMsgReplaceArgs($entry['url'], array($id, $width, $height));
        $clause = "";
        if ($hasalign)
            $clause = $this->generateAlignClause($url, $width, $height, $align, $desc);
        else
            $clause = $this->generateNormalClause($url, $width, $height);
        return array($clause, 'noparse' => true, 'isHTML' => true);
        //return $parser->insertStripItem(
        //    $clause,
        //    $parser->mStripState
        //);
    }

    function generateNormalClause($url, $width, $height)
    {
        $clause = "<object width=\"{$width}\" height=\"{$height}\">" .
            "<param name=\"movie\" value=\"{$url}\"></param>" .
            "<param name=\"wmode\" value=\"transparent\"></param>" .
            "<embed src=\"{$url}\" type=\"application/x-shockwave-flash\"" .
            " wmode=\"transparent\" width=\"{$width}\" height=\"{$height}\">" .
            "</embed></object>";
        return $clause;
    }

    function generateAlignExternClause($clause, $align, $desc, $width, $height)
    {
        $clause = "<div class=\"thumb t{$align}\">" .
            "<div class=\"thumbinner\" style=\"width: {$width}px;\">" .
            $clause .
            "<div class=\"thumbcaption\">" .
            $desc .
            "</div></div></div>";
        return $clause;
    }

    function generateAlignClause($url, $width, $height, $align, $desc)
    {
        $clause = "<div class=\"thumb t{$align}\">" .
            "<div class=\"thumbinner\" style=\"width: {$width}px;\">" .
            "<object width=\"{$width}\" height=\"{$height}\">" .
            "<param name=\"movie\" value=\"{$url}\"></param>" .
            "<param name=\"wmode\" value=\"transparent\"></param>" .
            "<embed src=\"{$url}\" type=\"application/x-shockwave-flash\"" .
            " wmode=\"transparent\" width=\"{$width}\" height=\"{$height}\"></embed>" .
            "</object>" .
            "<div class=\"thumbcaption\">" .
            $desc .
            "</div></div></div>";
        return $clause;
    }

    function getServiceEntry($service)
    {
        # Get the entry in the list of services
        global $wgEmbedVideoServiceList;
        return $wgEmbedVideoServiceList[$service];
    }

    function sanitizeWidth(&$width)
    {
        global $wgEmbedVideoMinWidth, $wgEmbedVideoMaxWidth;
        if ($width === null) {
            if (isset($entry['default_width']))
                $width = $entry['default_width'];
            else
                $width = 425;
            return true;
        }
        if (!is_numeric($width))
            return false;
        return $width >= $wgEmbedVideoMinWidth && $width <= $wgEmbedVideoMaxWidth;
    }

    function getHeight($width)
    {
        $ratio = 425 / 350;
        if (isset($entry['default_ratio']))
            $ratio = $entry['default_ratio'];
        return round($width / $ratio);
    }

    function getDescriptionMarkup($desc)
    {
        if ($desc !== null)
            return "<div class=\"thumbcaption\">$desc</div>";
        return "";
    }

    function verifyID($entry, $id)
    {
        $idhtml = htmlspecialchars($id);
        //$idpattern = (isset($entry['id_pattern']) ? $entry['id_pattern'] : '%[^A-Za-z0-9_\\-]%');
        //if ($idhtml == null || preg_match($idpattern, $idhtml)) {
        return ($idhtml != null);
    }

    function errBadID($service, $id)
    {
        $idhtml = htmlspecialchars($id);
        $msg = wfMsgForContent('embedvideo-bad-id', $idhtml, @htmlspecialchars($service));
        return '<div class="errorbox">' . $msg . '</div>';
    }

    function errBadWidth($width)
    {
        $msg = wfMsgForContent('embedvideo-illegal-width', @htmlspecialchars($width));
        return '<div class="errorbox">' . $msg . '</div>';
    }

    function errMissingParams($service, $id)
    {
        return '<div class="errorbox">' . wfMsg('embedvideo-missing-params') . '</div>';
    }

    function errBadService($service)
    {
        $msg = wfMsg('embedvideo-unrecognized-service', @htmlspecialchars($service));
        return '<div class="errorbox">' . $msg . '</div>';
    }

    function VerifyWidthMinAndMax()
    {
        global $wgEmbedVideoMinWidth, $wgEmbedVideoMaxWidth;
        if (!is_numeric($wgEmbedVideoMinWidth) || $wgEmbedVideoMinWidth<100)
            $wgEmbedVideoMinWidth = 100;
        if (!is_numeric($wgEmbedVideoMaxWidth) || $wgEmbedVideoMaxWidth>1024)
            $wgEmbedVideoMaxWidth = 1024;
    }
}