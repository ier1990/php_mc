<?php
session_start(); /* Starts the session */
//Validate Login
if(!isset($_SESSION['UserData']['Username'])){
    //header("location:login.php");
    //exit;
}
/************************************************/
// Production
//error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
//ini_set('display_errors', 0);
//ini_set('display_startup_errors', 0);
/************************************************/

/************************************************/
// Development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/************************************************/
$mc_array = array();
/************************************************/


/************************************************/
/*   */
/************************************************/
function perm($stat){
    $file_perm = ($stat['mode'] & 0x1000) ? 'd' : '-';
    $file_perm .= ($stat['mode'] & 0x0100) ? 'r' : '-';
    $file_perm .= ($stat['mode'] & 0x0080) ? 'w' : '-';
    $file_perm .= ($stat['mode'] & 0x0040) ?
        (($stat['mode'] & 0x0800) ? 's' : 'x' ) :
        (($stat['mode'] & 0x0800) ? 'S' : '-');
    $file_perm .= ($stat['mode'] & 0x0020) ? 'r' : '-';
    $file_perm .= ($stat['mode'] & 0x0010) ? 'w' : '-';
    $file_perm .= ($stat['mode'] & 0x0008) ?
        (($stat['mode'] & 0x0400) ? 's' : 'x' ) :
        (($stat['mode'] & 0x0400) ? 'S' : '-');
    $file_perm .= ($stat['mode'] & 0x0004) ? 'r' : '-';
    $file_perm .= ($stat['mode'] & 0x0002) ? 'w' : '-';
    $file_perm .= ($stat['mode'] & 0x0001) ?
        (($stat['mode'] & 0x0200) ? 't' : 'x' ) :
        (($stat['mode'] & 0x0200) ? 'T' : '-');
    return $file_perm;
}
/************************************************/
/*   */
/************************************************/

//Never changes
$mc_array['exclude_list']= array(".", "..");
$mc_array['HTTP_HOST']=(isset($_SERVER['HTTP_HOST'])) ? $_SERVER['HTTP_HOST'] : false;
$mc_array['DOCUMENT_ROOT'] = (isset($_SERVER['DOCUMENT_ROOT'])) ? $_SERVER['DOCUMENT_ROOT'] : false;
$mc_array['DOCUMENT_ROOT'] = str_replace('\\', '/', $mc_array['DOCUMENT_ROOT']);
if($mc_array['HTTP_HOST'] === false || $mc_array['DOCUMENT_ROOT'] === false){
    echo 'HTTP_HOST or DOCUMENT_ROOT not set';
    exit;
}
/************************************************/
/* GET Variables  */
/************************************************/
$mc_array['dir'] =   (isset($_GET['dir'])) ? $_GET['dir'] : getcwd();
$mc_array['view'] = (isset($_GET['view'])) ? $_GET['view'] : false;
$mc_array['tpage'] =  (isset($_GET['tpage'])) ? $_GET['tpage'] : false;
$mc_array['tpage'] =  str_replace('//', '/', $mc_array['tpage']);

/************************************************/
/*  Figure out new dir_path */
/************************************************/
$mc_array['dir'] = realpath($mc_array['dir']);
if ($mc_array['dir'] === false) {
    $mc_array['dir'] = getcwd();
}
$mc_array['dir'] = str_replace('\\', '/', $mc_array['dir']);
$mc_array['dir_path'] = $mc_array['dir'] . '/';
$mc_array['dir_path'] = str_replace('//', '/', $mc_array['dir_path']);

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
/*  Figure out self */
/************************************************/
$mc_array['self'] = str_replace($mc_array['DOCUMENT_ROOT'] , '',$_SERVER['PHP_SELF'] );

/************************************************/
/*  Start Header */
/************************************************/
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Classic PHP File Commander</title>

    <!-- Bootstrap v5.2.3 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">

    <link rel="stylesheet" href="dark.css">

</head>

<body>

<div class="medium" style="overflow:scroll; overflow-x:hidden; height:50%;">
    <?php
    //📝💾📁▶️
    // Get all files and directories in the current directory
    $directories = array_diff(scandir($mc_array['dir_path']), $mc_array['exclude_list']);

    // Header
    echo '<h1>Classic PHP Mugsy Commander - <a href="'. $protocol . $mc_array['HTTP_HOST'] . $mc_array['self'] . '" >' . $mc_array['HTTP_HOST'] . '</a> - <a href="logout.php">logout?</a> </h1>';
    echo 'Directory: ' . $mc_array['dir_path'] . '';

    echo '<ul style="list-style:none;padding:0">';

    //up directory 💾️
    echo '<li style="margin-left:1em;">.. ';
    echo '<a href="?tpage=' . $mc_array['tpage'] . '&dir=' . dirname($mc_array['dir_path']) . '">💾 up</a>';
    echo '<br></li>';

    foreach ($directories as $entry) {
        //Directory path
        $dir_path_entry = $mc_array['dir_path'] . "" . $entry;

        //Stat
        $stat = stat($dir_path_entry);
        $mc_array['stat']=$stat;

        //File size
        $file_size = ($stat['size'] > 1024) ? round($stat['size'] / 1024, 2) . ' KB' : $stat['size'] . ' B';

        //Permissions 0666-rw-rw-rw- 0777-rwxrwxrwx
        $file_perm = substr(sprintf('%o', $stat['mode']), -4);
        $file_perm .= perm($stat);



   //Directory listing
        if (is_dir($dir_path_entry)) {
           //📁️ Change Directory
            echo "<li style='margin-left:1em;'>📁 ";
            echo "<a href='?dir=" . $mc_array['dir_path'] . $entry . "" . "'>";
            echo $entry .' ' . $file_perm . "</a>";
            echo "<br></li>";

        } else {
   //File listing
        //HTML path
        $html_path = $protocol   .  $mc_array['HTTP_HOST'] . str_replace($mc_array['DOCUMENT_ROOT'] , '', $dir_path_entry);

        echo '<li style="margin-left:1em;">📝 ';

        //📝 View File
        echo '<a href="?tpage=' . $mc_array['dir_path'] . $entry . '&view=true&dir=' . $mc_array['dir_path'] . '" target="main">' . $entry . '</a> ';
        echo $file_size . ' ' . $file_perm;

        //▶️ Run File   
        echo ' <a href="' . $html_path . '" title="stats:' . "" . '" target="main"> ▶️ </a>';

        echo '<br></li>';

        }  //end if is_dir
    } //end foreach
    echo "</ul>";
    echo '</div>';

    /***************
     * Source view *
     ***************/
    if( ($mc_array['view']) && (is_readable($mc_array['tpage'])) ) {
        echo '<div class="medium" style="overflow:scroll; overflow-x:hidden; height:50%;">';
        echo "Displaying file:" . $mc_array['tpage'] . "<br>";
//Caution
//Care should be taken when using the highlight_file() function to make sure that you do not
// inadvertently reveal sensitive information such as passwords or any other type of information
// that might create a potential security risk.
        // color purple hex #800080
        ini_set('highlight.comment', '#800080; font-weight: bold;');
        //ini_set('highlight.default', '#000000');
        $file =  highlight_file($mc_array['tpage'], true);

//since the output is returned as html and new lines are broken with the <br /> tag, let's explode each line to array using <br /> to recognise a new line
        $file = explode ( '<br />', $file );
//first line number should be 1 right?
        $i = 1;
//let's wrap the output with a table
        echo '<table class="table table-striped table-hover ">';
//Now for each line we are gonna add line number to it and wrap it up with their divs
        foreach ( $file as $line ) {
            echo '<tr><td width="34">';
            echo $i;
            echo '. ';
            echo '</td>';

            echo '<td class="syntax-highlight-line">';
            echo $line;
            echo '</td></tr>';

            $i++;
        }
echo '</table>';


        echo '</div>';
    }else{
        echo '<div class="medium" style="overflow:scroll; overflow-x:hidden; height:50%;">';
        echo "Displaying array:" . '<pre>';
        var_dump($mc_array);
        echo '</pre></div>';
    }
    ?>

    <!-- v5.2.3 Latest compiled and minified JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.min.js" integrity="sha384-cuYeSxntonz0PPNlHhBs68uyIAVpIIOZZ5JqeqvYYIcEL727kskC66kF92t6Xl2V" crossorigin="anonymous"></script>

</body>
</html>