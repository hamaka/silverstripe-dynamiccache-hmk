<?php

namespace TractorCow\DynamicCache;

use Exception;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DB;
use SilverStripe\Security\BasicAuth;
use SilverStripe\Security\Member;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use function array_diff;
use function array_keys;
use function array_map;
use function array_merge;
use function count;
use function date;
use function explode;
use function header;
use function headers_list;
use function headers_sent;
use function http_response_code;
use function implode;
use function in_array;
use function is_array;
use function join;
use function json_encode;
use function md5;
use function preg_grep;
use function preg_match;
use function preg_replace;
use function serialize;
use function sizeof;
use function str_replace;
use function stripos;
use function strpos;
use function trim;
use function unserialize;
use const BASE_PATH;

class DynamicCacheMiddleware implements HTTPMiddleware
{
    use Configurable;
    use Extensible;
    use Injectable;

    /**
     * Instance of DynamicCache
     *
     * @var DynamicCacheMiddleware
     */
    protected static $instance;
    protected $oLogger = null;
    public static $bStopTheStoringOfCurrentPageInCache = false;

    public function process(HTTPRequest $request, callable $delegate)
    {
        if ( ! self::config()->enabled) {
            // op projecten waar dynamic cache via yml uit staat gewoon de kortste weg naar de uitgang

            /** @var HTTPResponse $response */
            return $delegate($request);
        }

        $bTryToGetFromCache = true;

        $aLogReason = [];

        $url               = $request->getURL();
        $bUseInstantByPass = false;

        if (strpos($url, 'Security/ping') !== false) {
            $bUseInstantByPass = true;
        }

        // focus image module komt hier langs
        if (strpos($url, 'assets/') !== false) {
            $bUseInstantByPass = true;
        }

        $responseHeader = false;

        if ($bUseInstantByPass !== true) {
            $responseHeader = self::config()->responseHeader;
            $cache          = $this->getCache();
            $cacheKey       = $this->getCacheKey($url);

            // Set the stage of the website
            // This is normally called in VersionedRequestFilter.
            Versioned::choose_site_stage($request);

            // Check if caching should be short circuted
            $bTryToGetFromCache = $this->enabled($request, $aLogReason);

            if ($bTryToGetFromCache === true) {
                $aLogReason[] = 'zoek in cache';
            }

            $this->extend('updateEnabled', $bTryToGetFromCache, $request, $aLogReason);
        }
        else {
            $aLogReason[] = 'Instant Bypass';
        }

        if ($bUseInstantByPass === true || ! $bTryToGetFromCache) {
            if ($responseHeader) {
                if ( ! headers_sent()) {
                    //header("$responseHeader: live - geen cache (skip)");
                    header("$responseHeader: skip - no cache");

                    if (strpos($_SERVER['REMOTE_ADDR'], '217.100.134.114') !== false) {
                        header("X-DynamicCache-HMK: live - geen cache " . join(', ', $aLogReason));
                    }
                }
            }

            /** @var HTTPResponse $response */
            $response = $delegate($request);

            if (self::config()->logSkip) {
                $aReasonsNotLogged = [
                    'Er is een cookie met een sectorvoorkeur',
                    'AOb ingelogd via cookie',
                    'Instant Bypass',
                    'Er is POST data',
                    'URL matcht op setting optOutURL',
                    'URL (dev/cron) bevat niet toegestane GET keys: quiet'
                ];

                if (sizeof($aLogReason) === 1 && in_array($aLogReason[0], $aReasonsNotLogged)) {
                    // je kan dit alsnog aanzetten als je iets wil debuggen maar dit levert veel logs op
                    //$this->log()->info('DynamicCache skipped (' . $url . ') ' . join(', ', $aLogReason));
                }
                else {
                    $this->log('DynamicCache not enabled (skip) (' . $url . ') ' . join(', ', $aLogReason));
                }
            }

            return $response;
        }

        $cachedValue = $this->getFromCache($cache, $cacheKey);

        if ($oHTTPResponse = $this->getCachedResult($cachedValue)) {

            if (self::config()->logHit) {
                $this->log('DynamicCache cache gebruikt (' . $url . ') ' . join(', ', $aLogReason));
            }

            // Return the content of the cache
            return $oHTTPResponse;
            //return new HTTPResponse($body, $responseCode);
        }
        else {
            //$aLogReason[] = 'URL niet gevonden in de cache met key ' . $cacheKey;
            if ($bTryToGetFromCache === true) {
                //$aLogReason[] = 'niet gevonden in cache';
                $aLogReason[] = 'niet gevonden in cache (' . $url . ')';
            }
        }

        // call $delegate callback to generate the real response
        /** @var HTTPResponse $response */
        $response = $delegate($request);

        // Check if cached value can be returned
        $responseCode = $response->getStatusCode();

        // aantekening: de headers mergen we hieronder uit HTTPResponse ($response->getStatusCode()) en wat er al in Apache/PHP (http_response_code()) is ingesteld op dit moment
        // voor nu doe ik dat niet met de status code. Die pak ik op dit moment uit de $response (de HTTPResponse van de pagina)
        // Toch lijkt het ook voor te komen dat er in de cache pagina's komen met een status 200 maar wel een location header (wat een redirect suggereert)
        // Mocht dat in de toekomst vaker voorkomen dan kun je hier eea heroverwegen (dus om naar beide lagen te kijken voor statuscode)
        // maar dan heb je wel de uitdaging om te moeten beslissen welke van de 2 je gelooft.. stel je hebt in http_response_code een status 301 en in $response->getStatusCode()
        // een 404, welke ga je dan geloven?

        // we hebben zowel de headers in de response nodig als de headers die nu in Apache/PHP zijn opgebouwd, die hoeven niet te matchen want die van Silverstripe zijn wellicht nog niet overgezet
        $headers = [];

        foreach ($response->getHeaders() as $sHeaderKey => $sHeaderValue) {
            // DynamicCacheControllerExtension kan nog niet naar deze schrijven in onBeforeInit
            $headers[] = $sHeaderKey . ': ' . $sHeaderValue;
        }

        $headers = array_merge($headers, headers_list()); // dus die komt hier terecht

        // alternatief: dit komt uit een fork waar iemand blijkbaar een complexe authenticatie had in de eigen site code - wellicht ooit nog nuttig
        // maar nu lijkt het eerder issues te veroorzaken
        //foreach ($response->getHeaders() as $header => $value) {
        // $headers[] = "{$header}: {$value}";
        //}

        // get response body to
        $result = $response->getBody();

        $bIsStoringInCacheEnabled = true;

        // Run this page, caching output and capturing data

        // Skip blank copy unless redirecting
        $locationHeaderMatches = preg_grep('/^Location/i', $headers);
        if (empty($result) && empty($locationHeaderMatches)) {
            $aLogReason[]             = 'Er zijn Location headers in de response';
            $bIsStoringInCacheEnabled = false;
        }

        // Skip excluded status codes
        $optInResponseCodes  = self::config()->optInResponseCodes;
        $optOutResponseCodes = self::config()->optOutResponseCodes;

        if (is_array($optInResponseCodes) && ! in_array($responseCode, $optInResponseCodes)) {
            $aLogReason[]             = 'responseCode ' . serialize($responseCode) . ' zit niet in optInResponseCodes';
            $bIsStoringInCacheEnabled = false;
        }

        if (is_array($optOutResponseCodes) && in_array($responseCode, $optOutResponseCodes)) {
            $aLogReason[]             = 'responseCode ' . serialize($responseCode) . ' zit in optOutResponseCodes';
            $bIsStoringInCacheEnabled = false;
        }

        // Check if any headers match the specified rules forbidding caching
        if ( ! $this->headersAllowCaching($headers, $aLogReason)) {
            // logging wordt al verwerkt in headersAllowCaching
            $bIsStoringInCacheEnabled = false;
        }

        /**
         * Voor alle zaken die je pas na het volledig opstarten van Silverstripe tot je beschikking hebt
         * die van invloed kunnen zijn op de vraag of je de pagina wel wil opslaan in de cache is er
         * enabledAfterPageWasBuilt
         */
        if ($bIsStoringInCacheEnabled === true) {
            $bIsStoringInCacheEnabled = $this->enabledAfterPageWasBuilt($request, $response, $aLogReason);

            if ($bIsStoringInCacheEnabled !== true) {
                $aLogReason[] = 'enabledAfterPageWasBuilt false';
            }

            $this->extend('updateEnabledAfterPageWasBuilt', $bIsStoringInCacheEnabled, $request, $response, $aLogReason);
        }

        if ($bIsStoringInCacheEnabled === true) {
            // Opslaan in de cache
            // Include any "X-Header" sent with this request. This is necessary to
            // ensure that additional CSS, JS, and other files are retained
            $saveHeaders = $this->getCacheableHeaders($headers);

            // Save data along with sent headers
            if (self::config()->logStore || self::config()->logMiss) {
                $this->log('DynamicCache live pagina getoond en opgeslagen in cache (' . $url . ') ' . join(', ', $aLogReason));
            }

            if ($responseHeader) {
                if ( ! headers_sent()) {
                    //header("$responseHeader: live (miss - opslaan) op " . @date('r'));
                    header("$responseHeader: miss - not in cache - " . @date('r'));
                }
                // logging zit verderop
            }

            $this->cacheResult($cache, $result, $saveHeaders, $cacheKey, $responseCode);
        }
        else {
            // toch niet opslaan in de cache
            if ($responseHeader) {
                if ( ! headers_sent()) {
                    //header("$responseHeader: live (miss - niet opslaan) op " . @date('r'));
                    header("$responseHeader: miss - not in cache " . @date('r'));

                    if (strpos($_SERVER['REMOTE_ADDR'], '217.100.134.114') !== false) {
                        header("X-DynamicCache-HMK: live (miss - niet opslaan) " . join(', ', $aLogReason));
                    }
                }
                // logging zit verderop
            }

            if (self::config()->logMiss || self::config()->logDontStore) {
                $aReasonsNotLogged = [
                    'Er zijn Location headers in de response', // voor nu uit want je kan wel bezig blijven met alle redirects naar urls MET een slash aan het einde
                ];

                if (sizeof($aLogReason) === 1 && in_array($aLogReason[0], $aReasonsNotLogged)) {
                    // deze niet loggen
                }
                else {
                    $this->log('DynamicCache live pagina getoond maar niet opgeslagen in cache (' . $url . ') ' . join(', ', $aLogReason));
                }
            }
        }

        // return de live gegenereerde pagina
        return $response;
    }

    /**
     * Er is al een nieuwe pagina gemaakt maar daardoor zitten we nu op een punt dat
     * we wel weten of iemand ingelogd is, wel weten welke classes aangeroepen zijn etc
     * Als je deze functie false terug laat geven zorg je er voor dat de pagina alsnog
     * NIET in de cache wordt opgeslagen.
     *
     * Voor de werking van de standaard optOut per page class zie DynamicCacheControllerExtension en
     * in deze class de headersAllowCaching method.
     *
     * @param HTTPRequest  $request
     * @param HTTPResponse $response
     * @param array        $aLogReason
     *
     * @return bool
     */
    public function enabledAfterPageWasBuilt(HTTPRequest $request, $response, &$aLogReason = []): bool
    {
        // overschrijf deze functie in je eigen child class van deze middleware om custom logica toe te voegen,
        // maar begin dan wel met een check van deze parent class.

        // Deze static kan handig zijn op plekken waar je in lopende code iets doet waardoor
        // je weet dat deze hele pageload gewoon niet opgeslagen moet worden, zoals bijvoorbeeld
        // bij het opstarten van een Elemental blokje met een formulier er in.
        if (static::$bStopTheStoringOfCurrentPageInCache === true) {
            $aLogReason[] = 'static halt';

            return false;
        }

        return true;
    }

    /**
     * Returns the caching factory
     *
     * @return CacheInterface
     */
    protected function getCache()
    {
        return Injector::inst()->get(CacheInterface::class . '.DynamicCacheStore');
    }

    /**
     * Save a page result into the cache
     *
     * @param CacheInterface $cache
     * @param string         $result   Page content
     * @param array          $headers  Headers to cache
     * @param string         $cacheKey Key to cache this page under
     */
    protected function cacheResult($cache, $result, $headers, $cacheKey, $responseCode)
    {
        $cache->set($cacheKey, serialize([
          'headers'       => $headers,
          'response_code' => $responseCode,
          'content'       => $result
        ]));
    }

    protected function getFromCache($cache, $cacheKey)
    {
        // als het goed is is dit niet meer nodig nu we Versioned::choose_site_stage($request); aanroepen
        // als deze regel hier over een maand nog uitgequote staat mag hij weg
        //$cacheKey .= '_' . md5('Stage.' . Versioned::LIVE);

        return $cache->get($cacheKey);
    }

    /**
     * Clear the cache
     *
     * @param CacheInterface $cache
     */
    public function clear($cache = null)
    {
        $this->log('DynamicCache force clear');

        if (empty($cache)) {
            $cache = $this->getCache();
        }

        $cache->clear();
    }

    /**
     * Determines identifier by which this page should be identified, given a specific
     * url
     *
     * @param string $url The request URL
     *
     * @return string The cache key
     */
    protected function getCacheKey($url)
    {
        $fragments = [];

        // Segment by protocol (always)
        $fragments['protocol'] = Director::protocol();

        // Stage
        $sStage = Versioned::get_stage();

        if ($sStage === null) {
            $sStage = Versioned::LIVE;
        }

        $fragments['stage'] = $sStage;

        // Segment by hostname if necessary
        if (self::config()->segmentHostname && isset($_SERVER['HTTP_HOST'])) {
            $fragments['HTTP_HOST'] = $_SERVER['HTTP_HOST'];
        }

        // Clean up url to match SS_HTTPRequest::setUrl() interpretation
        $fragments['url'] = preg_replace('|/+|', '/', $url);

        // Extend
        $this->extend('updateCacheKeyFragments', $fragments, $url);

        return "DynamicCache_" . md5(implode('|', array_map('md5', $fragments)));
    }

    public static function flush()
    {
        self::inst()->clear();
    }

    /**
     * Return the current cache instance
     *
     * @return DynamicCacheMiddleware
     */
    public static function inst()
    {
        if ( ! self::$instance) {
            self::$instance = self::create();
        }

        return self::$instance;
    }

    /**
     * Determine if the cache should be enabled for the current request
     *
     * @param HTTPRequest $request
     *
     * @return bool
     * @throws Exception
     */
    protected function enabled(HTTPRequest $request, &$aLogReason = [])
    {
        // NB: dit is de basiswerking van de module, we overschrijven deze hele functie in DynamicCacheMiddlewareHmk
        // NB zie ook enabledAfterPageWasBuilt voor zaken die pas na het ophalen van de echte pagina
        // beschikbaar zijn (Member etc).. die is alleen om te voorkomen dat iets in de cache wordt opgeslagen

        $url = $request->getURL();
        // Master override
        if ( ! self::config()->enabled) {
            return false;
        }

        // No GET params other than cache relevant config is passed (e.g. "?stage=Stage"),
        // which would mean that we have to bypass the cache
        // NB: dit is de basiswerking van de module, we overschrijven deze hele functie in DynamicCacheMiddlewareHmk
        if (count(array_diff(array_keys($_GET), ['url']))) {
            return false;
        }

        // Request is not POST (which would have to be handled dynamically)
        if ($_POST) {
            return false;
        }

        // Check url doesn't hit opt out filter
        $optOutURL = self::config()->optOutURL;
        if ( ! empty($optOutURL) && preg_match($optOutURL, $url)) {
            return false;
        }

        // Check url hits the opt in filter
        $optInURL = self::config()->optInURL;
        if ( ! empty($optInURL) && ! preg_match($optInURL, $url)) {
            return false;
        }

        // Check ajax filter
        if ( ! self::config()->enableAjax && Director::is_ajax()) {
            return false;
        }

        // Disable caching on staging site
        $isStage = ($stage = Versioned::get_stage()) && ($stage !== 'Live');
        if ($isStage) {
            return false;
        }

        // If user failed BasicAuth, disable cache and fallback to PHP code
        $basicAuthConfig = Config::forClass(BasicAuth::class);
        if ($basicAuthConfig->entire_site_protected) {
            // NOTE(Jake): Required so BasicAuth::requireLogin() doesn't early exit with a 'true' value
            // This will affect caching performance with BasicAuth turned on.
            if ( ! DB::is_active()) {
                global $databaseConfig;
                if ($databaseConfig) {
                    DB::connect($databaseConfig);
                }
            }

            // If no DB configured / failed to connect
            if ( ! DB::is_active()) {
                return false;
            }

            // NOTE(Jake): Required so MemberAuthenticator::record_login_attempt() can call
            //             Controller::curr()->getRequest()->getIP()
            $stubController = new Controller();
            $stubController->pushCurrent();

            $member = null;
            try {
                $member = BasicAuth::requireLogin($basicAuthConfig->entire_site_protected_message, $basicAuthConfig->entire_site_protected_code, false);
            }
            catch (HTTPResponse_Exception $e) {
                // This codepath means Member auth failed
            }
            catch (Exception $e) {
                // This means an issue occurred elsewhere
                throw $e;
            }
            $stubController->popCurrent();
            // Do not cache because:
            // - $member === true when: "Security::database_is_ready()" is false (No Member tables configured) or unit testing
            // - $member is not a Member object, means the authentication failed.
            if ($member === true || ! $member instanceof Member) {
                return false;
            }
        }

        // If displaying form errors then don't display cached result
        /** @var Session $oSession */
        $oSession = $request->getSession();

        $aSessionData = $oSession->getAll();
        if (empty($sessionData)) {
            return true;
        }

        if ($aSessionData && is_array($aSessionData)) {
            foreach ($oSession->getAll() as $field => $data) {
                // Check for session details in the form FormInfo.{$FormName}.errors/FormInfo.{$FormName}.formError
                if ($field === 'FormInfo') {
                    foreach ($data as $formData) {
                        if (isset($formData['result']) || isset($formData['errors']) || isset($formData['formError'])) {
                            return false;
                        }
                    }
                }
            }
        }

        // OK!
        return true;
    }

    /**
     * Determine if the specified headers permit this page to be cached
     *
     * @param array $headers
     *
     * @return boolean
     */
    protected function headersAllowCaching(array $headers, array &$aLogReason = [])
    {
        // Check if any opt out headers are matched
        $optOutHeader = self::config()->optOutHeader;
        if ( ! empty($optOutHeader)) {
            foreach ($headers as $header) {
                if (preg_match($optOutHeader, $header)) {
                    $aLogReason[] = 'Match gevonden op optOutHeader: ' . json_encode($header);

                    return false;
                }
            }
        }

        // Check if any opt in headers are matched
        $optInHeaders = self::config()->optInHeader;
        if ( ! empty($optInHeaders)) {
            foreach ($headers as $header) {
                if (preg_match($optInHeaders, $header)) {
                    return true;
                }
            }

            $aLogReason[] = 'DynCache is ingesteld op optInHeaders en er is geen match gevonden.';

            return false;
        }

        return true;
    }

    /**
     * Sends the cached value to the browser, including any necessary headers
     *
     * @param string $cachedValue Serialised cached value
     *
     * @return bool | HTTPResponse
     */
    protected function getCachedResult($cachedValue)
    {
        // Check for empty cache
        if (empty($cachedValue)) {
            return false;
        }

        $deserialisedValue = unserialize($cachedValue);

        // Set response code
        http_response_code($deserialisedValue['response_code']);

        // Present cached headers
        foreach ($deserialisedValue['headers'] as $header) {
            header($header);
        }

        // Send success header
        $responseHeader = self::config()->responseHeader;

        if ($responseHeader) {
            //header("$responseHeader: uit cache (hit) op " . @date('r'));
            header("$responseHeader: hit - from cache " . @date('r'));
        }

        // Substitute security id in forms
        $securityID = SecurityToken::getSecurityID();
        $outputBody = preg_replace(
          '/\<input type="hidden" name="SecurityID" value="\w+"/',
          "<input type=\"hidden\" name=\"SecurityID\" value=\"{$securityID}\"",
          $deserialisedValue['content']
        );

        if ($outputBody) {
            $response = HTTPResponse::create();
            $response->setBody($outputBody);
            $response->setStatusCode($deserialisedValue['response_code']);

            foreach ($deserialisedValue['headers'] as $header) {
                $parts = explode(':', $header);
                if (count($parts) >= 2) {
                    $response->addHeader(
                      trim($parts[0]),
                      trim(str_replace('HTTP_REPLACE', '://', $parts[1]))
                    );
                }
            }

            return $response;
        }

        return null;
    }

    /**
     * Determine which already sent headers should be cached
     *
     * @param array $headers of sent headers to filter
     *
     * @return array List of cacheable headers
     */
    protected function getCacheableHeaders($headers)
    {
        // Caching options
        $responseHeader = self::config()->responseHeader;
        $cachePattern   = self::config()->cacheHeaders;

        $saveHeaders = array();
        foreach ($headers as $header) {

            // Filter out headers starting with $responseHeader
            if ($responseHeader && stripos($header, $responseHeader) === 0) {
                continue;
            }

            // Filter only headers that match the specified pattern
            if ($cachePattern && ! preg_match($cachePattern, $header)) {
                continue;
            }

            // Save this header
            $header        = str_replace('://', 'HTTP_REPLACE', $header);
            $saveHeaders[] = $header;
        }

        return $saveHeaders;
    }

    public function initLoggerOnce()
    {
        if ($this->oLogger === null) {
            $this->oLogger = Injector::inst()->create(LoggerInterface::class);
            $this->oLogger->pushHandler(new StreamHandler(BASE_PATH . '/dynamic_cache.log', Level::Info));
        }
    }

    public function log($sMsg, $sLevel = LogLevel::INFO)
    {
        // voor nu alles level info
        if ( ! $this->oLogger) {
            $this->initLoggerOnce();
        }

        if ($this->oLogger) {
            $this->oLogger->log($sLevel, $sMsg);
        }
    }

    public static function clearCacheForURL($sURL)
    {
        /** @var DynamicCacheMiddleware $oSelf */
        $oSelf = DynamicCacheMiddleware::inst();

        $cache    = $oSelf->getCache();
        $cacheKey = $oSelf->getCacheKey($sURL);
        $cache->delete($cacheKey);
    }

    public static function clearAll()
    {
        /** @var DynamicCacheMiddleware $oSelf */
        $oSelf = DynamicCacheMiddleware::inst();

        $cache = $oSelf->getCache();
        $cache->clear();
    }

}
