<?php


// Production
//error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
//ini_set('display_errors', 0);

// Development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
//include "login.php";

?>
<!DOCTYPE html>
<html>

<head>
  <meta charset="utf-8">
  <meta name="robots" content="noindex, nofollow">
  <title>Classic PHP File Commander</title>

    <meta name="viewport" content="width=device-width, initial-scale=1">

	<!-- Latest compiled and minified CSS -->
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">

	<!-- Optional theme -->
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap-theme.min.css" integrity="sha384-rHyoN1iRsVXV4nD0JutlnGaslCJuC7uwjduW9SVrLvRYooPp2bWYgmgJQIXwl/Sp" crossorigin="anonymous">

	<!-- Latest compiled and minified JavaScript -->
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous"></script>

    <!-- HTML5 shim and Respond.js for IE8 support of HTML5 elements and media queries -->
    <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
    <!--[if lt IE 9]>
      <script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
      <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->

</head>

<body>
<?php
	$exclude_list = array(".", "..");
	error_reporting(E_ALL);
/************************************************/
/* GET Variables  */
/************************************************/

    $dir   = (isset($_GET['dir'])) ? $_GET['dir'] : getcwd();
    $view   = (isset($_GET['view'])) ? $_GET['view'] : false;
    $tpage   = (isset($_GET['tpage'])) ? $_GET['tpage'] : false;

    $dir   = htmlspecialchars($dir, ENT_QUOTES, 'UTF-8');
    $view   = htmlspecialchars($view, ENT_QUOTES, 'UTF-8');
    $tpage   = htmlspecialchars($tpage, ENT_QUOTES, 'UTF-8');


    $dir   = substr($dir, 0, 250);
    $view   = substr($view, 0, 250);
    $tpage   = substr($tpage, 0, 250);


	$bad = array('..', '../', '..\\', '//');

	echo "dir=".$dir."<br>";
	echo "view=".$view."<br>";
	echo "tpage=".$tpage."<br>";

	if ($dir) {
  		$dir_path = $dir;
	}else{
		$dir_path = $_SERVER["DOCUMENT_ROOT"]."/";
		 $dir = "";
	}
	$dir_path=str_replace('//','/',$dir_path);
	$self = basename($_SERVER['PHP_SELF']);
?>

<div class="medium" style="overflow:scroll; overflow-x:hidden; height:382px;">
  <?php
  $directories = array_diff(scandir($dir_path), $exclude_list);
  echo '<ul style="list-style:none;padding:0">';
  echo '<li style="margin-left:1em;">&#11014; <a href="?tpage='.$tpage.'&dir='.dirname($dir_path).'/">up</a></li>';
//📝💾
  foreach($directories as $entry) {
    $dir_path_entry= $dir_path."/".$entry;
    $stat = stat($dir_path_entry);
    $tit=implode(",", $stat);
    if(is_dir($dir_path_entry)) {
        echo "<li style='margin-left:1em;'>&#128193; <a href='?dir=".$dir_path.$entry."/"."'>".$entry."</a></li>";
    }else{
        $file_path=str_replace('\\','/',$dir_path_entry);
        $file_path=str_replace($_SERVER['DOCUMENT_ROOT'],'',$file_path);
        $file_path='https://'.$_SERVER['HTTP_HOST'].$file_path;
        echo '<li style="margin-left:1em;">📝 ';
        echo '<a href="?tpage='.$dir_path."/".$entry.'&filename='.$entry.'&view=true&dir='.$dir_path.'" target="main">'.$entry.
        '</a>  <a href="'.$file_path.'" title="'.$tit.'" target="main"> ▶️ </a>
        </li>';
    }
  }
  echo "</ul>";
 echo '</div>';

/***************
 * Source view *
 ***************/
if ($view)
{
    echo '<div class="medium" style="overflow:scroll; overflow-x:hidden; height:382px;">';
    echo "Displaying file:".$tpage."<hr>";
    echo internoetics_highlight_file($tpage);
    //highlight_file($tpage);
    echo '</div>';
}
?>


    <!-- jQuery (necessary for Bootstrap's JavaScript plugins) -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
    <!-- Include all compiled plugins (below), or include individual files as needed -->
    <script src="js/bootstrap.min.js"></script>
  </body>
</html>


<?php
/*
  PHP's Syntax highlight_file() Function with Line Numbers and Alternating Coloured Rows
  http://www.internoetics.com/2016/03/17/php-syntax-highlight-file-function-line-numbers/
  http://php.net/manual/en/function.highlight-file.php
*/

function internoetics_highlight_file($file) {
  $return="";
  $code = substr(highlight_file($file, true), 36, -15);
  $lines = explode('<br />', $code);
  //$lines = explode('<br />', $code);
  $lines = array_combine(range(1, count($lines)), $lines);

  $lineCount = count($lines);
  $padLength = strlen($lineCount);

  $return .= '<div style="width: 100%;"><code>';
   foreach($lines as $i => $line) {
     $lineNumber = str_pad($i + 1,  $padLength, '0', STR_PAD_LEFT);
       if ($i % 2 == 0) {
         $numbgcolor = '#C8E1FA';
         $linebgcolor = '#F7F7F7';
         $fontcolor = '#3F85CA';
          } else {
         $numbgcolor = '#DFEFFF';
         $linebgcolor = '#FDFDFD';
         $fontcolor = '#5499DE';
       }
     $return .= '<br><div style="background-color: ' . $numbgcolor . '; width: 23; float: left; padding-left: 2px; padding-right: 2px; text-align: center; color: ' . $fontcolor . ';">' . $i . '</div><div style="background-color: ' . $linebgcolor . '; margin-left: 0; float: left; padding-left: 5px;  width: calc(100% - 32px);">' . $line . '</div>';
   }
 $return .= '</code></div>';
return $return;
}

?>
