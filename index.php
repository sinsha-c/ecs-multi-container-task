<?php
echo "<h1>Amazon ECS Demo</h1>";
echo "<hr>";
echo "<h2>Application Name</h2>";
echo getenv("APP_NAME");
echo "<br><br>";
echo "<h2>Database Password</h2>";
echo getenv("DB_PASSWORD");
echo "<br><br>";
echo "<h2>Hostname</h2>";
echo gethostname();
?>
