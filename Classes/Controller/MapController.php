<?php

namespace Brinkert\Cbgooglemaps\Controller;

use Symfony\Component\Routing\RequestContext;
use TYPO3\CMS\Core\Http\Request;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Class to extend the backend with a tca user field
 * @package             Cbgooglemaps
 * @path                Cbgooglemaps\Controller\MapController.php
 * @version             5.0: MapController.php,  02.07.2018
 * @copyright           (c)2011-2020 Christian Brinkert / Tobi
 * @author              Christian Brinkert <christian.brinkert@googlemail.com>
 */
class MapController extends ActionController
{

    protected $ceData;
    protected array $settings = [];
    protected $cobj;
    protected $filePath;


    /**
     * Do some global initialization
     */
    public function initializeAction(): void
    {
        // store content element data to local property
        $contentObjectRenderer = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $configurationManager = GeneralUtility::makeInstance(ConfigurationManager::class);

        // Check if the currentContentObject is available
        $contentObject = $contentObjectRenderer->data;
        if (is_array($contentObject) && isset($contentObject['data'])) {
            $this->ceData = $contentObject['data'];
        } elseif (is_object($contentObject) && property_exists($contentObject, 'data')) {
            $this->ceData = $contentObject->data;
        }

        // get extension typoscript
        $this->settings = $configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
            'Cbgooglemaps',
            'Quickgooglemap');

        // set sitepath
        $request = $GLOBALS['TYPO3_REQUEST'];
        $normalizedParams = $request->getAttribute('normalizedParams');
        $baseUri = $normalizedParams->getSiteUrl();
        $this->filePath = $baseUri . '/typo3conf/ext/cbgooglemaps/';

        // set content object renderer
        $this->cobj = GeneralUtility::makeInstance(
            ContentObjectRenderer::class);
    }


    /**
     * Create map content element to the frontend
     */
    public function indexAction(): ResponseInterface
    {
        // add google or openstreetmap scripts/styles
        $this->addJsCss();

        // set map parameters
        $mapParameter = $this->getMapParameters();

        // assign mapStyling and settings
        $mapParameter['mapStyling'] = $this->getMapStyling();

        // assign map parameters to the view
        $this->view->assignMultiple($mapParameter);
        return $this->htmlResponse();
    }


    /**
     * Build and return map parameter array, with defaults or ce specific values
     * @return array
     */
    private function getMapParameters()
    {
        $contentObjectRenderer = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $contentObject = $contentObjectRenderer->data;

        return [
            // assign uid of current content element
            'contentId' => ((
                    $this->ceData['uid'] ?? rand(1, 999999)
                ) . '_' . isset($contentObject->parentRecord['data']['uid'])),
            // map provider to build map: googleMaps or OpenStreetMap
            'mapProvider' => $this->settings['mapProvider'],
            // assign width and height of map
            'width' => $this->ceData['width'] ?? (
                0 < (int)$this->settings['cbgmMapWidth']
                    ? $this->settings['cbgmMapWidth']
                    : $this->settings['display']['width']
                ),
            'height' => $this->ceData['height'] ?? (
                0 < (int)$this->settings['cbgmMapHeight']
                    ? $this->settings['cbgmMapHeight']
                    : $this->settings['display']['height']
                ),
            // assign pin description text, to placed into info box
            'infoText' => urlencode(
                (string) ($this->ceData['infoText'] ?? (
                    $this->settings['cbgmDescription'] ?? $this->settings['infoText']
                ))
            ),
            // assign auto open flag to the view
            'openInfoBox' => $this->settings['cbgmAutoOpen'] ?? $this->settings['infoTextOpen'],
            // assign deactivation of zooming by mousewheel
            'useScrollwheel' => $this->settings['options']['useScrollwheel'],
            // assign location (longitude and latitude) to the view
            'latitude' => isset($this->ceData['latitude'])
                ? (float)$this->ceData['latitude']
                : (
                isset($this->settings['cbgmLatitude'])
                    ? (float)$this->settings['cbgmLatitude']
                    : (float)$this->settings['latitude']
                ),
            'longitude' => isset($this->ceData['longitude'])
                ? (float)$this->ceData['longitude']
                : (
                isset($this->settings['cbgmLongitude'])
                    ? (float)$this->settings['cbgmLongitude']
                    : (float)$this->settings['longitude']
                ),
            // assign map zoom level to the view ,if given value is valid
            'mapZoom' => isset($this->ceData['zoom'])
                ? (int)$this->ceData['zoom']
                : (
                0 <= (int)$this->settings['cbgmScaleLevel'] && !empty($this->settings['cbgmScaleLevel'])
                    ? (int)$this->settings['cbgmScaleLevel']
                    : (int)$this->settings['display']['zoom']
                ),
            // assign map type to the view, if given value is valid
            'mapType' => isset($this->ceData['mapType']) && in_array($this->ceData['mapType'],
                preg_split("/[\s]*[,][\s]*/", (string) $this->settings['valid']['mapTypes']))
                ? $this->ceData['mapType']
                : (
                in_array((string)$this->settings['cbgmMapType'],
                    preg_split("/[\s]*[,][\s]*/", (string) $this->settings['valid']['mapTypes']))
                    ? $this->settings['cbgmMapType']
                    : $this->settings['display']['mapType']
                ),
            // assign navigation controls to the view
            'mapControl' => isset($this->ceData['navigationControl'])
            && in_array((string)$this->ceData['navigationControl'],
                preg_split("/[\s]*[,][\s]*/", (string) $this->settings['valid']['navigationControl']))
                ? $this->ceData['navigationControl']
                : (
                in_array((string)$this->settings['cbgmNavigationControl'],
                    preg_split("/[\s]*[,][\s]*/", (string) $this->settings['valid']['navigationControl']))
                    ? $this->settings['cbgmNavigationControl']
                    : $this->settings['display']['navigationControl']
                ),
            // assign icon if given by constant or typoscript
            'icon' => isset($this->ceData['icon']) && file_exists(Environment::getPublicPath() . '/' . $this->ceData['icon'])
                ? GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST') . '/' . $this->ceData['icon']
                : (
                    !empty($this->settings['display']['icon']) && file_exists(Environment::getPublicPath() . '/' . $this->settings['display']['icon'])
                        ? GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST') . '/' . $this->settings['display']['icon']
                        : null
                ),
            // add map styling default
            'mapStyling' => null,
            // some braces?
            'braceStart' => '{',
            'braceEnd' => '}'
        ];
    }


    /**
     * Fetch and return map styling from file if defined
     * @param array $mapParameter
     * @return mixed
     */
    private function getMapStyling()
    {

        if (isset($this->ceData['mapStyling'])
            && file_exists(Environment::getPublicPath() . '/' . $this->ceData['mapStyling'])) {
            // assign map styling from content element

            $styling = file_get_contents(Environment::getPublicPath() . '/' . $this->ceData['mapStyling']);
            return !is_null(json_decode($styling)) ? $styling : null;

        } else if (!empty($this->settings['display']['mapStyling'])
            && file_exists(Environment::getPublicPath() . '/' . $this->settings['display']['mapStyling'])) {
            // assign map styling from typoscript file definition

            $styling = file_get_contents(Environment::getPublicPath() . '/' . $this->settings['display']['mapStyling']);
            return !is_null(json_decode($styling)) ? $styling : null;

        } else if (!empty($this->settings['display']['mapStyling'])
            && !is_null(json_decode($this->settings['display']['mapStyling']))) {
            // assign map styling from typoscript style definition

            return $this->settings['display']['mapStyling'];
        }

        return null;
    }


    /**
     * Add some javascripts and css styles to the view
     * @return void
     */
    private function addJsCss(): void
    {

        // add google or openstreet map scripts and styles to the view
        if ('Google' == $this->settings['mapProvider']) {

            // build google maps uri
            $googleMapsUri = preg_match('/^http/', (string) $this->settings['googleapi']['uri'])
                ? $this->settings['googleapi']['uri']
                : $this->filePath . $this->settings['googleapi']['uri'];

            // add optional or required given key
            if (!empty($this->settings['googleapi']['key']))
                $googleMapsUri .= '?key=' . $this->settings['googleapi']['key'];

            // add google api file
            $GLOBALS['TSFE']->additionalHeaderData['cbgooglemaps'] =
                '<script src="' . $googleMapsUri . '"></script>';


        } else if ('MapBox' == $this->settings['mapProvider']) {
            // add mapbox js and css files
            $mapboxJs = preg_match('/^http/', (string) $this->settings['mapboxapi']['js'])
                ? $this->settings['mapboxapi']['js']
                : $this->filePath . $this->settings['mapboxapi']['js'];

            $mapboxCss = preg_match('/^http/', (string) $this->settings['mapboxapi']['css'])
                ? $this->settings['mapboxapi']['css']
                : $this->filePath . $this->settings['mapboxapi']['css'];

            $GLOBALS['TSFE']->additionalHeaderData['cbgooglemapsJs'] =
                '<script src="' . $mapboxJs . '"></script>';
            $GLOBALS['TSFE']->additionalHeaderData['cbgooglemapsCss'] =
                '<link href="' . $mapboxCss . '" rel="stylesheet" />';


        } else {
            // add leaflet js and css files
            $osmJs = preg_match('/^http/', (string) $this->settings['osmapi']['js'])
                ? $this->settings['osmapi']['js']
                : $this->filePath . $this->settings['osmapi']['js'];

            $osmCss = preg_match('/^http/', (string) $this->settings['osmapi']['css'])
                ? $this->settings['osmapi']['css']
                : $this->filePath . $this->settings['osmapi']['css'];

            $GLOBALS['TSFE']->additionalHeaderData['cbgooglemapsJs'] =
                '<script src="' . $osmJs . '"></script>';
            $GLOBALS['TSFE']->additionalHeaderData['cbgooglemapsCss'] =
                '<link href="' . $osmCss . '" rel="stylesheet" />';

        }

    }

}
