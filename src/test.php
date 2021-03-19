<?php

include('L3fsReader.php');

$oReader = new L3fsReader('../fsdisc.img');

var_dump($oReader);
var_dump($oReader->getCatalogue());

//var_dump($oReader->getFile("BBC.ART.!MENU"));
