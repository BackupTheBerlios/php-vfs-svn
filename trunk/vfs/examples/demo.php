<?php // $Id$
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

// This demonstrated creating a filesystem, adding a file, and outputting it to the browser.

// Change this to the location of your VFS include file
require_once("../vfs/vfs.mysql.php");

// Change these to your server
$GLOBALS["vfs_mysql_host"] = "localhost";
$GLOBALS["vfs_mysql_username"] = "vfstest";
$GLOBALS["vfs_mysql_password"] = "hello";
$GLOBALS["vfs_mysql_database"] = "vfstest";
$GLOBALS["vfs_mysql_prefix"] = "db_";

$vfsobject = new $vfs_mysql;
$vfsobject->VFS_CreateFS("vol1");

$file = fopen("vfs+mysql://vol1/demo.htm");
fwrite($file,"<html><body>This is a test!</body></html>");
fclose($file);

print file_get_contents("vfs+mysql://vol1/demo.htm");

?>