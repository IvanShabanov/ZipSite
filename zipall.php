<?php
echo '<p>MySQL and Files archiver</p>';

function microtime_float()
{
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}


      // класс для дампа базы данных mysql
      class MySQLDump {
      
      
          function dumpDatabase($database,$nodata = false,$nostruct = false, $filename = '') {
              // Connect to database
              $db = @mysql_select_db($database);
      
              if (!empty($db)) {
      
                  // Get all table names from database
                  $c = 0;
                  $result = mysql_list_tables($database);
                  for($x = 0; $x < mysql_num_rows($result); $x++) {
                      $table = mysql_tablename($result, $x);
                      if (!empty($table)) {
                          $arr_tables[$c] = mysql_tablename($result, $x);
                          $c++;
                      }
                  }
                  // List tables
                  $dump = '';
                  
                 
                  for ($y = 0; $y < count($arr_tables); $y++){
      
                      // DB Table name
                      $table = $arr_tables[$y];
                      if($nostruct == false){
      
                          // Structure Header
                          $structure .= "-- ------------------------------------------------ \n";
                          $structure .= "-- Table structure for table `{$table}` started >>> \n";
      
                          // Dump Structure
                          $structure .= "DROP TABLE IF EXISTS `{$table}`; \n";
                          $structure .= "CREATE TABLE `{$table}` (\n";
                          $result = mysql_db_query($database, "SHOW FIELDS FROM `{$table}`");
                          
                          while($row = mysql_fetch_object($result)) {
                              $structure .= "  `{$row->Field}` {$row->Type}";
                              if($row->Default != 'CURRENT_TIMESTAMP'){
                              	$structure .= (!empty($row->Default)) ? " DEFAULT '{$row->Default}'" : false;
                              }else{
                              	$structure .= (!empty($row->Default)) ? " DEFAULT {$row->Default}" : false;
                              }
                              $structure .= ($row->Null != "YES") ? " NOT NULL" : false;
                              $structure .= (!empty($row->Extra)) ? " {$row->Extra}" : false;
                              $structure .= ",\n";
      
                          }
      
                          $structure = ereg_replace(",\n$", "", $structure);
      
                          // Save all Column Indexes in array
                          unset($index);
                          $result = mysql_db_query($database, "SHOW KEYS FROM `{$table}`");
                          while($row = mysql_fetch_object($result)) {
      
                              if (($row->Key_name == 'PRIMARY') AND ($row->Index_type == 'BTREE')) {
                                  $index['PRIMARY'][$row->Key_name] = $row->Column_name;
                              }
      
                              if (($row->Key_name != 'PRIMARY') AND ($row->Non_unique == '0') AND ($row->Index_type == 'BTREE')) {
                                  $index['UNIQUE'][$row->Key_name] = $row->Column_name;
                              }
      
                              if (($row->Key_name != 'PRIMARY') AND ($row->Non_unique == '1') AND ($row->Index_type == 'BTREE')) {
                                  $index['INDEX'][$row->Key_name] = $row->Column_name;
                              }
      
                              if (($row->Key_name != 'PRIMARY') AND ($row->Non_unique == '1') AND ($row->Index_type == 'FULLTEXT')) {
                                  $index['FULLTEXT'][$row->Key_name] = $row->Column_name;
                              }
      
                          }
                          
      
                          // Return all Column Indexes of array
                          if (is_array($index)) {
                              foreach ($index as $xy => $columns) {
      
                                  $structure .= ",\n";
      
                                  $c = 0;
                                  foreach ($columns as $column_key => $column_name) {
      
                                      $c++;
      
                                      $structure .= ($xy == "PRIMARY") ? "  PRIMARY KEY  (`{$column_name}`)" : false;
                                      $structure .= ($xy == "UNIQUE") ? "  UNIQUE KEY `{$column_key}` (`{$column_name}`)" : false;
                                      $structure .= ($xy == "INDEX") ? "  KEY `{$column_key}` (`{$column_name}`)" : false;
                                      $structure .= ($xy == "FULLTEXT") ? "  FULLTEXT `{$column_key}` (`{$column_name}`)" : false;
      
                                      $structure .= ($c < (count($index[$xy]))) ? ",\n" : false;
      
                                  }
      
                              }
      
                          }
      
                          $structure .= "\n);\n\n";
                          $structure .= "-- Table structure for table `{$table}` finished <<< \n";
                          $structure .= "-- ------------------------------------------------- \n";
      
                      }
      
                      // Dump data
                      if($nodata == false) {
      
                      $structure .= " \n\n";
                      if ($filename != '') {
                        file_put_contents($filename ,$structure, FILE_APPEND); 
                        unset($structure);
                      }
                          $result     = mysql_query("SELECT * FROM `$table`");
                          $num_rows   = mysql_num_rows($result);
                          $num_fields = mysql_num_fields($result);
      
      	                $data .= "-- -------------------------------------------- \n";
      	                $data .= "-- Dumping data for table `$table` started >>> \n";

                      if ($filename != '') {
                        file_put_contents($filename ,$data, FILE_APPEND); 
                        unset($data);
                      }

      
      		            for ($i = 0; $i < $num_rows; $i++) {
      
                              $row = mysql_fetch_object($result);
                              $data .= "INSERT INTO `$table` (";
      
                              // Field names
                              for ($x = 0; $x < $num_fields; $x++) {
                                  $field_name = mysql_field_name($result, $x);
                                  $data .= "`{$field_name}`";
                                  $data .= ($x < ($num_fields - 1)) ? ", " : false;
                              }
                              $data .= ") VALUES (";
                              // Values
                              for ($x = 0; $x < $num_fields; $x++) {
                                  $field_name = mysql_field_name($result, $x);
                                  $data .= "'" . str_replace('\"', '"', mysql_real_escape_string($row->$field_name)) . "'";
                                  $data .= ($x < ($num_fields - 1)) ? ", " : false;
                              }
                              $data.= ");\n";
                              if ($filename != '') {
                                file_put_contents($filename ,$data, FILE_APPEND); 
                                unset($data);
                              }
                          }
      	                $data .= "-- Dumping data for table `$table` finished <<< \n";
      	                $data .= "-- -------------------------------------------- \n\n";
                          
                        $data.= "\n";
                        if ($filename != '') {
                          file_put_contents($filename ,$data, FILE_APPEND); 
                          unset($data);
                        }
                      }
      
                      
                      
                  }
                  $dump .= $structure . $data;
      
              }
                  return $dump;
      
          }
          function SaveToFile($data, $filename = 'mysqldump.sql'){
              $path = getcwd();
              $handle = fopen($path.'/'."$filename", 'w');
              fwrite($handle,$data);
              fclose($handle);
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
          return $CollectArray;
        }

      if ($_GET['archivzip'] == '') {
            echo '<form action ="zipall.php?archivzip=start" method="post">
                 <P>Mysql<br />
                 <input type="text" name="dbhost" placeholder="MySQL host" title="MySQL host" value="localhost" style="width: 50%"/><br/>
                 <input type="text" name="dbname" placeholder="MySQL DB name. If empty dont make MySQL Archive" title="MySQL DB name. If empty dont make MySQL Archive" value=""  style="width: 50%" /><br/>
                 <input type="text" name="dbuser" placeholder="MySQL User" title="MySQL User" value="" style="width: 50%"/><br/>
                 <input type="text" name="dbpass" placeholder="MySQL Password" title="MySQL Password" value="" style="width: 50%"/><br/>
                 <input type="text" name="dbquery" placeholder="SET NAMES QUERY" title="SET NAMES QUERY" value="SET NAMES UTF-8" style="width: 50%"/><br/>
                 </p>
                 <p>Files<br />
                 <input type="checkbox" name="allfiles" value="1" title="Archive all files" checked /> Archive all files</p>
                 <p><input type="submit" value="START" /></p>
                 </form>';
      } else if ($_GET['archivzip'] == 'start') {
          $filenamezip='site_'.date('Y-m-d-H-i-s').".zip";
          if ($_POST['dbname'] != '')  {
              $filenamesql=$_POST['dbname'].'_'.date('Y-m-d-H-i-s').".sql";
              $db = @mysql_connect($_POST['dbhost'], $_POST['dbuser'], $_POST['dbpass']);
              if ($_POST['dbquery'] != '') {
                mysql_query($_POST['dbquery']);
              }
              $dump = new MySQLDump();
              $dbdata =  $dump->dumpDatabase( $_POST['dbname'],false,false, $filenamesql);
              /* $dump->SaveToFile($dbdata, $filenamesql); */
              @mysql_close();
          };
          if ($_POST['allfiles']== '1') {
              echo '<script>location.replace("zipall.php?archivzip=files&filename='.$filenamezip.'"); </script>';
          } else {
              $filenamezip = $filenamesql.'.zip';
              $zip = new ZipArchive;
              $res = $zip->open($filenamezip, ZipArchive::CREATE);
              $zip->addFile($filenamesql);
              $zip->close();
              @unlink($filenamesql);
              echo '<p><a href="'.$filenamezip.'">'.$filenamezip.'</a></p>';
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
                $res = $zip->open($filenamezip, ZipArchive::CREATE);
       
                $curtime=microtime_float();
                $runtime=$curtime-$starttime ;
                $curfiles = 0;
                while (($runtime < 5) and ($n < count($files)) and ($curfiles < 1000)) {
                  $files[$n]= rtrim(substr($files[$n],2));
                  $zip->addFile($files[$n]);
                  $curtime=microtime_float();
                  $runtime=$curtime-$starttime ;
                  $n++;
                  $curfiles ++;
                }
                echo  'Current session worktime '.$runtime.'sec. Archived '.$curfiles.' files. Last file is '.$n.'/'.count($files).' '.$files[$n - 1].'<br />';
                $zip->close();
                if ($n < count($files)) {
                    $were = 'zipall.php?archivzip=go&filename='.$filenamezip.'&n='.$n;
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
                } else {
                  echo '<p><a href="'.$filenamezip.'">'.$filenamezip.'</a></p>';
                }
                
      }

?>