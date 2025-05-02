<?php
$mc_array = array();
$mc_array['username'] = 'admin';
$mc_array['password'] = 'password';
$mc_array['login'] = false;
const COOKIE_VALID_LENGTH = 3600; // 60sec * 60min = 1hour  3600   1day 86400
const MOS_COOKIE_NAME  = 'MOS_LOGIN_CLASS_COOKIE';

// Path: login.php
/************************************************/
/*   */
/************************************************/
function encrypt_decrypt($action, $string) {
    $output = false;

    $encrypt_method = "AES-256-CBC";
    $secret_key = 'This is my secret key';
    $secret_iv = 'This is my secret iv';

    // hash
    $key = hash('sha256', $secret_key);

    // iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
    $iv = substr(hash('sha256', $secret_iv), 0, 16);

    if( $action == 'encrypt' ) {
        $output = openssl_encrypt($string, $encrypt_method, $key, 0, $iv);
        $output = base64_encode($output);
    }
    else if( $action == 'decrypt' ){
        $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
    }

    return $output;
}
/************************************************/
/*   */
/************************************************/
function loginUser($username,$password){
    global $mc_array;
    if($username == $mc_array['username'] && $password == $mc_array['password'])
    {
        $mc_array['login'] = true;
        session_start();
        $_SESSION['UserData']['Username']=$mc_array['username'];
        $time = time() + COOKIE_VALID_LENGTH;
        $token = encrypt_decrypt('encrypt',$username."|".$password);
        // Setting a cookie for
        setcookie(MOS_COOKIE_NAME, $token, $time);
        header("location:mc.php");
        exit;
    }else{
        //login failed
        return false;
    }
}
/************************************************/
$username = (isset($_REQUEST['username'])) ? $_REQUEST['username'] : false;
$password = (isset($_REQUEST['password'])) ? $_REQUEST['password'] : false;

$cookie_user_array = array();
$cookie     = (isset($_COOKIE[MOS_COOKIE_NAME]))   ? trim($_COOKIE[MOS_COOKIE_NAME])      : false;
$cookie = htmlspecialchars($cookie, ENT_QUOTES, 'UTF-8');
if($cookie)
{
    $cookie_user=encrypt_decrypt('decrypt',$cookie);
    $cookie_user_array=explode('|',$cookie_user);
    $cookie_user_name = (isset($cookie_user_array[0])) ? $cookie_user_array[0] : false;
    $cookie_user_pass = (isset($cookie_user_array[1])) ? $cookie_user_array[1] : false;
    if( ($cookie_user_name == $mc_array['username'] && $cookie_user_pass == $mc_array['password']) ){
        $mc_array['login'] = loginUser($mc_array['username'], $mc_array['password']);
    }
}else{$mc_array['login'] = false;}




// Path: login.php
// /************************************************/
// /*   */
// /************************************************/
 //if(isset($_POST['submit'])){
if($username == $mc_array['username'] && $password == $mc_array['password'])
{
        $mc_array['login'] = true;
        $mc_array['login'] = loginUser($username,$password);
        header("location:mc.php");
        exit;
}else{
    $mc_array['login'] = false;
} ?>
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
     <body class="text-center">

     <main class="form-signin w-100 m-auto">
         <form action="login.php" method="post">
             <img class="mb-4" src="logoskull.png" alt="" width="72" height="57">
             <h1 class="h3 mb-3 fw-normal">Please sign in</h1>

             <div class="form-floating input-field" data-theme="dark">
                 <input type="text" name="username" class="form-control" id="floatingInput" placeholder="admin">
                 <label for="floatingInput">Username</label>
             </div>
             <div class="form-floating input-field" data-theme="dark">
                 <input type="password" name="password" class="form-control" id="floatingPassword" placeholder="Password">
                 <label for="floatingPassword">Password</label>
             </div>

             <div class="checkbox mb-3">
                 <label>
                     <input type="checkbox" value="remember-me"> Remember me
                 </label>
             </div>
             <button class="w-100 btn btn-lg btn-primary" name="submit" value="submit" type="submit">Sign in</button>
             <p class="mt-5 mb-3 text-muted">&copy; 2016–2023</p>
         </form>
     </main>

     <!-- v5.2.3 Latest compiled and minified JavaScript -->
     <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.min.js" integrity="sha384-cuYeSxntonz0PPNlHhBs68uyIAVpIIOZZ5JqeqvYYIcEL727kskC66kF92t6Xl2V" crossorigin="anonymous"></script>


</body>
</html>

