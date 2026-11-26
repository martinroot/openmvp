<?php
/* Публичная обёртка ганта для портала openmvp.online (без basic auth). */
$_GET["page"] = "gantt";
$_GET["embed"] = "1";
require __DIR__ . "/index.php";
