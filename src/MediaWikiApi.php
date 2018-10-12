<?php

namespace Nischayn22;

ini_set('display_errors', 1); 
error_reporting(E_ALL);

global $settings;
$settings['cookiefile'] = "cookies.tmp";

use Google\Cloud\Translate\TranslateClient;
use Exception;

class MediaWikiApi {

    private $editToken;

	private $createAccountToken;

    private $siteUrl;

    private $logggedIn = false;

	private $last_error = "";

	private $templateData = array();

	private $googleTranslateProjectId;

	private $translateTo;

    function __construct( $siteUrl ) {
        assert(!empty($siteUrl));
        $this->siteUrl = $siteUrl;
    }

	function setTranslateSettings( $googleTranslateProjectId, $translateTo ) {
		$this->googleTranslateProjectId = $googleTranslateProjectId;
		$this->translateTo = $translateTo;
	}

	function createAccount( $username, $password, $useremail = '', $realname = '', &$api_error = '' ) {
        if ( empty( $this->createAccountToken ) ) {
            $this->setCreateAccountToken();
		}
        assert( !empty( $this->siteUrl ) );

        $url = $this->siteUrl . "/api.php?action=createaccount&format=xml";

		$params = array( "createtoken" => $this->createAccountToken, "username" => $username, "realname" => $realname, "email" => $useremail, "password" => $password, "retype" => $password, "reason" => "Auto Creation", "createreturnurl" => $this->siteUrl );

        $data = self::httpRequest( $url, $params );

		if ($data == null) {
            return null;
		}

        $xml = simplexml_load_string( $data );
        $apiError = self::errorHandler( $xml, $url );
		if ( $apiError ) {
			return null;
		}
		if ( reset( ( (array)$xml->createaccount[0]['status'][0] ) ) == "PASS" ){
			return 1;
		} else {
			$this->last_error = reset( (array)$xml->createaccount[0]['messagecode'][0] );
			return 0;
		}
	}

	function getLastError() {
		return $this->last_error;
	}

    function login($user, $pass) {

        try {
            $token = $this->getToken();
			if ( $token == null ) {
				$token = $this->_login($user, $pass);
			}
            $token = $this->_login($user, $pass, $token);
            return true;
        }
        catch (Exception $e) {
            die("FAILED: " . $e->getMessage() . "\n");
        }
    }

    private function getToken( $type = 'login' ) {
        assert(!empty($this->siteUrl));
        $url = $this->siteUrl . "/api.php?action=query&meta=tokens&type=$type&format=xml";
        $data = self::httpRequest($url);
        if (empty($data)) {
            throw new Exception("No data received from server. Check that API is enabled.");
        }

        $xml = simplexml_load_string($data);
		$expr   = "/api/query/tokens";
		$result = $xml->xpath($expr);
		if ( empty( $result ) ) {
			return null;
		}
		return urlencode($result[0]->attributes()->logintoken);
	}

    private function _login($user, $pass, $token = '') {

        assert(!empty($this->siteUrl));
        assert(!empty($user));
        assert(!empty($pass));
        $url = $this->siteUrl . "/api.php?action=login&format=xml";

        $params = "action=login&lgname=$user&lgpassword=$pass";
        if (!empty($token)) {
            $params .= "&lgtoken=$token";
        }

        $data = self::httpRequest($url, $params);

        if (empty($data)) {
            throw new Exception("No data received from server. Check that API is enabled.");
        }

        $xml = simplexml_load_string($data);
        if (!empty($token)) {
            //Check for successful login
            $expr   = "/api/login[@result='Success']";
            $result = $xml->xpath($expr);

            if (!count($result)) {
                throw new Exception("Reason :" . $xml->xpath("/api/login")[0]->attributes()->reason);
            }
        } else {
            $expr   = "/api/login[@token]";
            $result = $xml->xpath($expr);

            if (!count($result)) {
				// For old versions
				$expr   = "/api/login[@lgtoken]";
				$result = $xml->xpath($expr);
				if (!count($result)) {
					throw new Exception("Login token not found in XML");
				}
            }
        }
        return urlencode($result[0]->attributes()->token);
    }

    function logout() {
        $url = $this->siteUrl . "/api.php?action=logout";
        $params = "";
        $data = self::httpRequest($url, $params);
    }

    function setCreateAccountToken() {
        $url    = $this->siteUrl . "/api.php?format=xml&action=query&meta=tokens&type=createaccount";
        $data   = self::httpRequest($url, $params = '');
        $xml    = simplexml_load_string($data);
		$expr   = "/api/query/tokens";
		$result = $xml->xpath($expr);
        $this->createAccountToken = $result[0]->attributes()->createaccounttoken;
        self::errorHandler($xml);
        return $this->createAccountToken;
    }

    function setEditToken() {
        $url    = $this->siteUrl . "/api.php?format=xml&action=query&meta=tokens&assert=user";
        $data   = self::httpRequest($url, $params = '');
        $xml    = simplexml_load_string($data);
        $expr   = "/api/query/tokens[@csrftoken]";
        $result = $xml->xpath($expr);
        $this->editToken = urlencode($result[0]->attributes()->csrftoken);
	//UNCOMMENT TO DEBUG TO STDOUT
	//print($this->editToken);
        self::errorHandler($xml);
        return $this->editToken;
    }

    function hasApiAction($action) {
        $url    = $this->siteUrl . "/api.php?format=xml&action=$action";
        $data   = self::httpRequest($url, $params = '');
        $xml    = simplexml_load_string($data);
        $expr   = "/api/error";
        $result = $xml->xpath($expr);
		if (count($result)) {
			return false;
		}
		return true;
    }

    function listPageInNamespace($namespace) {

        // Hope this limit is enough large that we don't have the trouble to do this again and again using 'continue'

        $url    = $this->siteUrl . "/api.php?action=query&list=allpages&format=xml&apnamespace=$namespace&aplimit=10000";
        $data   = self::httpRequest($url, $params = '');
        $xml    = simplexml_load_string($data);
        $expr   = "/api/query/allpages/p";
        $result = $xml->xpath($expr);
        return $result;
    }

    function listPageInCategory($category) {
        $category = urlencode( $category );
        $url      = $this->siteUrl . "/api.php?format=xml&action=query&cmtitle=$category&list=categorymembers&cmlimit=10000";
        $data     = self::httpRequest($url, $params = '');
        $xml      = simplexml_load_string($data);
        self::errorHandler($xml);
        //fetch category pages and call them recursively
        $expr = "/api/query/categorymembers/cm";
        return $xml->xpath($expr);
    }

    function listImagesOnPage($pageName) {
        // Returns a list with all image pages this page links to
        $pageName   = urlencode($pageName);
        $url        = $this->siteUrl . "/api.php?format=xml&action=query&prop=images&titles=$pageName&imlimit=1000";
        $data       = self::httpRequest( $url );
        $xml        = simplexml_load_string($data);
        self::errorHandler($xml);
        //fetch image Links and copy them as well
        $expr = "/api/query/pages/page/images/im";
        return $xml->xpath($expr);
    }

    function getFileUrl($pageName) {
        $pageName   = urlencode( $pageName );
        $url        = $this->siteUrl . "/api.php?action=query&titles=$pageName&prop=imageinfo&iiprop=url&format=xml";
        $data       = self::httpRequest($url, $params = '');
        $xml        = simplexml_load_string($data);
        $expr       = "/api/query/pages/page/imageinfo/ii";
        $imageInfo  = $xml->xpath($expr);
        $rawFileURL = $imageInfo[0]['url'];
        return (string) $imageInfo[0]['url'];
    }

    function readPage($pageName, $section = false) {
		$pageName = urlencode( $pageName );
        $url  = $this->siteUrl . "/api.php?format=xml&action=query&titles=$pageName&prop=revisions&rvprop=content";
		if ($section) {
			$section   = urlencode($section);
			$url .= "&rvsection=$section";
		}
        //UNCOMMENT TO DEBUG TO STDOUT
        //print($url);

        $data = self::httpRequest($url, $params = '');

        //UNCOMMENT TO DEBUG TO STDOUT
        //print($data);

        $xml  = simplexml_load_string($data);
        self::errorHandler($xml);
        return (string) $xml->query->pages->page->revisions->rev;
    }

    function createPage($pageName, $content) {
        return $this->editPage($pageName, $content, true);
    }

	function fetchTemplateData( $templateName ) {
		$arguments = array();

        $url    = $this->siteUrl . "/api.php?format=xml&action=templatedata&titles=Template:$templateName";
        $data   = self::httpRequest($url, $params = '');
        $xml    = simplexml_load_string($data);
        $expr   = "/api/pages/page/params/param";
        $result = $xml->xpath($expr);
		foreach( $result as $argument ) {
			$arguments[] = (string) $argument->attributes()->key;
		}
		$this->templateData[$templateName] = $arguments;
	}

	function templateValuesFilter($content) {
		$templateBegin = 0;

		// Find all templates
		while( ( $templateBegin = strpos( $content, "{{", $templateBegin ) ) !== false ) {
			$templateEnd = strpos( $content, "}}", $templateBegin ) + 2;
			$templateParts = explode( '|', substr( $content, $templateBegin + 2, $templateEnd - $templateBegin - 4 ) );

			$templateName = '';
			$templateArgs = array();

			foreach( $templateParts as $templatePart ) {
				$templatePart = trim( $templatePart );

				if ( empty( $templateName ) ) {
					$templateName = $templatePart;
				} else {
					$argumentParts = explode( '=', $templatePart );
					if ( count( $argumentParts ) != 2 ) {
						break;
					}
					$templateArgs[$argumentParts[0]] = $argumentParts[1];
				}
			}
			// If valid template filter out data using templatedata
			if ( count( $templateArgs ) > 0 ) {
				if ( !array_key_exists( $templateName, $this->templateData ) ) {
					$this->fetchTemplateData( $templateName );
				}
				$templateArgs = array_intersect_key( $templateArgs, array_flip($this->templateData[$templateName]) );
				$newTemplateContent = implode( PHP_EOL . '|', 
					array_map(
						function ( $key, $value ) { return "$key=$value"; },
						array_keys( $templateArgs ),
						$templateArgs
					)
				);
				$newTemplateContent = "{{" . $templateName . PHP_EOL . '|' . $newTemplateContent . PHP_EOL . "}}";
				$content = substr_replace( $content, $newTemplateContent, $templateBegin, $templateEnd - $templateBegin );
				$templateBegin += strlen( $newTemplateContent );
			} else {
				$templateBegin += $templateEnd;
			}
		}
		return $content;
	}

	function importComments( $pageName, $comments ) {
        assert(!empty($pageName));
        assert(!empty($comments));

        if (empty($this->editToken))
            $this->setEditToken();

        $editToken = $this->editToken;
        $site      = $this->siteUrl;
        $url  = $site . "/api.php?format=xml&action=csImportComments&title=" . urlencode($pageName);
		$params = array( "token" => urldecode( $editToken ) );
		$params['comments'] = $comments;
        $data = self::httpRequest($url, $params);

		if ($data == null) {
            return null;
		}

        $xml = simplexml_load_string($data);

        $apiError = self::errorHandler($xml, $url);
		if ($apiError) {
			return null;
		}

        return 1;
	}

    function editPage($pageName, $content, $createonly = false, $prepend = false, $append = false, $summary = false, $section = false, $sectiontitle = false, $retryNumber = 0) {
        assert(!empty($pageName));
        assert(!empty($content));

        if (empty($this->editToken))
            $this->setEditToken();

		if ($this->hasApiAction('templatedata')) {
			$content = $this->templateValuesFilter($content);
		}

        $editToken = $this->editToken;
        $site      = $this->siteUrl;
        $url  = $site . "/api.php?format=xml&action=edit&title=" . urlencode($pageName);
		$params = array( "token" => urldecode( $editToken ), "assert" => "user" );
		$params['text'] = $content;
        if ($createonly)
			$params['createonly'] = 'true';
        if ($prepend)
			$params['prependtext'] = $content;
        if ($append)
			$params['appendtext'] = $content;
        if ($summary) {
			$params['summary'] = $summary;
		}
        if ($sectiontitle) {
			$params['sectiontitle'] = $sectiontitle;
			$params['section'] = 'new';
		} else if ($section !== false) {
			$params['section'] = $section;
		}
        //UNCOMMENT TO DEBUG TO STDOUT
        //print($url);

        $data = self::httpRequest($url, $params);

	//UNCOMMENT TO DEBUG TO STDOUT
	//print($data);

		if ($data == null) {
            return null;
		}

        $xml = simplexml_load_string($data);

        $apiError = self::errorHandler($xml, $url);
		if ($apiError) {
			return null;
		}

        return 1;
    }

	function upload( $filename, $filepath ) {
        assert(!empty($filename));
        assert(!empty($filepath));

        if (empty($this->editToken)) {
            $this->setEditToken();
		}

        $editToken = $this->editToken;
        $site      = $this->siteUrl;
        $url  = $site . "/api.php";
		$url .= "?action=upload&format=xml";

		if (function_exists('curl_file_create')) { // php 5.5+
		  $cFile = curl_file_create($filepath);
		} else { // 
		  $cFile = '@' . realpath($filepath);
		}

		$params = array( "token" => urldecode( $editToken ), "ignorewarnings" => 1, "filename" => urlencode($filename), "file" => $cFile );
        $data = self::httpRequest($url, $params, false, 0, "Content-Type: multipart/form-data");
		if ($data == null) {
            return null;
		}

        $xml = simplexml_load_string($data);
        $apiError = self::errorHandler($xml, $url);
		if ($apiError) {
			return null;
		}

        return 1;
	}

    function deleteByTitle($title) {
        if (empty($this->editToken))
            $this->setEditToken();

        $deleteToken = $this->editToken;
        $url         = $this->siteUrl . "/api.php?action=delete&format=xml";
        $params      = "action=delete&title=$title&token=$deleteToken&reason=Outdated";
        $data = self::httpRequest($url, $params);
        $xml = simplexml_load_string($data);
        self::errorHandler($xml, $url . $params);
    }

    function deleteById($id) {
        if (empty($this->editToken))
            $this->setEditToken();

        $deleteToken = $this->editToken;
        $url         = $this->siteUrl . "/api.php?action=delete&format=xml";
        $params      = "action=delete&pageid=$id&token=$deleteToken&reason=Outdated";
        $data = self::httpRequest($url, $params);
        $xml = simplexml_load_string($data);
        self::errorHandler($xml, $url . $params);
    }


	function getSections($pageName) {
        $url  = $this->siteUrl . "/api.php?format=xml&action=parse&page=$pageName&prop=sections";
        $data = self::httpRequest($url, $params = '');
        $xml  = simplexml_load_string($data);
        $expr       = "/api/parse/sections/s";
		$section_data = $xml->xpath($expr);
		$result = array();
		foreach($section_data as $data) {
			$result[(string)$data['line']]  = array( "number" => (string)$data['number'], "level" => (string)$data['level']);
		}
		return $result;
    }

	function getSectionHeader($sectionName, $sectionLevel) {
		return str_repeat("=", $sectionLevel) . $sectionName . str_repeat("=", $sectionLevel);
	}

	function insertBeginSection($pageName, $sectionName, $text, $changeReason = '') {
		$sections = $this->getSections($pageName);
		if (!array_key_exists($sectionName, $sections)) {
			return false;
		}
		$content = $this->readPage($pageName, $sections[$sectionName]['number']);
		$section_header = $this->getSectionHeader($sectionName, $sections[$sectionName]['level']);
		$content = str_replace($section_header, '', $content);
		$text .= $content;
		$text = $section_header . "\n" . $text;
		return $this->editPage($pageName, $text, false, false, false, $changeReason, $sections[$sectionName]['number']);
	}

	function insertEndSection($pageName, $sectionName, $text, $changeReason = '') {
		$sections = $this->getSections($pageName);
		if (!array_key_exists($sectionName, $sections)) {
			return false;
		}
		$content = $this->readPage($pageName, $sections[$sectionName]['number']);
		$section_header = $this->getSectionHeader($sectionName, $sections[$sectionName]['level']);
		$content = str_replace($section_header, '', $content);
		$text = $content . $text;
		$text = $section_header . "\n" . $text;
		return $this->editPage($pageName, $text, false, false, false, $changeReason, $sections[$sectionName]['number']);
	}

	function insertAfterSection($pageName, $sectionName, $text, $changeReason = '', $afterStr) {
		$sections = $this->getSections($pageName);
		if (!array_key_exists($sectionName, $sections)) {
			return false;
		}
		$content = $this->readPage($pageName, $sections[$sectionName]['number']);
		$section_header = $this->getSectionHeader($sectionName, $sections[$sectionName]['level']);
		$content = str_replace($section_header, '', $content);
		$parts = explode($afterStr, $content);
		$text = $parts[0] . $afterStr . $text . $parts[1] . $parts[2];
		$text = $section_header . "\n" . $text;
		return $this->editPage($pageName, $text, false, false, false, $changeReason, $sections[$sectionName]['number']);
	}

	function replaceSection($pageName, $sectionName, $text, $changeReason = '') {
		if (!array_key_exists($sectionName, $sections)) {
			return false;
		}
		$sections = $this->getSections($pageName);
		$section_header = $this->getSectionHeader($sectionName, $sections[$sectionName]['level']);
		$text = $section_header . "\n" . $text;
		return $this->editPage($pageName, $text, false, false, false, $changeReason, $sections[$sectionName]['number']);
	}

	private function translateInternalLink( $link_str ) {
		$link_parts = explode( '|', $link_str );
		$translated_link = $link_parts[0];

		if ( count( $link_parts ) == 2 ) {
			return $translated_link . '|' . $this->translateText( $link_parts[1] );
		}
		return $translated_link;
	}

	private function translateTemplateContents( $templateContent ) {
		$pos = strpos( $templateContent, '|' );
		$templateName = substr( $templateContent, 0, $pos );
		$templateParametersContent = substr( $templateContent, $pos + 1, strlen( $templateContent ) - ( $pos + 1 ) );

		$translatedTemplateContent = $templateName . '|' . $this->translateWikiText( $templateParametersContent, true );
		return $translatedTemplateContent;
	}

	private function translateText( $text ) {
		if ( empty( trim( $text ) ) ) {
			return $text;
		}

		$cache_dir = __DIR__ . '/.cache';
		if ( !is_dir( $cache_dir ) ) {
			mkdir( $cache_dir );
		}

		// trim text and then join the parts back as Google trims them
		$ltrimmed = ltrim( $text );

		$ltrim = '';
		if ( strlen( $text ) > strlen( $ltrimmed ) ) {
			$ltrim = substr( $text, 0, strlen( $text ) - strlen( $ltrimmed ) );
		}

		$rtrim = '';

		$rtrimmed = trim( $ltrimmed );
		if ( strlen( $ltrimmed ) > strlen( $rtrimmed ) ) {
			$rtrim = substr( $ltrimmed, strlen( $rtrimmed ), strlen( $ltrimmed ) - strlen( $rtrimmed ) );
		}

		$md5 = md5( $rtrimmed );
		$cache_file = $cache_dir . '/' . $md5;

		$ts_now = ( new \DateTime('NOW'))->getTimestamp();

		$translated_string = '';
		if ( file_exists( $cache_file ) && $ts_now - filemtime( $cache_file ) < 30 * 86400 ) {
			$translated_string = file_get_contents( $cache_file );
		} else {
			# Your Google Cloud Platform project ID
			$projectId = $this->googleTranslateProjectId;

			$translate = new TranslateClient([
				'projectId' => $projectId
			]);

			# The target language
			$target = $this->translateTo;

			# Translates some text into Russian
			$translation = $translate->translate($rtrimmed, [
				'target' => $target,
				'format' => 'text'
			]);

			$translated_string = $translation['text'];
			file_put_contents( $cache_file, $translated_string );
		}
		return $ltrim . $translated_string . $rtrim;
	}

	// TODO: DISPLAYTITLE, <includeonly>, etc

	// $templateContent: true if $content provided is content inside a template and parameter names should not be translated

	function translateWikiText( $content, $templateContent = false ) {
		assert( !empty( $this->googleTranslateProjectId ) );
		$translated_content = '';

		$len = strlen( $content );
		$curr_str = '';
		$state_deep = 0;
		$state_arr = array( 'CONTENT' );

		for ( $i = 0; $i < $len; $i++ ){

			if ( $content[$i] == "<" && $content[$i+1] == "!" && $state_arr[$state_deep] == 'CONTENT' ) {
				if ( $content[$i+2] == "-" && $content[$i+3] == "-" ) {
					$translated_content .= $this->translateText( $curr_str );
					$curr_str = '';
					$state_arr[] = 'COMMENTBEGIN';
					$state_deep++;
					$i = $i + 3;
					continue;
				}
			}

			if ( $content[$i] == "-" && $content[$i+1] == "-" && $state_arr[$state_deep] == 'COMMENTBEGIN' ) {
				if ( $content[$i+2] == ">" ) {
					$translated_content .=  "<!--" . $curr_str . "-->";
					$curr_str = '';

					array_pop( $state_arr );
					$state_deep--;
					$i = $i + 2;
					continue;
				}
			}

			if ( $content[$i] == "'" && $content[$i+1] == "'" && $state_arr[$state_deep] == 'CONTENT' ) {
				$translated_content .= $this->translateText( $curr_str );
				$curr_str = '';
				if ( $content[$i+2] == "'" && $content[$i+3] == "'" && $content[$i+4] == "'" ) {
					$state_arr[] = 'BOLDITALICBEGIN';
					$state_deep++;
					$i = $i + 4;
					continue;
				} else if ( $content[$i+2] == "'" ) {
					$state_arr[] = 'BOLDBEGIN';
					$state_deep++;
					$i = $i + 2;
					continue;
				} else {
					$state_arr[] = 'ITALICBEGIN';
					$state_deep++;
					$i = $i + 1;
					continue;
				}
			}

			if ( $content[$i] == "'" && $content[$i+1] == "'" && $state_arr[$state_deep] == 'BOLDITALICBEGIN' ) {
				$translated_content .=  "'''''" . $this->translateWikiText( $curr_str ) . "'''''";
				$curr_str = '';

				array_pop( $state_arr );
				$state_deep--;
				$i = $i + 4;
				continue;
			}
			if ( $content[$i] == "'" && $content[$i+1] == "'" && $state_arr[$state_deep] == 'BOLDBEGIN' ) {
				$translated_content .=  "'''" . $this->translateWikiText( $curr_str ) . "'''";
				$curr_str = '';

				array_pop( $state_arr );
				$state_deep--;
				$i = $i + 2;
				continue;
			}
			if ( $content[$i] == "'" && $content[$i+1] == "'" && $state_arr[$state_deep] == 'ITALICBEGIN' ) {
				$translated_content .=  "''" . $this->translateWikiText( $curr_str ) . "''";
				$curr_str = '';

				array_pop( $state_arr );
				$state_deep--;
				$i = $i + 1;
				continue;
			}

			if ( $content[$i] == "=" && $content[$i+1] == "=" && $state_arr[$state_deep] == 'CONTENT' ) {
				$translated_content .= $this->translateText( $curr_str );
				$curr_str = '';

				if ( $content[$i+2] == "=" && $content[$i+3] == "=" && $content[$i+4] == "=" ) {
					$state_arr[] = 'SEC5BEGIN';
					$state_deep++;
					$i = $i + 4;
					continue;
				} else if ( $content[$i+2] == "=" && $content[$i+3] == "=" ) {
					$state_arr[] = 'SEC4BEGIN';
					$state_deep++;
					$i = $i + 3;
					continue;
				} else if ( $content[$i+2] == "=" ) {
					$state_arr[] = 'SEC3BEGIN';
					$state_deep++;
					$i = $i + 2;
					continue;
				} else {
					$state_arr[] = 'SEC2BEGIN';
					$state_deep++;
					$i = $i + 1;
					continue;
				}
			}

			if ( $content[$i] == "=" && $content[$i+1] == "=" && $state_arr[$state_deep] == 'SEC5BEGIN' ) {
				$translated_content .=  "=====" . ucfirst( trim( $this->translateWikiText( $curr_str ) ) ) . "=====";
				$curr_str = '';

				array_pop( $state_arr );
				$state_deep--;
				$i = $i + 4;
				continue;
			}

			if ( $content[$i] == "=" && $content[$i+1] == "=" && $state_arr[$state_deep] == 'SEC4BEGIN' ) {
				$translated_content .=  "====" . ucfirst( trim( $this->translateWikiText( $curr_str ) ) ) . "====";
				$curr_str = '';

				array_pop( $state_arr );
				$state_deep--;
				$i = $i + 3;
				continue;
			}

			if ( $content[$i] == "=" && $content[$i+1] == "=" && $state_arr[$state_deep] == 'SEC3BEGIN' ) {
				$translated_content .=  "===" . ucfirst( trim( $this->translateWikiText( $curr_str ) ) ) . "===";
				$curr_str = '';

				array_pop( $state_arr );
				$state_deep--;
				$i = $i + 2;
				continue;
			}

			if ( $content[$i] == "=" && $content[$i+1] == "=" && $state_arr[$state_deep] == 'SEC2BEGIN' ) {
				$translated_content .=  "==" . ucfirst( trim( $this->translateWikiText( $curr_str ) ) ) . "==";
				$curr_str = '';

				array_pop( $state_arr );
				$state_deep--;
				$i = $i + 1;
				continue;
			}

			if ( $content[$i] == '[' && $state_arr[$state_deep] == 'CONTENT' ) {

				// Translate content accumulated so far
				$translated_content .= $this->translateText( $curr_str );
				$curr_str = '';

				$state_arr[] = 'LINKBEGIN';
				$state_deep++;
				continue;
			}

			// Internal Link Begin
			if ( $content[$i] == '[' && $state_arr[$state_deep] == 'LINKBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'INTERNALLINKBEGIN';
				continue;
			}

			// External Link End
			// No need to translate
			if ( $content[$i] == ']' && $state_arr[$state_deep] == 'LINKBEGIN' ) {
				array_pop( $state_arr );
				$state_deep--;
				$translated_content .= "[" . $curr_str . "]";
				$curr_str = '';
				continue;
			}

			// Internal Link End
			if ( $content[$i] == ']' && $state_arr[$state_deep] == 'INTERNALLINKBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'INTERNALLINKEND';
				continue;
			}

			if ( $content[$i] == ']' && $state_arr[$state_deep] == 'INTERNALLINKEND' ) {
				array_pop( $state_arr );
				$state_deep--;
				$translated_content .= "[[" . $this->translateInternalLink( $curr_str ) . "]]";
				$curr_str = '';
				continue;
			}

			if ( $content[$i] == '{' && $state_arr[$state_deep] == 'CONTENT' ) {

				// Translate content accumulated so far
				$translated_content .= $this->translateText( $curr_str );
				$curr_str = '';

				$state_arr[] = 'CURLYBEGIN';
				$state_deep++;
				continue;
			}

			if ( $content[$i] == '#'&& substr( $content, $i+1, 8 ) == 'REDIRECT' && $state_arr[$state_deep] == 'CONTENT' ) {
				$curr_str .= "#REDIRECT";
				$i = $i + 8;
				continue;
			}

			if ( $content[$i] == '{'&& $content[$i+1] == '#' && $state_arr[$state_deep] == 'CURLYBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'PARSERFUNCBEGIN';
				continue;
			}

			if ( $content[$i] == '{' && $state_arr[$state_deep] == 'CURLYBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'TEMPLATEBEGIN';
				continue;
			}

			// Handle nested templates
			if ( $content[$i] == '{' && in_array( $state_arr[$state_deep], array( 'PARSERFUNCBEGIN', 'TEMPLATEBEGIN' ) ) ) {
				$state_arr[] = 'NESTEDTEMPLATEBEGIN';
				$state_deep++;
				$curr_str .= $content[$i];
				continue;
			}
			if ( $content[$i] == '{' && $state_arr[$state_deep] == 'NESTEDTEMPLATEBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'NESTEDTEMPLATE';
				$curr_str .= $content[$i];
				continue;
			}
			if ( $content[$i] == '}' && $state_arr[$state_deep] == 'NESTEDTEMPLATE' ) {
				array_pop( $state_arr );
				$state_arr[] = 'NESTEDTEMPLATEEND';
				$curr_str .= $content[$i];
				continue;
			}
			if ( $content[$i] == '}' && $state_arr[$state_deep] == 'NESTEDTEMPLATEEND' ) {
				array_pop( $state_arr );
				$state_deep--;
				$curr_str .= $content[$i];
				continue;
			}

			if ( $content[$i] == '}' && $state_arr[$state_deep] == 'PARSERFUNCBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'PARSERFUNCEND';
				continue;
			}

			if ( $content[$i] == '}' && $state_arr[$state_deep] == 'PARSERFUNCEND' ) {
				array_pop( $state_arr );
				$state_deep--;
				$translated_content .= "{{#" . $curr_str . "}}";
				$curr_str = '';
				continue;
			}

			if ( $content[$i] == '}' && $state_arr[$state_deep] == 'TEMPLATEBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'TEMPLATEEND';
				continue;
			}

			if ( $content[$i] == '}' && $state_arr[$state_deep] == 'TEMPLATEEND' ) {
				array_pop( $state_arr );
				$state_deep--;

				if ( strpos( $curr_str, '|' ) !== false ) {
					$translated_content .= "{{" . $this->translateTemplateContents( $curr_str ) . "}}";
				} else {
					$translated_content .= "{{" . $curr_str . "}}";
				}

				$curr_str = '';
				continue;
			}

			if ( $content[$i] == '_' && $state_arr[$state_deep] == 'CONTENT' ) {
				$state_arr[] = 'UNDERSCBEGIN';
				$state_deep++;
				continue;
			}
			if ( $content[$i] != '_' && $state_arr[$state_deep] == 'UNDERSCBEGIN' ) {
				array_pop( $state_arr );
				$state_deep--;

				// We didn't add this before so add now
				$curr_str .= '_';
			}

			if ( $content[$i] == '_' && $state_arr[$state_deep] == 'UNDERSCBEGIN' ) {
				// Translate content accumulated so far
				$translated_content .= $this->translateText( $curr_str );
				$curr_str = '';

				array_pop( $state_arr );
				$state_arr[] = 'MAGICBEGIN';
				continue;
			}
			if ( $content[$i] == '_' && $state_arr[$state_deep] == 'MAGICBEGIN' ) {
				array_pop( $state_arr );
				$state_arr[] = 'MAGICEND';
			}
			if ( $content[$i] == '_' && $state_arr[$state_deep] == 'MAGICEND' ) {
				array_pop( $state_arr );
				$state_deep--;
				$translated_content .= "__" . $curr_str . "__";
				$curr_str = '';
				continue;
			}

			if ( $templateContent && $state_arr[$state_deep] == 'CONTENT' && in_array( $content[$i], array( '|', '=' ) ) ) {
				if ( $content[$i] == '=' ) { //Its a parameter name of a template
					$translated_content .= $curr_str . '=';
					$curr_str = '';
				} else if ( $content[$i] == '|' ) {
					$translated_content .= $this->translateText( $curr_str ) . '|';
					$curr_str = '';
				}
				continue;
			}

			// Reached here means add it to curr_str
			$curr_str .= $content[$i];
		}
		$translated_content .= $this->translateText( $curr_str );

		return $translated_content;
	}

	public static function httpRequest($url, $post = "", $retry = false, $retryNumber = 0, $headers = array()) {
		sleep(3);
		global $settings;

		try {
			$ch = curl_init();
			//Change the user agent below suitably
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.9) Gecko/20071025 Firefox/2.0.0.9');
			if (isset( $settings['serverAuth'] )) {
				curl_setopt($ch, CURLOPT_USERPWD, $settings['AuthUsername'] . ":" . $settings['AuthPassword']);
			}
			curl_setopt($ch, CURLOPT_URL, ($url));
			curl_setopt($ch, CURLOPT_ENCODING, "UTF-8");
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_COOKIEFILE, $settings['cookiefile']);
			curl_setopt($ch, CURLOPT_COOKIEJAR, $settings['cookiefile']);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_COOKIESESSION, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			if (!empty($post)) {
//				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
			}
			// UNCOMMENT TO DEBUG TO output.tmp
			// curl_setopt($ch, CURLOPT_VERBOSE, true); // Display communication with server
			// $fp = fopen("output.tmp", "w");
			// curl_setopt($ch, CURLOPT_STDERR, $fp); // Display communication with server
			if (!empty($headers))
				curl_setopt($ch, CURLOPT_HTTPHEADER, (array)$headers);
			$xml = curl_exec($ch);
			if (!$xml) {
				throw new \Exception("Error getting data from server: " . curl_error($ch));
			}

			curl_close($ch);
			//UNCOMMENT TO DEBUG TO output.tmp
			// fclose($fp);
		}
		catch (Exception $e) {
			echo 'Caught exception: ', $e->getMessage(), "\n";
			if (!$retry && $retryNumber < 3) {
				echo "Retrying \n";
				return self::httpRequest($url, $post, true, $retryNumber++);
			} else {
				echo "Could not perform action after 3 attempts. Skipping now...\n";
				return null;
			}
		}
		return $xml;
	}

	public static function download($url, $file_target) {
		global $settings;
		$fp = fopen($file_target, 'w+'); //This is the file where we save the information
		$ch = curl_init(str_replace(" ", "%20", $url)); //Here is the file we are downloading, replace spaces with %20
		if ($settings['serverAuth']) {
			curl_setopt($ch, CURLOPT_USERPWD, $settings['AuthUsername'] . ":" . $settings['AuthPassword']);
		}
		curl_setopt($ch, CURLOPT_URL, ($url));
		//	curl_setopt( $ch, CURLOPT_ENCODING, "UTF-8" );
		//	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_COOKIEFILE, $settings['cookiefile']);
		curl_setopt($ch, CURLOPT_COOKIEJAR, $settings['cookiefile']);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_TIMEOUT, 50);
		curl_setopt($ch, CURLOPT_FILE, $fp); // write curl response to file
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		$xml = curl_exec($ch); // get curl response
		curl_close($ch);
		fclose($fp);
		return $xml;
	}

	public static function errorHandler($xml, $url = '') {
		if (property_exists($xml, 'error')) {
			$errors = is_array($xml->error) ? $xml->error : array(
				$xml->error
			);
			foreach ($errors as $error) {
				echo "Error code: " . $error['code'] . " " . $error['info'] . "\n";
			}
			if ($url != '') {
			   echo "URL: $url \n\n\n";
			}
			return true;
		}
		if (property_exists($xml, 'warnings')) {
		  $warnings = is_array($xml->warnings) ? $xml->warnings : array(
			   $xml->warnings
		  );
		  foreach ($warnings as $warning) {
			if (property_exists($warning, 'info')) {
			  $infos = (array)$warning->info;
			  foreach($infos as $info)
				if (!empty($info))
				echo "Warning: " . $info . "\n";
			}
		  }
		}
		return false;
	}

	public static function dieq() {
			foreach ( func_get_args() as $arg ) {
					var_dump( $arg );
					echo "\n";
			}
			die('.');
	}
}