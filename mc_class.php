<?php
session_start(); /* Starts the session */
//Validate Login
if(!isset($_SESSION['UserData']['Username'])){
    header("location:login.php");
    exit;
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
//echo dirname(__FILE__).'/class/mc_class.php';
include_once(dirname(__FILE__).'/class/mc_class.php');
$mc = new mc_class();
$listing=array();

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
    // Get all files and directories in the current directory
    $directories = glob($mc->get_dir_path() . '*', GLOB_MARK | GLOB_ONLYDIR);
    $files = array_filter(glob($mc->get_dir_path() ."*"), 'is_file');

    // Header
    echo '<h1>Classic PHP Mugsy Commander - <a href="'. $mc->get_protocol() . $mc->get_HTTP_HOST() . $mc->get_self() . '" >' . $mc->get_HTTP_HOST() . '</a> - <a href="logout.php">logout?</a> </h1>';
    echo 'Directory: ' . realpath($mc->get_dir_path()) . '';
    //echo '<br>Current URL:'.$mc->get_current_url();

    $a=0;
    $listing[$a]['path']=dirname($mc->get_dir_path()).'/';
    $listing[$a]['name']=$mc->get_icon_up() . 'UP';
    $listing[$a]['type']='dir';
    $listing[$a]['size']='4096';
    $listing[$a]['perm']='..';
    $a++;

    // Loop through all directories   
    foreach ($directories as $entry) {

        $listing[$a]['path']=$entry;
        $listing[$a]['name']=$mc->get_icon_folder() . basename($entry);
        $listing[$a]['type']='dir';
        $listing[$a]['size']=$mc->get_file_size($entry);
        $listing[$a]['perm']=$mc->get_file_perm($entry);
        //$listing[$a]['date']=$mc->get_file_date($entry);
        //$listing[$a]['owner']=$mc->get_file_owner($entry);

        $a++;
    } 

    // Loop through all files
    foreach ($files as $entry) {
        $listing[$a]['path']=$entry;
        $listing[$a]['name']=$mc->get_icon_file().basename($entry);

        //works for linux & windows
        $html_path = str_replace(str_replace('\\','/',$mc->get_DOCUMENT_ROOT()), "", str_replace('\\','/',$entry));
        $html_path = $mc->get_protocol() . $mc->get_HTTP_HOST() . $html_path;
        //echo '<br>'.$html_path;
        $listing[$a]['type']='<a href="'.$html_path.'" target="_blank">'.$mc->get_icon_run().' Play?file'.'</a>';

        $listing[$a]['size']=$mc->get_file_size($entry);
        $listing[$a]['perm']=$mc->get_file_perm($entry);
        //$listing[$a]['date']=$mc->get_file_date($entry);
        //$listing[$a]['owner']=$mc->get_file_owner($entry);

        $a++;


    } //end foreach

//var_dump($listing);
$mc->show_table($listing);
echo '</div>';


    /***************
     * Source view *
     ***************/
    if( ($mc->get_view()) && (is_readable($mc->get_tpage())) ) {
        echo '<div class="medium" style="overflow:scroll; overflow-x:hidden; height:50%;">';
        echo "Displaying file:" . $mc->get_tpage() . "<br>";
//Caution
//Care should be taken when using the highlight_file() function to make sure that you do not
// inadvertently reveal sensitive information such as passwords or any other type of information
// that might create a potential security risk.
        // color purple hex #800080
        ini_set('highlight.comment', '#800080; font-weight: bold;');
        //ini_set('highlight.default', '#000000');
        $file =  highlight_file($mc->get_tpage(), true);

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
        echo "Displaying Paths:" . '<table class="table table-striped table-hover " style="width:100%;" border="1">';
        echo '<th>Variable</th><th>Value</th>';
        echo '<tr><td>'.'getcwd()'.'</td><td>'.getcwd().'</td></tr>';
        //__FILE__ is a magic constant containing the full path to the file you are executing.
        //So getcwd() returns the directory where you started executing, 
        //while dirname(__FILE__) is file-dependent.
        echo '<tr><td>'.'dirname(__FILE__)'.'</td><td>'.dirname(__FILE__).'</td></tr>';
        echo '<tr><td>'.'_SERVER["DOCUMENT_ROOT"]'.'</td><td>'.$_SERVER["DOCUMENT_ROOT"].'</td></tr>';
        echo '<tr><td>'.'_SERVER["SERVER_ADDR"]'.'</td><td>'.$_SERVER["SERVER_ADDR"].'</td></tr>';
        echo '<tr><td>'.'_SERVER["SERVER_PORT"]'.'</td><td>'.$_SERVER["SERVER_PORT"].'</td></tr>';
        echo '<tr><td>'.'_SERVER["REQUEST_SCHEME'.'</td><td>'.$_SERVER["REQUEST_SCHEME"].'</td></tr>';
        echo '<tr><td>'.'_SERVER["HTTP_HOST"]'.'</td><td>'.$_SERVER["HTTP_HOST"].'</td></tr>';        
        echo '<tr><td>'.'_SERVER["REQUEST_URI"]'.'</td><td>'.$_SERVER["REQUEST_URI"].'</td></tr>';
        echo '<tr><td>'.'_SERVER["QUERY_STRING"]'.'</td><td>'.$_SERVER["QUERY_STRING"].'</td></tr>';
        echo '<tr><td>'.'_SERVER["SCRIPT_NAME"]'.'</td><td>'.$_SERVER["SCRIPT_NAME"].'</td></tr>';
        echo '<tr><td>'.'_SERVER["PHP_SELF"]'.'</td><td>'.$_SERVER["PHP_SELF"].'</td></tr>';
        echo '<tr><td>'.'_SERVER["SCRIPT_FILENAME"]'.'</td><td>'.$_SERVER["SCRIPT_FILENAME"].'</td></tr>';
        echo '<tr><td>'.'__FILE__ '.'</td><td>'.__FILE__ .'</td></tr>';
        echo '<tr><td>'.'__DIR__ '.'</td><td>'.__DIR__ .'</td></tr>';
        echo '<tr><td>'.'__LINE__ '.'</td><td>'.__LINE__ .'</td></tr>';
        echo '<tr><td>'.'parse_url(SERVER[REQUEST_URI]'.'</td><td>'.parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH).'</td></tr>';
        echo '</table>';

        $url = $_SERVER["REQUEST_SCHEME"].'://'.$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"];
        echo 'Current URL='.$url.'<br>';
        // ======= PATHINFO ====== //
$x_PATHINFO = pathinfo($url);
echo '<pre>';
var_dump($x_PATHINFO);
echo '</pre>';
//$x['dirname']      🡺 https://example.com/subFolder
//$x['basename']     🡺                               myfile.php?var=blabla#555 // Unsecure!
//$x['extension']    🡺                                      php?var=blabla#555 // Unsecure!
//$x['filename']     🡺                               myfile

// ======= PARSE_URL ====== //
$x_PARSE_URL = parse_url($url);
echo '<pre>';
var_dump($x_PARSE_URL);
echo '</pre>';
//$x['scheme']       🡺 https
//$x['host']         🡺         example.com
//$x['path']         🡺                    /subFolder/myfile.php
//$x['query']        🡺                                          var=blabla
//$x['fragment']     🡺                                                     555
        

        
        //var_dump($mc_array);
        echo '</pre></div>';
    }
    function highlight_array($array, $name = 'var') {
        highlight_string("<?php\n\$$name =\n" . var_export($array, true) . ";\n?>");
    }

    echo highlight_array($_SERVER, 'SERVER');

    echo '</div><hr>';
    ?>

    <!-- v5.2.3 Latest compiled and minified JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.min.js" integrity="sha384-cuYeSxntonz0PPNlHhBs68uyIAVpIIOZZ5JqeqvYYIcEL727kskC66kF92t6Xl2V" crossorigin="anonymous"></script>

</body>
</html>
