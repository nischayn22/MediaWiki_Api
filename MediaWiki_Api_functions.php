<?php
global $settings;
$settings['cookiefile'] = "cookies.tmp";

class MediaWikiApi {
	private $editToken;
	private $siteUrl;
	private $logggedIn = false;

	function MediaWikiApi( $siteUrl ) {
		assert( !empty( $siteUrl ) );
		$this->siteUrl = $siteUrl;
	}

	function login( $user, $pass ) {

		try {
			$token = $this->_login( $user, $pass );
			$token = $this->_login( $user, $pass, $token );
			return true;
		} catch ( Exception $e ) {
			die( "FAILED: " . $e->getMessage() . "\n" );
		}
	}

	private function _login( $user, $pass, $token = '' ) {

		assert( !empty( $this->siteUrl ) );
		assert( !empty( $user ) );
		assert( !empty( $pass ) );
		$url = $this->siteUrl . "/api.php?action=login&format=xml";

		$params = "action=login&lgname=$user&lgpassword=$pass";
		if ( !empty( $token ) ) {
			$params .= "&lgtoken=$token";
		}

		$data = httpRequest( $url, $params );

		if ( empty( $data ) ) {
			throw new Exception( "No data received from server. Check that API is enabled." );
		}

		$xml = simplexml_load_string( $data );
		if ( !empty( $token ) ) {
			//Check for successful login
			$expr = "/api/login[@result='Success']";
			$result = $xml->xpath( $expr );

			if ( !count( $result ) ) {
				throw new Exception( "Reason :" .
				$xml->xpath( "/api/login[@result='WrongPass']" )->attributes()->result );
			}
		} else {
			$expr = "/api/login[@token]";
			$result = $xml->xpath( $expr );

			if ( !count( $result ) ) {
				throw new Exception( "Login token not found in XML" );
			}
		}

		return $result[0]->attributes()->token;
	}

	function setEditToken() {
		$url = $this->siteUrl .
			"/api.php?format=xml&action=query&titles=Main_Page&prop=info|revisions&intoken=edit";
		$data = httpRequest( $url, $params = '' );
		$xml = simplexml_load_string( $data );
		$this->editToken = urlencode( (string) $xml->query->pages->page['edittoken'] );
		errorHandler( $xml );
		return $this->editToken;
	}

	function listPageInNamespace( $namespace ) {

		// Hope this limit is enough large that we don't have the trouble
		// to do this again and again using 'continue'
		$url = $this->siteUrl .
			"/api.php?action=query&list=allpages&format=xml&apnamespace=$namespace&aplimit=10000";
		$data = httpRequest( $url, $params = '' );
		$xml = simplexml_load_string( $data );
		$expr = "/api/query/allpages/p";
		$result = $xml->xpath( $expr );
		return $result;
	}

	function listPageInCategory( $category ) {
		$category = urlencode( $category );
		$url = $this->siteUrl .
			"/api.php?format=xml&action=query&cmtitle=$category&list=categorymembers&cmlimit=10000";
		$data = httpRequest( $url, $params = '' );
		$xml = simplexml_load_string( $data );
		errorHandler( $xml );
		//fetch category pages and call them recursively
		$expr = "/api/query/categorymembers/cm";
		return $xml->xpath( $expr );
	}

	function getFileUrl( $pageName ) {
		$url = $this->siteUrl .
			"/api.php?action=query&titles=$pageName&prop=imageinfo&iiprop=url&format=xml";
		$data = httpRequest( $url, $params = '' );
		$xml = simplexml_load_string( $data );
		$expr = "/api/query/pages/page/imageinfo/ii";
		$imageInfo = $xml->xpath( $expr );
		$rawFileURL = $imageInfo[0]['url'];
		return (string) $imageInfo[0]['url'];
	}

	/**
	 *
	 * @param string $pageName
	 * @return string Wikitext
	 */
	function readPage( $pageName ) {
		return file_get_contents( $this->siteUrl . '/index.php?title=' .
			urlencode( $pageName ) . '&action=raw' );
	}

	function createPage( $pageName, $content ) {
		return $this->editPage( $pageName, $content, true );
	}

	function editPage( $pageName, $content, $createonly = false, $prepend = false, $append = false ) {
		assert( !empty( $pageName ) );
		assert( !empty( $content ) );

		if ( empty( $this->editToken ) ) $this->setEditToken();

		$editToken = $this->editToken;
		$site = $this->siteUrl;
		$content = urlencode( $content );
		$pageName = urlencode( $pageName );
		$url = $site . "/api.php?format=xml&action=edit&title=$pageName";
		if ( $createonly ) $url .= "&createonly=true";
		if ( $prepend ) $url .= "&prependtext=true";
		if ( $append ) $url .= "&appendtext=true";
		$data = httpRequest( $url,
			$params = "format=xml&action=edit&title=$pageName&text=$content&token=$editToken" );

		$xml = simplexml_load_string( $data );
		errorHandler( $xml, $url . $params );

		if ( $data == null ) return null;
		else return 1;
	}

	function deleteByTitle( $title ) {
		if ( empty( $this->editToken ) ) $this->setEditToken();

		$deleteToken = $this->editToken;
		$url = $this->siteUrl . "/api.php?action=delete&format=xml";
		$params = "action=delete&title=$title&token=$deleteToken&reason=Outdated";
		$data = httpRequest( $url, $params );
		$xml = simplexml_load_string( $data );
		errorHandler( $xml, $url . $params );
	}

	function deleteById( $id ) {
		if ( empty( $this->editToken ) ) $this->setEditToken();

		$deleteToken = $this->editToken;
		$url = $this->siteUrl . "/api.php?action=delete&format=xml";
		$params = "action=delete&pageid=$id&token=$deleteToken&reason=Outdated";
		$data = httpRequest( $url, $params );
		$xml = simplexml_load_string( $data );
		errorHandler( $xml, $url . $params );
	}

	/**
	 * Do an action and post the parameter string
	 * @todo The other action functions should use this somehow.
	 *
	 * @param string $action
	 * @param string|array $paramString Parameters without a token
	 * @param string $format
	 * @return mixed
	 */
	function doAction( $action, $paramString, $format = 'xml' ) {
		if ( is_array( $paramString ) ) {
			$paramString = http_build_query( $paramString );
		}

		if ( empty( $this->editToken ) ) {
			$this->setEditToken();
		}
		$actionToken = $this->editToken;
		$url = $this->siteUrl . "/api.php?action=$action&format=$format";
		$params = "action=$action&token=$actionToken&" . $paramString;
		$data = httpRequest( $url, $params );
		$xml = simplexml_load_string( $data );
		errorHandler( $xml, $url . $params );

		return $data;
	}
}

function httpRequest( $url, $post = "", $retry = false, $retryNumber = 0, $headers = array() ) {
	global $settings;

	try {
		$ch = curl_init();
		//Change the user agent below suitably
		curl_setopt( $ch, CURLOPT_USERAGENT,
			'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.9) Gecko/20071025 Firefox/2.0.0.9' );
		if ( isset( $settings['serverAuth'] ) && $settings['serverAuth'] ) {
			curl_setopt( $ch, CURLOPT_USERPWD, $settings['AuthUsername'] . ":" . $settings['AuthPassword'] );
		}
		curl_setopt( $ch, CURLOPT_URL, ($url ) );
		curl_setopt( $ch, CURLOPT_ENCODING, "UTF-8" );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_COOKIEFILE, $settings['cookiefile'] );
		curl_setopt( $ch, CURLOPT_COOKIEJAR, $settings['cookiefile'] );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		if ( !empty( $post ) ) curl_setopt( $ch, CURLOPT_POSTFIELDS, $post );
		//UNCOMMENT TO DEBUG TO output.tmp
		//curl_setopt($ch, CURLOPT_VERBOSE, true); // Display communication with server
		//$fp = fopen("output.tmp", "w");
		//curl_setopt($ch, CURLOPT_STDERR, $fp); // Display communication with server
		if ( !empty( $headers ) ) curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		$xml = curl_exec( $ch );

		if ( !$xml ) {
			throw new Exception( "Error getting data from server: " . curl_error( $ch ) );
		}

		curl_close( $ch );
	} catch ( Exception $e ) {
		echo 'Caught exception: ', $e->getMessage(), "\n";
		if ( !$retry && $retryNumber < 3 ) {
			echo "Retrying \n";
			httpRequest( $url, $post, true, $retryNumber++ );
		} else {
			echo "Could not perform action after 3 attempts. Skipping now...\n";
			return null;
		}
	}
	return $xml;
}

function download( $url, $file_target ) {
	global $settings;
	$fp = fopen( $file_target, 'w+' ); //This is the file where we save the information
	//Here is the file we are downloading, replace spaces with %20
	$ch = curl_init( str_replace( " ", "%20", $url ) );
	if ( $settings['serverAuth'] ) {
		curl_setopt( $ch, CURLOPT_USERPWD, $settings['AuthUsername'] . ":" . $settings['AuthPassword'] );
	}
	curl_setopt( $ch, CURLOPT_URL, ($url ) );
	//	curl_setopt( $ch, CURLOPT_ENCODING, "UTF-8" );
	//	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt( $ch, CURLOPT_COOKIEFILE, $settings['cookiefile'] );
	curl_setopt( $ch, CURLOPT_COOKIEJAR, $settings['cookiefile'] );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 50 );
	curl_setopt( $ch, CURLOPT_FILE, $fp ); // write curl response to file
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
	$xml = curl_exec( $ch ); // get curl response
	curl_close( $ch );
	fclose( $fp );
	return $xml;
}

function errorHandler( $xml, $url = '' ) {
	if ( property_exists( $xml, 'error' ) ) {
		$errors = is_array( $xml->error ) ? $xml->error : array(
			$xml->error
		);
		foreach ( $errors as $error ) {
			echo "Error code: " . $error['code'] . " " . $error['info'] . "\n";
		}
		if ( $url != '' ) echo "URL: $url \n\n\n";
	} elseif ( property_exists( $xml, 'warnings' ) ) {
		$warnings = is_array( $xml->warnings ) ? $xml->warnings : array(
			$xml->warnings
		);
		foreach ( $warnings as $warning ) {
			if ( property_exists( $warning, 'info' ) ) {
				$infos = (array) $warning->info;
				foreach ( $infos as $info ) if ( !empty( $info ) ) echo "Warning: " . $info . "\n";
			}
		}
	}
}
