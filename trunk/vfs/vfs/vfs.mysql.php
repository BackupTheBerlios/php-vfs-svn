<?php // $Id$
/* PHP-vfs, a virtual file system for PHP5
Written by: Andrew Koester

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

// This is the PHP5 version. It is not compatable with PHP4 due to class method differences.

/* MySQL structure
	directories
	---
	id		parent	name
	0		0		(root)
	1		0		whee
	
	files
	---
	id		data
	0		aadga
	1		aga00b
	
	fat
	---
	id		dir		name		flags	ctime	atime	mtime
	0		0		mew.txt				35131	351351	312654
	1		0		whee.bat	b64		35132	351351	312654
	2		1		hee.jpg		gz:b64	32510	351351	312654
	
	
	Notes: Files will be split into 500kb blocks (after formatting). Defragmentation may or may not be nessicary.
*/

/* Global variables for VFS+MySQL:
	vfs_mysql_host		Hostname of the mySQL server			Required
	vfs_mysql_username	Username to access the mySQL server		Required
	vfs_mysql_password	Password of the user					Required
	vfs_mysql_database	Database to use							Required
	vfs_mysql_prefix	Prefix for the tables					Required
*/

// How to use it: example_command("vfs+mysql://fsname/directory/file.ext");
stream_wrapper_register("vfs+mysql","VFS_MySQL") or die("Failed to register VFS+MySQL wrapper.");

// Defines
define("_FLAG_SEPERATOR_",":");
define("FLAG_GZIP","gz");
define("FLAG_BASE64","b64");

// Class definition
class VFS_MySQL {
	public $usebase64 = true;		// Use base64? Recommended.
	public $usegzip = true;			// GZIP files before putting in database?
	public $gziplevel = 5;			// GZIP compression level

	public $mysql;					// mySQL resource
	private $thiserror = false;		// Error
	private $doerror = true;		// Should we raise errors? (stream_open option)
	private $fpath;					// stream_open $path
	private $fmode;					// stream_open $mode
	private $foptions;				// stream_open $options
	private $fopened_path;			// stream_open $opened_path
	private $filedata;				// In-stream file contents
	private $fileopenchecksum;		// Checksum of the data after opening, operations
	private $fileposition;			// File 'pointer' position
	
	public $fsname = "default";

	public function __constructor() {
		if ($GLOBALS["vfs_mysql_host"] == "") { $host = "localhost"; } else { $host = $GLOBALS["vfs_mysql_host"]);
		if ($GLOBALS["vfs_mysql_username"] == "") { $username = "root"; } else { $username = $GLOBALS["vfs_mysql_username"]);
		if ($GLOBALS["vfs_mysql_database"] == "") { trigger_error("Cannot have a blank database.",E_USER_ERROR); } else { $database = $GLOBALS["vfs_mysql_database"]; }
		if ($GLOBALS["vfs_mysql_password"] == "") { $password = ""; } else { $password = $GLOBALS["vfs_mysql_password"]);
		if ($GLOBALS["vfs_mysql_prefix"] == "") { $prefix = ""; } else { $prefix = $GLOBALS["vfs_mysql_prefix"]; }
		$this->mysql = mysql_connect($host,$username,$password) or $this->raiseMySQLError();
		mysql_select_db($database) or $this->raiseMySQLError();
	}
	public function __destructor() {
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
		$pathinfo = pathinfo($this->path);
		$dirid = $this->FindDirID($pathinfo["dirname"]);
		if ($dirid === false) { /* Do something */ die("Directory not found"); }
		$fileid = $this->FindFileID($dirid,$pathinfo["basename"]);
		if ($fileid === false) {
			// Create the file in the database
			
			// Blank the filedata string
			$this->filedata = "";
		}
		else {
			$this->filedata = $this->ReadWholeFile($fileid);
		}

		$this->fileopenchecksum = md5($this->filedata);
		
		// Reset the file pointer position
		$this->fileposition = 0;
	}
	public function stream_close() {
		// Clear the data
		$this->filedata = 0;
	}
	public function stream_read($count) {
		$data = substr($this->filedata, $this->fileposition, $count);
		$this->fileposition += strlen($data);
		return $data;
	}
	public function stream_write($data) {
		$leftside = substr($this->filedata, 0, $this->fileposition);
		$rightside = substr($this->filedata, ($this->fileposition + strlen($data)));
		$this->filedata = $leftside.$data.$rightside;
		$this->fileposition += strlen($data);
		return strlen($data);
	}
	public function stream_eof() {
		return $this->fileposition >= strlen($this->filedata);
	}
	public function stream_tell() {
		return $this->fileposition;
	}
	public function stream_seek($offset,$whence) {
		switch ($whence) {
			case SEEK_SET:
				if (($offset < strlen($this->filedata)) && ($offset >= 0)) {
					$this->fileposition = $offset;
					return true;
				}
				else { return false; }
				break;
			case SEEK_CUR:
				if ($offset >= 0) {
					$this->fileposition += $offset;
					return true;
				}
				else { return false; }
				break;
			case SEEK_END:
				if ((strlen($this->filedata) + $offset) >= 0) {
					return true;
				}
				else { return false; }
				break;
	}
	public function stream_flush() {
		// We will want to perform re-upload if nessicary
		if (md5($this->filedata) != $this->fileopenchecksum) {
			// File content has changed, we need to re-upload it
			// Re-encode and stuff
			$data = $this->filedata;
			if ($this->usegzip) {
				$data = gzcompress($data,$gziplevel);
			}
			if ($this->usebase64) {
				$data = base64_encode($data);
			}
			// Deal with GC for un-needed IDs
			// Send the data to the server
			// Update the mtime
		}
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
		$dev = 0; $ino = 0; $mode = 0; $nlink = 0; $uid = 0; $gid = 0; $rdev = -1; $blksize = -1; $blocks = 0;
		$realstat = stat($this->fakepath);
		$size = $realstat["size"];
		$ctime = 0;	// Get the time from mySQL
//		$atime = 0; // Get the time from mySQL
		$atime = 0;	// Unimplemented as of yet
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
		$sql = "DROP TABLE `".$GLOBALS["mysql_vfs_prefix"].$name."_directories`;" .
				"CREATE TABLE `".$GLOBALS["mysql_vfs_prefix"].$name."_directories` (" .
				"`id` INT NOT NULL AUTO_INCREMENT ," .
				"`parent` INT NOT NULL ," .
				"`name` VARCHAR( 255 ) NOT NULL ," .
				"UNIQUE (`id`)" .
				") TYPE = MYISAM COMMENT = '".$name." directory table';"
		
		// Create the root directory, without this the system will fail!
		$sql = "INSERT INTO `".$GLOBALS["mysql_vfs_prefix"].$name."_directories` VALUES ('0', '0', '(root)');";
		
		// Create file data table
		$sql .= "DROP TABLE `".$GLOBALS["mysql_vfs_prefix"].$name."_files`;" .
				"CREATE TABLE `".$GLOBALS["mysql_vfs_prefix"].$name."_files` (" .
				"`id` INT NOT NULL AUTO_INCREMENT ," .
				"`data` LONGBLOB NOT NULL ," .
				//"`nextblock` INT NOT NULL ," .
				"UNIQUE (`id`)" .
				") TYPE = MYISAM COMMENT = '".$name." file data table';";

		// Create FAT table
		$sql = "DROP TABLE `".$GLOBALS["mysql_vfs_prefix"].$name."_fat`;" .
				"CREATE TABLE `".$GLOBALS["mysql_vfs_prefix"].$name."_fat` (" .
				"`id` INT NOT NULL AUTO_INCREMENT ," .
				"`dir` INT NOT NULL ," .
				"`name` VARCHAR( 255 ) NOT NULL ," .
				"`flags` VARCHAR( 255 ) NOT NULL , " .
				"`ctime` INT NOT NULL , " .
				"`atime` INT NOT NULL , " .
				"`mtime` INT NOT NULL , " .
				"UNIQUE (`id`)" .
				") TYPE = MYISAM COMMENT = 'vol1 file allocation table';";

		$query = mysql_query($sql);
		if ($query) { return true; } else { return false; $this->raiseMySQLError(); }
	}
	
	// Imports a set of real directories into the VFS
	public function VFS_Import($srcpath,$destpath) {
	
	}
	
	// Exports a VFS directory structure to a real FS
	public function VFS_Export($srcpath,$destpath) {
		
	}
	
	// mySQL error handler
	public function raiseMySQLError() {
		trigger_error("Problem with mySQL: ".mysql_error(),E_USER_ERROR);
	}

	// Breaks apart a path for use
	public function BreakApartPath($path) {
		$parsed = parse_url($path);
		if ($parsed["scheme"] != "vfs+mysql") { die("Bad scheme!"); /* How'd this happen?!? */ }
		if ($parsed["host"] != "") { $this->fsname = $parsed["username"]; }
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
		$flags = explode(":",$results["flags"]);
		if ($this->CheckForFlag($flags,FLAG_BASE64)) {
			$data = base64_decode($data);
		}
		if ($this->CheckForFlag($flags,FLAG_GZIP)) {
			$data = gzuncompress($data);
		}
		return $data;
	}
	
	// Reads flags from a flag list entry
	private function CheckForFlag($flags,$flag) {
		$exploded = explode(_FLAG_SEPERATOR_,$flags);
		if (in_array($flah,$exploded) {
			return true;
		}
		else {
			return false;
		}
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
				$query = mysql_query("SELECT * FROM `".$this->buildTableName("directories")."` WHERE (".$parent."`name`='".$dirs[$i]."')") or $this->raiseMySQLError();
				if (mysql_num_rows($query) == 0) { return false; /* Directory not found */ }
				$result = mysql_fetch_assoc($query);
				$lastdir = $result["id"];
			}
		}
		return $dir;
	}
	
	// Finds the file number entry in the FAT
	private function FindFileID($dirid,$filename) {
		$query = mysql_query("SELECT * FROM `".$this->buildTableName("fat")."` WHERE (`filename`='".$filename."' AND `dir`='".$dirid."')") or $this->raiseMySQLError();
		if (mysql_num_rows($query) == 0) { return false; /* File not found */ }
		$result = mysql_fetch_assoc($query);
		return $result["id"];
	}
}

?>