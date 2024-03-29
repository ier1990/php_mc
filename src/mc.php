<?php

// Production
//error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
//ini_set('display_errors', 0);

// Development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
//include "login.php";

/************************************************/
/*   */
/************************************************/
function internoetics_highlight_file($file,$mc_array=array())
{
    $return = "";
    $code = substr(highlight_file($file, true), 36, -15);
    $lines = explode('<br />', $code);
    $lines = array_combine(range(1, count($lines)), $lines);

    $return .= '<div style="width: 100%;"><code>';
    foreach ($lines as $i => $line) {
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
/************************************************/
/*   */
/************************************************/

$mc_array = array();

//Never changes
$mc_array['exclude_list']= array(".", "..");
$mc_array['HTTP_HOST']=$_SERVER['HTTP_HOST']; //"192.168.0.103"
$mc_array['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT']; // /var/www/html

/************************************************/
/* GET Variables  */
/************************************************/

$mc_array['dir'] =   (isset($_GET['dir'])) ? $_GET['dir'] : getcwd();
$mc_array['view'] = (isset($_GET['view'])) ? $_GET['view'] : false;
$mc_array['tpage'] =  (isset($_GET['tpage'])) ? $_GET['tpage'] : false;

/************************************************/
/*  Figure out new dir_path */
/************************************************/

$mc_array['dir'] = realpath($mc_array['dir']);
if($mc_array['dir']	== false) {$mc_array['dir'] = getcwd();}
$mc_array['dir_path'] = $mc_array['dir'] . '/';  



/************************************************/
/*  Figure out HTTP or HPPTS */
/*  https://stackoverflow.com/questions/4503135/php-get-site-url-protocol-http-vs-https */
/************************************************/

if (isset($_SERVER['HTTPS']) &&
    ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) ||
    isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
    $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
  $protocol = 'https://';
}
else {
  $protocol = 'http://';
}
$mc_array['protocol'] = $protocol; 


/************************************************/
/*  Start Header */
/************************************************/
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Classic PHP File Commander</title>

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Latest compiled and minified CSS -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css"
          integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">

    <!-- Optional theme -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap-theme.min.css"
          integrity="sha384-rHyoN1iRsVXV4nD0JutlnGaslCJuC7uwjduW9SVrLvRYooPp2bWYgmgJQIXwl/Sp" crossorigin="anonymous">

    <!-- Latest compiled and minified JavaScript -->
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"
            integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa"
            crossorigin="anonymous"></script>

    <!-- HTML5 shim and Respond.js for IE8 support of HTML5 elements and media queries -->
    <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
    <!--[if lt IE 9]>
    <script src="https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js"></script>
    <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->

</head>

<body>

<div class="medium" style="overflow:scroll; overflow-x:hidden; height:50%;">
    <?php
    $directories = array_diff(scandir($mc_array['dir_path']), $mc_array['exclude_list']);
    echo '<ul style="list-style:none;padding:0">';
    echo '<li style="margin-left:1em;">.. 
    <a href="?tpage=' . $mc_array['tpage'] . '&dir=' . dirname($mc_array['dir_path']) . '">💾 up</a></li>';
    //📝💾📁▶️
    foreach ($directories as $entry) {
        $dir_path_entry = $mc_array['dir_path'] . "" . $entry;
        $stat = stat($dir_path_entry);
        $tit = implode(",", $stat);
        $mc_array['stat']=$stat;
        
        if (is_dir($dir_path_entry)) {
            echo "<li style='margin-left:1em;'>📁 
            <a href='?dir=" . $mc_array['dir_path'] . $entry . "" . "'>" . $entry . "</a><br></li>";
        } else {
      	
            $file_path = str_replace($mc_array['DOCUMENT_ROOT'] , '', $dir_path_entry);
            $html_path = $protocol   .  $mc_array['HTTP_HOST'] . $file_path;
            echo '<li style="margin-left:1em;">📝 ';
            echo '<a href="?tpage=' . $mc_array['dir_path'] . "" . $entry . '&filename=' . $entry . '&view=true&dir=' . $mc_array['dir_path'] . '" target="main">' . $entry .
                '</a>  <a href="' . $html_path . '" title="stats:' . $tit . '" target="main"> ▶️ </a><br>
        </li>';
        }
    }
    echo "</ul>";
    echo '</div>';

    /***************
     * Source view *
     ***************/
    if ($mc_array['view']) {
        echo '<div class="medium" style="overflow:scroll; overflow-x:hidden; height:50%;">';
        echo "Displaying file:" . $mc_array['tpage'] . "<hr>";
        echo internoetics_highlight_file($mc_array['tpage']);
        echo '</div>';
    }else{
        echo '<div class="medium" style="overflow:scroll; overflow-x:hidden; height:50%;">';
        echo "Displaying file:" . $mc_array['tpage'] . "<hr><pre>";
        //echo internoetics_highlight_file($tpage,$mc_array);
        var_dump($mc_array);
        echo '</pre></div>';

    
    
    }
    ?>


    <!-- jQuery (necessary for Bootstrap's JavaScript plugins) -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
    <!-- Include all compiled plugins (below), or include individual files as needed -->
    <script
            src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.2.3/js/bootstrap.min.js"
            integrity="sha512-1/RvZTcCDEUjY/CypiMz+iqqtaoQfAITmNSJY17Myp4Ms5mdxPS5UV7iOfdZoxcGhzFbOm6sntTKJppjvuhg4g=="
            crossorigin="anonymous"
            referrerpolicy="no-referrer">
    </script>
</body>
</html>