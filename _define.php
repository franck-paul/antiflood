<?php
/**
 * @brief Antiflood, an antispam filter plugin for Dotclear 2
 *
 * @package Dotclear
 * @subpackage Plugins
 *
 * @author Olivier Meunier and contributors
 *
 * @copyright Olivier Meunier and contributors
 * @copyright GPL-2.0 https://www.gnu.org/licenses/gpl-2.0.html
 */
if (!defined('DC_RC_PATH')) {
    return;
}

$this->registerModule(
    'antiflood',                // Name
    'Anti flood spam filter',   // Description
    'dcTeam',                   // Author
    '0.6',
    [
        'requires'    => [['core', '2.24']],                                     // Dependencies
        'permissions' => 'usage,contentadmin',
        'priority'    => 200,
        'type'        => 'plugin',
    ]
);
