![MCClass](https://github.com/ier1990/php_mc/blob/3239d1f898fdfa2c9d1580c02f571d67a3fc9eff/mc_class.png)
This is a git repo of Classic PHP MC File Commander

name: Classic PHP Mugsy File Commander
tested on: PHP 8.1.2-1ubuntu2.11

    //basics
    $mc_array['username'] = 'admin';
    $mc_array['password'] = 'password';
    include_once(dirname(__FILE__).'/class/mc_class.php');
    $mc = new mc_class();
    $listing=array();

    // Get all files and directories in the current directory
    $directories = glob($mc->get_dir_path() . '*', GLOB_MARK | GLOB_ONLYDIR);
    $files = array_filter(glob($mc->get_dir_path() ."*"), 'is_file');
    
    //process example name	type	size	perm
    $listing[$a]['path']=dirname($mc->get_dir_path()).'/';
    $listing[$a]['name']=$mc->get_icon_up() . 'UP';
    $listing[$a]['type']='dir';
    $listing[$a]['size']='4096';
    $listing[$a]['perm']='..';

$mc->show_table($listing);


