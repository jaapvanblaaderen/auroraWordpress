<?php

// 
// -wwinception-
// version 0.2
// 
// interface to receive or get WoodWing Inception articles and store them in wordpress
//
// required wordpress plugin:
// - https://wordpress.org/plugins/auto-iframe/
// - https://wordpress.org/plugins/search-everything/
// 
// Change history
// 0.2 - moved parsing/handling of aurora/inception message to aurora class
// 0.2 - extract 'hero' component from article and add as feature image
// 0.2 - extract part of text as excerpt
// 0.2 - use external-id as reference for detecting update
//
// --------------------------------------------------

// ----------------------
// Configuration settings
// ----------------------


// tempdir required to download zipfile , folder must be RW to Apache/IIs 
// 
define( 'TEMPDIR'  , __DIR__ . '/temp/');

// if you want logging, specify path to writable log folder
define( 'LOGPATH'  , dirname(__FILE__) . '/wwlog/'); // including ending '/'
define( 'LOGPATHWITHIP', false);

// if you want to run from local server, specify the URL to the 
// AWSSNS subserver, leave empty to disable subserver functionality
define( 'AWSSNSURL' , '' );
define( 'SUBKEY'	, 'wvr-localhost');

// --------------------------------------------------
// if you want to finetune the logging,
// you might consider to play with the settings below
// Normally no changes are required
// --------------------------------------------------

// specify the name of the logfile, normally this is the name of the script
define( 'LOGNAME', basename(__FILE__) );


define( 'LOG_ALL',true); // if true, everything will be logged, 
                        // if false, only IP's listed will be logged
                       


// define IP-addresses to log, only the specified IP-addresses will be logged
define( 'LOG_IP', serialize( array('localhost',
                                   )
                            ) );   


// see : http://php.net/manual/en/timezones.asia.php
ini_set( 'date.timezone', 'Europe/Amsterdam');


//Lenght of the excerpt in characters 
define( 'MAX_EXCERPT_LENGTH', 250 );

// ========================================
// take care the php problems are reported
// ========================================
error_reporting(E_ALL);
ini_set('display_errors','On');
ini_set ('error_log', LOGPATH . 'php.log');
set_error_handler( 'ErrorHandler' );

function ErrorHandler( $errno, $errmsg, $file, $line, $debug )
{
   MyLog("ERROR in PHP: Errno:$errno  errMsg[$errmsg] $file:$line");
}


// -----------------------------
// load the wordpress frame work
// -----------------------------
require_once(dirname(__FILE__) . '/wp-config.php');
$wp->init();
$wp->parse_request();
$wp->query_posts();
$wp->register_globals();
$wp->send_headers();
require_once( ABSPATH . 'wp-admin/includes/file.php' );
require_once( ABSPATH . 'wp-admin/includes/post.php');
// -----------------------------




// this script can run in two modes:
// mode-1)
// if no POST data, it wil (try to) connect to the server specified on AWSSNSURL
// and load the file data from there, this is usefull if this wordpress can not be 
// reached by the AWS-SNS service (local machine)
//
// mode-2)
// if postdata is received, it is expected to be in the AWS-SNS message format
// the sns message data will be used to update wordpress.


// main check to see if any postdata is available
// Input socket
$inputSocket = fopen('php://input','rb');
$rawrequest = stream_get_contents($inputSocket);
fclose($inputSocket);

MyLog('=================================');
MyLog('=================================');
// see if there are GET parameters
MyLog('GET parameters:' . print_r($_GET,1));
MyLog('=================================');

// chck if we run in 'test-config' mode
if (isset ($_GET['testconfig']))
{
	define( 'EOL' , "<br>");
	MyLog("test-mode");
	checkSetup();
	exit;
}






//print ('rawrequest [' . print_r($rawrequest,1) . ']');
if ( $rawrequest == '' )
{
	define( 'EOL' , "<br>");
	MyLog ( "No POST-request data found, running in sub-server mode");
	MyLog ( "SubServer [" . AWSSNSURL . "]");
	if ( AWSSNSURL == '' ){
		MyLog( "No subserver defined, nothing to do");
	}
	else
	{	
		// get the files , if passing a true, then ALL files will be loaded
		// otherwise only newer files
		getFiles(false);
	}	
} else {
	define( 'EOL' , "\n");
	MyLog( "POST-request received, attempt to parse.." );
	handleAWSSNSmessage($rawrequest);
}

print "OK, see logfile for more info<br>";


// --------------------------------------------
// -- functions below this line ---------------
// --------------------------------------------

function checkSetup()
{
	print "WW-inception connector" .EOL;
	print "----------------------" .EOL;
	print "Checking setup" . EOL;
	print " - Check temp folder...";
	
	if (!file_exists( TEMPDIR)) 
	{ print "failed: TempFolder [" . TEMPDIR . "] does not exists, please create" . EOL; exit;}
	
	if (! is_writable( TEMPDIR )) 
	{ print "failed: TempFolder [" . TEMPDIR . "] is not writable, please add correct access rights" . EOL; exit;}
	
	print "OK" . EOL . EOL;
	print " - Check Log folder...";
	if (!file_exists( LOGPATH)) 
	{ print "failed: Log Folder [" . LOGPATH . "] does not exists, please create" . EOL; exit;}
	
	if (! is_writable( LOGPATH )) 
	{ print "failed: Log Folder [" . LOGPATH . "] is not writable, please add correct access rights" . EOL; exit;}
	
	print "OK" . EOL . EOL;
	
	
	print "Check SubServer..." .EOL;;
	if ( AWSSNSURL == '' )
	{ print " - Setting for SUBSERVER (AWSSNSURL) is empty, this configuration can only receive push from Inception".EOL;;}
	else
	{ print " - Setting for SUBSERVER (AWSSNSURL) found, run this script to collect articles from [" . AWSSNSURL . "]".EOL;;}
	
}




function handleAWSSNSmessage($rawrequest)
{
	$request = json_decode( $rawrequest, true );
	MyLog ('request [' . print_r($request,1) . ']');
  
	if ( $request['Type'] == 'SubscriptionConfirmation')
	{
	  MyLog ('Handle SubscriptionConfirmation' );
	  $filedata = file_get_contents( $request['SubscribeURL'] );
	  MyLog ('data:' . $filedata );
	}

	if ( $request['Type'] == 'Notification' )
	{
 
	  $topicARN = $request['TopicArn'];
	  MyLog ('Handle Notification from ARN:' . $topicARN );
	   if ( strpos($topicARN, 'inception') > 0 ||
	  	   strpos($topicARN, 'ecs-export-topic' ) > 0)
	   {	
		  MyLog ('Found inception or aurora ARN');
		  $message = json_decode($request['Message']);
		  
		  if (isset($_GET['iframe'])){
			  MyLog('Iframe option detected');
			  $message->iframe = 'true';
		  }
		  
		  MyLog (' handling message:' . print_r($message,1));
		  
		  // we use the publish system ID as unique key,
		  // for Inception this key is an elvis-ID
		  // for Aurora this key is an enterprise-ID
		  $storyId = $message->tenantId . '-' . $message->id;
		  
		  MyLog (' storyId: ' . $storyId );
	  		
	  	  if (	isset($message->iframe) &&
				$message->iframe == 'true')
			{
				upsertWPIframe( $message, $storyId );
			}
			else
			{	
				upsertWPArticle( $message );
			}   	
		 
		  MyLog ( "done" );
	  
	  }
	}
}




function getFiles($allFiles = false)
{
   
    if ($allFiles){
    	 MyLog ( "Getting All files" );
		$files = getDataFromUrl( AWSSNSURL , json_encode(array('Type'=>'getAllFiles','Caller' => SUBKEY )));
	}else{
		 MyLog ( "Getting New files" );
		$files = getDataFromUrl( AWSSNSURL , json_encode(array('Type'=>'getNewFiles','Caller' => SUBKEY )));
	}
	
	//print "files:" . print_r($files,1) .'<br>';
	$files = json_decode($files);
	if (count($files) == 0 )
	{
		MyLog ( "No (new) files found" );
		print "No (new) files found<br>\n" ;
		return;
	}
	
	MyLog ( "Files loaded:" . count($files) );
	print "Files loaded:" . count($files) . "<br>\n" ;
	foreach ( $files as $name => $data )
	{
		print("-------------<br>\n");
		print("Handling file: $name<br>\n");
		MyLog("-------------");
		MyLog("Handling file: $name");
		MyLog("-------------");
	
		$message = json_decode($data);
		MyLog("message:" . print_r($message,1));
		
		$storyId = $message->tenantId . '-' . $message->id;
		MyLog (' storyId: ' . $storyId );

		if (isset($message->iframe) &&
			$message->iframe == 'true')
		{
			upsertWPIframe( $message , $storyId);
		}
		else
		{	
		    upsertWPArticle( $message );
		}    
	}
}


// this function is an alternative for upsertWPArticles
// in this case the inception article-structure is uploaded to 
// the wordpress upload folder,
// then an article in wp is created, containing a iframe that points to the
// article structure

function upsertWPIframe( $message, $storyId )
{
	$aurora = New Aurora($message);
	
	// save to disk
	$zipname = $aurora->getArticleZipName();
	MyLog ("zipname:" . $zipname  );
	// store the zipfile in or tempfolder
	$aurora->getArticleZipToPath(TEMPDIR . $zipname);
	
	$dirname = basename($zipname,'.article');
	
	// prepare the wp-side
	$upload_dir = wp_upload_dir();
	//MyLog ( print_r($upload_dir,1));
	$articleDirName =  $aurora->getArticleID() . '-' . $dirname;
	$articleDir = $upload_dir['path'] . '/' . $articleDirName;
	$articleUrl = $upload_dir['url']  . '/' . $articleDirName . '/output.html';
	$articleHtmlFileName = $articleDir . '/output.html';
	
	if ( ! file_exists($articleDir) )
	{
		MyLog ( "Creating folder [$articleDir]");
		if ( wp_mkdir_p( $articleDir ) ){
			chmod($articleDir, 0755);
		}	
		else
		{
			MyLog ( "failed to create upload dir [$articleDir], no further processing" );
			return false;
		}
	}
	
	// now unzip the zipfile to our folder
	$zip = new ZipArchive;
	if ($zip->open(TEMPDIR . $zipname) === TRUE) {
    	$zip->extractTo($articleDir);
		$zip->close();

		//Get plain content		
		$metaData = $aurora->getArticleMetadata(); //json_decode(file_get_contents($data->metadataUrl));
		$plainContent = $metaData->MetaData->ContentMetaData->PlainContent;
				
		//Extract content from hero component and remove it from the html	
		$html = file_get_contents ($articleHtmlFileName);		
		$heroStartTag = "<div class=\"_hero-bg-box\">";
		$heroEndTag = "</h3>\n</figcaption>\n</div>\n</div>\n</figure>\n</div>";			
		$standfirstStartTag = "<div class=\"headline\">";
		$standfirstEndTag = "</div>";		
		$titleStartTag = "<h2 class=\"text title\" doc-editable=\"text\">";
		$titleEndTag = "</h2>";		
				
		$featuredImageURI = "";
		if (strpos ($html, $heroStartTag) !== false) {
            //Extract the hero html component
			$heroStart = strpos ($html, $heroStartTag); 
			$heroEnd   = strpos ($html, $heroEndTag) + strlen ($heroEndTag);
			$heroHTML  = substr ($html, $heroStart, $heroEnd - $heroStart);
		
			//Remove the hero html 
			$html = substr_replace ($html, "", $heroStart, $heroEnd - $heroStart);
			file_put_contents ($articleHtmlFileName, $html);
		
			//Get the image url for the teaser image 
			$imageStartTag = "url(&quot;";
			$imageEndTag = "&quot;)";
			$imageStart = strpos ($heroHTML, $imageStartTag) + strlen ($imageStartTag); 
			$imageEnd = strpos ($heroHTML, $imageEndTag) ;
			$featuredImageURI = $articleDir . "/" . substr ($heroHTML, $imageStart, $imageEnd - $imageStart);		
				
			//Generate title	
			$articleTitle = createPageTitle ($heroHTML);						

		} else if (strpos ($html, $standfirstStartTag) !== false) {
			//load the title from the standfirst component

            //Extract the standfirst html
			$standfirstStart = strpos ($html, $standfirstStartTag); 
			$standfirstEnd  = strpos ($html, $standfirstEndTag) + strlen ($standfirstEndTag);
			$standfirstHTML = substr ($html, $standfirstStart, $standfirstEnd - $standfirstStart);
		
			//Remove the standfirst html 
			$html = substr_replace ($html, "", $standfirstStart, $standfirstEnd - $standfirstStart);
			file_put_contents ($articleHtmlFileName, $html);
						
			//Generate title	
			$articleTitle = createPageTitle ($standfirstHTML);			
		} else if (strpos ($html, $titleStartTag) !== false) {
			//load the title from the title component

            //Extract the title html
			$titleStart = strpos ($html, $titleStartTag); 
			$titleEnd = strpos ($html, $titleEndTag) + strlen ($titleEndTag);
			$titleHTML = substr ($html, $titleStart, $titleEnd - $titleStart);
		
			//Remove the title html 
			$html = substr_replace ($html, "", $titleStart, $titleEnd - $titleStart);
			file_put_contents ($articleHtmlFileName, $html);
						
			//Generate title	
			$articleTitle = createPageTitle ($titleHTML);			
		} else {
			//use article title
			$articleTitle = $aurora->getArticleName();
		}		 

		//Generate excerpt from first paragraphs (p, h, etc) 		
		preg_match_all('|class="text [^>]+>(.*)</|iU', $html, $pTags);			
		$excerpt = "";
		for ($i = 0; $i < count($pTags[1]); $i++) {
			if ($excerpt != "") {
				$excerpt = $excerpt . '<br>';
			}
			$excerpt = $excerpt . $pTags[1][$i];
			if (sizeof ($excerpt) > MAX_EXCERPT_LENGTH) {
				break;
			}
		}
		$excerpt = truncateString ($excerpt, MAX_EXCERPT_LENGTH, true);
		 
		// create the article pointing to this folder
		// use format required for iframe plugin
		$articleHTML = '[auto-iframe link=' . $articleUrl . ']';

		//Check if we are updating an existing post
		$posts = get_posts(array(
			'numberposts'	=> -1,
			'post_type'		=> 'post',
			'meta_key'		=> 'Enterprise-Id',
			'meta_value'	=> $storyId
		));
		
		MyLog('posts:' . print_r($posts,1));
		$post_id = 0;
		if (count($posts) > 0) {
			$post_id = $posts[0]->ID;
			MyLog ( "existing post found with ID:$post_id" );
		}

		$wp_error = false;
		if ( $post_id > 0 ){
			MyLog ( 'updating post' );
			$postarr = get_post( $post_id , 'ARRAY_A');
			
			$postarr['post_content'] = $articleHTML;
			$postarr['post_title'] = wp_strip_all_tags( $articleTitle );
			$postarr['post_excerpt'] = $excerpt;
			// switch off the versioning for this post
			remove_action( 'post_updated', 'wp_save_post_revision' );
			$post_id = wp_update_post( $postarr,  $wp_error  ); //
			MyLog ("wp_error:" . print_r($wp_error,1));
			// switch on the versioning for this post
			add_action( 'post_updated', 'wp_save_post_revision' ); 
			update_post_meta ($post_id, "Enterprise-Plain-Content", $plainContent);			
		} else {
			MyLog ( 'insert new post' );
			$postarr = array(
			 'ID'		=> $post_id, // does not seem to work
			 'post_title'    => wp_strip_all_tags( $articleTitle ),
			 'post_content'  => $articleHTML,
			 'post_status'   => 'publish',
			 'post_author'   => 1,
			 'post_category' => array( 8,39 ),
			 'post_excerpt'  => $excerpt
			);
			$post_id = wp_insert_post( $postarr,  $wp_error  );
			MyLog ("wp_error:" . print_r($wp_error,1));
			
			//Set the Enteprise-ID to uniquely identity the post and find it when updating the content
			add_post_meta ($post_id, "Enterprise-Id", $storyId);			
			add_post_meta ($post_id, "Enterprise-Plain-Content", $plainContent);			
		}
		
		//Set the featured image
		//Todo, cleanup previous image
		if ($featuredImageURI != "") {
			$wp_filetype = wp_check_filetype($featuredImageURI, null );
			$attachment = array(
				'post_mime_type' => $wp_filetype['type'],
				'post_title' => sanitize_file_name($featuredImageURI),
				'post_content' => '',
				'post_status' => 'inherit'
			);
			$attach_id = wp_insert_attachment( $attachment, $featuredImageURI, $post_id );
			require_once(ABSPATH . 'wp-admin/includes/image.php');
			$attach_data = wp_generate_attachment_metadata( $attach_id, $featuredImageURI );
			$res1= wp_update_attachment_metadata( $attach_id, $attach_data );
			$res2= set_post_thumbnail( $post_id, $attach_id );			
		}   			
	
		MyLog ( 'Created or updated post with ID:' . $post_id );
	}	

}

/*
* Create the title from the hero or standfirst component
* @componentHTML The HTML of the hero or standfirst component
*/
function createPageTitle ($componentHTML) {
	$articleTitle = "";
	
 	preg_match_all('|<h1[^>]+>(.*)</h[^>]+>|iU', $componentHTML, $h1Headings);
	preg_match_all('|<h2[^>]+>(.*)</h[^>]+>|iU', $componentHTML, $h2Headings);	
	
	if (count ($h1Headings) > 0 ) {
		$articleTitle = $h1Headings[1][0] ; 
	}
		
	if ((count ($h2Headings) > 0 ) && ($h2Headings[1][0] != "")) {
		if ($articleTitle != "") {
			$articleTitle = $articleTitle . ' - ';
		}	
		$articleTitle = $articleTitle . $h2Headings[1][0];
	}
			
	return $articleTitle;			  
}

// this function will upload the related images to the upload folder
// replace the url in the article to point to the uploaded article
// and store the article as new article
// this will cause some display problems bcause of javasript and styling not working correctly

function upsertWPArticle( $message )
{
	$aurora = New Aurora($message);
	
	// save to disk
	$zipname = $aurora->getArticleZipName();
	MyLog ("zipname:" . $zipname  );
	// store the zipfile in or tempfolder
	$aurora->getArticleZipToPath(TEMPDIR . $zipname);
	
		
	$dirname = basename($zipname,'.article');
	if ( $dirname != '')
	{
		//echo "dirname:" . $dirname . '<br>';
		if (file_exists(TEMPDIR . $dirname)) { deleteDir (TEMPDIR . $dirname); }
		if ( ! file_exists( TEMPDIR . $dirname) )
		{
		  mkdir(TEMPDIR . $dirname,0777);
		  chmod(TEMPDIR . $dirname,0777);
		}   
	}
	else
	{
		MyLog ( "no dirname found, exit" );
		return;
	}	
	
	$zip = new ZipArchive;
	if ($zip->open(TEMPDIR . $zipname) === TRUE) {
    	$zip->extractTo(TEMPDIR . $dirname);
		$zip->close();
		
		// parse the content in the temp folder
		$images = getImagesFromPath( TEMPDIR . $dirname . '/img');
		$articleHTML = file_get_contents(TEMPDIR . $dirname .'/output.html');
		$upload_dir = wp_upload_dir();
		
		
		// --------------------------------
		// time to do some wordpress logic
		// --------------------------------

		// upload the template/design.css
		$designCS = $aurora->getArticleID() . '-design.css';
		$uploadFile = $upload_dir['path'] . '/' . $designCS;
		if ( ! file_exists($uploadFile))
		{
			MyLog ("upload $uploadFile" );
			$wpfile = wp_upload_bits($wpname, null,file_get_contents(TEMPDIR . $dirname . '/template/design.css') );
		}else{
			MyLog ( "skipping $uploadFile" );
			$wpfile['url'] =  $upload_dir['url'] . '/' . $wpname;
		}
		$articleHTML = str_replace ("template/design.css",  $wpfile['url'] ,$articleHTML);
		
		// upload the vendor.js
		$vendorJs = $aurora->getArticleID() . '-vendor.js';
		$uploadFile = $upload_dir['path'] . '/' . $vendorJs;
		if ( ! file_exists($uploadFile))
		{
			MyLog ( "upload $uploadFile" );
			$wpfile = wp_upload_bits($wpname, null,file_get_contents(TEMPDIR . $dirname . '/template/vendor.js') );
		}else{
			MyLog ( "skipping $uploadFile" );
			$wpfile['url'] =  $upload_dir['url'] . '/' . $wpname;
		}
		$articleHTML = str_replace ("template/vendor.js",  $wpfile['url'] ,$articleHTML);
		
		
		
		// upload the images and replace the links in the article
		foreach( $images as $image )
		{
			// check if file exists
			$fname = $aurora->getArticleID() . '-' . basename($image);
			//MyLog('upload_dir :' . print_r($upload_dir,1) );
			$uploadFile = $upload_dir['path'] . '/' . $fname;
			
			MyLog("Checking for file [$uploadFile]");
			if ( ! file_exists($uploadFile))
			{
				MyLog ( "uploading file" );
				$wpfile = wp_upload_bits($fname, null,file_get_contents($image) );
			}else{
				MyLog ( "skipping upload, file already there !" );
				$wpfile['url'] =  $upload_dir['url'] . '/' . $fname;
			}
			
			MyLog ("wpfile:" . print_r( $wpfile,1) );
			//Update the image url in the html
       		MyLog ("Replacing [" .  basename($image) . "]  with [" . $wpfile['url'] . "]" );
       		
       		$articleHTML = str_replace ("&quot;img/" . basename($image) . "&quot;", "'". $wpfile['url'] . "'",$articleHTML);
	   		$articleHTML = str_replace ("img/" . basename($image) . "", "". $wpfile['url'] . "",$articleHTML);
        	
		}
		
		file_put_contents( $upload_dir['path'] . '/article.txt', $articleHTML );
		

		$WPNodeName = $aurora->getArticleName(); 
		
		MyLog ( "doing our Wordpress thing:" . $WPNodeName );
		
		
		// Create post object
		$post_id = post_exists($WPNodeName);
		MyLog ( "existing post found with ID:$post_id");
		
		
		
		
		$wp_error = false;
		if ( $post_id > 0 ){
			MyLog ( 'update post' );
			$postarr = get_post( $post_id , 'ARRAY_A');
			
			$postarr['post_content'] = $articleHTML;
			// switch off the versioning for this post
			remove_action( 'post_updated', 'wp_save_post_revision' );
			$post_id = wp_update_post( $postarr,  $wp_error  ); //
			MyLog ("error:" . print_r($wp_error,1));
			// switch on the versioning for this post
			add_action( 'post_updated', 'wp_save_post_revision' ); 
		}else{
			MyLog ( 'insert new post' );
			$postarr = array(
			 'ID'		=> $post_id, // does not seem to work
			 'post_title'    => wp_strip_all_tags( $WPNodeName ),
			 'post_content'  => $articleHTML,
			 'post_status'   => 'publish',
			 'post_author'   => 1,
			 'post_category' => array( 8,39 ) // use metadata
			);
			wp_insert_post( $postarr,  $wp_error  );
		}
		MyLog ( 'result:' . print_r($wp_error,1)  );
		
		
		if (file_exists(TEMPDIR . $dirname)) { deleteDir (TEMPDIR . $dirname); }
		
	} else {
    	MyLog ( 'failed to retrieve/unzip from url [' . $url . ']');
	}
}


function getImagesFromPath($imageFolder)
{
	$images = array();
	$dh  = opendir($imageFolder);
	if ( $dh !== false)
	{
		$filename = readdir($dh);
		
		while ($filename !== false) 
		{
			if ( $filename != '.' &&
				 $filename != '..' &&
				 $filename !== false)
			{	 
				//print ('filename:' . $filename );
				//$fileContent = file_get_contents( $imageFolder .'/'. $filename );
				$images[] = $imageFolder .'/'.  $filename ;
				
			}	
			$filename = readdir($dh);
		}
	}	
	
	return $images;

}



function getDataFromUrl( $url, $postdata )
{
	//print "url:$url data:$postdata<br>";
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_POST, 1);
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata);
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1);
	curl_setopt( $ch, CURLOPT_HEADER, 0);
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1);
	//print 'exec curl';
	$response = curl_exec( $ch );
	//print 'not:' . print_r($response,1);
	return $response;
}


function deleteDir($dirPath) {
    if (! is_dir($dirPath)) {
        throw new InvalidArgumentException("$dirPath must be a directory");
    }
    if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
        $dirPath .= '/';
    }
    $files = glob($dirPath . '*', GLOB_MARK);
    foreach ($files as $file) {
        if (is_dir($file)) {
            deleteDir($file);
        } else {
        	//print "unlink:" . $file . '<br>';
            unlink($file);
        }
    }
    rmdir($dirPath);
}

// -------------------------------
// -------- LOG FUNCTIONS --------
// -------------------------------
function getRealIpAddr()
{
    $ip = '::1';
    if (!empty($_SERVER['HTTP_CLIENT_IP']))   //check ip from share internet
    {
      $ip=$_SERVER['HTTP_CLIENT_IP'];
    }
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))   //to check ip is pass from proxy
    {
      $ip=$_SERVER['HTTP_X_FORWARDED_FOR'];
    }
    elseif (!empty($_SERVER['REMOTE_ADDR']))
    {
      $ip=$_SERVER['REMOTE_ADDR'];
    }
    
    
    if ( $ip == '::1' ) { $ip = 'localhost';}
    return $ip;
}


function getLogPath()
{
   $logfolder = LOGPATH;
   $date = date('Ymd');
   
    if ( ! file_exists( $logfolder) )
    { 
       error_log (basename(__FILE__) . ' -> ERROR: Logfolder [' . $logfolder . '] does not exists, please create',0);
       print basename(__FILE__) . ' -> ERROR: Logfolder [' . $logfolder . '] does not exists, please create';
       exit;
    }
    
   $logfolder = $logfolder . $date ;
   if ( ! file_exists( $logfolder) )
   {
     mkdir($logfolder,0777);
     chmod($logfolder,0777);
   } 
      
      
   // add IPAdres if required
   if ( defined ('LOGPATHWITHIP') &&
   		LOGPATHWITHIP === true )
   {
	  $ip = getRealIpAddr();
   	  $logfolder = $logfolder . '/' . $ip;
   }	   		
   
  
   if ( ! file_exists( $logfolder) )
   {
     mkdir($logfolder,0777);
     chmod($logfolder,0777);
   }    

   return $logfolder .'/';
}

function getLogTimeStamp()
{
  list($ms, $sec) = explode(" ", microtime()); // get seconds with ms part
  $msFmt = sprintf( '%03d', round( $ms*1000, 0 ));
  return date('Y-m-d H-i-s (T)',$sec).'.'.$msFmt;
}

function mustLog()
{
   global $loggedInUser;
   $do_log = false;
  // error_log('LOG_ALL:' . LOG_ALL );
   $ip = getRealIpAddr();
   
   if ( LOG_ALL === false)
   {
    
     $logip = unserialize(LOG_IP);
    // error_log('logip:' . print_r($logip,1));
    // error_log('ip:' . print_r($ip,1));
      
     if (in_array($ip,$logip) )
     {
       $do_log = true;
     }  
   
    
   
   }
   else
   {
     $do_log = true;
   } 
   //error_log( 'do_log:' . $do_log );
   return $do_log;
}


function MyLogS( $logline )
{
   MyLog( $logline, true );
}

function MyLog( $logline , $toBrowser = false)
{ 
   global $loggedInUser, $currentCommand, $logTimeStamp, $LOGNAME, $logfilename;
   
   if ( isset($logfilename))
   {
     $LOGNAME = $logfilename;
   }
   else
   {
     $LOGNAME = LOGNAME;
   }
   
   if ( mustLog() === true )
   {
      
      $userID = 0;
      if ( isset($loggedInUser->user_id) )
      {
        $userID = $loggedInUser->user_id;
      }
      $ip = getRealIpAddr();

      $datetime = getLogTimeStamp() . "[$ip] [$userID]";
      //'[' . date("d-M-Y H:i:s") . "] [$ip] [$userID]";
      
      $logfolder = getLogPath();
      $logname = $LOGNAME;
      
      
                                        
      if ( $currentCommand != '' &&
           $logTimeStamp   != '')
      {
         $logfile = $logfolder . '/' .$logTimeStamp . '-' . $currentCommand .  '.log';
      }
      else
      {                                  
        $logfile = $logfolder . '/' . $logname . '.log';
      }
      
      $logh = fopen($logfile, 'a');
      if ( $logh !== false)
      {
         fwrite( $logh, $datetime .  $logline . "\n");
         fclose( $logh );
         chmod ( $logfile, 0777 );
      }
      else
      {
          error_log ( basename(__FILE__) . ' -> ERROR: writing to logfile [$logfile]' );
      }
    
      if ( $toBrowser )
      {
        print $logline . "<br>\n"; 
        try {while (ob_get_level() > 0) ob_end_flush();} catch( Exception $e ) {}
      }     
    }
 } 


/**
 * Places dangerous characters with "-" characters. Dangerous characters are the ones that 
 * might error at several file systems while creating files or folders. This function does
 * NOT check the platform, since the Server and Filestore can run at different platforms!
 * So it replaces all unsafe characters, no matter the OS flavor. 
 * Another advantage of doing this, is that it keeps filestores interchangable.
 * IMPORTANT: The given file name should NOT include the file path!
 *
 * @param string $fileName Base name of file. Path excluded!
 * @return string The file name, without dangerous chars.
 */
function replaceDangerousChars( $fileName )
{
    MyLog('-replaceDangerousChars');
    MyLog(" input: $fileName ");
	$dangerousChars = "`~!@#$%^*\\|;:'<>/?\"";
	$safeReplacements = str_repeat( '-', strlen($dangerousChars) );
	$fileName = strtr( $fileName, $dangerousChars, $safeReplacements );
	MyLog(" output: $fileName ");
	return $fileName;
}
	
/**
 * Encodes the given file path respecting the FILENAME_ENCODING setting.
 *
 * @param string $path2encode The file path to encode
 * @return string The encoded file path
 */
function encodePath( $path2encode )
{
  MyLog('-encodePath');
  MyLog(" input: $path2encode ");
  
  setlocale(LC_CTYPE, 'nl_NL');
  $newPath = iconv('UTF-8', "ASCII//TRANSLIT", $path2encode);
  $newPath = preg_replace('/[^A-Za-z0-9\-]/', '', $newPath);
  
  MyLog(" output: $newPath ");
  return $newPath;
}

/**
* Truncates to nearest preceding space of target character. Demo
* @str The string to be truncated
* @chars The amount of characters to be stripped, can be overridden by $to_space
* @to_space boolean for whether or not to truncate from space near $chars limit
*/
function truncateString($str, $chars, $to_space, $replacement="...") {
   if($chars > strlen($str)) return $str;

   $str = substr($str, 0, $chars);
   $space_pos = strrpos($str, " ");
   if($to_space && $space_pos >= 0) 
       $str = substr($str, 0, strrpos($str, " "));

   return($str . $replacement);
}



/* - Aurora -

	 functions handling the Aurora specific data
*/	 

class Aurora {
	
	/* structure of the message being received from AWS
   [id] => 146
    [name] => da_1-iframe
    [url] => https://prod-published-articles-bucket-eu-west-1.s3.amazonaws.com/146/c959e74d-8d52-4adc-9599-62415e0861fa/da-1-iframe.article
    [metadataUrl] => https://prod-published-articles-bucket-eu-west-1.s3.amazonaws.com/146/c959e74d-8d52-4adc-9599-62415e0861fa/metadata.json
    [articleJsonUrl] => https://prod-published-articles-bucket-eu-west-1.s3.amazonaws.com/146/c959e74d-8d52-4adc-9599-62415e0861fa/article.json
    [tenantId] => f21d1f27-68bc-f4cc-8fba-b91ab5d99c1c
    [brand] => 1
	*/
	
	private $_awsMessage = null;
	private $_errors = array();
	
	public function __construct( $message = null)
    {
        if($message){
            $this->_awsMessage = $message;
            $this->_errors = array();
        }
    }
	
	
	
	public function getArticleID()
	{
		 if($this->_awsMessage){
			 return $this->_awsMessage->id;
		 }
		 
		 return false;	 

	}

	
	public function getArticleName()
	{
		if($this->_awsMessage){
			 return $this->_awsMessage->name;
		 }
		 
		 return false;	
	}
	
	public function getArticleZipName()
	{
		if($this->_awsMessage){
			 try {
			 	$zipname = basename($this->_awsMessage->url);
			 } 	catch( Exception $e )
			 {
				 $this->_errors[] = "Problem loading article zipped data, error: $e";
			 }
			 return $zipname;
		 }
		 
		 return false;
	}
	
	//
	// get the zipfile from the path specified in the message
	// and download it to the path specified
	//
	public function getArticleZipToPath($zippath)
	{
		if($this->_awsMessage){
			 try {
			 	$zipdata = file_get_contents($this->_awsMessage->url);
			 	file_put_contents( $zippath, $zipdata);
			 } 	catch( Exception $e )
			 {
				 $this->_errors[] = "Problem loading article zipped data, error: $e";
			 }
			 return $zipdata;
		 }
		 
		 return false;
	}
	
	//
	// get the zipfile as raw data
	// 
	public function getArticleZipData()
	{
		if($this->_awsMessage){
			 try {
			 	$zipdata = file_get_contents($this->_awsMessage->url);
			 } 	catch( Exception $e )
			 {
				 $this->_errors[] = "Problem loading article zipped data, error: $e";
			 }
			 return $zipdata;
		 }
		 
		 return false;
	}
	
		
	public function getArticleMetadata()
	{
		 if($this->_awsMessage){
			 try {
			 	$metadata = file_get_contents($this->_awsMessage->metadataUrl);
			 } 	catch( Exception $e )
			 {
				 $this->_errors[] = "Problem loading article metadata, error: $e";
			 }
			 return json_decode($metadata);
		 }
		 
		 return false;
	}
	
	public function getArticleJSON()
	{
		 if($this->_awsMessage){
			 try {
			 	$articleJSON = file_get_contents($this->_awsMessage->articleJsonUrl);
			 } 	catch( Exception $e )
			 {
				 $this->_errors[] = "Problem loading article JSON, error: $e";
			 }
			 return json_decode($articleJSON);
		 }
		 
		 return false;
	}
	
	
	
	public function getErrors ()
	{
		if($this->_errors){
			return $this->_errors;
		}
		return false;
		
	}
	
}
