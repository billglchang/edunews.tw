<?php

# This php file should be deployed to WEB Service Server. 
# If the target system cannot run Execution at System Background.
namespace Google\Cloud\Samples\Auth;
		
# Includes the autoloader for libraries installed with composer
require __DIR__ . '/vendor/autoload.php';

use Google\Cloud\ServiceBuilder;
use Google\Cloud\Storage\StorageClient;		// Imports the Google Cloud Storage client library.	
use Google\Cloud\Datastore\DatastoreClient;

header("Content-Type:text/html; charset=utf-8");
	
/** 
 * Upload a file.
 *
 * @param string $bucketName the name of your Google Cloud bucket.
 * @param string $objectName the name of the object.
 * @param string $source the path to the file to upload.
 *
 * @return Psr\Http\Message\StreamInterface
 */
function upload_object($bucketName, $objectName, $source)
{
	# Instantiates a client, for running at APP Engine.
	$storage = new StorageClient([
		'projectId' => 'bc-0904'
	]);
    $bucket = $storage->bucket($bucketName);

	// Open Source File
    //$fcontent = file_get_contents($source);
	$file = fopen($source, 'r');
	
	// Upload file to Bucket in App Engine.
    $object = $bucket->upload($file, [
        'name' => $objectName
    ]);
    
    //printf('Uploaded %s to gs://%s/%s' . PHP_EOL, basename($source), $bucketName, $objectName);
}

function download_object($bucketName, $objectName, $dest)
{
	$storage = new StorageClient([
		'projectId' => 'bc-0904'
	]);
    $bucket = $storage->bucket($bucketName);
    $object = $bucket->object($objectName);
    $object->downloadToFile($dest);
    printf('Object gs://%s/%s %s' . PHP_EOL, $bucketName, $objectName, $dest);
}



function object_metadata($bucketName, $objectName)
{
    $storage = new StorageClient();
    $bucket = $storage->bucket($bucketName);
    $object = $bucket->object($objectName);
    $info = $object->info();
	echo $file.":".$info['size'];
	return "100KB";
}

function open_url($targeturl)
{
	
	$curl = curl_init($targeturl);  // the vesion of php-curl must be the same as php. run phpinfo() to get the php version.
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	$response = curl_exec($curl);
	
	if ($response === false) {
		$info = curl_getinfo($curl);
		curl_close($curl);
		die('error occured during curl exec. Additioanl info: ' . var_export($info));
	}

	curl_close($curl);
	
	return $response;
}


/*****************  DATA STORE FUNCTIONS ********************************************/

/**
 * Create a Cloud Datastore client.
 *
 * @param string $projectId
 * @return DatastoreClient
 */
 
function build_datastore_service($projectId)
{
    $datastore = new DatastoreClient(['projectId' => $projectId]);
 
    return $datastore;
}

function query_by_filename(DatastoreClient $datastore, $filename)
{ 
	$found = false;
	
	$query = $datastore->query()
		->kind('EduNews')
		->filter("filename", "=", $filename);	# @Bill: MUST User double quote, then it works!!
	
	$result = $datastore->runQuery($query);
	
	foreach ($result as $entity) {
		$found = true;
		break;
     #   echo 'Entity found: '. PHP_EOL;
     #   echo 'UserID:'. $entity["UserIdentifyCode"];	# @Bill: MUST User double quote, then it works!!
     #   echo 'Sign:'. $entity["Sign"];					# @Bill: MUST User double quote, then it works!!
    }
	
	return $found;
}     

/**
 * @param DatastoreClient $datastore
 * @param $description
 * @return Google\Cloud\Datastore\Entity
 */
function add_edunews(DatastoreClient $datastore, $published, $title, $content, $filename)
{
	$found = query_by_filename($datastore, $filename);	# return YES/NO.
	
	if($found)
	{
		echo __LINE__ . ",";
		header("Content-Type:text/html;charset=utf-8");
/*		echo '<script type="text/javascript" charset="UTF-8">';
		echo 'alert("已轉換過此則新聞.")';
		echo '</script>';
*/
		return;
	}
	
    $taskKey = $datastore->key('EduNews');
	
    $task = $datastore->entity(
        $taskKey,
        [
			'Published' => $published,
            'filename' => $filename,
            'content' => $content,
            'title' => $title
        ]
    );

    $datastore->upsert($task);
    
    $datastore->transaction()->commit();
    
/*	echo '<script type="text/javascript" charset="UTF-8">';
	echo 'alert("轉換完成.")';
	echo '</script>';		
*/	
    return;
}

/*****************  MAIN FUNCTION ********************************************/

// use google translate tts service to convert every news from NEWS API to mp3. 
// each mp3 file name is MD5 hashed. 
// these mp3 files will be stored in GCP storage bucket-bc-0904.
// bucket-bc-0904 has been made PUBLIC from GCP Console.
function make_news_mp3()
{
	global $datastore;
		
	$xml = simplexml_load_file('https://www.google.com/alerts/feeds/05502333830484499387/4973723945749986909');	
	$json = json_encode($xml);
	$news_json  = json_decode($json,TRUE);
	
	foreach ($news_json['entry'] as $obj){
			
		$title = strip_tags($obj['title']);
		$txtcontent = strip_tags($obj['content']);
		$published = strip_tags($obj['published']);
		$words = strip_tags($obj['title']).".....".strip_tags($obj['content']);
		$words = substr($words, 0, 220);
		$lang = "zh";

		// MP3 filename generated using MD5 hash    
		$file = md5(urlencode($words));
		// Save MP3 file in folder with .mp3 extension 
		$file = "audio" . $file . ".mp3";
		//echo $file . '<br>';

		// Check if file exists, if not create it.	
		$resp = open_url('https://www.googleapis.com/storage/v1/b/bucket-bc-0904/o/'.$file);
		$resp_json = json_decode($resp);
		if(array_key_exists('error',$resp_json)==true){	// file does not exist.
			// create a tmp file to store google tts audio.
			$dir = sys_get_temp_dir();
			$tmp = tempnam($dir, 'foo');
			// download the file content from google tts service.
			$content = file_get_contents('http://translate.google.com/translate_tts?client=tw-ob&ie=UTF-8&q='. urlencode($words) .'&tl='. $lang);
			file_put_contents($tmp, $content);

			upload_object('bucket-bc-0904',$file,$tmp);	// Add an object into GCP Storage.

			add_edunews($datastore, $published, $title, $txtcontent, $file);	// Add an entry into GCP Data Store.
			   
		}else
		if(array_key_exists('selfLink',$resp_json)==true){ // file already exist.
			
		}
	} // end of foreach
}

$datastore = build_datastore_service('bc-0904');

make_news_mp3();


?>
