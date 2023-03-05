<?php
$mc_array = array();
$mc_array['username'] = 'admin';
$mc_array['password'] = 'password';


$username = (isset($_REQUEST['username'])) ? $_REQUEST['username'] : false;
$password = (isset($_REQUEST['password'])) ? $_REQUEST['password'] : false;
$mc_array['login'] = false;

// Path: login.php
// /************************************************/
// /*   */
// /************************************************/
 //if(isset($_POST['submit'])){
if($username == $mc_array['username'] && $password == $mc_array['password'])
{
        $mc_array['login'] = true;
        session_start();
        $_SESSION['UserData']['Username']=$mc_array['username'];
        header("location:mc.php");
        exit;
}else{ ?>
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
     <?php

}

?>

     <!-- v5.2.3 Latest compiled and minified JavaScript -->
     <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.min.js" integrity="sha384-cuYeSxntonz0PPNlHhBs68uyIAVpIIOZZ5JqeqvYYIcEL727kskC66kF92t6Xl2V" crossorigin="anonymous"></script>


</body>
</html>

