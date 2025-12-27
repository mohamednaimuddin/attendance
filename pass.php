<?php
$password = 'admin';
$hash = hash('sha256', $password);
echo $hash;
