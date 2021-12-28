<?php

require_once(__DIR__ . '/nuliga_config.php');
require_once(__DIR__ . '/nuLigaClient.php');

$client = new nuLigaClient($nuLigaConfig);
$clubs = $client->getClubs('BDV');

?>
<html>
    <head>
        <title>Vereine im BDV</title>
        <style>
            table {
                border-collapse: collapse;
            }

            th {
                padding: 5px;
                text-align: left;
            }

            tbody tr:nth-child(odd) {
                background: #eeeeee;
            }

            td {
                padding: 5px;
                vertical-align: top;
            }
        </style>
    </head>
    <body>
        <h1>Vereine im BDV</h1>
        <?php if (count($clubs) == 0) : ?>
            <h2>Es wurden keine Spieler gefunden.</h2>
        <?php else : ?>
            <table>
                <colgroup>
                    <col width="25">
                    <col width="25%">
                    <col width="15%">
                    <col>
                </colgroup>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Nummer</th>
                        <th>Kontaktadresse</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($clubs as $club) : ?>
                        <tr>
                            <td><?php echo $i ?>.</td>
                            <td><?php echo $club['name'] ?></td>
                            <td><?php echo $club['clubNr'] ?></td>
                            <td>
                                <?php if ($club['contactAddress']['contactPerson']) : ?>
                                    <?php echo $club['contactAddress']['contactPerson'] ?>
                                    <br>
                                    <?php echo $club['contactAddress']['street'] ?>
                                    <br>
                                    <?php echo $club['contactAddress']['zip'] ?>
                                    <?php echo $club['contactAddress']['city'] ?>
                                <?php else : ?>
                                -
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php $i++; endforeach ?>
                </tbody>
            </table>
        <?php endif ?>
    </body>
</html>
