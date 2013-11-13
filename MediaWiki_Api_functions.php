<?php

global $settings;
$settings['cookiefile'] = "cookies.tmp";

class MediaWikiApi{

      private $editToken;

      private $siteUrl;

      private $logggedIn = false;

      function MediaWikiApi($siteUrl){
               assert(!empty($siteUrl));
	       $this->siteUrl = $siteUrl;
      }

      function login($user, $pass){

               try {
                   $token = $this->_login($user,$pass);
                   $token = $this->_login($user,$pass, $token);
                   return true;
               } catch (Exception $e) {
                   die("FAILED: " . $e->getMessage() . "\n");
               }
       }

private function _login ( $user, $pass, $token='') {

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
		$expr = "/api/login[@result='Success']";
		$result = $xml->xpath($expr);

		if(!count($result)) {
			throw new Exception("Login failed");
		}
	} else {
		$expr = "/api/login[@token]";
		$result = $xml->xpath($expr);

		if(!count($result)) {
			throw new Exception("Login token not found in XML");
		}
	}

	return $result[0]->attributes()->token;
}


      function setEditToken( ){
      	       $url = $this->siteUrl . "/api.php?format=xml&action=query&titles=Main_Page&prop=info|revisions&intoken=edit";
	       $data = httpRequest($url, $params = '');
	       $xml = simplexml_load_string($data);
	       $this->editToken =  urlencode( (string)$xml->query->pages->page['edittoken'] );
	       return $this->editToken;
      }
	
function listPageInNamespace($namespace){

         // Hope this limit is enough large that we don't have the trouble to do this again and again using 'continue'

	$url = $this->siteUrl . "/api.php?action=query&list=allpages&format=xml&apnamespace=$namespace&aplimit=10000"; 
	$data = httpRequest($url, $params = '');
	$xml = simplexml_load_string($data);
	$expr = "/api/query/allpages/p";
	$result = $xml->xpath($expr);
	return $result;
}

function createPage($pageName, $content){
         $this->editPage($pageName, $content, true);
}

function editPage( $pageName, $content, $createonly = false, $prepend = false, $append = false){
        assert(!empty($pageName));
        assert(!empty($content));

	if (empty($this->editToken))
	   $this->setEditToken();

	$editToken = $this->editToken;
	$site = $this->siteUrl;
	$content = urlencode( $content );
	$url = $site . "/api.php?format=xml&action=edit&title=$pageName";
        if($createonly)
                $url .= "&createonly=true";
        if($prepend)
                $url .= "&prependtext=true";
        if($append)
                $url .= "&appendtext=true";
	$data = httpRequest($url, $params = "format=xml&action=edit&title=$pageName&text=$content&token=$editToken");

	$xml = simplexml_load_string($data);
	errorHandler( $xml );

	if ($data == null)
	   return null;
	else
	   return 1;
}

      
function deleteById( $id ){
	 $deleteToken = $this->editToken;
	$url = $this->siteUrl . "/api.php?action=delete&format=xml";
	$params = "action=delete&pageid=$pageid&token=$deleteToken&reason=Outdated";
	httpRequest($url, $params);
	// Nothing to do with response currently
	// $data = httpRequest($url, $params);
	// $xml = simplexml_load_string($data);
}


}


function httpRequest($url, $post="", $retry = false, $retryNumber = 0, $headers = array()) {
	global $settings;

	try {
		$ch = curl_init();
		//Change the user agent below suitably
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.9) Gecko/20071025 Firefox/2.0.0.9');
		if( $settings['serverAuth'] ) {
			curl_setopt($ch, CURLOPT_USERPWD, $settings['AuthUsername'] . ":" . $settings['AuthPassword']);
		}
		curl_setopt($ch, CURLOPT_URL, ($url));
		curl_setopt( $ch, CURLOPT_ENCODING, "UTF-8" );
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_COOKIEFILE, $settings['cookiefile']);
		curl_setopt($ch, CURLOPT_COOKIEJAR, $settings['cookiefile']);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);                
		if (!empty($post)) curl_setopt($ch,CURLOPT_POSTFIELDS,$post);
		//UNCOMMENT TO DEBUG TO output.tmp
		//curl_setopt($ch, CURLOPT_VERBOSE, true); // Display communication with server
		//$fp = fopen("output.tmp", "w");
		//curl_setopt($ch, CURLOPT_STDERR, $fp); // Display communication with server
                if(!empty($headers))
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		$xml = curl_exec($ch);

		if (!$xml) {
			throw new Exception("Error getting data from server: " . curl_error($ch));
		}

		curl_close($ch);
	} catch( Exception $e ) {
		echo 'Caught exception: ',  $e->getMessage(), "\n";
		if( !$retry && $retryNumber <3 ) {
			echo "Retrying \n";
			httpRequest($url, $post, true, $retryNumber++ );
		} else {
			echo "Could not perform action after 3 attempts. Skipping now...\n";
			return null;
		}
	}
	return $xml;
}

function download($url, $file_target) {
	global $settings;
	$fp = fopen ( $file_target, 'w+');//This is the file where we save the information
	$ch = curl_init(str_replace(" ","%20",$url));//Here is the file we are downloading, replace spaces with %20
	if( $settings['serverAuth'] ) {
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


function errorHandler( $xml ){
	if( property_exists( $xml, 'error' ) ) {
		$errors = is_array( $xml->error )? $xml->error : array( $xml->error );
		foreach( $errors as $error ) {
			echo "Error code: " . $error['code'] . " " . $error['info'] . "\n";
		}
	}
}
