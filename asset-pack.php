<?php

/**
 * Provides asset-pack download info, generating asset-pack archive files as needed.
 */

/**************************************************************************
 * CONSTANTS
 **************************************************************************/

// expected POST properties
const PRP_APPID     = 'appId';              // application ID, i.e. "com.captainmcfinn.SwimAndPlay"; used as a directory name
const PRP_APPVER    = 'appVersion';         // application version; used as a directory name; assumed to be an integer
const PRP_PCKIDS    = 'assetPackIds';       // asset pack ID list, in JSON array format, i.e. '["world-sand-dusty-reef","activity-music"]'
const PRP_SLTSUB    = 'selectSubdirs';      // (optional) for specified parent directories, selects subdirs to retain, causing all other sibling subdirs to be omitted from the asset pack
                                            // keys = parent dir names, values = array of subdir names to retain, i.e. '{"tiles":["dxt"],"spritesheets":["png","xml"]}'

// exit codes
const EC_SUCCESS              = 0;          // success
const EC_NOT_A_POST           = 1;          // this script was called but not POSTed to
const EC_EMPTY_PROPERTY       = 2;          // a required POST property was missing or empty
const EC_BAD_DIR_SETUP        = 3;          // asset directories are not setup as expected by this script
const EC_INVALID_JSON         = 4;          // input property contained invalid JSON
const EC_GENERATE_ERROR       = 5;          // an error occurred when generating an asset pack

// misc
const ASTPCKDIR     = "./packs/";           // path of asset packs subdirectory

/**************************************************************************
 * MAIN
 **************************************************************************/

// validate POST data
if($_SERVER['REQUEST_METHOD']!='POST') { exitWithStatus(EC_NOT_A_POST); }
if(empty($_POST[PRP_APPID])) { exitWithStatus(EC_EMPTY_PROPERTY); }
if(empty($_POST[PRP_APPVER])) { exitWithStatus(EC_EMPTY_PROPERTY); }
if(empty($_POST[PRP_PCKIDS])) { exitWithStatus(EC_EMPTY_PROPERTY); }

// ensure main app ID directory exists
if(!file_exists(ASTPCKDIR.$_POST[PRP_APPID])) { exitWithStatus(EC_BAD_DIR_SETUP,"app ID directory does not exist"); }

// read optional selected subdirs object
$sltsubdirobj = (object)[]; // default to empty object
if(!empty($_POST[PRP_SLTSUB])) {
  $sltsubdirobj = json_decode($_POST[PRP_SLTSUB]);
  if($sltsubdirobj===NULL || !is_object($sltsubdirobj)) { exitWithStatus(EC_INVALID_JSON,"bad selected subdirs JSON"); }
  foreach($sltsubdirobj as $dirnamarr) {
    if(!is_array($dirnamarr) || count($dirnamarr)<=0) { exitWithStatus(EC_INVALID_JSON,"selected subdirs values must be arrays with at least one element"); }
  }
}

// read asset pack ID array
$astpckids = json_decode($_POST[PRP_PCKIDS]);
if($astpckids===NULL || !is_array($astpckids)) { exitWithStatus(EC_INVALID_JSON,"bad asset pack IDs JSON"); }

// generate asset pack info object
$astpckinf = (object)[];
foreach($astpckids as $id)
{
  // select best-match version subdirectory for this asset pack
  $vsndirpth = getVersionDirectoryPath(ASTPCKDIR.$_POST[PRP_APPID]."/",$_POST[PRP_APPVER],$id);
  if($vsndirpth===FALSE) { exitWithStatus(EC_BAD_DIR_SETUP,"no matching asset directory found: ".$id); }
  $vsndirpth .= "/"; // add trailing slash

  // check if a matching and valid asset pack archive file already exists
  $fnmpfx = generateAssetPackFilenamePrefix($id,$sltsubdirobj);
  $astpckpth = findArchiveFileWithPrefix($vsndirpth,$fnmpfx);
  if(!is_string($astpckpth)) {
    // asset pack archive does not exist; create it
    $srcdir = $vsndirpth.$id."/";
    $astpckpth = generateAssetPack($id,$sltsubdirobj,$vsndirpth,$srcdir);
    if($astpckpth===FALSE && file_exists($srcdir)) { exitWithStatus(EC_GENERATE_ERROR,"could not generate asset pack: ".$id); }
  }

  // include relative path for this ID
  if(is_string($astpckpth)) {
    $astpckinf->$id = (object)["relativePath"=>makeWebServiceRelativePath($astpckpth)];
  }
}

// finally, return asset pack info
exitWithStatus(EC_SUCCESS,"ok",$astpckinf);

/**************************************************************************
 * FUNCTIONS
 **************************************************************************/

/**
 * Common exit function.
 *
 * @param int $exitCode           exit code
 * @param string $exitMessage     (optional) exit message
 * @param object $assetPackInfo   (optional) asset pack info object
 */
function exitWithStatus($exitCode, $exitMessage=NULL, $assetPackInfo=NULL) {
  $arr = array('statusCode' => $exitCode);
  if(!is_null($exitMessage)) { $arr['message'] = $exitMessage; }
  if(!is_null($assetPackInfo)) { $arr['assetPackInfo'] = $assetPackInfo; }
  echo json_encode($arr);
  exit($exitCode);
}

/**
 * Scans the given application directory for the best-matching version subdirectory.
 * The best-matching version subdirectory will
 *   1) have a name that is <= the target version string and
 *   2) contain a subdirectory matching the given asset pack ID
 * Version subdirectories are assumed to have integer names (no characters or punctuation) and are
 * searched in descending order so newest/highest versions are checked first.
 *
 * @param string $directoryPath     application directory path, assumed to end with a '/'
 * @param string $targetVersion     target version string
 * @param string $assetPackId       a valid asset pack ID
 * @return The relative path of the best-matching version subdirectory, or FALSE if none could be found.
 */
function getVersionDirectoryPath($directoryPath, $targetVersion, $assetPackId)
{
  // get paths of all version subdirs
  $dirptharr = glob($directoryPath.'*', GLOB_ONLYDIR);

  // reverse sort array on int value of directory names; high to low
  usort($dirptharr,function($pthA,$pthB) {
    $intnamA = intval(basename($pthA));                   // directory name A as integer
    $intnamB = intval(basename($pthB));                   // directory name B as integer
    if      ($intnamA == $intnamB) { return  0; }
    else if ($intnamA >  $intnamB) { return -1; }
    else                           { return  1; }
  });

  // search for best-match version subdir
  foreach($dirptharr as $dirpth) {
    $dirnam = basename($dirpth);                          // directory name is version number
    if($dirnam<=$targetVersion && file_exists($dirpth.'/'.$assetPackId)) {
      return $dirpth; // return best-match
    }
  }
  return FALSE; // no best-match was found
}

/**
 * Generates an asset pack archive file.
 *
 * @param string $assetPackId       a valid asset pack ID
 * @param object $selectedSubdirs   selected subdirs object
 * @param string $targetDir         path of directory in which to create the asset pack archive file; trailing '/' expected
 * @param string $sourceDir         path of directory which holds all assets to include in the archive; trailing '/' expected
 * @return the path of the newly created asset pack archive file, or FALSE upon error.
 */
function generateAssetPack($assetPackId, $selectedSubdirs, $targetDir, $sourceDir)
{
  // target and source directories must exist and be writable!
  if(!is_writable($targetDir)) { return FALSE; }
  if(!is_writable($sourceDir)) { return FALSE; }

  // create asset pack filename prefix
  $fnmpfx = generateAssetPackFilenamePrefix($assetPackId,$selectedSubdirs);

  // create archive from filtered directory contents
  $tmpfilpth = $targetDir.$fnmpfx."-".randomString16().".zip"; // temp file path
  if(!createZipFromDir($sourceDir,$tmpfilpth,$selectedSubdirs)) {
    unlink($tmpfilpth);
    return FALSE;
  }

  // calculate archive file hash and add it to the filename
  $filhsh = hash_file("adler32",$tmpfilpth);
  $fnlfilpth = $targetDir.$fnmpfx."-$filhsh.zip"; // final file path
  if(!rename($tmpfilpth,$fnlfilpth)) {
    unlink($tmpfilpth);
    return FALSE;
  }

  // return file path
  return $fnlfilpth;
}

/**
 * Generates an asset pack filename prefix with selected subdir decorators,
 * separated by dashes: <assetPackId>-<decorator1>-<decorator2>
 *
 * @param string $assetPackId       a valid asset pack ID
 * @param object $selectedSubdirs   selected subdirs object
 * @return An asset pack filename prefix.
 */
function generateAssetPackFilenamePrefix($assetPackId, $selectedSubdirs)
{
  // start prefix with asset pack ID
  $fnmpfx = $assetPackId;

  // sort selected subdirs by key to enforce consistency
  $srtsltdrs = get_object_vars($selectedSubdirs);
  ksort($srtsltdrs);

  // append selected subdir decorators to filename
  foreach($srtsltdrs as $pntdirnam => $subdirnms) {
    $fnmpfx .= "-".substr($pntdirnam,0,3).substr($subdirnms[0],0,3); // note: select first array entry for prefix
  }

  // return complete prefix
  return $fnmpfx;
}

/**
 * Creates a zip file from a directory.
 * Directory contents are filtered according to the selected subdirs object.
 *
 * @param string $dirPath           path of directory to archive
 * @param string $zipPath           path of zipfile to create
 * @param object $selectedSubdirs   selected subdirs object used to filter the source directory
 * @return TRUE on success; FALSE on failure
 */
function createZipFromDir($dirPath, $zipPath, $selectedSubdirs)
{
  // get absolute path of directory
  $absDirPath = realpath($dirPath);

  // init archive object
  $zip = new ZipArchive();
  $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

  // create filtered recursive directory iterator
  $diritr = new RecursiveDirectoryIterator($absDirPath); // note: iterator of SplFileInfo objects
  $fltitr = new RecursiveCallbackFilterIterator($diritr, function($current, $key, $iterator) use($selectedSubdirs) {

    // filter directories
    if($current->isDir()) {

      // get current directory name
      $curdirnam = $current->getFilename();

      // skip hidden files and directories
      if($curdirnam[0] === '.') { return FALSE; }

      // skip sibling directories not found in $selectedSubdirs
      $pntdirinf = $current->getPathInfo();     // parent dir info
      $pntdirnam = $pntdirinf->getFilename();   // parent dir name
      if(property_exists($selectedSubdirs,$pntdirnam) && !in_array($curdirnam, $selectedSubdirs->$pntdirnam)) { return FALSE; }

      // recurse into all other subdirs if they have children
      return $iterator->hasChildren();
    }

    // allow all files
    return TRUE;
  });
  $filitr = new RecursiveIteratorIterator($fltitr,RecursiveIteratorIterator::LEAVES_ONLY);

  // add selected files to archive
  foreach($filitr as $nam => $fil) {
    $filabspth = $fil->getRealPath();                           // get absolute path of current file
    $filrelpth = substr($filabspth, strlen($absDirPath) + 1);   // get relative path of current file
    if(!is_readable($filabspth)) { return FALSE; }              // file does not exist or is not readable!
    $zip->addFile($filabspth, $filrelpth);                      // add current file to archive
  }

  // create archive and return
  return $zip->close(); // zip archive will be created after closing object
}

/**
 * Given a current directory path (assumed "./" prefix), returns a path relative to the web service root directory.
 */
function makeWebServiceRelativePath($currentDirectoryPath) {
  // define path from web services root to current directory; assume it's one level up
  // note: use $_SERVER['SCRIPT_FILENAME'] to account for symlinks
  $websvcpth = basename(dirname($_SERVER['SCRIPT_FILENAME']))."/";
  return str_replace("./",$websvcpth,$currentDirectoryPath);
}

/**
 * Given an asset pack archive prefix, searches the target directory and returns the path of the best matching asset pack archive file.
 *
 * @param string $directory     path of directory to search
 * @param string $prefix        asset pack archive file prefix, i.e. 'activity-drawing' or 'world-unsmashable-sprpng-tildxt'
 * @return The path of the best matching asset pack archive file or FALSE if no files match.
 */
function findArchiveFileWithPrefix($directory, $prefix)
{
  // build array of archive file paths
  $ptharr = glob($directory.$prefix.'*.zip');     // find all files and subdirectories with prefix
  if($ptharr===FALSE) { return FALSE; }           // no files found / error occurred
  $ptharr = array_filter($ptharr,'is_file');      // filter for files only (eliminate directories)
  if(count($ptharr)<=0) { return FALSE; }         // no files were found

  // now, return the path of the first valid archive found in the filtered array
  foreach($ptharr as $pth) {
    if(isValidArchivePathForPrefix($pth,$prefix)) { return $pth; }
  }

  // no valid archive files found
  return FALSE;
}

/**
 * Checks if an asset pack archive file path is valid for the given prefix.
 *
 * Note: aside from the '-' characters in the prefix and the one '-' expected to immediately follow the prefix,
 * no other '-' characters are allowed to exist in a valid archive filename.
 *
 * @param $archivePath    archive file path
 * @param $prefix         asset pack archive file prefix
 * @return TRUE if the archive file path starts with the given prefix, and there are no unexpected '-' characters
 */
function isValidArchivePathForPrefix($archivePath, $prefix)
{
  // setup vars
  $arcfnm = basename($archivePath);   // isolate archive filename for comparison
  $pfxdsh = $prefix.'-';              // prefix string and '-' character
  $pfxdshlen = strlen($pfxdsh);       // length of prefix and '-'

  // 1) prefix and trailing '-' must match
  if(substr_compare($arcfnm,$pfxdsh,0,$pfxdshlen)!=0) { return FALSE; }

  // 2) there must be no other '-' characters in the filename!
  if(strpos($arcfnm,'-',$pfxdshlen)!==FALSE) { return FALSE; }

  // all tests passed
  return TRUE;
}

/**
 * @return a random 16 character string of base64 characters
 */
function randomString16() {
  return substr(base64_encode(md5(mt_rand())),0,16);
}

?>
