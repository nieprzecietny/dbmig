<?php
$link = mysql_connect("mysql57", "acc_app", "appPass") or die("Could not connect: " . mysql_error());
mysql_select_db("acc_app", $link);
mysql_query("SET NAMES utf8");
