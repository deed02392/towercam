<?php
require_once('towercam.v3.php');

$valid_contexts = array('html', 'stream');
$context = isset($_GET['c']) && in_array($_GET['c'], $valid_contexts) ? $_GET['c'] : 'html';

// Update towercam to prepare for output
$tower = new TowerCam('towercam.sqlite');

$start_time = microtime(true);
$tower->update();
$tower->build_image();
$tower->output($context);
$end_time = microtime(true);

if ($context == "html")
{
    ?>
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML Basic 1.1//EN" "http://www.w3.org/TR/xhtml-basic/xhtml-basic11.dtd">
    <html>
        <head>
            <title>Towercam</title>
            <meta http-equiv="Content-Type" content="text/html;charset=utf-8" />
            <meta http-equiv="pragma" content="no-cache" />
            <meta http-equiv="cache-control" content="no-cache" />
            <meta http-equiv="expires" content="0" /><!-- immediately -->
            <meta name="author" content="George Hafiz, george.hafiz -.at.- baesystems.com" />
            <meta http-equiv="refresh" content="<?php echo $tower->refresh_in ?>" />
            <link rel="stylesheet" href="styles/styles.css" type="text/css">
            <style type="text/css">
                #tower {
                    display: block;
                    width: 352px;
                    height: 288px;
                    background-image: url('tower.png?r=<?php echo rand(1,10000); ?>');
                }
            </style>
        </head>
        <body>
            <div id="tower">
                <div id="author">by <a href="mailto:george.hafiz@baesystems.com?subject=Towercam">George Hafiz</a></div>
                <div id="timestamp"></div>
            </div>
        </body>
    </html>

    <?php
    echo $end_time - $start_time;
    echo "\n<br />\n";
    echo date('r', $tower->get_tower_last_updated()) . ' - ' . date('r', $tower->get_met_last_updated());
}
?>