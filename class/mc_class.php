<?php


// New Class called MC
class mc_class{
    // Class Variables
    public $exclude_list = array(".", "..");
    public $HTTP_HOST;
    public $DOCUMENT_ROOT;
    public $current_url;
    public $dir;
    public $view;
    public $tpage;
    public $dir_path;
    public $protocol;
    public $self;
    public $file_perm;
    public $file_size;
    public $file_size_kb;
    public $file_size_mb;
    public $file_size_gb;
    public $file_size_tb;
    public $icon_folder='📁';
    public $icon_file='📝';
    public $icon_run='▶️';
    public $icon_up='💾';


    // Methods
    public function get_current_url(){
        //$this->current_url = (isset($_SERVER['REQUEST_URI'])) ? $_SERVER['REQUEST_URI'] : false;
        //$url=$_SERVER["REQUEST_SCHEME"].'://'.$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"];
        //$x_PATHINFO = pathinfo($url);
        //$this->current_url = $x_PATHINFO['dirname'];
        $this->current_url = $this->get_protocol() . $this->get_HTTP_HOST() . $this->get_self();
        return $this->current_url;
    }


    public function get_icon_up(){
        return $this->icon_up;
    }
    public function get_icon_run(){
        return $this->icon_run;
    }
    public function get_icon_file(){
        return $this->icon_file;
    }
    public function get_icon_folder(){
        return $this->icon_folder;
    }

/////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////
    public function get_HTTP_HOST(){
        return $this->HTTP_HOST;
    }
    public function set_HTTP_HOST($HTTP_HOST=false){
        if($HTTP_HOST === false){
            $this->HTTP_HOST = (isset($_SERVER['HTTP_HOST'])) ? $_SERVER['HTTP_HOST'] : false;
        }else{
            $this->HTTP_HOST = $HTTP_HOST;
        }
        return $this->HTTP_HOST;
    }
/////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////
    public function set_DOCUMENT_ROOT($DOCUMENT_ROOT=false){
        if($DOCUMENT_ROOT === false){
            $this->DOCUMENT_ROOT = (isset($_SERVER['DOCUMENT_ROOT'])) ? $_SERVER['DOCUMENT_ROOT'] : false;
        }else{
            $this->DOCUMENT_ROOT = $DOCUMENT_ROOT;
        }
        return $this->DOCUMENT_ROOT;
    }
    public function get_DOCUMENT_ROOT(){
        return $this->DOCUMENT_ROOT;
    }
/////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////
    public function get_dir(){
        $this->dir = (isset($_GET['dir'])) ? $_GET['dir'] : false;
        if(is_dir($this->dir) === false){
            $this->dir = getcwd();
        }
        return $this->dir;
    }

    public function set_dir($dir=false){
        if($dir === false){
            $this->dir = (isset($_GET['dir'])) ? $_GET['dir'] : false;
        }else{
            $this->dir = $dir;
        }
        if(is_dir($this->dir) === false){
            $this->dir = getcwd();
        }
        return $this->dir;
    }
/////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////

    public function get_dir_path(){
        return $this->dir_path;
    }
    public function set_dir_path($dir_path=false){
        if($dir_path === false){
            $this->dir_path = $this->dir . '/';
            $this->dir_path = str_replace('\\','/',$this->dir_path);
            $this->dir_path = str_replace('//', '/', $this->dir_path);
        }else{
            $this->dir_path = $dir_path;
        }
        return $this->dir_path;
    }

/////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////
/*
if(is_dir($mc_array['tpage'])){
    $mc_array['current_dir_path'] = $mc_array['tpage'];
    $mc_array['view'] = false;    
}else{
    $mc_array['view'] = true;    
}
*/
public function get_tpage(){
    $this->tpage = (isset($_GET['tpage'])) ? $_GET['tpage'] : false;
    return $this->tpage;
}
public function set_tpage($tpage=false){
    if($tpage === false){
        $this->tpage = (isset($_GET['tpage'])) ? $_GET['tpage'] : false;
        if(is_dir($this->tpage)){
            $this->set_dir_path($this->tpage);
            $this->set_view(false);
        }else{
            $this->set_view(true);
        }
    }else{
        $this->tpage = $tpage;
    }
    return $this->tpage;
}
/////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////
    public function get_view(){
        return $this->view;
    }
    public function set_view($view=false){
        $this->view = $view;
        return $this->view;
    }
/////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////
    
    public function get_protocol(){
        if (isset($_SERVER['HTTPS']) &&
            ($_SERVER['HTTPS'] == 'on' || $_SERVER['HTTPS'] == 1) ||
            isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
            $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
          $this->protocol = 'https://';
        }
        else {
          $this->protocol = 'http://';
        }
        return $this->protocol;
    }
    public function get_self(){
        $this->self = str_replace($this->DOCUMENT_ROOT , '',$_SERVER['PHP_SELF'] );
        return $this->self;
    }


    public function get_exclude_list(){
        return $this->exclude_list;
    }
    public function get_file_perm($file){
        $this->file_perm = substr(sprintf('%o', fileperms($file)), -4);
        return $this->file_perm;
    }

    public function get_file_size($file){
        $this->file_size = filesize($file);
        return $this->file_size;
    }

    public function get_file_size_kb($file){
        $this->file_size_kb = filesize($file) / 1024;
        return $this->file_size_kb;
    }

    public function get_file_size_mb($file){
        $this->file_size_mb = filesize($file) / 1024 / 1024;
        return $this->file_size_mb;
    }

    public function get_file_size_gb($file){
        $this->file_size_gb = filesize($file) / 1024 / 1024 / 1024;
        return $this->file_size_gb;
    }

    public function get_file_size_tb($file){
        $this->file_size_tb = filesize($file) / 1024 / 1024 / 1024 / 1024;
        return $this->file_size_tb;
    }

    /************************************************/
    /*   */
    /************************************************/
    public function perm($stat){
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

    // Class Constructor
    public function __construct(){
        $this->set_HTTP_HOST();
        $this->set_DOCUMENT_ROOT();
        $this->set_dir();
        $this->set_dir_path();
        $this->set_tpage();



    }
    

    public function show_table($val){
        echo '<table  align="center"  class=" table table-hover table-striped" width="600" border="1" cellspacing="10" cellpadding="10" >';
        echo '<thead><tr>';
        
        foreach($val[0] as $key => $value){
            if($key!='path'){echo '<th >'.$key.'</th>';}
        }
        echo '</tr></thead>';
    
        for($i=0;$i<count($val);$i++){
            $path=$val[$i]['path'];
            echo '<tr>';
            foreach($val[$i] as $key => $value){
                if($key!='path'){
                    if($key=='name'){
                        $value='<a href="'.$this->get_current_url().'?dir='.$this->get_dir_path().'&tpage='.$path.'">'.$value.'</a>';
                        echo '<td>'.$value.'</td>';
                    }elseif($key=='size'){
                        $value=round($value/1024,2).' KB';
                        echo '<td>'.$value.'</td>';
                    }else{
                        echo '<td>'.$value.'</td>';
                    }
                }    
            }
            echo '</tr>';
        }
    
    
        echo '</table>';
     
        
        
    }
}














?>