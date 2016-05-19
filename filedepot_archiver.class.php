<?php

/**
 * @file
 * filedepot_archiver.class.php
 * Archiving class for filedepot
 */
class filedepot_archiver extends ZipArchive {

  private $archiveStorePath = NULL;
  private $checkedFileObjects = NULL;
  private $checkedFolderObjects = NULL;
  private $uniquename          = NULL;
  private $filesToBeDownloaded = Array();
  private $uncheckedFileIds = Array();
  private $uncheckedFolderIds = Array();
  private $processedCategoryIds = Array();
  private $permissionCount = 0;
  private $zipFileName = NULL;
  private $permissionObjectList = Array();
  private $filedepotInstance = NULL;
  private $processedPathComponents = Array();
  private $archiveDirPath = NULL;
  private $filedepotStoragePath = NULL;

  /**
   * Saves and reopens the ZIP archive 
   */
  private function saveAndReopen() {
    $this->close();
    return $this->open($this->zipFileName, ZIPARCHIVE::OVERWRITE);
  }

  /**
   * Checks to see if a folder has view permissions
   * @param type $cid
   * 
   * @return    TRUE on view, FALSE on no view
   */
  private function hasViewPermission($cid) {
    if (!array_key_exists($cid, $this->permissionObjectList)) {
      $this->permissionObjectList[$cid] = $this->filedepotInstance->getPermissionObject($cid); //
    }

    return $this->permissionObjectList[$cid]->canView();
  }

  /**
   * Generate all the files under a given category ID
   * @param type $cid
   */
  private function generateAllFilesUnderCidRecursively($cid) {
    $found_subdirs = FALSE;

    // Only process if not already finished
    if (in_array($cid, $this->processedCategoryIds)) {
      return;
    }
    else {
      $this->processedCategoryIds[] = $cid;
    }

    // Grab all subdirectories under this category
    $result = db_query("SELECT cid FROM {filedepot_categories} WHERE pid = :pid", array(':pid' => $cid));
    while ($A = $result->fetchAssoc()) {
      $found_subdirs = TRUE;
      if (!in_array($A['cid'], $this->uncheckedFolderIds)) {
        $this->generateAllFilesUnderCidRecursively($A['cid']);
      }
    }

    // Grab all files under this category
    $result = db_query("SELECT fid FROM {filedepot_files} WHERE cid = :cid", array(':cid' => $cid));
    $file_count = 0;
    while ($A = $result->fetchAssoc()) {
      $file_count++;
      if ((!in_array($A['fid'], $this->filesToBeDownloaded)) && (!in_array($A['fid'], $this->uncheckedFileIds))) {
        $this->filesToBeDownloaded[] = $A['fid'];
      }
    }

    if (($file_count == 0) && ($found_subdirs === FALSE)) {
      if ($this->hasViewPermission($cid) === TRUE) {
        $destination_dir = $this->archiveStorePath . $this->getProcessedPath($cid) . "";
        // Make sure CID exists
        if (!file_exists($destination_dir)) {
          @mkdir($destination_dir, 0777, TRUE);
        }

        // $this->addEmptyDir($this->getProcessedPath($cid));
      }
    }
  }

  /**
   * Searches for the path component and returns the ProcessedPathObject - if it does not exist in the processedpathcomponents list, then it is grabbed from the DB
   *
   * @return  ProcessedPathObject  for that cid or NULL if nothing can be found
   */
  private function getPathComponent($cid) {
    if (!array_key_exists($cid, $this->processedPathComponents)) {
      $result = db_query("SELECT pid, name FROM {filedepot_categories} WHERE cid = :cid", array(':cid' => $cid));
      $A = $result->fetchAssoc();
      if ($A) {
        $ppo = new ProcessedPathObject();
        $ppo->catName = $A['name'];
        $ppo->catPid = $A['pid'];
        $this->processedPathComponents[$cid] = $ppo;
      }
      else {
        return NULL;
      }
    }

    return $this->processedPathComponents[$cid];
  }

  /**
   * Returns the full path from first parent (top level) to last parent
   * Also adds all lookup items to the queue to preserve database lookups
   */
  private function getProcessedPath($cid) {
    $path = array();

    $ppo = $this->getPathComponent($cid);
    while (true) {
      if ($ppo === NULL) {
        break;
      }
      $path[] = $ppo->catName;
      //if (($ppo->catPid == 0)) {
      //  break;
      //}

      $ppo = $this->getPathComponent($ppo->catPid);
    }

    // Reverse the array so it is in the proper order, implode it into a proper path, and then add a trailing slash
    // This is so that there is no trailing slash if no folder name
    if (count($path) > 0) {
      return implode('/', array_reverse($path)) . '/';
    }
    else {
      return "";
    }
  }

  /**
   * Instantiates a new zip archive object - also creates the zip file
   */
  public function __construct() {
    $this->filedepotInstance = filedepot::getInstance();
    $this->archiveDirPath = drupal_realpath('private://filedepot/') . '/tmp_archive/';
    $tmp_archive_dirpath = drupal_realpath('private://filedepot/') . '/tmp_archive/' . uniqid("fdarchive") . '/';
    //shell_exec("cd {$tmp_archive_dirpath}");
    //$ret = chdir($this->archiveDirPath);
    $this->archiveStorePath =  $tmp_archive_dirpath; //uniqid("fdarchive") . '/';

    if (file_exists($this->archiveDirPath) === FALSE) {
      mkdir($this->archiveDirPath, 0777, TRUE);
    }

    $this->uniquename = uniqid("filedepot_") . ".zip";
    $this->zipFileName = $this->archiveDirPath . $this->uniquename;
    @unlink($this->zipFileName);
    //$this->open($this->zipFileName, ZIPARCHIVE::CREATE);
    $this->filedepotStoragePath = drupal_realpath($this->filedepotInstance->root_storage_path);
  }

  /**
   * Runs over the supplied directory, creates it if it does not exist, and if it does, cleans any old archive files
   */
  public function createAndCleanArchiveDirectory() {
    // Create a new archive folder
    $archiveDirectory = $this->archiveDirPath;
    if (!file_exists($archiveDirectory)) {
      @mkdir($archiveDirectory, 0777, TRUE);
    }

    // delete any older zip archives that were created
    $fd = opendir($archiveDirectory);
    while ((false !== ($file = @readdir($fd)))) {
      if ($file <> '.' && $file <> '..' && $file <> 'CVS' &&
          preg_match('/\.zip$/i', $file)) {
        $ftimestamp = @fileatime("{$archiveDirectory}{$file}");
        if ($ftimestamp < (time() - 600)) {
          @unlink("{$archiveDirectory}{$file}");
        }
      }
    }
  }

  public function close() {
    // do nothing for 
  }
  
  public function getArchiveFilename() {
    if (file_exists($this->zipFileName) !== FALSE AND !empty($this->uniquename)) {
      return $this->uniquename;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Download the archive
   * @param type $errorsInHeaders
   * @return boolean
   */
  public function download($errorsInHeaders = FALSE) {
    set_time_limit(0);
    ob_end_clean(); // are you  kidding me
    if (file_exists($this->zipFileName) === FALSE) {
      $file_exists = FALSE;
      $file_size = 0;
      $error_code = 1;
    }
    else {
      $file_exists = TRUE;
      $error_code = 0;
      $file_size = filesize($this->zipFileName);
    }

    $headers = array(
      'Content-Type: application/x-zip-compressed; name="filedepot_archive.zip"',
      'Content-Length: ' . $file_size,
      'Content-Disposition: attachment; filename="filedepot_archive.zip"',
      'Cache-Control: private',
      'Pragma: no-cache',
      'Expires: 0',
      "Content-Transfer-Encoding: binary",
      'Error-Code: ' . $error_code,
      'FileSize: ' . $file_size,
    );

    // This has to be manually done so we can still show error header information
    /*foreach ($headers as $value) {
      //drupal_add_http_header($name, $value);
      header($value);
    }*/
    header('Pragma: public');   // required
    header('Expires: 0');       // no cache
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    //header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($this->zipFileName)) . ' GMT'); // commented out because fails on system for messed up reason
    header('Cache-Control: private', false);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename=ziparchive.zip"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . $file_size); // provide file size
    header('Connection: close');

    if ($file_exists === TRUE) {

      set_time_limit(0);
      $file = @fopen($this->zipFileName, "rb");
      while (!feof($file)) {
        print(@fread($file, 1024 * 8));
        ob_flush();
        flush();
      }

      exit();
    }
    else {
      echo "";
    }

    drupal_exit();
  }

  /**
   * Add the checked object arrays to the archiving class
   * These are JSON decoded associative arrays:
   *  [id] => array("id" => id, "checked" => boolean)
   * 
   * @param type $checked_fileobj_array
   * @param type $checked_folderobj_array 
   */
  public function addCheckedObjectArrays($checked_fileobj_array, $checked_folderobj_array) {
    $this->checkedFileObjects = $checked_fileobj_array;
    $this->checkedFolderObjects = $checked_folderobj_array;
  }

  /**
   * Create the requested archive
   */
  public function createArchive() {

    $checked_folders = array();

    /**
     * Sort the folder and file objects into checked and unchecked arrays
     *  Foreach folder, get all files inside - if any are listed in the unchecked_files array, discard
     *                  Get all folders, if any are listed in the unchecked_folders array, discard
     *                  If any are already listed, discard
     */
    foreach ($this->checkedFolderObjects as $folder_id => $folder_obj) {
      if ($folder_obj['checked'] === FALSE) {
        array_push($this->uncheckedFolderIds, $folder_obj['id']);
      }
      else {
        $checked_folders[] = $folder_obj['id'];
      }
    }

    foreach ($this->checkedFileObjects as $file_id => $file_obj) {
      if ($file_obj['checked'] === FALSE) {
        array_push($this->uncheckedFileIds, $file_obj['id']);
      }
      else {
        $this->filesToBeDownloaded[] = (int) $file_obj['id'];
      }
    }

    // @TIM fix this method
    foreach ($checked_folders as $cid) {
      $this->generateAllFilesUnderCidRecursively($cid);
    }

    if (count($this->filesToBeDownloaded) > 0) {
      $this->filesToBeDownloaded = implode(',', $this->filesToBeDownloaded);

      $result = db_query("SELECT fid, fname, size, title, cid FROM {filedepot_files} WHERE fid IN ({$this->filesToBeDownloaded})");
      $file_count = 0;
      $f_array = array();
      while ($A = $result->fetchAssoc()) {
        $f_array_tmp = $A['fname'] . " - s= " . $A['size'];
        if ($this->hasViewPermission($A['cid']) === TRUE) {
          // Inode limit workaround [ set to 2 to introduce heavy load for profiling ]
          /* if ($file_count === 1) {
            if ($this->saveAndReopen() !== TRUE) {
            watchdog('filedepot', 'Failure when creating archive, could not close and reopen', array(), WATCHDOG_ERROR);
            $f_array_tmp .= " -:save and reopen fail:  - ";
            }
            else {
            $f_array_tmp .= " -:save and reopen success:- ";
            }

            $file_count = 0;
            } */

          $sourcefile = $this->filedepotStoragePath . "/{$A['cid']}/{$A['fname']}";

          if (file_exists($sourcefile)) {



            $archive_path = $this->getProcessedPath($A['cid']); // . $A['title'];
            $destination_dir = $this->archiveStorePath . $archive_path . "";
            // Make sure CID exists
            if (!file_exists($destination_dir)) {
              $cmd = "mkdir -p '{$destination_dir}'";
              shell_exec("mkdir -p '{$destination_dir}'");
              shell_exec("chmod 777 -R '{$destination_dir}'");

              //@mkdir($destination_dir, 0777, TRUE);
            }

            $this->permissionCount++;
            // copy file directory to fd directory
            $res = file_unmanaged_copy($sourcefile, $destination_dir . $A['title'], FILE_EXISTS_REPLACE);

            //$res = $this->addFile($sourcefile, $archive_path);
            $res_str = ($res) ? " TRUE " : "FALSE";
            $f_array_tmp .= " - file added to archive ({$res_str}): {$destination_dir}{$A['title']}";
            $file_count++;
          }
          else {
            watchdog("filedepot", "Missing file @file", array('@file' => $sourcefile), WATCHDOG_WARNING);
          }
        }
        else {
          watchdog("filedepot_debug", "invalid perms for {$A['cid']}");
        }

        $f_array[] = $f_array_tmp;
      }

      watchdog("filedepot_debug", "file_download_listing <pre>" . print_r($f_array, TRUE) . "</pre>");
    }

    // create zip archive
    shell_exec("chmod 777 -R {$this->archiveStorePath}");
    $ret = shell_exec("cd '{$this->archiveStorePath }'; zip -r  {$this->zipFileName} *");
    // unlink folder
    shell_exec("chmod 777 {$this->zipFileName}");
    shell_exec("rm -rf {$this->archiveStorePath}");
    watchdog("filedepot_debug", "zip archive created at {$this->zipFileName} with return {$ret} ");
  }

}

/**
 * This class tracks the hierarchy of the various categories to minimize database queries
 */
class ProcessedPathObject {

  public $catPid;
  public $catName;

}
?>
