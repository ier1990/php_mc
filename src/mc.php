<?php

// Development mode
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$mc_array = array();
$mc_array['exclude_list'] = array(".", "..", ".git", ".svn");
$mc_array['HTTP_HOST'] = $_SERVER['HTTP_HOST'];
$mc_array['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'];
$mc_array['dir'] = isset($_GET['dir']) ? $_GET['dir'] : getcwd();
$mc_array['view'] = isset($_GET['view']) ? $_GET['view'] : false;
$mc_array['tpage'] = isset($_GET['tpage']) ? $_GET['tpage'] : false;
$mc_array['dir'] = realpath($mc_array['dir']);
if ($mc_array['dir'] == false) { $mc_array['dir'] = getcwd(); }
$mc_array['dir_path'] = $mc_array['dir'] . '/';
$protocol = (!empty($_SERVER['HTTPS']) || $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https://' : 'http://';
$mc_array['protocol'] = $protocol;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="robots" content="noindex, nofollow">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PHP File Browser</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/styles/base16/tomorrow-night.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/highlight.min.js"></script>
  <script>hljs.highlightAll();</script>
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
  <style>
    body { background-color:#111; color:#eee; }
    .scroll-box { height: 90vh; overflow: auto; border-right: 1px solid #444; padding-right: 1em; }
    .code-box { height: 90vh; overflow: auto; background: #1e1e1e; padding: 1em; }
    pre { white-space: pre-wrap; word-wrap: break-word; }
    .tree-label { font-weight: bold; color: #66ccff; margin-bottom: 0.5em; }
  </style>
</head>
<body>
<div class="container-fluid">
  <div class="row">
    <div class="col-sm-5 scroll-box">
      <div class="tree-label">📂 Directory Tree</div>
      <?php
      $parent_dir = dirname($mc_array['dir_path']);
      if ($parent_dir !== $mc_array['dir_path']) {
          echo '<div><a href="?dir=' . urlencode($parent_dir) . '" style="color:lightblue">⬆️ Up to ' . htmlspecialchars($parent_dir) . '</a></div><hr>';
      }

      $directories = array_diff(scandir($mc_array['dir_path']), $mc_array['exclude_list']);
      echo '<ul style="list-style:none;padding:0">';
      foreach ($directories as $entry) {
          $dir_path_entry = $mc_array['dir_path'] . $entry;
          if (is_dir($dir_path_entry)) {
              echo "<li style='margin-left:1em;'>📁 <a href='?dir=" . urlencode($dir_path_entry) . "' style='color:orange'>" . htmlspecialchars($entry) . "</a></li>";
          } else {
              $file_path = str_replace($mc_array['DOCUMENT_ROOT'], '', $dir_path_entry);
              $html_path = $protocol . $mc_array['HTTP_HOST'] . $file_path;
              $stat = stat($dir_path_entry);
              $perms = substr(sprintf('%o', fileperms($dir_path_entry)), -4);
              $size = number_format($stat['size']);
              $owner = posix_getpwuid($stat['uid'])['name'] ?? $stat['uid'];
              echo '<li style="margin-left:1em;">📝 ';
              echo '<a href="?tpage=' . urlencode($dir_path_entry) . '&filename=' . urlencode($entry) . '&view=true&dir=' . urlencode($mc_array['dir_path']) . '" style="color:lightgreen">' . htmlspecialchars($entry) . '</a> ';
              echo "<small style='color:#999'>[$perms | {$size} bytes | owner: $owner]</small> ";
              echo '<a href="' . $html_path . '" target="_blank">🔗</a></li>';
          }
      }
      echo '</ul>'; 
      ?>
    </div>
    <div class="col-sm-7 code-box">
      <?php
      if ($mc_array['view']) {
          echo '<h4>Viewing file: ' . htmlspecialchars($mc_array['tpage']) . '</h4><hr>';
          $ext = strtolower(pathinfo($mc_array['tpage'], PATHINFO_EXTENSION));
          if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
              echo '<img src="' . htmlspecialchars($protocol . $mc_array['HTTP_HOST'] . str_replace($mc_array['DOCUMENT_ROOT'], '', $mc_array['tpage'])) . '" class="img-responsive" style="max-width:100%">';
          } elseif ($ext === 'pdf') {
              echo '<iframe src="' . htmlspecialchars($protocol . $mc_array['HTTP_HOST'] . str_replace($mc_array['DOCUMENT_ROOT'], '', $mc_array['tpage'])) . '" width="100%" height="800px"></iframe>';
          } else {
              echo '<pre><code class="' . htmlspecialchars($ext) . '">' . htmlspecialchars(file_get_contents($mc_array['tpage'])) . '</code></pre>';
          }
      } else {
          // Display the mc_array contents for debugging
          //can we make it black bground and white text?         
          echo '<pre style="background-color:#111;color:#eee;">';
          echo '<code>' . htmlspecialchars(print_r($mc_array, true)) . '</code>';
          echo '</pre>';;
      }
      ?>
    </div>
  </div>
</div>
</body>
</html>
