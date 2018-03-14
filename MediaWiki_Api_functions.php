<?php
 ini_set('display_errors', 1); 
 error_reporting(E_ALL);

global $settings;
$settings['cookiefile'] = "cookies.tmp";

class MediaWikiApi {

    private $editToken;

    private $siteUrl;

    private $logggedIn = false;

	private $templateData = array();

    function MediaWikiApi($siteUrl) {
        assert(!empty($siteUrl));
        $this->siteUrl = $siteUrl;
    }

    function login($user, $pass) {

        try {
            $token = $this->getToken();
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
        $data = httpRequest($url);
        if (empty($data)) {
            throw new Exception("No data received from server. Check that API is enabled.");
        }

        $xml = simplexml_load_string($data);
		$expr   = "/api/query/tokens";
		$result = $xml->xpath($expr);
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

        $data = httpRequest($url, $params);

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
        $data = httpRequest($url, $params);
    }

    function setEditToken() {
        $url    = $this->siteUrl . "/api.php?format=xml&action=query&meta=tokens&assert=user";
        $data   = httpRequest($url, $params = '');
        $xml    = simplexml_load_string($data);
        $expr   = "/api/query/tokens[@csrftoken]";
        $result = $xml->xpath($expr);
        $this->editToken = urlencode($result[0]->attributes()->csrftoken);
	//UNCOMMENT TO DEBUG TO STDOUT
	//print($this->editToken);
        errorHandler($xml);
        return $this->editToken;
    }

    function hasApiAction($action) {
        $url    = $this->siteUrl . "/api.php?format=xml&action=$action";
        $data   = httpRequest($url, $params = '');
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
        $data   = httpRequest($url, $params = '');
        $xml    = simplexml_load_string($data);
        $expr   = "/api/query/allpages/p";
        $result = $xml->xpath($expr);
        return $result;
    }

    function listPageInCategory($category) {
        $category = urlencode( $category );
        $url      = $this->siteUrl . "/api.php?format=xml&action=query&cmtitle=$category&list=categorymembers&cmlimit=10000";
        $data     = httpRequest($url, $params = '');
        $xml      = simplexml_load_string($data);
        errorHandler($xml);
        //fetch category pages and call them recursively
        $expr = "/api/query/categorymembers/cm";
        return $xml->xpath($expr);
    }

    function listImagesOnPage($pageName) {
        // Returns a list with all image pages this page links to
        $pageName   = urlencode($pageName);
        $url        = $this->siteUrl . "/api.php?format=xml&action=query&prop=images&titles=$pageName&imlimit=1000";
        $data       = httpRequest( $url );
        $xml        = simplexml_load_string($data);
        errorHandler($xml);
        //fetch image Links and copy them as well
        $expr = "/api/query/pages/page/images/im";
        return $xml->xpath($expr);
    }

    function getFileUrl($pageName) {
        $pageName   = urlencode( $pageName );
        $url        = $this->siteUrl . "/api.php?action=query&titles=$pageName&prop=imageinfo&iiprop=url&format=xml";
        $data       = httpRequest($url, $params = '');
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

        $data = httpRequest($url, $params = '');

        //UNCOMMENT TO DEBUG TO STDOUT
        //print($data);

        $xml  = simplexml_load_string($data);
        errorHandler($xml);
        return (string) $xml->query->pages->page->revisions->rev;
    }

    function createPage($pageName, $content) {
        return $this->editPage($pageName, $content, true);
    }

	function fetchTemplateData( $templateName ) {
		$arguments = array();

        $url    = $this->siteUrl . "/api.php?format=xml&action=templatedata&titles=Template:$templateName";
        $data   = httpRequest($url, $params = '');
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
		$url .= "&text=" . urlencode($content);
        if ($createonly)
            $url .= "&createonly=true";
        if ($prepend)
            $url .= "&prependtext=" . urlencode($content);
        if ($append)
            $url .= "&appendtext=" . urlencode($content);
        if ($summary) {
            $url .= "&summary=" . urlencode($summary);
		}
        if ($sectiontitle) {
            $url .= "&sectiontitle=" . urlencode($sectiontitle);
            $url .= "&section=new";
		} else if ($section !== false) {
            $url .= "&section=" . urlencode($section);
		}
        //UNCOMMENT TO DEBUG TO STDOUT
        //print($url);

        $data = httpRequest($url, $params = "format=xml&action=edit&title=". urlencode($pageName) ."&token=$editToken&assert=user");

	//UNCOMMENT TO DEBUG TO STDOUT
	//print($data);

		if ($data == null) {
            return null;
		}

        $xml = simplexml_load_string($data);
        $apiError = errorHandler($xml, $url . $params);
		if ($apiError) {
			if (!$retry && $retryNumber < 3) {
	            echo "Retrying \n";
				return $this->editPage($pageName, $content, $createonly, $prepend, $append, $summary, $section, $sectiontitle, $retryNumber++);
			}
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
        $data = httpRequest($url, $params);
        $xml = simplexml_load_string($data);
        errorHandler($xml, $url . $params);
    }

    function deleteById($id) {
        if (empty($this->editToken))
            $this->setEditToken();

        $deleteToken = $this->editToken;
        $url         = $this->siteUrl . "/api.php?action=delete&format=xml";
        $params      = "action=delete&pageid=$id&token=$deleteToken&reason=Outdated";
        $data = httpRequest($url, $params);
        $xml = simplexml_load_string($data);
        errorHandler($xml, $url . $params);
    }


	function getSections($pageName) {
        $url  = $this->siteUrl . "/api.php?format=xml&action=parse&page=$pageName&prop=sections";
        $data = httpRequest($url, $params = '');
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

}


function httpRequest($url, $post = "", $retry = false, $retryNumber = 0, $headers = array()) {
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
        if (!empty($post))
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        //UNCOMMENT TO DEBUG TO output.tmp
        // curl_setopt($ch, CURLOPT_VERBOSE, true); // Display communication with server
        // $fp = fopen("output.tmp", "w");
        // curl_setopt($ch, CURLOPT_STDERR, $fp); // Display communication with server
        if (!empty($headers))
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $xml = curl_exec($ch);

        if (!$xml) {
            throw new Exception("Error getting data from server: " . curl_error($ch));
        }

        curl_close($ch);
        //UNCOMMENT TO DEBUG TO output.tmp
        // fclose($fp);
    }
    catch (Exception $e) {
        echo 'Caught exception: ', $e->getMessage(), "\n";
        if (!$retry && $retryNumber < 3) {
            echo "Retrying \n";
            return httpRequest($url, $post, true, $retryNumber++);
        } else {
            echo "Could not perform action after 3 attempts. Skipping now...\n";
            return null;
        }
    }
    return $xml;
}

function download($url, $file_target) {
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


function errorHandler($xml, $url = '') {
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
function dieq() {
        foreach ( func_get_args() as $arg ) {
                var_dump( $arg );
                echo "\n";
        }
        die('.');
}
