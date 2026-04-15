<?php
/**
 * Meta viewport — incluir una vez en <head> (después de charset).
 */
if (!defined('CNE_HEAD_VIEWPORT_EMITTED')) {
    define('CNE_HEAD_VIEWPORT_EMITTED', true);
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
}
