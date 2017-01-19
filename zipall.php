<!DOCTYPE HTML>
<html lang="ru-RU">
<head>
  <title>MySQL and Files archiver</title>
  <meta charset="UTF-8">
</head>
<body>
<?php
/**
 * MySQL and Files archiver
 *
 * @author     Ivan Shabanov (https://www.facebook.com/ivan.shabanov.98)
 * @copyright  Copyright (c) 2016 Ivan Shabanov
 * @license    New BSD License
 * @version    1.0
 */

echo '<p>MySQL and Files archiver</p>';

function microtime_float()
{
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}


/**
 * MySQL database dump.
 *
 * @author     David Grudl (http://davidgrudl.com)
 * @copyright  Copyright (c) 2008 David Grudl
 * @license    New BSD License
 * @version    1.0
 */
class MySQLDump
{
	const MAX_SQL_SIZE = 1e6;

	const NONE = 0;
	const DROP = 1;
	const CREATE = 2;
	const DATA = 4;
	const TRIGGERS = 8;
	const ALL = 15; // DROP | CREATE | DATA | TRIGGERS

	/** @var array */
	public $tables = array(
		'*' => self::ALL,
	);

	/** @var mysqli */
	private $connection;


	/**
	 * Connects to database.
	 * @param  mysqli connection
	 */
	public function __construct(mysqli $connection, $charset = 'utf8')
	{
		$this->connection = $connection;

		if ($connection->connect_errno) {
			throw new Exception($connection->connect_error);

		} elseif (!$connection->set_charset($charset)) { // was added in MySQL 5.0.7 and PHP 5.0.5, fixed in PHP 5.1.5)
			throw new Exception($connection->error);
		}
	}


	/**
	 * Saves dump to the file.
	 * @param  string filename
	 * @return void
	 */
	public function save($file)
	{
		$handle = strcasecmp(substr($file, -3), '.gz') ? fopen($file, 'wb') : gzopen($file, 'wb');
		if (!$handle) {
			throw new Exception("ERROR: Cannot write file '$file'.");
		}
		$this->write($handle);
	}


	/**
	 * Writes dump to logical file.
	 * @param  resource
	 * @return void
	 */
	public function write($handle = NULL)
	{
		if ($handle === NULL) {
			$handle = fopen('php://output', 'wb');
		} elseif (!is_resource($handle) || get_resource_type($handle) !== 'stream') {
			throw new Exception('Argument must be stream resource.');
		}

		$tables = $views = array();

		$res = $this->connection->query('SHOW FULL TABLES');
		while ($row = $res->fetch_row()) {
			if ($row[1] === 'VIEW') {
				$views[] = $row[0];
			} else {
				$tables[] = $row[0];
			}
		}
		$res->close();

		$tables = array_merge($tables, $views); // views must be last

		$this->connection->query('LOCK TABLES `' . implode('` READ, `', $tables) . '` READ');

		$db = $this->connection->query('SELECT DATABASE()')->fetch_row();
		fwrite($handle, "-- Created at " . date('j.n.Y G:i') . " using David Grudl MySQL Dump Utility\n"
			. (isset($_SERVER['HTTP_HOST']) ? "-- Host: $_SERVER[HTTP_HOST]\n" : '')
			. "-- MySQL Server: " . $this->connection->server_info . "\n"
			. "-- Database: " . $db[0] . "\n"
			. "\n"
			. "SET NAMES utf8;\n"
			. "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n"
			. "SET FOREIGN_KEY_CHECKS=0;\n"
		);

		foreach ($tables as $table) {
			$this->dumpTable($handle, $table);
		}

		fwrite($handle, "-- THE END\n");

		$this->connection->query('UNLOCK TABLES');
	}


	/**
	 * Dumps table to logical file.
	 * @param  resource
	 * @return void
	 */
	public function dumpTable($handle, $table)
	{
		$delTable = $this->delimite($table);
		$res = $this->connection->query("SHOW CREATE TABLE $delTable");
		$row = $res->fetch_assoc();
		$res->close();

		fwrite($handle, "-- --------------------------------------------------------\n\n");

		$mode = isset($this->tables[$table]) ? $this->tables[$table] : $this->tables['*'];
		$view = isset($row['Create View']);

		if ($mode & self::DROP) {
			fwrite($handle, 'DROP ' . ($view ? 'VIEW' : 'TABLE') . " IF EXISTS $delTable;\n\n");
		}

		if ($mode & self::CREATE) {
			fwrite($handle, $row[$view ? 'Create View' : 'Create Table'] . ";\n\n");
		}

		if (!$view && ($mode & self::DATA)) {
			$numeric = array();
			$res = $this->connection->query("SHOW COLUMNS FROM $delTable");
			$cols = array();
			while ($row = $res->fetch_assoc()) {
				$col = $row['Field'];
				$cols[] = $this->delimite($col);
				$numeric[$col] = (bool) preg_match('#^[^(]*(BYTE|COUNTER|SERIAL|INT|LONG$|CURRENCY|REAL|MONEY|FLOAT|DOUBLE|DECIMAL|NUMERIC|NUMBER)#i', $row['Type']);
			}
			$cols = '(' . implode(', ', $cols) . ')';
			$res->close();


			$size = 0;
			$res = $this->connection->query("SELECT * FROM $delTable", MYSQLI_USE_RESULT);
			while ($row = $res->fetch_assoc()) {
				$s = '(';
				foreach ($row as $key => $value) {
					if ($value === NULL) {
						$s .= "NULL,\t";
					} elseif ($numeric[$key]) {
						$s .= $value . ",\t";
					} else {
						$s .= "'" . $this->connection->real_escape_string($value) . "',\t";
					}
				}

				if ($size == 0) {
					$s = "INSERT INTO $delTable $cols VALUES\n$s";
				} else {
					$s = ",\n$s";
				}

				$len = strlen($s) - 1;
				$s[$len - 1] = ')';
				fwrite($handle, $s, $len);

				$size += $len;
				if ($size > self::MAX_SQL_SIZE) {
					fwrite($handle, ";\n");
					$size = 0;
				}
			}

			$res->close();
			if ($size) {
				fwrite($handle, ";\n");
			}
			fwrite($handle, "\n");
		}

		if ($mode & self::TRIGGERS) {
			$res = $this->connection->query("SHOW TRIGGERS LIKE '" . $this->connection->real_escape_string($table) . "'");
			if ($res->num_rows) {
				fwrite($handle, "DELIMITER ;;\n\n");
				while ($row = $res->fetch_assoc()) {
					fwrite($handle, "CREATE TRIGGER {$this->delimite($row['Trigger'])} $row[Timing] $row[Event] ON $delTable FOR EACH ROW\n$row[Statement];;\n\n");
				}
				fwrite($handle, "DELIMITER ;\n\n");
			}
			$res->close();
		}

		fwrite($handle, "\n");
	}


	private function delimite($s)
	{
		return '`' . str_replace('`', '``', $s) . '`';
	}

}

  
  


        function FileListinfile($directory, $outputfile) {
          if ($handle = opendir($directory)) {
            while (false !== ($file = readdir($handle))) {
              if (is_file($directory.$file)) {
                if (!(($directory == './') and (substr($file,-4) == '.zip'))) {
                  file_put_contents($outputfile ,$directory.$file."\n", FILE_APPEND);
                }
              } elseif ($file != '.' and $file != '..' and is_dir($directory.$file)) {
                FileListinfile($directory.$file.'/', $outputfile);
              }
            }
          }
          closedir($handle);
        }

      function GetCMS() {
        $result = array('CMS' => 'Unkonwn',
                          'dbhost' => 'localhost',
                          'dbuser' => '',
                          'dbpass' => '',
                          'dbname' => '',
                          'charset' => 'utf8'
                          );
        if (file_exists($_SERVER['DOCUMENT_ROOT'].'/bitrix/php_interface/dbconn.php')) {
          include($_SERVER['DOCUMENT_ROOT'].'/bitrix/php_interface/dbconn.php');
          $result = array('CMS' => 'BITRIX',
                          'dbhost' => $DBHost,
                          'dbuser' => $DBLogin,
                          'dbpass' => $DBPassword,
                          'dbname' => $DBName,
                          'charset' => 'utf8'
                          );
        } else if (file_exists($_SERVER['DOCUMENT_ROOT'].'/configuration.php')){
          include($_SERVER['DOCUMENT_ROOT'].'/configuration.php');
          $conf = new JConfig();
          $result = array('CMS' => 'Joomla',
                          'dbhost' => $conf->host,
                          'dbuser' => $conf->user,
                          'dbpass' => $conf->password,
                          'dbname' => $conf->db,
                          'charset' => 'utf8'
                          );
        } else if (file_exists($_SERVER['DOCUMENT_ROOT'].'/wp-config.php')){
          include($_SERVER['DOCUMENT_ROOT'].'/wp-config.php');
          $result = array('CMS' => 'WordPress',
                          'dbhost' => DB_HOST,
                          'dbuser' => DB_USER,
                          'dbpass' => DB_PASSWORD,
                          'dbname' => DB_NAME,
                          'charset' => 'utf8'
                          );
        } else if (file_exists($_SERVER['DOCUMENT_ROOT'].'/engine/data/dbconfig.php')){
          include($_SERVER['DOCUMENT_ROOT'].'/engine/data/dbconfig.php');
          $result = array('CMS' => 'DLE',
                          'dbhost' => DBHOST,
                          'dbuser' => DBUSER,
                          'dbpass' => DBPASS,
                          'dbname' => DBNAME,
                          'charset' => 'utf8'
                          );
        } else if (file_exists($_SERVER['DOCUMENT_ROOT'].'/conf.global.php')){
          include($_SERVER['DOCUMENT_ROOT'].'/conf.global.php');
          $result = array('CMS' => 'Axioma Master',
                          'dbhost' => $VARS['db_host'],
                          'dbuser' => $VARS['db_user'],
                          'dbpass' => $VARS['db_pass'],
                          'dbname' => $VARS['db_name'],
                          'charset' => 'utf8'
                          );
        } else if (file_exists($_SERVER['DOCUMENT_ROOT'].'/manager/includes/config.inc.php')){
          include($_SERVER['DOCUMENT_ROOT'].'/manager/includes/config.inc.php');
          $result = array('CMS' => 'MODx',
                          'dbhost' => $database_server,
                          'dbuser' => $database_user,
                          'dbpass' => $database_password,
                          'dbname' => $dbase,
                          'charset' => $database_connection_charset
                          );
        } else if (file_exists($_SERVER['DOCUMENT_ROOT'].'/docs/config.ini')){
          $ini = parse_ini_file($_SERVER['DOCUMENT_ROOT'].'/docs/config.ini', true);
          $result = array('CMS' => 'UMI',
                          'dbhost' => $ini['connections']['core.host'],
                          'dbuser' => $ini['connections']['core.login'],
                          'dbpass' => $ini['connections']['core.password'],
                          'dbname' => $ini['connections']['core.dbname'],
                          'charset' => 'utf8'
                          );
        }
        return $result;
      }


      if ($_GET['archivzip'] == '') {
      
            $config = GetCMS();
            echo '<p>Avtodetect CMS: '.$config['CMS'].'</p>';
            echo '<form action ="zipall.php?archivzip=start" method="post">
                 <P>Mysql<br />
                 <input type="text" name="dbhost" placeholder="MySQL host" title="MySQL host" value="'.$config['dbhost'].'" style="width: 50%"/><br/>
                 <input type="text" name="dbname" placeholder="MySQL DB name. If empty dont make MySQL Archive" title="MySQL DB name. If empty dont make MySQL Archive" value="'.$config['dbname'].'"  style="width: 50%" /><br/>
                 <input type="text" name="dbuser" placeholder="MySQL User" title="MySQL User" value="'.$config['dbuser'].'" style="width: 50%"/><br/>
                 <input type="text" name="dbpass" placeholder="MySQL Password" title="MySQL Password" value="'.$config['dbpass'].'" style="width: 50%"/><br/>
                 <input type="text" name="charset" placeholder="Charset" title="Charset" value="'.$config['charset'].'" style="width: 50%"/><br/>
                 </p>
                 <p>Files<br />
                 <input type="checkbox" name="allfiles" value="1" title="Archive all files" checked /> Archive all files</p>
                 <p><input type="submit" value="START" /></p>
                 </form>';
      } else if ($_GET['archivzip'] == 'start') {
          $filenamezip='site_'.date('Y-m-d-H-i-s').".zip";
          if ($_REQUEST['dbname'] != '')  {
              $filenamesql=$_REQUEST['dbname'].'_'.date('Y-m-d-H-i-s').".sql.gz";
              $mysqli = new mysqli($_REQUEST['dbhost'], $_REQUEST['dbuser'], $_REQUEST['dbpass'], $_REQUEST['dbname']);
              $dump = new MySQLDump($mysqli, $_REQUEST['charset']);
              $dump->save($filenamesql);
          };
          if ($_REQUEST['allfiles']== '1') {
              echo '<script>location.replace("zipall.php?archivzip=files&filename='.$filenamezip.'"); </script>';
          } else {
              echo '<p><a href="'.$filenamesql.'">'.$filenamesql.'</a></p>';
          }
      } else if ($_GET['archivzip'] == 'files') {
                $filenamezip = $_GET['filename'];
                echo '<p>Collect files to zip</p>';
                $were = 'zipall.php?archivzip=go&filename='.$filenamezip.'&n=0';
                echo 'Wait <span id="counter">10</span> second<br />';
                echo '<p><a href="'.$were.'">I dont want wait. GO GO GO.</a></p>';
                echo '<script type="text/javascript">
                function TimeOut () {
                var timec = parseInt(document.getElementById("counter").innerHTML, 10);
                 timec--;
                 document.getElementById("counter").innerHTML = timec;
                 if (timec <= 0){
                   location.replace("'.$were.'");
                   clearInterval(idtimer);
                  }
                }
                var idtimer = setInterval("TimeOut()", 1000);
                </script>';
                @unlink ('filestozip.txt');
                @unlink ($filenamezip);                
                FileListinfile('./', 'filestozip.txt');
      } else if ($_GET['archivzip'] == 'go') {
                $filenamezip = $_GET['filename'];
                echo '<p>Archivation...</p>';
                $starttime = microtime_float();
                $n=$_GET['n']+0;
                $files=file('filestozip.txt');
                $zip = new ZipArchive;
                $curtime=microtime_float();
                $runtime=$curtime-$starttime ;
                $curfiles = 0;
                $cursize = 0;
                $stop = false;
                if (filesize($filenamezip) > 100 * 1024 * 1024) {
                  $filenamezip = str_replace('.zip', '_.zip', $filenamezip);
                }
                $zip->open($filenamezip, ZipArchive::CREATE);
                while (!$stop) {
                  $files[$n]= rtrim(substr($files[$n],2));
                  $zip->addFile($files[$n]);
                  $cursize += filesize($files[$n]);
                  $n ++;
                  $curfiles ++;
                  $curtime=microtime_float();
                  $runtime=$curtime-$starttime;
                  if ($runtime > 10) { $stop = true; }
                  if ($curfiles > 1000) { $stop = true; }
                  if ($cursize > 10 * 1024 * 1024) { $stop = true; }
                  if ($n >= count($files)) { $stop = true; }
                }
                $zip->close();
                $curtime=microtime_float();
                $runtime=$curtime-$starttime ;
                echo '<div style="width: 100%; background: #ddd; min-height: 70px; position: relative;">';
                echo '<div style="width: '.($n / count($files) * 100).'%; height: 100%; background: #f55; position: absolute; top: 0px; left: 0px;">';
                echo '</div>';
                echo '<div style="width: 100%; height: 100%; background: none; position: absolute; top: 0px; left: 0px;">';
                echo  'Current session worktime '.$runtime.'sec. Archived '.$curfiles.' files. Last file is '.$n.'/'.count($files).' '.$files[$n - 1].'';
                echo '</div>';
                echo '</div>';

                if ($n < count($files)) {
                    $were = 'zipall.php?archivzip=go&filename='.$filenamezip.'&n='.$n;
                    echo '<div>Wait <span id="counter">10</span> second<br />';
                    echo '<p><a href="'.$were.'">I dont want wait. GO GO GO.</a></p></div>';
                    echo '<script type="text/javascript">
                    function TimeOut () {
                    var timec = parseInt(document.getElementById("counter").innerHTML, 10);
                     timec--;
                     document.getElementById("counter").innerHTML = timec;
                     if (timec <= 0){
                       location.replace("'.$were.'");
                       clearInterval(idtimer);
                      }
                    }
                    var idtimer = setInterval("TimeOut()", 1000);
                    </script>';
                } else {
                  @unlink ('filestozip.txt');
                  @unlink (__FILE__);                  
                  
                  echo '<p><a href="'.$filenamezip.'">'.$filenamezip.'</a></p>';
                  while (strpos($filenamezip , '_.zip') > 0) {
                   $filenamezip = str_replace('_.zip','.zip', $filenamezip);
                   echo '<p><a href="'.$filenamezip.'">'.$filenamezip.'</a></p>';
                  }
                }
                
      }

?>
</body>
</html>
