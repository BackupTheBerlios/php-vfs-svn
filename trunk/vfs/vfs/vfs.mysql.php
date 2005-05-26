<?php // $Id: $
/* PHP-vfs, a virtual file system for PHP5
Copyright (C) 2005  Andrew Koester

This library is free software; you can redistribute it and/or
modify it under the terms of the GNU Lesser General Public
License as published by the Free Software Foundation; either
version 2.1 of the License, or (at your option) any later version.

This library is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public
License along with this library; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA */

/* MySQL structure
	directories
	---
	id		parent	name
	0		0		(root)
	1		0		whee
	
	files
	---
	id		data	nextblock
	0		aadga	1
	1		aga00b	-1
	
	fat
	---
	id		dir		name		flags	atime	mtime
	0		0		mew.txt		uu		351351	312654
	1		0		whee.bat			351351	312654
	2		1		hee.jpg		uu gz	351351	312654
	
	
	Notes: Files will be split into 50kb blocks (after formatting). Defragmentation may or may not be nessicary.
*/

/* Global variables for VFS+MySQL:
	vfs_mysql_host		Hostname of the mySQL server			Can be overwritten by URI
	vfs_mysql_username	Username to access the mySQL server		Required
	vfs_mysql_password	Password of the user					Required
	vfs_mysql_database	Database to use							Required
	vfs_mysql_prefix	Prefix for the tables					Required
*/

// How to use it: example_command("vfs+mysql://fsname@host/directory/file.ext");
stream_wrapper_register("vfs+mysql","VFS_MySQL") or die("Failed to register VFS+MySQL wrapper.");

class VFS_MySQL {
	var $usegzip = true;	// GZIP files before putting in database?

	var $mysql;
	var $thiserror = false;
	var $fpath;
	var $fmode;
	var $foptions;
	var $fopened_path;
	var $fpointer;
	var $fakepath;
	
	var $fsname = "default";

	public function _constructor() {
		if ($GLOBALS["vfs_mysql_host"] == "") { $host = "localhost"; } else { $host = $GLOBALS["vfs_mysql_host"]);
		if ($GLOBALS["vfs_mysql_username"] == "") { $username = "root"; } else { $username = $GLOBALS["vfs_mysql_username"]);
		if ($GLOBALS["vfs_mysql_database"] == "") { trigger_error("Cannot have a blank database.",E_USER_ERROR); } else { $database = $GLOBALS["vfs_mysql_database"]; }
		if ($GLOBALS["vfs_mysql_password"] == "") { $password = ""; } else { $password = $GLOBALS["vfs_mysql_password"]);
		if ($GLOBALS["vfs_mysql_prefix"] == "") { $prefix = ""; } else { $prefix = $GLOBALS["vfs_mysql_prefix"]; }
		$this->mysql = mysql_connect($host,$username,$password);
		mysql_select_db($database);
	}
	public function _destructor() {
		mysql_close($this->mysql);
	}
    public function stream_open($path,$mode,$options,$opened_path)
	{
		$this->fpath = $path;
		$this->fmode = $mode;
		$this->foptions = $options;
		$this->fopened_path = $opened_path;
		
		// Options: STREAM_REPORT_ERRORS
			//$this->doerror = true;
		
		// Do fun things to open it from mySQL
		// You probably want to export to real files and operate from there
		// Return true or false depending on mySQL success
		// File modes are important later on to what we do with it
		//  (Ex. if we wrote, we may want to re-upload the file -- md5 check for diff?)
		$this->fpointer = fopen($this->fakepath,$mode);
	}
	public function stream_close() {
		// We close connection on destruction
		// We will want to perform re-upload if nessicary
		return fclose($this->fpointer);
	}
	public function stream_read($count) {
		return fread($this->fpointer,$count);
	}
	public function stream_write($data) {
		return fwrite($this->fpointer,$data);
	}
	public function stream_eof() {
		return feof($this->fpointer);
	}
	public function stream_tell() {
		return ftell($this->fpointer);
	}
	public function stream_seek($offset,$whence) {
		return fseek($this->fhandle,$offset,$whence)
	}
	public function stream_flush() {
		return fflush($this->fhandle);
	}
	/*public function stream_stat() {
		trigger_error("Cannot use fstat with this resource.",E_USER_ERROR);
	}*/
	public function unlink($path) {
		// Remove the file given!
	}
	public function rename($path_from,$path_to) {
		// Rename the file given!
	}
	public function mkdir($path,$mode,$options) {
		// Make the directory given!
		// Options: STREAM_REPORT_ERRORS  STREAM_MKDIR_RECURSIVE
	}
	public function rmdir($path,$options) {
		// Remove the directory given!
		// Options: STREAM_REPORT_ERRORS
	}
	public function dir_opendir($path,$options) {
		// Some.. thing.. to explore?
		// See the real opendir()
	}
	public function url_stat($path,$flags) {
		// Flags: STREAM_URL_STAT_LINK  STREAM_URL_STAT_QUIET
		$dev = 0; $ino = 0; $mode = 0; $nlink = 0; $uid = 0; $gid = 0; $rdev = -1; $ctime = 0; $blksize = -1; $blocks = 0;
		$realstat = stat($this->fakepath);
		$size = $realstat["size"];
		$atime = 0; // Get the time from mySQL
		$mtime = 0; // Get the time from mySQL
		
		$array = array(
			0 => $dev, "dev" => $dev,
			1 => $ino, "ino" => $ino,
			2 => $mode, "mode" => $mode,
			3 => $nlink, "nlink" => $nlink,
			4 => $uid, "uid" => $uid,
			5 => $gid, "gid" => $gid,
			6 => $rdev, "rdev" => $rdev,
			7 => $size, "size" => $size,
			8 => $atime, "atime" => $atime,
			9 => $mtime, "mtime" => $mtime,
			10 => $ctime, "ctime" => $ctime,
			11 => $blksize, "blksize" => $blksize,
			12 => $blocks, "blocks" => $blocks
		);
		return $array;
	}
	public function dir_readdir() {
		// Return the next filename
		// See something about readdir()
	}
	public function dir_rewinddir() {
		// Go back to the first file in the dir
		// See something about rewinddir()	
	}
	
	// This creates a new filesystem on the VFS
	public function VFS_CreateFS($name) {
		// Create directory table
		$sql = "CREATE TABLE `".$GLOBALS["mysql_vfs_prefix"].$name."_directories` (";
		// Blah blah, finish me
		$sql .= ");";
		
		// Create file data table
		$sql .= "CREATE TABLE `".$GLOBALS["mysql_vfs_prefix"].$name."_files` (";
		// Blah blah, finish me
		$sql .= ");";

		// Create FAT table
		$sql = "CREATE TABLE `".$GLOBALS["mysql_vfs_prefix"].$name."_fat` (";
		// Blah blah, finish me
		$sql .= ");";

		mysql_query($sql);
	}
	
	// Imports a set of real directories into the VFS
	public function VFS_Import($srcpath,$destpath) {
	
	}
	
	// Exports a VFS directory structure to a real FS
	public function VFS_Export($srcpath,$destpath) {
		
	}

	// Breaks apart a path for use
	public function BreakApartPath($path) {
		$parsed = parse_url($path);
		if ($parsed["scheme"] != "vfs+mysql") { die("Bad scheme!"); /* How'd this happen?!? */ }
		if ($parsed["username"] != "") { $this->fsname = $parsed["username"]; }
		if ($parsed["host"] != "") { $GLOBALS["vfs_mysql_host"] = $parsed["host"]; }
		if ($parsed["path"] == "") { die("No path"); /* Do something about it */ }
			else { return $parsed["path"]; }
	}
	
	// Builds a table name
	private function buildTableName($base) {
		return $GLOBALS["mysql_vfs_prefix"].$this->fsname."_".$base;
	}

	// Read an entire file into a string from the VFS
	public function readWholeFile($id) {
		$next = $id;
		$data = "";
		while ($next > -1) {
			$query = mysql_query("SELECT * FROM `".buildTableName("files")."` WHERE `id`='".$next."'");
			if (mysql_num_rows($query)) { /* Do something about it! */ $next = -1; break; }
			$results = mysql_fetch_assoc($query);
			$next = $results["nextblock"];
			$data .= $results["data"];
		}
		// Deal with flags and decompression, etc.
		return $data;
	}
	
	// Finds the directory number entry in the directory table
	private function FindDirID($path) {
		$path = str_replace("\\","/",$path);	// Fix slashes
		if ($path == "/") {
			// Single is always root
			$dir = 0;
		}
		elseif (substr($path,0,1) == "/") {
			// Strip leading slash
			$path = substr($path,1);
		}
		
		if ($dir != 0) {
			// Find the directories and recurse in reverse order
			$dirs = explode("/",$path);
			$lastdir = 0;	// Start at root
			for ($i = 0; $i < count($dirs); $i++) {
				$parent = "`parent`='".$lastdir."' AND ";
				$query = mysql_query("SELECT * FROM `".$this->buildTableName("directories")."` WHERE (".$parent."`name`='".$dirs[$i]."')");
				if (mysql_num_rows($query) == 0) { return false; /* Directory not found */ }
				$result = mysql_fetch_assoc($query);
				$lastdir = $result["id"];
			}
		}
		return $dir;
	}
	
	// Finds the file number entry in the FAT
	private function FindFileID($dirid,$filename) {
		$query = mysql_query("SELECT * FROM `".$this->buildTableName("fat")."` WHERE (`filename`='".$filename."' AND `dir`='".$dirid."')");
		if (mysql_num_rows($query) == 0) { return false; /* File not found */ }
		$result = mysql_fetch_assoc($query);
		return $result["id"];
	}
}

?>