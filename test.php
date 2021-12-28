<?php

require_once(__DIR__ . '/nuliga_config.php');
require_once(__DIR__ . '/nuLigaClient.php');

$client = new nuLigaClient($nuLigaConfig);

//$federations = $client->getFederations();
//print_r($federations);

//$bdv = $client->getFederation('BDV');
//print_r($bdv);

//$seasons = $client->getSeasons('BDV');
//print_r($seasons);

$clubs = $client->getClubs('BDV');
print_r($clubs);

//$players = $client->getPlayers('BDV');
//print_r($players);
